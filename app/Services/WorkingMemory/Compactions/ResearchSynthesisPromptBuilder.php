<?php

namespace App\Services\WorkingMemory\Compactions;

use App\Models\Thought;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class ResearchSynthesisPromptBuilder
{
    /**
     * @param  Collection<int, Thought>  $thoughts
     */
    public function build(
        string $scopeType,
        string $scopeKey,
        Collection $thoughts,
    ): string {
        $maxChars = (int) config('working_memory.authoring_max_prompt_input_chars', 60000);
        $body = $this->renderResearch($thoughts);

        $prompt = <<<TEXT
## Research synthesis task

Synthesize the following research-tagged captures into a durable research compaction.

Scope: {$scopeType} / {$scopeKey}

## Research captures
{$body}

## Output contract

Return JSON only (no Markdown fences) with this shape:

{
  "summary_markdown": "<full markdown with the sections below as `## <section>`>",
  "structured_sections": {
    "Open Questions": [{"text": "...", "importance": 1, "fallback_mode": "direct", "citations": []}],
    "Risks / Blockers": [...],
    "Latest Signals": [...],
    "Source Notes": [...]
  },
  "references": []
}

Rules:
- The four required sections are: Open Questions, Risks / Blockers, Latest Signals, Source Notes.
- Promote contradictions and confidence gaps into Open Questions or Risks / Blockers.
- Latest Signals = newly observed evidence shaping next decisions.
- Source Notes = one bullet per cited capture, briefest possible.
- Citations array should be empty; the canonical composer adds permalinks.
TEXT;

        return Str::limit($prompt, $maxChars, '');
    }

    /**
     * @param  Collection<int, Thought>  $thoughts
     */
    private function renderResearch(Collection $thoughts): string
    {
        if ($thoughts->isEmpty()) {
            return '_No research captures._';
        }

        $lines = [];
        foreach ($thoughts as $thought) {
            $id = (string) ($thought->id ?? 'unknown');
            $createdAt = $thought->created_at?->toIso8601String() ?? 'unknown';
            $content = trim((string) $thought->content);
            $lines[] = "- [{$createdAt}] thought:{$id}\n  {$content}";
        }

        return implode("\n", $lines);
    }
}
