<?php

namespace App\Services;

use App\DataTransferObjects\MorningBriefCardData;
use App\DataTransferObjects\MorningBriefData;
use App\Models\Draft;
use App\Models\InboxItem;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class MorningBriefService
{
    public function __construct(
        private IdeasToRevisitService $ideasToRevisitService,
    ) {}

    public function forUser(User $user): MorningBriefData
    {
        $cards = [];

        $latestDraft = Draft::query()
            ->where('user_id', $user->id)
            ->orderByDesc('updated_at')
            ->first();

        if ($latestDraft !== null) {
            $preview = trim((string) $latestDraft->content_preview);
            $cards[] = new MorningBriefCardData(
                kind: 'draft',
                label: 'Draft',
                title: $preview !== '' ? $preview : 'Untitled draft',
                subtitle: $this->relativeTimeLabel($latestDraft->updated_at),
                href: route('idea.index'),
                draftId: $latestDraft->id,
            );
        }

        $inboxCount = (int) InboxItem::query()
            ->forUser($user)
            ->actionable()
            ->count();

        if ($inboxCount > 0) {
            $cards[] = new MorningBriefCardData(
                kind: 'inbox',
                label: 'Inbox',
                title: $inboxCount === 1
                    ? '1 item needs attention'
                    : "{$inboxCount} items need attention",
                subtitle: 'Review and triage',
                href: route('inbox.index'),
            );
        }

        $revisitCount = $this->ideasToRevisitService->countForUser($user);

        if ($revisitCount > 0) {
            $cards[] = new MorningBriefCardData(
                kind: 'revisit',
                label: 'Revisit',
                title: $revisitCount === 1
                    ? '1 idea to revisit'
                    : "{$revisitCount} ideas to revisit",
                subtitle: 'Incomplete ideas, oldest first',
                href: route('idea.revisit'),
            );
        }

        $latestProject = Project::query()
            ->where('user_id', $user->id)
            ->orderByDesc('updated_at')
            ->first();

        if ($latestProject !== null) {
            $cards[] = new MorningBriefCardData(
                kind: 'project',
                label: 'Project',
                title: $latestProject->title,
                subtitle: 'Continue where you left off',
                href: route('projects.show', $latestProject),
            );
        }

        return new MorningBriefData(
            greeting: $this->greetingFor($user),
            cards: $cards,
        );
    }

    private function greetingFor(User $user): string
    {
        $hour = (int) now()->format('G');
        $period = match (true) {
            $hour < 12 => 'Good morning',
            $hour < 17 => 'Good afternoon',
            default => 'Good evening',
        };

        $firstName = Str::before(trim((string) $user->name), ' ');
        if ($firstName === '') {
            return $period;
        }

        return "{$period}, {$firstName}";
    }

    private function relativeTimeLabel(?Carbon $at): ?string
    {
        if ($at === null) {
            return null;
        }

        return $at->diffForHumans(short: true);
    }
}
