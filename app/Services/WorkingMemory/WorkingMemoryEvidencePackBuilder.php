<?php

namespace App\Services\WorkingMemory;

use App\Models\Thought;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

final class WorkingMemoryEvidencePackBuilder
{
    private const MAX_SIGNALS = 60;

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
     *     }>
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

        $signals = $this->selectSignals($userScopedThoughts, $normalizedScopeType, $normalizedScopeKey)
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
        $internal = $this->internalThoughtReference($thought);
        if ($internal !== null) {
            return [$internal];
        }

        $fallback = $this->sourceFallbackReference($thought);

        return $fallback !== null ? [$fallback] : [];
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
