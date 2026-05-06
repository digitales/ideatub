<?php

namespace App\Http\Requests\Learning;

use Illuminate\Foundation\Http\FormRequest;

class LearningCaptureRequest extends FormRequest
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
            'artifact_type' => ['required', 'string', 'in:takeaway,confusion,lesson_summary'],
            'content' => ['required', 'string', 'max:65535'],
        ];
    }
}
