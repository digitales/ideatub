<?php

namespace App\Services\WorkingMemory\Compactions;

use App\Models\Thought;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class TopicDigestPromptBuilder
{
    /**
     * @param  Collection<int, Thought>  $thoughts
     */
    public function build(
        string $scopeType,
        string $scopeKey,
        string $topic,
        Collection $thoughts,
    ): string {
        $maxChars = (int) config('working_memory.authoring_max_prompt_input_chars', 60000);
        $body = $this->renderThoughts($thoughts);

        $prompt = <<<TEXT
## Topic digest task

Produce a durable on-demand digest compaction over the captures tagged with this topic.

Scope: {$scopeType} / {$scopeKey}
Topic: {$topic}

## Tagged captures
{$body}

## Output contract

Return JSON only (no Markdown fences) with this shape:

{
  "summary_markdown": "<full markdown with the sections below as `## <section>`>",
  "structured_sections": {
    "Active Priorities": [{"text": "...", "importance": 1, "fallback_mode": "direct", "citations": []}],
    "Open Questions": [...],
    "Latest Signals": [...]
  },
  "references": []
}

Rules:
- The three required sections are: Active Priorities, Open Questions, Latest Signals.
- Cluster related captures; do not repeat the same point in multiple sections.
- Active Priorities = work currently in flight on this topic or claimed for next.
- Open Questions = unresolved decisions or contradictions raised by captures.
- Latest Signals = newly observed information that may shape next decisions on this topic.
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
            return '_No tagged captures._';
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
