<?php

namespace App\Http\Requests\Learning;

use Illuminate\Foundation\Http\FormRequest;

class SubmitLearningQuizRequest extends FormRequest
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
            'answers' => ['required', 'array'],
            'answers.*' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
