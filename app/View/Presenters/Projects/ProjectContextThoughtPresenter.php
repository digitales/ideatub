<?php

namespace App\View\Presenters\Projects;

use App\Models\Thought;
use App\Support\Research\MicrositePageLabel;
use App\View\Presenters\Concerns\ObfuscatesDemoText;
use Illuminate\Support\Facades\Log;

final class ProjectContextThoughtPresenter
{
    use ObfuscatesDemoText;

    private function __construct(
        private readonly Thought $thought,
    ) {}

    public static function fromThought(Thought $thought): self
    {
        return new self($thought);
    }

    public function thought(): Thought
    {
        return $this->thought;
    }

    public function isMicrositeLayout(): bool
    {
        return $this->thought->isMicrositeDocumentLayout();
    }

    public function markdown(): string
    {
        $raw = (string) $this->thought->content;

        return $this->obfuscatedOrRaw($raw, 'thought_content', 'project_context_thought_presenter.markdown');
    }

    public function displayLabel(): string
    {
        $raw = MicrositePageLabel::forThought($this->thought);

        return $this->obfuscatedOrRaw($raw, 'thought_content', 'project_context_thought_presenter.display_label');
    }

    private function obfuscatedOrRaw(string $value, string $context, string $boundary): string
    {
        try {
            return $this->demoText($value, $context) ?? '';
        } catch (\Throwable $e) {
            Log::warning('Demo obfuscation failed for project context thought presenter field.', [
                'boundary' => $boundary,
                'context' => $context,
                'thought_id' => $this->thought->id,
                'exception' => $e::class,
            ]);

            return 'Demo content hidden';
        }
    }
}
