<?php

namespace App\Services\WorkingMemory\Compactions;

use App\Models\Thought;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class ScopeDigestPromptBuilder
{
    /**
     * @param  Collection<int, Thought>  $thoughts
     */
    public function build(
        string $scopeType,
        string $scopeKey,
        Carbon $windowStart,
        Carbon $windowEnd,
        Collection $thoughts,
    ): string {
        $maxChars = (int) config('working_memory.authoring_max_prompt_input_chars', 60000);
        $body = $this->renderThoughts($thoughts);

        $prompt = <<<TEXT
## Scope digest task

Produce a durable digest compaction over the recent activity in this scope.

Scope: {$scopeType} / {$scopeKey}
Window: {$windowStart->toIso8601String()} → {$windowEnd->toIso8601String()}

## Recent thoughts
{$body}

## Output contract

Return JSON only (no Markdown fences) with this shape:

{
  "summary_markdown": "<full markdown with the sections below as `## <section>`>",
  "structured_sections": {
    "Latest Signals": [{"text": "...", "importance": 1, "fallback_mode": "direct", "citations": []}],
    "Active Priorities": [...],
    "Recent Changes": [...]
  },
  "references": []
}

Rules:
- The three required sections are: Latest Signals, Active Priorities, Recent Changes.
- Cluster related thoughts; do not repeat the same point in multiple sections.
- Latest Signals = newly observed information that may shape next decisions.
- Active Priorities = work currently in flight or claimed for next.
- Recent Changes = factual deltas that already happened.
- Citations array should be empty; the canonical composer will add permalinks.
TEXT;

        return Str::limit($prompt, $maxChars, '');
    }

    /**
     * @param  Collection<int, Thought>  $thoughts
     */
    private function renderThoughts(Collection $thoughts): string
    {
        if ($thoughts->isEmpty()) {
            return '_No thoughts in window._';
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
