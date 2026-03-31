<?php

namespace App\View\Presenters\Ideas;

use App\Models\Thought;
use Illuminate\Support\Collection;

/**
 * View state for one row on the incomplete ideas list (logged date + research UI branching).
 */
final class IdeaListItemPresenter
{
    /**
     * @param  Collection<int, Thought>  $researchList
     */
    private function __construct(
        private readonly Thought $thought,
        private readonly Collection $researchList,
    ) {}

    /**
     * @param  Collection<int, Thought>  $researchList  Pre-grouped research thoughts for this idea (newest first).
     */
    public static function from(Thought $thought, Collection $researchList): self
    {
        return new self($thought, $researchList);
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
        return $this->thought->isResearchPending();
    }

    /**
     * @return Collection<int, Thought>
     */
    public function researchList(): Collection
    {
        return $this->researchList;
    }
}
