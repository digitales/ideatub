<?php

namespace App\Services\WorkingMemory;

use App\Models\Project;
use App\Models\Thought;
use App\Models\WorkingMemoryVersion;
use App\Services\OpenRouterService;
use Illuminate\Support\Facades\Log;

final class WorkingMemoryAutoRebuildService
{
    private const SYSTEM_PROMPT = <<<'PROMPT'
You are updating the working memory for a personal knowledge management project.
Produce a structured, opinionated working memory -- not a summary of what exists,
but a synthesis that reflects the current state, what matters most, and what needs
to happen next.

Write exactly these sections with ## headings:
- Current Focus: one or two sentences on what this project is about right now
- Active Priorities: 3-5 specific, actionable items in priority order
- Recent Changes: bullet list with dates, most recent first, include why each matters
- Open Questions: specific unanswered questions blocking progress or carrying risk
- Risks / Blockers: named risks with context and implications
- Next Actions: concrete steps with implied ownership where obvious
- Latest Signals: external data points or observations that inform judgment
- Source Notes: key source documents with dates and slugs where known

Rules:
- Write with judgment, not just description
- Be concise but specific -- no placeholders or generic items
- Every item must be grounded in the content provided
- If the project has insufficient content to populate a section meaningfully, omit that section rather than adding a placeholder
PROMPT;

    public function __construct(
        private readonly WorkingMemoryAssembler $assembler,
        private readonly WorkingMemoryUpsertService $upsertService,
        private readonly OpenRouterService $openRouter,
    ) {}

    public function shouldSkipForDebounce(int $userId, string $projectId): bool
    {
        $prefix = (string) config('working_memory.auto_rebuild_source_label_prefix', 'auto-rebuild');
        $debounceMinutes = max(1, (int) config('working_memory.auto_rebuild_debounce_minutes', 30));
        $cutoff = now()->subMinutes($debounceMinutes);

        return WorkingMemoryVersion::query()
            ->where('created_at', '>=', $cutoff)
            ->whereHas('workingMemory', function ($query) use ($userId, $projectId): void {
                $query->where('user_id', $userId)
                    ->where('scope_type', 'project')
                    ->where('scope_key', $projectId);
            })
            ->orderByDesc('created_at')
            ->limit(20)
            ->get()
            ->contains(function (WorkingMemoryVersion $version) use ($prefix): bool {
                $label = data_get($version->build_diagnostics_json, 'source_label');

                return is_string($label) && str_starts_with($label, $prefix);
            });
    }

    /**
     * @return array{version_id: string, thought_count: int}|null null when skipped or empty LLM output
     */
    public function rebuild(Project $project): ?array
    {
        if (! $project->working_memory_auto_update) {
            Log::info('WorkingMemoryAutoRebuild skipped: auto-update paused for project.', [
                'project_id' => (string) $project->id,
            ]);

            return null;
        }

        $userId = (int) $project->user_id;
        $projectId = (string) $project->id;

        if ($this->shouldSkipForDebounce($userId, $projectId)) {
            Log::info('WorkingMemoryAutoRebuild skipped: debounce window active.', [
                'project_id' => $projectId,
            ]);

            return null;
        }

        $projectBrief = $this->projectBriefContent($project);
        $currentMemory = $this->currentWorkingMemoryMarkdown($userId, $projectId);
        $recentThoughts = $this->recentThoughtsForProject($project);
        $thoughtCount = count($recentThoughts);

        $userPrompt = $this->buildUserPrompt($projectBrief, $currentMemory, $recentThoughts);

        $completion = $this->openRouter->researchFromPromptCompletion(
            $userPrompt,
            (string) config('working_memory.auto_rebuild_model', 'claude-sonnet-4-20250514'),
            null,
            (int) config('working_memory.auto_rebuild_max_tokens', 2000),
            self::SYSTEM_PROMPT,
        );

        $markdown = trim($completion['content']);
        if ($markdown === '') {
            Log::warning('WorkingMemoryAutoRebuild skipped: empty LLM response.', [
                'project_id' => $projectId,
            ]);

            return null;
        }

        $prefix = (string) config('working_memory.auto_rebuild_source_label_prefix', 'auto-rebuild');
        $sourceLabel = $prefix.'-'.now()->format('Y-m-d-His');

        $result = $this->upsertService->upsert(
            $userId,
            'project',
            $projectId,
            $markdown,
            $sourceLabel,
        );

        Log::info('WorkingMemoryAutoRebuild succeeded.', [
            'project_id' => $projectId,
            'version_id' => (string) $result->version->id,
            'thought_count' => $thoughtCount,
        ]);

        return [
            'version_id' => (string) $result->version->id,
            'thought_count' => $thoughtCount,
        ];
    }

    private function projectBriefContent(Project $project): string
    {
        $brief = $project->thoughts()
            ->tagMatchesQuery('project-brief')
            ->orderByDesc('thoughts.updated_at')
            ->first(['thoughts.content']);

        if (! $brief instanceof Thought) {
            return '';
        }

        return trim((string) $brief->content);
    }

    private function currentWorkingMemoryMarkdown(int $userId, string $projectId): string
    {
        $payload = $this->assembler->forScope($userId, 'project', $projectId);
        $markdown = $payload['summary_markdown'] ?? '';

        return is_string($markdown) ? trim($markdown) : '';
    }

    /**
     * @return list<Thought>
     */
    private function recentThoughtsForProject(Project $project): array
    {
        $limit = max(1, (int) config('working_memory.auto_rebuild_thought_limit', 20));

        return $project->orderMembersForDisplay($project->thoughts())
            ->limit($limit)
            ->get(['thoughts.id', 'thoughts.content', 'thoughts.updated_at'])
            ->all();
    }

    /**
     * @param  list<Thought>  $recentThoughts
     */
    private function buildUserPrompt(string $projectBrief, string $currentMemory, array $recentThoughts): string
    {
        $recentBlock = collect($recentThoughts)
            ->map(function (Thought $thought): string {
                $updatedAt = $thought->updated_at?->toIso8601String() ?? 'unknown';

                return "## Thought {$thought->id} ({$updatedAt})\n".trim((string) $thought->content);
            })
            ->implode("\n\n");

        return <<<PROMPT
Project context:
{$projectBrief}

Current working memory (for continuity, may be outdated):
{$currentMemory}

Recent thoughts:
{$recentBlock}

Write the updated working memory now.
PROMPT;
    }
}
