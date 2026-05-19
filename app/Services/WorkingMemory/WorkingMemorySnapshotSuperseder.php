<?php

namespace App\Services\WorkingMemory;

use App\Models\Thought;

class WorkingMemorySnapshotSuperseder
{
    public function supersede(Thought $prior, Thought $winner): void
    {
        $metadata = is_array($prior->source_metadata) ? $prior->source_metadata : [];
        $wm = is_array($metadata['working_memory'] ?? null) ? $metadata['working_memory'] : [];
        $wm['is_current'] = false;
        $wm['superseded_at'] = now()->toIso8601String();
        $wm['superseded_by_thought_id'] = (string) $winner->id;
        $metadata['working_memory'] = $wm;

        $thoughtMetadata = is_array($prior->metadata) ? $prior->metadata : [];
        $tags = collect($thoughtMetadata['tags'] ?? [])
            ->map(fn ($t) => (string) $t)
            ->push('working-memory:superseded')
            ->unique()
            ->values()
            ->all();
        $thoughtMetadata['tags'] = $tags;

        $prior->forceFill([
            'is_visible_in_stream' => false,
            'source_metadata' => $metadata,
            'metadata' => $thoughtMetadata,
        ])->save();
    }
}
