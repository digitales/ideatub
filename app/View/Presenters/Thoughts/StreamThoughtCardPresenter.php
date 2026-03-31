<?php

namespace App\View\Presenters\Thoughts;

use App\Models\ResearchShare;
use App\Models\Thought;
use App\Services\DemoMode;
use App\View\Presenters\Concerns\EnsuresPresenterDataIsLoaded;
use App\View\Presenters\Concerns\ObfuscatesDemoText;
use App\View\Presenters\Email\NewsletterResearchStatusPresenter;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Per-card view state for stream feeds (main + typed collections).
 */
final class StreamThoughtCardPresenter
{
    use EnsuresPresenterDataIsLoaded;
    use ObfuscatesDemoText;

    private function __construct(
        private readonly Thought $thought,
        private readonly ?ResearchShare $share,
        private readonly string $activityAtHuman,
        private readonly bool $ownerMayInlineEdit,
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
        $ownerMayInlineEdit = Auth::check() && $userId !== null && (int) $userId === (int) $thought->user_id;

        return new self(
            $thought,
            $share,
            $activityHuman,
            $ownerMayInlineEdit,
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

    public function displayContent(): string
    {
        $raw = (string) ($this->thought->content ?? '');

        return $this->obfuscatedOrRaw($raw, 'thought_content', 'stream_thought_card_presenter.display_content');
    }

    /**
     * @return list<array{content: string, created_at_human: string}>
     */
    public function commentPreviewRows(): array
    {
        return $this->thought->comments
            ->map(function (Thought $comment): array {
                $raw = $this->showFullSections
                    ? (string) ($comment->content ?? '')
                    : Str::limit($comment->content, 200);
                $content = $this->obfuscatedOrRaw($raw, 'thought_comment_preview', 'stream_thought_card_presenter.comment_preview');

                return [
                    'content' => $content,
                    'created_at_human' => $comment->created_at->diffForHumans(),
                ];
            })
            ->all();
    }

    public function editable(): bool
    {
        if (app(DemoMode::class)->enabled()) {
            return false;
        }

        return $this->ownerMayInlineEdit;
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

    private function obfuscatedOrRaw(string $value, string $context, string $boundary): string
    {
        try {
            return $this->demoText($value, $context) ?? '';
        } catch (\Throwable $e) {
            Log::warning('Demo obfuscation failed for stream thought card presenter field.', [
                'boundary' => $boundary,
                'context' => $context,
                'thought_id' => $this->thought->id,
                'exception' => $e::class,
            ]);

            return 'Demo content hidden';
        }
    }
}
