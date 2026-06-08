<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesElixirrProjectSlugs;
use App\Models\Project;
use Illuminate\Foundation\Http\FormRequest;

class UpdateProjectRequest extends FormRequest
{
    use ValidatesElixirrProjectSlugs;

    public function authorize(): bool
    {
        /** @var Project $project */
        $project = $this->route('project');

        return $project !== null && $this->user()?->can('update', $project);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return array_merge([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:65535'],
            'working_memory_auto_update' => ['sometimes', 'boolean'],
        ], $this->elixirrSlugRules());
    }

    protected function elixirrClientRootExclusionProjectId(): ?string
    {
        /** @var Project|null $project */
        $project = $this->route('project');

        return $project?->id;
    }
}
