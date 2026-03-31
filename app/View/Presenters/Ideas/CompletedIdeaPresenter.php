<?php

namespace App\View\Presenters\Ideas;

use App\Models\Thought;
use Illuminate\Support\Carbon;

/**
 * View state for one row on the completed ideas list (formatted logged / completed labels).
 */
final class CompletedIdeaPresenter
{
    private function __construct(
        private readonly Thought $thought,
        private readonly string $loggedFormatted,
        private readonly string $completedFormatted,
    ) {}

    public static function from(Thought $thought): self
    {
        $tz = (string) config('app.timezone');
        $loggedFormatted = Carbon::parse($thought->getLoggedDate(), $tz)->format('F j, Y');
        $completedAt = $thought->getIdeaCompletedAt();
        $completedFormatted = $completedAt !== null
            ? $completedAt->timezone($tz)->format('F j, Y')
            : '—';

        return new self($thought, $loggedFormatted, $completedFormatted);
    }

    public function thought(): Thought
    {
        return $this->thought;
    }

    public function loggedFormatted(): string
    {
        return $this->loggedFormatted;
    }

    /**
     * Date string for the "Completed …" line, or an em dash when the timestamp is missing or invalid.
     */
    public function completedFormatted(): string
    {
        return $this->completedFormatted;
    }
}
