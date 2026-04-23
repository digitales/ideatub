<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class QuickImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'files' => ['required', 'array', 'max:5'],
            'files.*' => [
                'file',
                'max:1024',
                'mimes:txt,md,markdown',
            ],
        ];
    }
}
