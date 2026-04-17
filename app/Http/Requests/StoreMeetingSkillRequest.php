<?php

namespace App\Http\Requests;

use App\Services\Meetings\MeetingSkillManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMeetingSkillRequest extends FormRequest
{
    /**
     * @var array<int, string>
     */
    private const OUTPUT_SECTIONS = MeetingSkillManager::DEFAULT_OUTPUT_SECTIONS;

    /**
     * @var array<int, string>
     */
    private const CORE_CATEGORIES = MeetingSkillManager::DEFAULT_CORE_CATEGORIES;

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
            'workflow_type' => ['required', 'string', Rule::in([MeetingSkillManager::WORKFLOW_MEETING_BRIEF])],
            'instructions' => ['nullable', 'string', 'max:100000'],
            'output_sections' => ['nullable', 'array'],
            'output_sections.*' => ['string', Rule::in(self::OUTPUT_SECTIONS)],
            'output_shape' => ['nullable', 'array'],
            'core_categories' => ['nullable', 'array'],
            'core_categories.*' => ['string', Rule::in(self::CORE_CATEGORIES)],
            'custom_categories' => ['nullable', 'array'],
            'custom_categories.*' => ['string', 'max:200'],
            'intensity' => ['required', 'string', Rule::in(['concise', 'standard', 'thorough'])],
            'is_manual_enabled' => ['sometimes', 'boolean'],
            'allow_auto_run' => ['sometimes', 'boolean'],
            'is_default' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $outputSections = $this->normalizeSelections($this->input('output_sections'));
        $coreCategories = $this->normalizeSelections($this->input('core_categories'));
        $customCategories = $this->normalizeCustomCategories($this->input('custom_categories_text'));

        $this->merge([
            'output_sections' => $outputSections === [] ? null : $outputSections,
            'output_shape' => $outputSections === [] ? null : ['sections' => $outputSections],
            'core_categories' => $coreCategories === [] ? null : $coreCategories,
            'custom_categories' => $customCategories,
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

    /**
     * @return array<int, string>
     */
    private function normalizeCustomCategories(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        if (is_array($value)) {
            return $this->normalizeSelections($value);
        }

        $lines = preg_split("/\r\n|\n|\r/", (string) $value) ?: [];
        $out = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line !== '') {
                $out[] = $line;
            }
        }

        return array_values(array_unique($out));
    }
}
