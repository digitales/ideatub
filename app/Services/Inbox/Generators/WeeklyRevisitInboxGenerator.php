<?php

namespace App\Services\Inbox\Generators;

use App\Models\User;
use App\Services\IdeasToRevisitService;
use App\Services\Inbox\Contracts\InboxGenerator;
use App\Support\Inbox\WeeklyRevisitBodyFormatter;

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
            ->map(fn ($idea) => '- '.WeeklyRevisitBodyFormatter::formatIdeaPreview($idea->content))
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
}
