<?php

namespace App\Services\WorkingMemory\Compactions;

use App\Models\WorkingMemory;
use App\Models\WorkingMemoryVersion;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class CompactionVersionWriter
{
    /**
     * @param  array<string, array<int, array<string, mixed>>>  $structuredSections
     * @param  array<int, array{type: string, url: string, label: string}>  $references
     * @param  array<int, string>  $sourceThoughtIds
     */
    public function write(
        int $userId,
        string $scopeType,
        string $scopeKey,
        string $buildType,
        string $summaryMarkdown,
        array $structuredSections,
        array $references,
        array $sourceThoughtIds,
    ): WorkingMemoryVersion {
        if (! str_starts_with($buildType, 'compaction:')) {
            throw new InvalidArgumentException(
                "CompactionVersionWriter only accepts compaction:* build types, got: {$buildType}"
            );
        }

        return DB::transaction(function () use (
            $userId,
            $scopeType,
            $scopeKey,
            $buildType,
            $summaryMarkdown,
            $structuredSections,
            $references,
            $sourceThoughtIds,
        ): WorkingMemoryVersion {
            $memory = WorkingMemory::query()->firstOrCreate(
                [
                    'user_id' => $userId,
                    'scope_type' => $scopeType,
                    'scope_key' => $scopeKey,
                ],
                [
                    'freshness_state' => 'stale',
                ]
            );

            $version = $memory->versions()->create([
                'build_type' => $buildType,
                'summary_markdown' => $summaryMarkdown,
                'structured_sections_json' => $structuredSections,
                'references_json' => $references,
                'authoring_status' => 'validated',
                'confidence_score' => 0,
            ]);

            foreach (array_unique($sourceThoughtIds) as $thoughtId) {
                $version->inputs()->create([
                    'thought_id' => $thoughtId,
                    'source_version_id' => null,
                    'contribution_type' => 'compaction-source',
                    'weight' => 1.0,
                ]);
            }

            return $version;
        });
    }
}
