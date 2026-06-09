<?php

namespace App\View\Presenters\Projects;

use App\Models\Project;
use App\View\Presenters\Concerns\ObfuscatesDemoText;
use Illuminate\Support\Facades\Log;

final class ProjectShowPresenter
{
    use ObfuscatesDemoText;

    private function __construct(
        private readonly Project $project,
    ) {}

    public static function fromProject(Project $project): self
    {
        return new self($project);
    }

    public function project(): Project
    {
        return $this->project;
    }

    public function pageTitle(): string
    {
        $raw = trim((string) $this->project->title);

        return $this->obfuscatedOrRaw($raw, 'project_title', 'project_show_presenter.page_title');
    }

    public function descriptionMarkdown(): ?string
    {
        $raw = $this->project->description;
        if ($raw === null || trim((string) $raw) === '') {
            return null;
        }

        return $this->obfuscatedOrRaw((string) $raw, 'project_description', 'project_show_presenter.description_markdown');
    }

    private function obfuscatedOrRaw(string $value, string $context, string $boundary): string
    {
        try {
            return $this->demoText($value, $context) ?? '';
        } catch (\Throwable $e) {
            Log::warning('Demo obfuscation failed for project show presenter field.', [
                'boundary' => $boundary,
                'context' => $context,
                'project_id' => $this->project->id,
                'exception' => $e::class,
            ]);

            return 'Demo content hidden';
        }
    }
}
