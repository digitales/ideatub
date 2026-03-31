<?php

namespace App\View\Presenters\Ideas;

use App\Models\ResearchRun;
use App\Models\Thought;

/**
 * Research run display state for an idea row (active run, latest outcome, metadata fallback).
 */
final class IdeaResearchStatusPresenter
{
    private function __construct(
        private readonly Thought $idea,
        private readonly ?ResearchRun $activeRun,
        private readonly ?ResearchRun $latestRun,
    ) {}

    public static function from(Thought $idea, ?ResearchRun $activeRun, ?ResearchRun $latestRun): self
    {
        return new self($idea, $activeRun, $latestRun);
    }

    public function showsInProgress(): bool
    {
        if ($this->activeRun !== null) {
            return true;
        }

        return $this->idea->isResearchPending();
    }

    public function activeSkillName(): ?string
    {
        if ($this->activeRun === null) {
            return null;
        }

        $this->activeRun->loadMissing('researchSkill');
        $name = $this->activeRun->researchSkill?->name;

        return is_string($name) && $name !== '' ? $name : null;
    }

    public function statusLine(): string
    {
        if (! $this->showsInProgress()) {
            return '';
        }

        $skill = $this->activeSkillName();

        return $skill !== null
            ? "Researching ({$skill})…"
            : 'Researching…';
    }

    public function showsFailed(): bool
    {
        if ($this->activeRun !== null) {
            return false;
        }

        return $this->latestRun !== null && $this->latestRun->status === 'failed';
    }

    public function failedSummary(): ?string
    {
        if (! $this->showsFailed()) {
            return null;
        }

        $s = $this->latestRun?->error_summary;

        return is_string($s) ? $s : null;
    }

    public function failedSkillName(): ?string
    {
        if (! $this->showsFailed()) {
            return null;
        }

        $this->latestRun?->loadMissing('researchSkill');
        $name = $this->latestRun?->researchSkill?->name;

        return is_string($name) && $name !== '' ? $name : null;
    }
}
