<?php

namespace App\Services\WorkingMemory;

use App\Models\WorkingMemory;
use App\Models\WorkingMemoryVersion;
use Illuminate\Pagination\LengthAwarePaginator;

class WorkingMemoryVersionCatalog
{
    public function __construct(
        private readonly WorkingMemoryScopeNormalizer $scopeNormalizer,
    ) {}

    public function listForScope(
        int $userId,
        string $scopeType,
        string $scopeKey,
        bool $includeCompactions = false,
        int $perPage = 20,
    ): LengthAwarePaginator {
        [$normalizedType, $normalizedKey] = $this->scopeNormalizer->normalize($scopeType, $scopeKey);

        $memory = WorkingMemory::query()
            ->where('user_id', $userId)
            ->where('scope_type', $normalizedType)
            ->where('scope_key', $normalizedKey)
            ->first();

        if ($memory === null) {
            return new LengthAwarePaginator([], 0, $perPage);
        }

        return $memory->versions()
            ->where(function ($query) use ($includeCompactions): void {
                $query->whereIn('build_type', ['external', 'consolidated']);
                if ($includeCompactions) {
                    $query->orWhere('build_type', 'like', 'compaction:%');
                }
            })
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }

    public function showForUser(int $userId, string $versionId): WorkingMemoryVersion
    {
        return WorkingMemoryVersion::query()
            ->where('id', $versionId)
            ->whereHas('workingMemory', fn ($query) => $query->where('user_id', $userId))
            ->firstOrFail();
    }

    /**
     * @return array{
     *     id: string,
     *     created_at: string|null,
     *     build_type: string,
     *     authoring_status: string|null,
     *     confidence_score: float,
     *     source_label: string|null,
     *     citation_coverage: float|null
     * }
     */
    public function toListItem(WorkingMemoryVersion $version): array
    {
        return [
            'id' => (string) $version->id,
            'created_at' => $version->created_at?->toIso8601String(),
            'build_type' => (string) $version->build_type,
            'authoring_status' => $version->authoring_status,
            'confidence_score' => (float) $version->confidence_score,
            'source_label' => $this->resolveSourceLabel($version),
            'citation_coverage' => $version->citation_coverage !== null
                ? (float) $version->citation_coverage
                : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toDetailPayload(WorkingMemoryVersion $version): array
    {
        return array_merge($this->toListItem($version), [
            'summary_markdown' => $version->summary_markdown,
            'structured_sections' => $version->structured_sections_json ?? [],
            'section_references' => $version->section_references_json ?? [],
            'references' => $version->references_json ?? [],
            'key_concepts' => $version->key_concepts_json ?? [],
            'active_threads' => $version->active_threads_json ?? [],
            'open_questions' => $version->open_questions_json ?? [],
            'next_actions' => $version->next_actions_json ?? [],
            'validation_error' => $version->validation_error,
            'build_diagnostics' => $version->build_diagnostics_json ?? null,
            'source_window_start' => $version->source_window_start?->toIso8601String(),
            'source_window_end' => $version->source_window_end?->toIso8601String(),
        ]);
    }

    private function resolveSourceLabel(WorkingMemoryVersion $version): ?string
    {
        if (! is_array($version->build_diagnostics_json)) {
            return null;
        }

        $label = $version->build_diagnostics_json['source_label'] ?? null;

        return is_string($label) ? $label : null;
    }
}
