<?php

namespace App\Services\WorkingMemory\Composer;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class WorkingMemoryComposerPromptBuilder
{
    private const REQUIRED_SECTIONS = [
        'Current Focus',
        'Active Priorities',
        'Recent Changes',
        'Open Questions',
        'Risks / Blockers',
        'Next Actions',
        'Latest Signals',
        'Source Notes',
    ];

    private const PROMOTION_RULES = <<<'TEXT'
Evidence promotion hints:
- compaction:meeting → Recent Changes, Next Actions, Risks / Blockers, Open Questions
- compaction:weekly-digest → Latest Signals, Active Priorities, Recent Changes
- compaction:topic-digest → Active Priorities, Open Questions, Latest Signals
- compaction:research-synth → Open Questions, Risks / Blockers, Latest Signals
- raw thought (recent) → Latest Signals, Open Questions
- raw thought with risk/block/issue/delay/incident keywords → Risks / Blockers, Next Actions
TEXT;

    /**
     * @param  array{
     *     scope_type: string,
     *     scope_key: string,
     *     generated_at: string,
     *     fresh_start?: bool,
     *     prior_memory?: array{
     *         version_id: string,
     *         build_type: string,
     *         created_at: string|null,
     *         source_label: string|null,
     *         summary_markdown: string
     *     }|null,
     *     signals: array<int, array{
     *         thought_id: string|null,
     *         content: string,
     *         created_at: string|null,
     *         references: array<int, array{type: string, url: string, label: string}>
     *     }>,
     *     compactions: array<int, array{
     *         version_id: string,
     *         subtype: string,
     *         summary_markdown: string,
     *         created_at: string,
     *         references: array<int, array{type: string, url: string, label: string}>
     *     }>
     * }  $evidencePack
     */
    public function build(array $evidencePack): string
    {
        $maxChars = (int) config('working_memory.authoring_max_prompt_input_chars', 60000);
        $sections = implode(', ', self::REQUIRED_SECTIONS);
        $freshStart = (bool) ($evidencePack['fresh_start'] ?? false);

        $coreSpec = trim($this->loadCoreSpec());
        $priorBlock = $freshStart
            ? "_Fresh start requested — do not treat prior memory as baseline._\n\nSynthesize from evidence only."
            : $this->renderPriorMemory($evidencePack['prior_memory'] ?? null);
        $compactionBlock = $this->renderCompactions($evidencePack['compactions'] ?? []);
        $signalBlock = $this->renderSignals($evidencePack['signals'] ?? []);

        $payload = <<<TEXT
## Working memory composition task

You are composing an opinionated, judgment-first working-memory snapshot for IdeaTub — a synthesis of what matters now, not a summary of raw inputs.

Scope: {$evidencePack['scope_type']} / {$evidencePack['scope_key']}
Generated at: {$evidencePack['generated_at']}

Required sections (in this order): {$sections}

## Authoring spec (canonical)

{$coreSpec}

{$this->promotionRules()}

## Prior canonical memory (baseline)
{$priorBlock}

## Compactions (preferred evidence)
{$compactionBlock}

## Recent raw thoughts
{$signalBlock}

## Output contract

Return JSON with this exact shape (no Markdown fences):

{
  "summary_markdown": "<full markdown with all required sections as `## <section>` headings>",
  "structured_sections": {
    "Current Focus": [
      {
        "text": "<narrative prose with judgment and specificity>",
        "importance": 1,
        "fallback_mode": "direct",
        "citations": []
      }
    ],
    ...
  },
  "references": [
    {"type": "thought|compaction|source", "url": "<url when known>", "label": "<label>"}
  ]
}

Rules:
- Write with judgment. Revise the prior baseline when present; preserve accumulated context unless evidence contradicts it.
- Be specific: name people, IDs, dates, and concrete state — no placeholders or generic questions.
- Citations are optional on section bullets. Source Notes must list key sources from the evidence above (dates and slugs where known).
- When citing, use only URLs and labels from the evidence blocks. Do not invent links.
- Prefer compaction evidence for meeting and digest signals when available.
TEXT;

        return Str::limit($payload, $maxChars, '');
    }

    private function loadCoreSpec(): string
    {
        $path = resource_path('prompts/working-memory-authoring-core.md');

        return File::isFile($path) ? (string) File::get($path) : '';
    }

    private function promotionRules(): string
    {
        return self::PROMOTION_RULES;
    }

    /**
     * @param  array{
     *     version_id?: string,
     *     build_type?: string,
     *     created_at?: string|null,
     *     source_label?: string|null,
     *     summary_markdown?: string
     * }|null  $priorMemory
     */
    private function renderPriorMemory(?array $priorMemory): string
    {
        if ($priorMemory === null || trim((string) ($priorMemory['summary_markdown'] ?? '')) === '') {
            return '_No prior canonical memory for this scope — synthesize from evidence._';
        }

        $versionId = $priorMemory['version_id'] ?? 'unknown';
        $buildType = $priorMemory['build_type'] ?? 'unknown';
        $createdAt = $priorMemory['created_at'] ?? 'unknown';
        $sourceLabel = $priorMemory['source_label'] ?? 'none';
        $body = trim((string) $priorMemory['summary_markdown']);

        return "version:{$versionId} ({$buildType}, {$createdAt}, source: {$sourceLabel})\n\n{$body}";
    }

    /**
     * @param  array<int, array{
     *     version_id: string, subtype: string, summary_markdown: string,
     *     created_at: string, references: array<int, array{type: string, url: string, label: string}>
     * }>  $compactions
     */
    private function renderCompactions(array $compactions): string
    {
        if ($compactions === []) {
            return '_No compactions available for this window._';
        }

        $blocks = [];
        foreach ($compactions as $compaction) {
            $references = $this->renderReferences($compaction['references'] ?? []);
            $blocks[] = "### compaction:{$compaction['subtype']} — {$compaction['version_id']} ({$compaction['created_at']})\n"
                ."References: {$references}\n\n"
                .trim($compaction['summary_markdown']);
        }

        return implode("\n\n---\n\n", $blocks);
    }

    /**
     * @param  array<int, array{
     *     thought_id: string|null, content: string, created_at: string|null,
     *     references: array<int, array{type: string, url: string, label: string}>
     * }>  $signals
     */
    private function renderSignals(array $signals): string
    {
        if ($signals === []) {
            return '_No raw thoughts in this window._';
        }

        $lines = [];
        foreach ($signals as $signal) {
            $thoughtId = $signal['thought_id'] ?? 'unknown';
            $createdAt = $signal['created_at'] ?? 'unknown';
            $references = $this->renderReferences($signal['references'] ?? []);
            $content = trim($signal['content']);
            $lines[] = "- [{$createdAt}] thought:{$thoughtId} (refs: {$references})\n  {$content}";
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<int, array{type: string, url: string, label: string}>  $references
     */
    private function renderReferences(array $references): string
    {
        if ($references === []) {
            return 'none';
        }

        $parts = [];
        foreach ($references as $reference) {
            $parts[] = "[{$reference['type']}] {$reference['label']} ({$reference['url']})";
        }

        return implode('; ', $parts);
    }
}
