<?php

namespace App\Services;

use App\Jobs\RunResearchRun;
use App\Models\CapturedInboundEmail;
use App\Models\ImportedEmail;
use App\Models\ResearchRun;
use App\Models\ResearchSkill;
use App\Models\ResearchSkillVersion;
use App\Models\Thought;
use App\Models\User;
use App\Services\Research\ResearchSkillManager;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Runs research for ideas and creates linked research thoughts.
 * Research thoughts are created directly (no embedding) and linked via metadata.idea_id.
 */
class ResearchService
{
    public function __construct(
        private OpenRouterService $openRouter,
        private ThoughtCaptureService $captureService,
        private ResearchSkillManager $researchSkillManager,
    ) {}

    /**
     * Run research for an existing idea and create a linked research thought.
     *
     * @param  string  $source  'web' or 'mcp'
     *
     * @throws RequestException
     * @throws \RuntimeException
     */
    public function runResearchForIdea(Thought $idea, string $source = 'web'): Thought
    {
        // Rate-limit can be applied here when config('research.rate_limit_enabled') is true (e.g. throttle by user_id).
        $researchText = $this->openRouter->researchNote($idea->content);

        return $this->persistResearchForIdea($idea, $researchText, $source);
    }

    /**
     * Persist a completed research body for an idea (OpenRouter already ran elsewhere).
     */
    public function saveRunResult(ResearchRun $run, string $researchText): Thought
    {
        $run->loadMissing('ideaThought');

        return $this->persistResearchForIdea($run->ideaThought, $researchText, $run->source ?? 'web');
    }

    /**
     * Create or reuse a research run for this idea and queue execution. At most one active
     * (queued or running) run per idea; an existing active run is returned without dispatching again.
     */
    /**
     * Whether the user has a default research skill that may run automatically (Save idea + global auto-run).
     */
    public function hasEligibleDefaultAutoRunSkillForUser(User $user): bool
    {
        return ResearchSkill::query()
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->where('is_manual_enabled', true)
            ->where('is_default', true)
            ->where('allow_auto_run', true)
            ->whereHas('latestVersion')
            ->exists();
    }

    public function queueResearchRunForIdea(
        Thought $idea,
        string $source = 'web',
        ?int $researchSkillId = null
    ): ResearchRun {
        return DB::transaction(function () use ($idea, $source, $researchSkillId): ResearchRun {
            $existing = ResearchRun::query()
                ->where('idea_thought_id', $idea->id)
                ->whereIn('status', ['queued', 'running'])
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            $version = $this->resolveManualResearchSkillVersionForIdea($idea, $researchSkillId);

            $run = ResearchRun::query()->create([
                'user_id' => $idea->user_id,
                'idea_thought_id' => $idea->id,
                'research_skill_id' => $version->research_skill_id,
                'research_skill_version_id' => $version->id,
                'source' => $source,
                'status' => 'queued',
                'workflow_type_snapshot' => $version->workflow_type,
                'context_options_snapshot' => $version->context_options,
                'output_shape_snapshot' => $version->output_shape,
                'intensity_snapshot' => $version->intensity,
                'current_stage' => 0,
                'total_stages' => 1,
                'usage_metadata' => null,
                'final_research_thought_id' => null,
                'error_summary' => null,
            ]);

            $runId = $run->id;
            DB::afterCommit(function () use ($runId): void {
                RunResearchRun::dispatch($runId);
            });

            return $run;
        });
    }

    /**
     * Clear research_pending on an idea thought after background research finishes or fails.
     */
    public function clearResearchPendingForIdeaThought(string $ideaThoughtId): void
    {
        $thought = Thought::find($ideaThoughtId);
        if ($thought === null) {
            return;
        }

        $metadata = $thought->metadata ?? [];
        unset($metadata['research_pending']);
        $thought->update(['metadata' => $metadata]);
    }

    private function resolveManualResearchSkillVersionForIdea(Thought $idea, ?int $researchSkillId = null): ResearchSkillVersion
    {
        $user = User::query()->findOrFail($idea->user_id);

        if ($researchSkillId !== null) {
            $requestedSkill = ResearchSkill::query()
                ->whereKey($researchSkillId)
                ->where('user_id', $user->id)
                ->where('is_active', true)
                ->where('is_manual_enabled', true)
                ->first();

            if ($requestedSkill === null) {
                throw new InvalidArgumentException('Requested research skill is not available.');
            }

            $requestedVersion = $requestedSkill->latestVersion;
            if (! $requestedVersion instanceof ResearchSkillVersion) {
                throw new \RuntimeException('Requested research skill has no version.');
            }

            return $requestedVersion;
        }

        $skill = ResearchSkill::query()
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->where('is_manual_enabled', true)
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->first();

        if ($skill !== null) {
            $version = $skill->latestVersion;
            if ($version instanceof ResearchSkillVersion) {
                return $version;
            }
        }

        $skill = $this->researchSkillManager->create($user, [
            'name' => 'Default research',
            'is_default' => true,
            'is_manual_enabled' => true,
        ]);

        $version = $skill->latestVersion;
        if (! $version instanceof ResearchSkillVersion) {
            throw new \RuntimeException('Research skill was created without a version.');
        }

        return $version;
    }

