<?php

namespace App\View\Presenters\Ideas;

use App\Models\Thought;
use App\View\Presenters\Concerns\ObfuscatesDemoText;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * View state for one row on the completed ideas list (formatted logged / completed labels).
 */
final class CompletedIdeaPresenter
{
    use ObfuscatesDemoText;

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

    /**
     * Truncated excerpt for the completed list (obfuscated in demo mode).
     */
    public function displayExcerpt(): string
    {
        $raw = Str::limit((string) ($this->thought->content ?? ''), 200);

        return $this->obfuscatedOrRaw($raw, 'thought_content', 'completed_idea_presenter.display_excerpt');
    }

    private function obfuscatedOrRaw(string $value, string $context, string $boundary): string
    {
        try {
            return $this->demoText($value, $context) ?? '';
        } catch (\Throwable $e) {
            Log::warning('Demo obfuscation failed for completed idea presenter field.', [
                'boundary' => $boundary,
                'context' => $context,
                'thought_id' => $this->thought->id,
                'exception' => $e::class,
            ]);

            return 'Demo content hidden';
        }
    }
}
