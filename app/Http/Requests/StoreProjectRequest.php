<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesElixirrProjectSlugs;
use App\Models\Project;
use Illuminate\Foundation\Http\FormRequest;

class StoreProjectRequest extends FormRequest
{
    use ValidatesElixirrProjectSlugs;

    public function authorize(): bool
    {
        return $this->user() !== null && $this->user()->can('create', Project::class);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return array_merge([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:65535'],
        ], $this->elixirrSlugRules());
    }
}
