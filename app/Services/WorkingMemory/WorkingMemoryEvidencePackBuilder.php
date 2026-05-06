<?php

namespace App\Services\WorkingMemory;

use App\Models\Thought;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

final class WorkingMemoryEvidencePackBuilder
{
    private const MAX_SIGNALS = 60;

    /** @var list<string> */
    private const SECTION_NAMES = [
        'Current Focus',
        'Active Priorities',
        'Recent Changes',
        'Open Questions',
        'Risks / Blockers',
        'Next Actions',
        'Latest Signals',
    ];

    /**
     * @param  Collection<int, Thought>  $thoughts
     * @return array{
     *     scope_type: string,
     *     scope_key: string,
     *     generated_at: string,
     *     signals: array<int, array{
     *         thought_id: string|null,
     *         content: string,
     *         created_at: string|null,
     *         references: array<int, array{type: string, url: string, label: string}>
     *     }>,
     *     section_candidates: array<string, array<int, string>>,
     *     section_bundles: array<string, array<int, array{type: string, url: string, label: string}>>
     * }
     */
    public function build(int $userId, string $scopeType, string $scopeKey, Collection $thoughts): array
    {
        $normalizedScopeType = Str::of($scopeType)->trim()->lower()->toString();
        $normalizedScopeKey = Str::of($scopeKey)->trim()->lower()->toString();
        $userScopedThoughts = $thoughts
            ->filter(fn ($thought): bool => $thought instanceof Thought)
            ->filter(fn (Thought $thought): bool => $thought->user_id === $userId)
            ->values();

        $selectedThoughts = $this->selectSignals($userScopedThoughts, $normalizedScopeType, $normalizedScopeKey);

        $signals = $selectedThoughts
            ->map(function (Thought $thought): array {
                $thoughtId = Str::of((string) ($thought->id ?? ''))->trim()->toString();

                return [
                    'thought_id' => $thoughtId !== '' ? $thoughtId : null,
                    'content' => Str::of((string) $thought->content)->trim()->toString(),
                    'created_at' => $thought->created_at?->toIso8601String(),
                    'references' => $this->referencesForThought($thought),
                ];
            })
            ->all();

        return [
            'scope_type' => $normalizedScopeType,
            'scope_key' => $normalizedScopeKey,
            'generated_at' => now()->toIso8601String(),
            'signals' => $signals,
            'section_candidates' => $this->buildSectionCandidates($selectedThoughts),
            'section_bundles' => $this->buildSectionBundles($selectedThoughts),
        ];
    }

    /**
     * @param  Collection<int, Thought>  $thoughts
     * @return Collection<int, Thought>
     */
    private function selectSignals(Collection $thoughts, string $scopeType, string $scopeKey): Collection
    {
        $filtered = $thoughts
            ->filter(fn ($thought): bool => $thought instanceof Thought)
            ->values();

        if ($scopeType === 'tag') {
            $filtered = $filtered->filter(function (Thought $thought) use ($scopeKey): bool {
                $tags = collect(data_get($thought->metadata, 'tags', []))
                    ->map(fn ($tag): string => Str::of((string) $tag)->trim()->lower()->toString())
                    ->filter(fn (string $tag): bool => $tag !== '')
                    ->values();

                return $tags->containsStrict($scopeKey);
            })->values();
        }

        return $filtered
            ->sort(fn (Thought $a, Thought $b): int => $this->compareThoughts($a, $b))
            ->take(self::MAX_SIGNALS)
            ->values();
    }

    private function compareThoughts(Thought $a, Thought $b): int
    {
        $aTimestamp = $a->created_at?->getTimestamp() ?? 0;
        $bTimestamp = $b->created_at?->getTimestamp() ?? 0;

        if ($aTimestamp !== $bTimestamp) {
            return $bTimestamp <=> $aTimestamp;
        }

        $aId = Str::of((string) ($a->id ?? ''))->trim()->toString();
        $bId = Str::of((string) ($b->id ?? ''))->trim()->toString();
        if ($aId !== $bId) {
            if ($aId === '') {
                return 1;
            }
            if ($bId === '') {
                return -1;
            }

            return strcmp($aId, $bId);
        }

        return strcmp(
            Str::of((string) $a->content)->trim()->toString(),
            Str::of((string) $b->content)->trim()->toString()
        );
    }

    /**
     * @return array<int, array{type: string, url: string, label: string}>
     */
    private function referencesForThought(Thought $thought): array
    {
        $references = [];
        $internal = $this->internalThoughtReference($thought);
        if ($internal !== null) {
            $references[] = $internal;
        }

        $fallback = $this->sourceFallbackReference($thought);
        if ($fallback !== null) {
            $references[] = $fallback;
        }

        return $references;
    }

