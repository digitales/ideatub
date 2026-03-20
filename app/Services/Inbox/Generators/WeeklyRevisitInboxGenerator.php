<?php

namespace App\Services\Inbox\Generators;

use App\Models\User;
use App\Services\IdeasToRevisitService;
use App\Services\Inbox\Contracts\InboxGenerator;

class WeeklyRevisitInboxGenerator implements InboxGenerator
{
    public function __construct(
        private IdeasToRevisitService $revisitService
    ) {}

    public function generate(User $user): array
    {
        $ideas = collect($this->revisitService->forUser($user))->values();

        if ($ideas->isEmpty()) {
            return [];
        }

        $lines = $ideas
            ->map(fn ($idea) => '- '.$this->formatIdeaContent($idea->content))
            ->implode("\n");

        return [[
            'generator_type' => 'weekly_revisit',
            'title' => 'Weekly revisit',
            'body' => "Review these older ideas:\n".$lines,
            'dedupe_key' => 'weekly-revisit',
            'generated_at' => now(),
            'source_data' => [
                'idea_ids' => $ideas->pluck('id')->all(),
            ],
        ]];
    }

    private function formatIdeaContent(string $content): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $content));
    }
}
