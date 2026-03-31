<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreResearchSkillRequest extends FormRequest
{
    /**
     * @var array<int, string>
     */
    private const CONTEXT_OPTIONS = [
        'idea',
        'tags',
        'related_thoughts',
        'existing_research',
    ];

    /**
     * @var array<int, string>
     */
    private const OUTPUT_SECTIONS = [
        'summary',
        'evidence',
        'risks',
        'next_steps',
    ];

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
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'workflow_type' => ['required', 'string', Rule::in(['quick_brief'])],
            'instructions' => ['nullable', 'string', 'max:100000'],
            'context_options' => ['nullable', 'array'],
            'context_options.*' => ['string', Rule::in(self::CONTEXT_OPTIONS)],
            'output_sections' => ['nullable', 'array'],
            'output_sections.*' => ['string', Rule::in(self::OUTPUT_SECTIONS)],
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
        $contextOptions = $this->normalizeSelections($this->input('context_options'));
        $outputSections = $this->normalizeSelections($this->input('output_sections'));

        $this->merge([
            'context_options' => $contextOptions === [] ? null : $contextOptions,
            'output_sections' => $outputSections === [] ? null : $outputSections,
            'output_shape' => $outputSections === [] ? null : ['sections' => $outputSections],
        ]);
    }

    /**
     * @return array<int, string>
     */
    private function normalizeSelections(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        $values = is_array($value) ? $value : [$value];
        $normalized = [];

        foreach ($values as $candidate) {
            $candidate = trim((string) $candidate);

            if ($candidate === '') {
                continue;
            }

            $normalized[] = $candidate;
        }

        return array_values(array_unique($normalized));
    }
}