    public function persistResearchForIdea(Thought $idea, string $researchText, string $source = 'web'): Thought
    {
        $metadata = Thought::normalizeMetadataTags([
            'type' => 'research',
            'idea_id' => $idea->id,
            'tags' => ['research'],
        ]);

        $emailLinkage = $this->buildEmailLinkagePayload($idea);
        if ($emailLinkage !== null) {
            $metadata = array_merge($metadata, $emailLinkage);
        }

        $research = Thought::create([
            'content' => $researchText,
            'embedding' => null,
            'metadata' => $metadata,
            'user_id' => $idea->user_id,
            'source' => $this->shouldStoreAsResearchSource($idea, $source) ? 'research' : $source,
            'source_metadata' => $emailLinkage,
        ]);

        if ($emailLinkage !== null) {
            $this->persistEmailResearchBackLinks($idea, $research);
        }

        return $research;
    }

    /**
     * Create an idea thought only (no research). Use when research will run in the background job.
     *
     * @param  string  $source  'web' or 'mcp'
     */
    public function createIdeaOnly(string $ideaContent, string $source = 'web'): Thought
    {
        $ideaMetadata = [
            'type' => 'idea',
            'completed' => false,
            'logged_date' => now()->toDateString(),
        ];

        $result = $this->captureService->create([
            'content' => trim($ideaContent),
            'user_id' => (int) auth()->id(),
            'source' => $source,
            'idea_metadata' => $ideaMetadata,
        ]);

        $idea = $result['thought'] ?? $result['root'];
        if (! $idea instanceof Thought) {
            throw new \RuntimeException('ThoughtCaptureService did not return an idea thought.');
        }

        return $idea;
    }

    /**
     * Create an idea thought then run research and link the research thought.
     * If research fails, the idea is still created; research will be null.
     *
     * @param  string  $source  'web' or 'mcp'
     * @return array{idea: Thought, research: Thought|null}
     */
    public function createIdeaAndResearch(string $ideaContent, string $source = 'web'): array
    {
        $idea = $this->createIdeaOnly($ideaContent, $source);

        try {
            $research = $this->runResearchForIdea($idea, $source);
        } catch (\Throwable) {
            return ['idea' => $idea, 'research' => null];
        }

        return ['idea' => $idea, 'research' => $research];
    }

    /**
     * @return array{email_thought_id: string, email_subject: string, email_sender: string}|null
     */
    private function buildEmailLinkagePayload(Thought $idea): ?array
    {
        if (! $this->isCanonicalEmailSource($idea->source)) {
            return null;
        }

        $subject = trim((string) ($idea->source_metadata['subject'] ?? $idea->source_metadata['email_subject'] ?? ''));
        $sender = trim((string) ($idea->source_metadata['from'] ?? $idea->source_metadata['email_sender'] ?? ''));

        $imported = $idea->importedEmail();
        if ($imported !== null) {
            $subject = trim((string) ($imported->subject ?? $subject));
            $sender = $this->formatImportedEmailSender($imported) ?: $sender;
        }

        $captured = $this->resolveCapturedInboundEmail($idea);
        if ($captured !== null) {
            $subject = trim((string) ($captured->subject ?? $subject));
            $sender = trim((string) ($captured->sender_email ?? $sender));
        }

        if ($subject === '' || $sender === '') {
            return null;
        }

        return [
            'email_thought_id' => (string) $idea->id,
            'email_subject' => $subject,
            'email_sender' => $sender,
        ];
    }

    private function shouldStoreAsResearchSource(Thought $idea, string $source): bool
    {
        return $this->isCanonicalEmailSource($idea->source) || $source === 'research';
    }

    private function isCanonicalEmailSource(?string $source): bool
    {
        return in_array(mb_strtolower(trim((string) $source)), ['email', 'emails'], true);
    }

    private function persistEmailResearchBackLinks(Thought $emailThought, Thought $research): void
    {
        $emailMeta = is_array($emailThought->source_metadata) ? $emailThought->source_metadata : [];
        $emailMeta['research_thought_id'] = $research->id;
        $emailThought->update(['source_metadata' => $emailMeta]);

        $imported = $emailThought->importedEmail();
        if ($imported !== null) {
            $imported->update(['research_thought_id' => $research->id]);
        }

        $captured = $this->resolveCapturedInboundEmail($emailThought);
        if ($captured !== null) {
            $captured->update(['research_thought_id' => $research->id]);
        }
    }

    private function resolveCapturedInboundEmail(Thought $thought): ?CapturedInboundEmail
    {
        $capturedId = data_get($thought->source_metadata, 'captured_inbound_email_id');
        if ($capturedId !== null && (string) $capturedId !== '') {
            $row = CapturedInboundEmail::query()
                ->where('user_id', $thought->user_id)
                ->find($capturedId);
            if ($row !== null) {
                return $row;
            }
        }

        return CapturedInboundEmail::query()
            ->where('user_id', $thought->user_id)
            ->where('thought_id', $thought->id)
            ->first();
    }

    private function formatImportedEmailSender(ImportedEmail $row): string
    {
        $from = is_array($row->from_json) ? ($row->from_json[0] ?? null) : null;
        if (! is_array($from)) {
            return '';
        }

        $email = trim((string) ($from['email'] ?? ''));
        $name = trim((string) ($from['name'] ?? ''));
        if ($email === '') {
            return '';
        }

        return $name !== '' ? $name.' <'.$email.'>' : $email;
    }
}
