<?php

namespace App\View\Presenters\Thoughts;

use App\Models\ResearchShare;
use App\Models\Thought;
use App\View\Presenters\Concerns\EnsuresPresenterDataIsLoaded;
use App\View\Presenters\Email\NewsletterResearchStatusPresenter;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * Per-card view state for stream feeds (main + typed collections).
 */
final class StreamThoughtCardPresenter
{
    use EnsuresPresenterDataIsLoaded;

    private function __construct(
        private readonly Thought $thought,
        private readonly ?ResearchShare $share,
        private readonly string $activityAtHuman,
        private readonly bool $editable,
        private readonly bool $showFullSections,
        private readonly ?NewsletterResearchStatusPresenter $newsletterResearchStatus,
    ) {
        $this->requireRelationLoaded($this->thought, 'comments');
    }

    public static function fromThought(
        Thought $thought,
        ?ResearchShare $share,
        bool $showFullSections,
        ?NewsletterResearchStatusPresenter $newsletterResearchStatus = null,
    ): self {
        $activityAt = self::resolveStreamActivityAt($thought);
        $activityHuman = $activityAt->diffForHumans();

        $userId = Auth::id();
        $editable = Auth::check() && $userId !== null && (int) $userId === (int) $thought->user_id;

        return new self(
            $thought,
            $share,
            $activityHuman,
            $editable,
            $showFullSections,
            $newsletterResearchStatus,
        );
    }

    public function thought(): Thought
    {
        return $this->thought;
    }

    public function share(): ?ResearchShare
    {
        return $this->share;
    }

    public function activityAtHuman(): string
    {
        return $this->activityAtHuman;
    }

    public function editable(): bool
    {
        return $this->editable;
    }

    public function showFullSections(): bool
    {
        return $this->showFullSections;
    }

    public function newsletterResearchStatus(): ?NewsletterResearchStatusPresenter
    {
        return $this->newsletterResearchStatus;
    }

    public function showViewFormattedLink(): bool
    {
        return $this->thought->relationLoaded('comments') && $this->thought->comments->isNotEmpty();
    }

    public function showCommentsBlock(): bool
    {
        return $this->showViewFormattedLink();
    }

    private static function resolveStreamActivityAt(Thought $thought): Carbon
    {
        if (($thought->source ?? null) === 'jira') {
            $jiraUpdatedAt = data_get($thought->source_metadata, 'jira_updated_at');
            if (is_string($jiraUpdatedAt) && trim($jiraUpdatedAt) !== '') {
                try {
                    return Carbon::parse($jiraUpdatedAt);
                } catch (\Throwable) {
                    // fall through to created_at
                }
            }
        }

        return $thought->created_at;
    }
}
