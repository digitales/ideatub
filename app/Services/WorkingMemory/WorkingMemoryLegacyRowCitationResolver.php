<?php

namespace App\Services\WorkingMemory;

use Illuminate\Support\Str;

/**
 * Picks a primary thought link from composer bullet citations for legacy JSON rows.
 *
 * @see docs/superpowers/specs/2026-05-08-working-memory-thread-links-design.md
 */
final class WorkingMemoryLegacyRowCitationResolver
{
    /**
     * @param  array<int, mixed>  $citations
     * @return array{thought_id: string, url?: string}|null
     */
    public function resolvePrimaryThought(array $citations): ?array
    {
        $normalized = $this->normalizeCitations($citations);
        if ($normalized === []) {
            return null;
        }

        foreach ($normalized as $citation) {
            $type = $citation['type'];
            if ($type === 'thought') {
                $id = $this->thoughtIdFromCitation($citation);
                if ($id !== null) {
                    return $this->rowWithUrl($id, $citation['url']);
                }
            }
        }

        foreach ($normalized as $citation) {
            $id = $this->thoughtIdFromUrlOnly($citation['url']);
            if ($id !== null) {
                return $this->rowWithUrl($id, $citation['url']);
            }
        }

        return null;
    }

    /**
     * @return list<array{type: string, url: string, thought_id: string|null}>
     */
    private function normalizeCitations(array $citations): array
    {
        $out = [];
        foreach ($citations as $citation) {
            if (! is_array($citation)) {
                continue;
            }
            $url = trim((string) ($citation['url'] ?? ''));
            $type = trim((string) ($citation['type'] ?? ''));
            if ($type === '') {
                $type = 'source';
            }
            $thoughtId = null;
            if (array_key_exists('thought_id', $citation) && $citation['thought_id'] !== null && $citation['thought_id'] !== '') {
                $tid = is_string($citation['thought_id']) ? trim($citation['thought_id']) : (string) $citation['thought_id'];
                if ($tid !== '' && Str::isUuid($tid)) {
                    $thoughtId = $tid;
                }
            }
            if ($url === '' && $thoughtId === null) {
                continue;
            }
            if ($url !== '' && ! $this->isSafeCitationUrl($url)) {
                continue;
            }
            $out[] = ['type' => $type, 'url' => $url, 'thought_id' => $thoughtId];
        }

        return $out;
    }

    /**
     * @param  array{type: string, url: string, thought_id: string|null}  $citation
     */
    private function thoughtIdFromCitation(array $citation): ?string
    {
        if ($citation['thought_id'] !== null) {
            return $citation['thought_id'];
        }

        return $this->thoughtIdFromUrlOnly($citation['url']);
    }

    private function thoughtIdFromUrlOnly(string $url): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }

        $path = parse_url($url, PHP_URL_PATH);
        if (! is_string($path) || $path === '') {
            return null;
        }

        if (str_contains($path, '..')) {
            return null;
        }

        $segments = array_values(array_filter(explode('/', trim($path, '/'))));
        if (count($segments) < 2 || $segments[0] !== 'thoughts') {
            return null;
        }

        $candidate = $segments[1];
        if (! Str::isUuid($candidate)) {
            return null;
        }

        return $candidate;
    }

    /**
     * @return array{thought_id: string, url?: string}
     */
    private function rowWithUrl(string $thoughtId, string $url): array
    {
        $url = trim($url);
        $row = ['thought_id' => $thoughtId];
        if ($url !== '') {
            $row['url'] = $url;
        }

        return $row;
    }

    private function isSafeCitationUrl(string $url): bool
    {
        $url = trim($url);
        if ($url === '') {
            return false;
        }

        if (str_starts_with(strtolower($url), 'javascript:')) {
            return false;
        }

        $parts = parse_url($url);
        if ($parts === false) {
            return false;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if ($scheme !== '') {
            if (! in_array($scheme, ['http', 'https'], true)) {
                return false;
            }

            return trim((string) ($parts['host'] ?? '')) !== '';
        }

        if (str_starts_with($url, '//')) {
            return false;
        }

        $path = (string) ($parts['path'] ?? '');
        foreach (explode('/', $path) as $segment) {
            if ($segment === '..') {
                return false;
            }
        }

        return str_starts_with($url, '/');
    }
}
