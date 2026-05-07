<?php

namespace App\Services\WorkingMemory\Composer;

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
Promotion guidance per source type:
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

        $compactionBlock = $this->renderCompactions($evidencePack['compactions'] ?? []);
        $signalBlock = $this->renderSignals($evidencePack['signals'] ?? []);

        $payload = <<<TEXT
## Working memory composition task

You are composing a decision-grade working-memory snapshot for IdeaTub.

Scope: {$evidencePack['scope_type']} / {$evidencePack['scope_key']}
Generated at: {$evidencePack['generated_at']}

Required sections (in this order): {$sections}

{$this->promotionRules()}

## Compactions (preferred evidence)
{$compactionBlock}

## Recent raw thoughts
{$signalBlock}

## Output contract

Return JSON with this exact shape (no Markdown fences):

{
  "summary_markdown": "<full markdown rendering with all required sections as `## <section>` headings, narrative bullet prose under each>",
  "structured_sections": {
    "Current Focus": [
      {
        "text": "<single bullet, narrative prose>",
        "importance": 1,
        "fallback_mode": "direct",
        "citations": [
          {"type": "thought|compaction|source", "url": "<exact url from evidence>", "label": "<exact label from evidence>"}
        ]
      }
    ],
    ...
  },
  "references": [
    {"type": "thought|compaction|source", "url": "<exact url>", "label": "<exact label>"}
  ]
}

Rules:
- Every bullet in a required section MUST have at least one citation taken verbatim from the evidence above.
- Do not invent URLs or labels. Use only the URLs and labels provided in compaction or signal references.
- Prefer compaction citations when both a compaction and its source thought are available.
- Source Notes should list each unique cited reference once.
- Write in concise decision-grade prose, not stub bullets. Aim for 1–3 sentence bullets that name people, IDs, dates, and concrete state.
TEXT;

        return Str::limit($payload, $maxChars, '');
    }

    private function promotionRules(): string
    {
        return self::PROMOTION_RULES;
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
