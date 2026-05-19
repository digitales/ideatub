<?php

namespace App\Http\Requests\Concerns;

use App\Models\Project;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

trait ValidatesElixirrProjectSlugs
{
    protected function prepareForValidation(): void
    {
        $normalized = [];

        foreach (['elixirr_client_slug', 'elixirr_project_slug', 'parent_project_id'] as $field) {
            if ($this->has($field) && $this->input($field) === '') {
                $normalized[$field] = null;
            }
        }

        if ($normalized !== []) {
            $this->merge($normalized);
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function elixirrSlugRules(): array
    {
        $userId = $this->user()?->id;

        return [
            'elixirr_client_slug' => ['nullable', 'string', 'max:64', 'regex:/^[a-z0-9-]+$/'],
            'elixirr_project_slug' => ['nullable', 'string', 'max:64', 'regex:/^[a-z0-9-]+$/'],
            'parent_project_id' => [
                'nullable',
                'uuid',
                Rule::exists('projects', 'id')->where(fn ($query) => $query->where('user_id', $userId)),
            ],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function (Validator $v): void {
            $this->validateElixirrProjectSlugRequiresParent($v);
            $this->validateUniqueElixirrClientRoot($v);
            $this->validateParentIsNotSelf($v);
        });
    }

    protected function validateElixirrProjectSlugRequiresParent(Validator $validator): void
    {
        if ($this->filled('elixirr_project_slug') && ! $this->filled('parent_project_id')) {
            $validator->errors()->add(
                'parent_project_id',
                'A parent project is required when an Elixirr project slug is set.'
            );
        }
    }

    protected function validateUniqueElixirrClientRoot(Validator $validator): void
    {
        if (! $this->filled('elixirr_client_slug')
            || $this->filled('elixirr_project_slug')
            || $this->filled('parent_project_id')) {
            return;
        }

        $query = Project::query()
            ->where('user_id', $this->user()->id)
            ->where('elixirr_client_slug', $this->input('elixirr_client_slug'))
            ->whereNull('parent_project_id')
            ->whereNull('elixirr_project_slug');

        if ($excludeId = $this->elixirrClientRootExclusionProjectId()) {
            $query->where('id', '!=', $excludeId);
        }

        if ($query->exists()) {
            $validator->errors()->add(
                'elixirr_client_slug',
                'You already have a client root project for this Elixirr client slug.'
            );
        }
    }

    protected function validateParentIsNotSelf(Validator $validator): void
    {
        $projectId = $this->elixirrClientRootExclusionProjectId();

        if ($projectId === null || ! $this->filled('parent_project_id')) {
            return;
        }

        if ((string) $this->input('parent_project_id') === (string) $projectId) {
            $validator->errors()->add('parent_project_id', 'A project cannot be its own parent.');
        }
    }

    protected function elixirrClientRootExclusionProjectId(): ?string
    {
        return null;
    }
}
