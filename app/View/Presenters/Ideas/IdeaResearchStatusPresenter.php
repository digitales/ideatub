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
        if ($this->activeRun !== null || $this->activeRunStatus() !== null) {
            return true;
        }

        return $this->idea->isResearchPending();
    }

    public function activeSkillName(): ?string
    {
        if ($this->activeRun !== null) {
            $this->activeRun->loadMissing('researchSkill');
            $name = $this->activeRun->researchSkill?->name;

            return is_string($name) && $name !== '' ? $name : null;
        }

        return $this->preloadedString('active_research_run_skill_name');
    }

    public function statusLine(): string
    {
        if (! $this->showsInProgress()) {
            return '';
        }

        $skill = $this->activeSkillName();
        $prefix = $this->activeRunStatus() === 'queued'
            ? 'Queued'
            : 'Researching';

        return $skill !== null
            ? "{$prefix} ({$skill})…"
            : "{$prefix}…";
    }

    public function showsFailed(): bool
    {
        if ($this->activeRun !== null) {
            return false;
        }

        return $this->latestRunStatus() === 'failed';
    }

    public function failedSummary(): ?string
    {
        if (! $this->showsFailed()) {
            return null;
        }

        $s = $this->latestRun?->error_summary ?? $this->preloadedString('latest_research_run_error_summary');

        return is_string($s) ? $s : null;
    }

    public function failedSkillName(): ?string
    {
        if (! $this->showsFailed()) {
            return null;
        }

        if ($this->latestRun !== null) {
            $this->latestRun->loadMissing('researchSkill');
            $name = $this->latestRun->researchSkill?->name;

            return is_string($name) && $name !== '' ? $name : null;
        }

        return $this->preloadedString('latest_research_run_skill_name');
    }

    private function activeRunStatus(): ?string
    {
        if ($this->activeRun !== null) {
            return $this->activeRun->status;
        }

        return $this->preloadedString('active_research_run_status');
    }

    private function latestRunStatus(): ?string
    {
        if ($this->latestRun !== null) {
            return $this->latestRun->status;
        }

        return $this->preloadedString('latest_research_run_status');
    }

    private function preloadedString(string $key): ?string
    {
        $value = $this->idea->getAttribute($key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
