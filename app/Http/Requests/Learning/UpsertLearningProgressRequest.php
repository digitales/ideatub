<?php

namespace App\Http\Requests\Learning;

use Illuminate\Foundation\Http\FormRequest;

class UpsertLearningProgressRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'content_version' => ['required', 'integer', 'min:1'],
            'bookmark_position' => ['nullable', 'string', 'max:512'],
            'completed' => ['sometimes', 'boolean'],
        ];
    }
}
