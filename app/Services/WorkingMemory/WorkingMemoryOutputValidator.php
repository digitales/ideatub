<?php

namespace App\Services\WorkingMemory;

final class WorkingMemoryOutputValidator
{
    /**
     * @var array<int, string>
     */
    public const REQUIRED_SECTION_KEYS = [
        'Current Focus',
        'Active Priorities',
        'Recent Changes',
        'Open Questions',
        'Risks / Blockers',
        'Next Actions',
        'Latest Signals',
        'Source Notes',
    ];

    /**
     * Coverage applies to high-signal sections only.
     *
     * @var array<int, string>
     */
    private const COVERAGE_SECTION_KEYS = [
        'Active Priorities',
        'Risks / Blockers',
        'Next Actions',
        'Latest Signals',
    ];

    /**
     * @return array{
     *     ok: bool,
     *     message: string|null,
     *     coveragePercent: float|null,
     *     failure_type: 'hard'|'soft'|null
     * }
     */
    public function validate(array $payload, ?float $minimumCoverage = null): array
    {
        $sections = $payload['structured_sections'] ?? null;
        if (! is_array($sections)) {
            return $this->hardFail('Missing structured_sections payload.');
        }

        $missingSections = [];
        foreach (self::REQUIRED_SECTION_KEYS as $requiredSection) {
            if (! array_key_exists($requiredSection, $sections) || ! $this->hasContent($sections[$requiredSection])) {
                $missingSections[] = $requiredSection;
            }
        }

        if ($missingSections !== []) {
            return $this->hardFail('Missing required sections: '.implode(', ', $missingSections));
        }

        $references = $payload['references'] ?? null;
        if (! is_array($references)) {
            return $this->hardFail('References must be an array.');
        }

        $normalizedReferences = [];
        foreach ($references as $reference) {
            if (! is_array($reference)) {
                return $this->hardFail('Every reference must be an object-like array.');
            }

            $url = trim((string) ($reference['url'] ?? ''));
            $label = trim((string) ($reference['label'] ?? ''));

            if ($url === '' || $label === '') {
                return $this->hardFail('References must include non-empty url and label.');
            }

            $normalizedReferences[] = [
                'url' => $url,
                'label' => $label,
            ];
        }

        $majorBullets = $this->extractMajorBullets($sections);
        [$citedBulletCount, $unresolvedCount] = $this->citationStats($majorBullets, count($normalizedReferences));

        if ($unresolvedCount > 0) {
            return $this->hardFail('Unresolvable references detected in major bullets.');
        }

        $coveragePercent = $this->coveragePercent($majorBullets, $citedBulletCount);
        $effectiveMinimumCoverage = $minimumCoverage ?? (float) config('working_memory.citation_min_coverage', 0.90);
        $coverageRatio = $coveragePercent / 100;

        if ($coverageRatio < $effectiveMinimumCoverage) {
            return [
                'ok' => false,
                'message' => sprintf(
                    'Citation coverage %.2f%% is below minimum %.2f%%.',
                    $coveragePercent,
                    $effectiveMinimumCoverage * 100
                ),
                'coveragePercent' => $coveragePercent,
                'failure_type' => 'soft',
            ];
        }

        return [
            'ok' => true,
            'message' => null,
            'coveragePercent' => $coveragePercent,
            'failure_type' => null,
        ];
    }

    private function hasContent(mixed $value): bool
    {
        if (is_string($value)) {
            return trim($value) !== '';
        }

        if (! is_array($value)) {
            return false;
        }

        foreach ($value as $entry) {
            if (is_string($entry) && trim($entry) !== '') {
                return true;
            }
            if (is_array($entry)) {
                foreach (['text', 'content', 'bullet', 'summary'] as $candidateKey) {
                    if (isset($entry[$candidateKey]) && is_string($entry[$candidateKey]) && trim($entry[$candidateKey]) !== '') {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $sections
     * @return array<int, string>
     */
    private function extractMajorBullets(array $sections): array
    {
        $majorBullets = [];

        foreach (self::COVERAGE_SECTION_KEYS as $requiredSection) {
            $section = $sections[$requiredSection] ?? [];

            if (is_string($section)) {
                $lines = preg_split('/\r\n|\r|\n/', $section) ?: [];
                foreach ($lines as $line) {
                    $trimmedLine = trim($line);
                    if ($trimmedLine === '') {
                        continue;
                    }
                    if (str_starts_with($trimmedLine, '- ')) {
                        $majorBullets[] = trim(substr($trimmedLine, 2));
                    }
                }

                continue;
            }

            if (! is_array($section)) {
                continue;
            }

            foreach ($section as $entry) {
                if (is_string($entry) && trim($entry) !== '') {
                    $majorBullets[] = trim($entry);

                    continue;
                }

                if (! is_array($entry)) {
                    continue;
                }

                foreach (['text', 'content', 'bullet', 'summary'] as $candidateKey) {
                    if (isset($entry[$candidateKey]) && is_string($entry[$candidateKey]) && trim($entry[$candidateKey]) !== '') {
                        $majorBullets[] = trim($entry[$candidateKey]);
                        break;
                    }
                }
            }
        }

        return $majorBullets;
    }

    /**
     * @param  array<int, string>  $majorBullets
     * @return array{int, int}
     */
    private function citationStats(array $majorBullets, int $referenceCount): array
    {
        $citedBulletCount = 0;
        $unresolvedReferenceCount = 0;

        foreach ($majorBullets as $bullet) {
            if (! preg_match_all('/\[(\d+)\]/', $bullet, $matches)) {
                continue;
            }

            $citedBulletCount++;

            foreach ($matches[1] as $rawIndex) {
                $index = (int) $rawIndex;
                if ($index <= 0 || $index > $referenceCount) {
                    $unresolvedReferenceCount++;
                }
            }
        }

        return [$citedBulletCount, $unresolvedReferenceCount];
    }

    /**
     * @param  array<int, string>  $majorBullets
     */
    private function coveragePercent(array $majorBullets, int $citedBulletCount): float
    {
        $totalBullets = count($majorBullets);
        if ($totalBullets === 0) {
            return 100.0;
        }

        return round(($citedBulletCount / $totalBullets) * 100, 2);
    }

    /**
     * @return array{
     *     ok: false,
     *     message: string,
     *     coveragePercent: null,
     *     failure_type: 'hard'
     * }
     */
    private function hardFail(string $message): array
    {
        return [
            'ok' => false,
            'message' => $message,
            'coveragePercent' => null,
            'failure_type' => 'hard',
        ];
    }
}
