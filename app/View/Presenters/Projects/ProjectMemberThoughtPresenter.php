<?php

namespace App\View\Presenters\Projects;

use App\Models\Thought;
use App\Support\Research\MicrositePageLabel;
use App\Support\ThoughtTypeNavigation;
use Illuminate\Support\Str;

final class ProjectMemberThoughtPresenter
{
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
            return MicrositePageLabel::forThought($this->thought);
        }

        $content = ltrim((string) $this->thought->content);
        if (preg_match('/^#+\s+(.+)$/m', $content, $matches)) {
            return trim($matches[1]);
        }

        $plain = trim(preg_replace('/[#*_`>\[\]()!-]/', '', Str::before($content, "\n")) ?? $content);

        return Str::limit($plain !== '' ? $plain : $content, 120);
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

        return $plain !== '' ? Str::limit($plain, 160) : null;
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
}
