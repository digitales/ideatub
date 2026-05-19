<?php

namespace App\Services\WorkingMemory;

use InvalidArgumentException;

class WorkingMemoryContentFingerprint
{
    public function hash(string $markdown, bool $strict = false): string
    {
        $normalized = $this->normalize($markdown, $strict);
        if ($normalized === '') {
            throw new InvalidArgumentException('Working memory content is empty after normalization.');
        }

        return hash('sha256', $normalized);
    }

    public function normalize(string $markdown, bool $strict = false): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $markdown);

        if (! $strict) {
            $patterns = config('working_memory.dedupe_volatile_patterns', []);
            $lines = [];
            foreach (explode("\n", $text) as $line) {
                $trimmed = trim($line);
                $skip = false;
                foreach ($patterns as $pattern) {
                    if (is_string($pattern) && $pattern !== '' && @preg_match($pattern, $trimmed) === 1) {
                        $skip = true;
                        break;
                    }
                }
                if (! $skip) {
                    $lines[] = $line;
                }
            }
            $text = implode("\n", $lines);
        }

        $text = preg_replace('/^#+\s*/m', '', $text) ?? $text;
        $text = preg_replace('/[*_`]/', '', $text) ?? $text;
        $text = mb_strtolower($text);
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }
}
