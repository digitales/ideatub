<?php

namespace App\View\Presenters\Ideas;

use App\Models\Thought;
use App\Services\DemoMode;
use App\View\Presenters\Concerns\ObfuscatesDemoText;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * View state for one row on the incomplete ideas list (logged date + research UI branching).
 */
final class IdeaListItemPresenter
{
    use ObfuscatesDemoText;

    /**
     * @param  Collection<int, Thought>  $researchList
     */
    private function __construct(
        private readonly Thought $thought,
        private readonly Collection $researchList,
        private readonly bool $ownerMayInlineEdit,
        private readonly IdeaResearchStatusPresenter $researchStatus,
    ) {}

    /**
     * @param  Collection<int, Thought>  $researchList  Pre-grouped research thoughts for this idea (newest first).
     */
    public static function from(Thought $thought, Collection $researchList, IdeaResearchStatusPresenter $researchStatus): self
    {
        $userId = Auth::id();
        $ownerMayInlineEdit = Auth::check() && $userId !== null && (int) $userId === (int) $thought->user_id;

        return new self($thought, $researchList, $ownerMayInlineEdit, $researchStatus);
    }

    public function thought(): Thought
    {
        return $this->thought;
    }

    public function loggedDateYmd(): string
    {
        return $this->thought->getLoggedDate();
    }

    public function isResearchPending(): bool
    {
        return $this->researchStatus->showsInProgress();
    }

    public function researchStatus(): IdeaResearchStatusPresenter
    {
        return $this->researchStatus;
    }

    /**
     * @return Collection<int, Thought>
     */
    public function researchList(): Collection
    {
        return $this->researchList;
    }

    /**
     * Full idea body for list preview / Alpine state (obfuscated in demo mode).
     */
    public function displayContent(): string
    {
        $raw = (string) ($this->thought->content ?? '');

        return $this->obfuscatedOrRaw($raw, 'thought_content', 'idea_list_item_presenter.display_content');
    }

    public function contentEditable(): bool
    {
        if (app(DemoMode::class)->enabled()) {
            return false;
        }

        return $this->ownerMayInlineEdit;
    }

    /**
     * @return list<array{research: Thought, preview: string}>
     */
    public function researchPreviewRows(): array
    {
        return $this->researchList->map(function (Thought $research): array {
            $raw = Str::limit((string) ($research->content ?? ''), 120);
            $preview = $this->obfuscatedOrRaw($raw, 'research_snippet', 'idea_list_item_presenter.research_preview');

            return [
                'research' => $research,
                'preview' => $preview,
            ];
        })->all();
    }

    private function obfuscatedOrRaw(string $value, string $context, string $boundary): string
    {
        try {
            return $this->demoText($value, $context) ?? '';
        } catch (\Throwable $e) {
            Log::warning('Demo obfuscation failed for idea list item presenter field.', [
                'boundary' => $boundary,
                'context' => $context,
                'thought_id' => $this->thought->id,
                'exception' => $e::class,
            ]);

            return 'Demo content hidden';
        }
    }
}
