<?php

namespace App\Http\Requests;

use App\Models\ResearchSkill;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UpdateResearchSkillRequest extends FormRequest
{
    public function authorize(): bool
    {
        $skill = $this->route('researchSkill');

        return $skill instanceof ResearchSkill
            && $this->user() !== null
            && (int) $skill->user_id === (int) $this->user()->id;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'workflow_type' => ['required', 'string', Rule::in(['quick_brief'])],
            'instructions' => ['nullable', 'string', 'max:100000'],
            'context_options' => ['nullable', 'array'],
            'output_shape' => ['nullable', 'array'],
            'intensity' => ['required', 'string', Rule::in(['concise', 'standard', 'thorough'])],
            'is_manual_enabled' => ['sometimes', 'boolean'],
            'allow_auto_run' => ['sometimes', 'boolean'],
            'is_default' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->mergeJsonTextareaIntoArrayField('context_options');
        $this->mergeJsonTextareaIntoArrayField('output_shape');
    }

    private function mergeJsonTextareaIntoArrayField(string $field): void
    {
        $jsonKey = $field.'_json';
        if (! $this->has($jsonKey)) {
            return;
        }

        $raw = trim((string) $this->input($jsonKey, ''));
        $this->request->remove($jsonKey);

        if ($raw === '') {
            $this->merge([$field => null]);

            return;
        }

        $decoded = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE || ! is_array($decoded)) {
            throw ValidationException::withMessages([
                $jsonKey => ['Must be valid JSON object or array.'],
            ]);
        }

        $this->merge([$field => $decoded]);
    }
}
