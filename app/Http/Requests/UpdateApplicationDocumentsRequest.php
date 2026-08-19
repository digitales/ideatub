<?php

namespace App\Http\Requests;

use App\Models\Application;
use Illuminate\Foundation\Http\FormRequest;

class UpdateApplicationDocumentsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && $this->user()->can('update', $this->route('application'));
    }

    public function rules(): array
    {
        return [
            'cv_markdown' => ['sometimes', 'nullable', 'string'],
            'cover_letter_markdown' => ['sometimes', 'nullable', 'string'],
            'stage' => ['sometimes', 'string', \Illuminate\Validation\Rule::in(Application::STAGES)],
        ];
    }
}