    /**
     * @param  Collection<int, Thought>  $thoughts
     * @return array<string, array<int, string>>
     */
    private function buildSectionCandidates(Collection $thoughts): array
    {
        $lines = $thoughts
            ->map(function (Thought $thought): string {
                return Str::of((string) $thought->content)->trim()->toString();
            })
            ->filter(fn (string $line): bool => $line !== '')
            ->flatMap(function (string $content): array {
                $chunks = preg_split('/\r\n|\r|\n/', $content) ?: [];

                return collect($chunks)
                    ->map(fn (string $chunk): string => Str::of($chunk)->trim()->toString())
                    ->filter(fn (string $chunk): bool => $chunk !== '')
                    ->take(3)
                    ->values()
                    ->all();
            })
            ->unique(fn (string $line): string => Str::lower($line))
            ->take(12)
            ->values()
            ->all();

        if ($lines === []) {
            $lines = ['No stream-visible captures yet for this scope.'];
        }

        $candidates = [];
        foreach (self::SECTION_NAMES as $sectionName) {
            $candidates[$sectionName] = $lines;
        }

        return $candidates;
    }

    /**
     * Per-section deduped source refs (by URL) for citation fallback.
     *
     * @param  Collection<int, Thought>  $thoughts
     * @return array<string, array<int, array{type: string, url: string, label: string}>>
     */
    private function buildSectionBundles(Collection $thoughts): array
    {
        /** @var array<string, array<string, array{type: string, url: string, label: string}>> */
        $bundleRefsByUrl = [];
        foreach (self::SECTION_NAMES as $name) {
            $bundleRefsByUrl[$name] = [];
        }

        foreach ($thoughts as $thought) {
            $sourceRef = $this->sourceFallbackReference($thought);
            if ($sourceRef === null) {
                continue;
            }

            $sectionTitle = Str::of((string) data_get($thought->source_metadata ?? [], 'section_title', ''))->trim()->toString();
            $targetSection = $this->resolveSectionForSourceMetadata($sectionTitle);
            if ($targetSection === null) {
                continue;
            }

            $url = $sourceRef['url'];
            if (! isset($bundleRefsByUrl[$targetSection][$url])) {
                $bundleRefsByUrl[$targetSection][$url] = $sourceRef;
            }
        }

        $out = [];
        foreach (self::SECTION_NAMES as $name) {
            $out[$name] = array_values($bundleRefsByUrl[$name]);
        }

        return $out;
    }

    private function resolveSectionForSourceMetadata(string $sectionTitle): ?string
    {
        if ($sectionTitle === '') {
            return null;
        }

        $needle = Str::lower($sectionTitle);
        foreach (self::SECTION_NAMES as $canonical) {
            if (Str::lower($canonical) === $needle) {
                return $canonical;
            }
        }

        return null;
    }

    /**
     * @return array{type: string, url: string, label: string}|null
     */
    private function internalThoughtReference(Thought $thought): ?array
    {
        $thoughtId = Str::of((string) ($thought->id ?? ''))->trim()->toString();
        if ($thoughtId === '') {
            return null;
        }

        return [
            'type' => 'thought',
            'url' => $thought->ideaTubViewUrl(),
            'label' => $thoughtId,
        ];
    }

    /**
     * @return array{type: string, url: string, label: string}|null
     */
    private function sourceFallbackReference(Thought $thought): ?array
    {
        $sourceMetadata = is_array($thought->source_metadata) ? $thought->source_metadata : [];
        $filePath = Str::of((string) data_get($sourceMetadata, 'file_path', ''))->trim()->toString();
        if ($filePath !== '') {
            $label = basename($filePath);
            $resolvedLabel = $label !== '' ? $label : $filePath;

            return [
                'type' => 'source',
                'url' => $filePath,
                'label' => $resolvedLabel,
            ];
        }

        foreach (['source_doc_url', 'source_url', 'url'] as $urlKey) {
            $url = Str::of((string) data_get($sourceMetadata, $urlKey, ''))->trim()->toString();
            if ($url === '') {
                continue;
            }

            $label = Str::of((string) data_get($sourceMetadata, 'section_title', ''))->trim()->toString();
            if ($label === '') {
                $label = Str::of((string) data_get($sourceMetadata, 'doc_type', ''))->trim()->toString();
            }
            if ($label === '') {
                $label = $url;
            }

            return [
                'type' => 'source',
                'url' => $url,
                'label' => $label,
            ];
        }

        return null;
    }
}
