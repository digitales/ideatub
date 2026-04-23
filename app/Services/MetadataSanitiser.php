<?php

namespace App\Services;

class MetadataSanitiser
{
    private const TAG_MAX_LEN = 64;

    private const TAG_MAX_COUNT = 20;

    private const PERSON_MAX_LEN = 96;

    private const PERSON_MAX_COUNT = 20;

    private const ACTION_MAX_LEN = 256;

    private const ACTION_MAX_COUNT = 20;

    // Case-insensitive substring match is deliberately aggressive: may drop a handful
    // of legitimate multi-segment tags (e.g. "priority:system:high") but guarantees no
    // injection phrase survives regardless of surrounding punctuation. See spec §5.6.3
    // — this sanitiser is the backstop; over-filtering 1–2 tags is the intended
    // trade-off vs. letting an injection payload through.
    /** @var list<string> */
    private const INJECTION_PHRASES = [
        'ignore',
        'previous',
        'instructions',
        'system:',
        'assistant:',
        '<system>',
        '```',
        'http://',
        'https://',
    ];

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    public function sanitise(array $metadata): array
    {
        $out = $metadata;

        if (isset($out['tags']) && is_array($out['tags'])) {
            $out['tags'] = $this->filterList(
                $out['tags'],
                self::TAG_MAX_LEN,
                self::TAG_MAX_COUNT,
                '/^[\p{L}\p{N} \-_:\']+$/u'
            );
        }

        if (isset($out['people']) && is_array($out['people'])) {
            $out['people'] = $this->filterList(
                $out['people'],
                self::PERSON_MAX_LEN,
                self::PERSON_MAX_COUNT,
                "/^[\p{L}\p{N} \-_.,'&]+$/u"
            );
        }

        if (isset($out['action_items']) && is_array($out['action_items'])) {
            $out['action_items'] = $this->filterList(
                $out['action_items'],
                self::ACTION_MAX_LEN,
                self::ACTION_MAX_COUNT,
                '//'
            );
        }

        return $out;
    }

    /**
     * Validate, injection-check, regex-check, dedupe, and cap a single metadata list in one pass.
     *
     * @param  array<int, mixed>  $items
     * @return list<string>
     */
    private function filterList(array $items, int $maxLen, int $maxCount, string $allowedRegex): array
    {
        $filtered = [];
        $seen = [];
        foreach ($items as $item) {
            if (! is_string($item)) {
                continue;
            }
            $item = trim($item);
            if ($item === '' || mb_strlen($item) > $maxLen) {
                continue;
            }
            if (isset($seen[$item])) {
                continue;
            }
            if ($this->containsInjectionPhrase($item)) {
                continue;
            }
            if ($allowedRegex !== '//' && preg_match($allowedRegex, $item) !== 1) {
                continue;
            }
            $seen[$item] = true;
            $filtered[] = $item;
            if (count($filtered) >= $maxCount) {
                break;
            }
        }

        return $filtered;
    }

    private function containsInjectionPhrase(string $value): bool
    {
        $haystack = mb_strtolower($value);
        foreach (self::INJECTION_PHRASES as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }
}
