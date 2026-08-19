<?php

namespace App\Services\Documents;

use App\Models\Achievement;
use App\Models\Application;

class DocumentAssemblyService
{
    /**
     * Assemble cv_markdown / cover_letter_markdown from tagged Achievements + the
     * research brief, save as the draft on the Application, and mark achievements used.
     *
     * @param  list<string>  $tags
     * @return array{cv_markdown: string, cover_letter_markdown: string}
     */
    public function assemble(Application $application, array $tags = []): array
    {
        $achievements = Achievement::query()
            ->where('user_id', $application->user_id)
            ->active()
            ->when($tags !== [], fn ($q) => $q->whereIn('tag', $tags))
            ->get();

        $bullets = $achievements->map(fn (Achievement $a) => '- '.$a->bullet_text)->implode("\n");

        $cvMarkdown = "# {$application->user->name}\n\n## Experience\n\n{$bullets}\n";

        $brief = $application->researchThought?->content ?? '';
        $coverLetterMarkdown = "Dear Hiring Team,\n\n{$brief}\n\nBest regards,\n{$application->user->name}\n";

        $application->update([
            'cv_markdown' => $cvMarkdown,
            'cover_letter_markdown' => $coverLetterMarkdown,
        ]);

        $achievements->each(fn (Achievement $a) => $a->markUsed());

        return ['cv_markdown' => $cvMarkdown, 'cover_letter_markdown' => $coverLetterMarkdown];
    }
}
