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
            $candidate = self::extractFirstJsonObject($trimmed);
            if ($candidate === null) {
                return null;
            }

            try {
                $decoded = json_decode($candidate, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                return null;
            }
        }

        return is_array($decoded) ? $decoded : null;
    }

    private static function extractFirstJsonObject(string $input): ?string
    {
        $length = strlen($input);
        $start = null;
        $depth = 0;
        $inString = false;
        $escaped = false;

        for ($i = 0; $i < $length; $i++) {
            $char = $input[$i];

            if ($inString) {
                if ($escaped) {
                    $escaped = false;
                    continue;
                }

                if ($char === '\\') {
                    $escaped = true;
                    continue;
                }

                if ($char === '"') {
                    $inString = false;
                }

                continue;
            }

            if ($char === '"') {
                $inString = true;
                continue;
            }

            if ($char === '{') {
                if ($depth === 0) {
                    $start = $i;
                }
                $depth++;
                continue;
            }

            if ($char === '}') {
                if ($depth === 0) {
                    continue;
                }

                $depth--;
                if ($depth === 0 && $start !== null) {
                    return substr($input, $start, $i - $start + 1);
                }
            }
        }

        return null;
    }
}
