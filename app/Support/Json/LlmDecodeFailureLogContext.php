<?php

namespace App\Support\Json;

use Illuminate\Support\Str;

/**
 * Append a redacted-length raw LLM response preview to log context when enabled.
 *
 * @see config('working_memory.log_llm_decode_failure_preview')
 */
final class LlmDecodeFailureLogContext
{
    /**
     * @param  array<string, mixed>  $base
     * @return array<string, mixed>
     */
    public static function withOptionalRawPreview(array $base, string $raw): array
    {
        if (! config('working_memory.log_llm_decode_failure_preview', false)) {
            return $base;
        }

        $max = (int) config('working_memory.llm_decode_failure_preview_max_chars', 800);
        if ($max <= 0) {
            return $base;
        }

        $base['raw_preview'] = Str::limit($raw, $max, '…');

        return $base;
    }
}
