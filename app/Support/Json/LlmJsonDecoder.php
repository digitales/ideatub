<?php

namespace App\Support\Json;

/**
 * Decode an LLM response into an associative array, tolerating markdown code-fence
 * wrappers ("```json ... ```") that some models add even when explicitly told not to.
 *
 * Returns null on any failure (empty input, invalid JSON, non-array root). Callers are
 * expected to fall back to an empty/safe result when null is returned.
 */
class LlmJsonDecoder
{
    /**
     * @return array<string, mixed>|null
     */
    public static function decode(string $raw): ?array
    {
        $trimmed = trim($raw);
        if ($trimmed === '') {
            return null;
        }

        if (str_starts_with($trimmed, '```')) {
            $trimmed = preg_replace('/^```(?:json)?\s*/', '', $trimmed) ?? $trimmed;
            $trimmed = preg_replace('/```\s*$/', '', $trimmed) ?? $trimmed;
        }

        try {
            $decoded = json_decode($trimmed, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }
}
