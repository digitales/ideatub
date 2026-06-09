<?php

namespace App\View\Presenters\Projects;

use App\Models\Thought;
use App\Support\Research\MicrositePageLabel;
use App\Support\ThoughtTypeNavigation;
use App\View\Presenters\Concerns\ObfuscatesDemoText;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class ProjectMemberThoughtPresenter
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

    public function url(): string
    {
        return $this->thought->ideaTubViewUrl();
    }

    public function title(): string
    {
        if ($this->thought->isMicrositeDocumentLayout()) {
            return $this->obfuscatedOrRaw(
                MicrositePageLabel::forThought($this->thought),
                'thought_content',
                'project_member_thought_presenter.title',
            );
        }

        $content = ltrim((string) $this->thought->content);
        if (preg_match('/^#+\s+(.+)$/m', $content, $matches)) {
            return $this->obfuscatedOrRaw(
                trim($matches[1]),
                'thought_content',
                'project_member_thought_presenter.title',
            );
        }

        $plain = trim(preg_replace('/[#*_`>\[\]()!-]/', '', Str::before($content, "\n")) ?? $content);

        return $this->obfuscatedOrRaw(
            Str::limit($plain !== '' ? $plain : $content, 120),
            'thought_content',
            'project_member_thought_presenter.title',
        );
    }

    public function excerpt(): ?string
    {
        if ($this->thought->isMicrositeDocumentLayout()) {
            return null;
        }

        $content = trim((string) $this->thought->content);
        if ($content === '') {
            return null;
        }

        $withoutHeading = preg_replace('/^#+\s+.+$/m', '', $content) ?? $content;
        $plain = trim(preg_replace('/[#*_`>\[\]()!-]/', ' ', $withoutHeading) ?? $withoutHeading);
        $plain = preg_replace('/\s+/', ' ', $plain) ?? $plain;
        $plain = trim($plain);

        if ($plain === '') {
            return null;
        }

        if (Str::startsWith($plain, $this->title())) {
            $plain = trim(Str::after($plain, $this->title()));
        }

        return $plain !== '' ? $this->obfuscatedOrRaw(
            Str::limit($plain, 160),
            'thought_content',
            'project_member_thought_presenter.excerpt',
        ) : null;
    }

    public function typeLabel(): ?string
    {
        $metadata = $this->thought->metadata;
        $typeRaw = is_array($metadata) ? ($metadata['type'] ?? null) : null;
        if (is_string($typeRaw) && trim($typeRaw) !== '') {
            $normalized = mb_strtolower(trim($typeRaw));
            $extra = ['decision', 'dev', 'support', 'spec'];
            if (in_array($normalized, $extra, true)) {
                return ucfirst($normalized);
            }
        }

        $navKey = ThoughtTypeNavigation::resolveThoughtToTypeKey($this->thought);
        if ($navKey !== null) {
            return ThoughtTypeNavigation::thoughtDisplayLabel($navKey);
        }

        if ($this->thought->isMicrositeDocumentLayout()) {
            return 'Research';
        }

        return null;
    }

    public function updatedAtHuman(): string
    {
        return $this->thought->updated_at->diffForHumans();
    }

    private function obfuscatedOrRaw(string $value, string $context, string $boundary): string
    {
        try {
            return $this->demoText($value, $context) ?? '';
        } catch (\Throwable $e) {
            Log::warning('Demo obfuscation failed for project member thought presenter field.', [
                'boundary' => $boundary,
                'context' => $context,
                'thought_id' => $this->thought->id,
                'exception' => $e::class,
            ]);

            return 'Demo content hidden';
        }
    }
}
