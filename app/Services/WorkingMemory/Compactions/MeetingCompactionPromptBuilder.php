<?php

namespace App\Services\WorkingMemory\Compactions;

use App\Models\Thought;
use Illuminate\Support\Str;

class MeetingCompactionPromptBuilder
{
    public function build(Thought $meeting): string
    {
        $maxChars = (int) config('working_memory.authoring_max_prompt_input_chars', 60000);
        $tags = collect(data_get($meeting->metadata, 'tags', []))
            ->map(fn ($t): string => trim((string) $t))
            ->filter()
            ->implode(', ');
        $createdAt = $meeting->created_at?->toIso8601String() ?? 'unknown';
        $body = trim((string) $meeting->content);

        $prompt = <<<TEXT
## Meeting compaction task

Synthesize the following meeting capture into a durable compaction note.

Meeting thought id: {$meeting->id}
Captured at: {$createdAt}
Tags: {$tags}

## Raw capture

{$body}

## Output contract

Return JSON only (no Markdown fences) with this shape:

{
  "summary_markdown": "<full markdown with the sections below as `## <section>`>",
  "structured_sections": {
    "Summary": [{"text": "...", "importance": 1, "fallback_mode": "direct", "citations": []}],
    "Decisions": [...],
    "Action Items": [...],
    "Risks / Blockers": [...],
    "Open Questions": [...]
  },
  "references": []
}

Rules:
- The five required sections are: Summary, Decisions, Action Items, Risks / Blockers, Open Questions.
- Action Items should name owners when the capture supports it. Do not invent owners.
- Decisions should include only confirmed decisions; tentative items belong under Open Questions.
- Keep prose concrete (dates, IDs, names). Do not pad.
- Citations array should be empty for now — a follow-up step will populate them from related thoughts.
TEXT;

        return Str::limit($prompt, $maxChars, '');
    }
}
