<?php

namespace App\Services\WorkingMemory;

use App\Models\WorkingMemoryVersion;

readonly class WorkingMemoryUpsertResult
{
    public function __construct(
        public WorkingMemoryVersion $version,
        public bool $deduplicated,
        public string $contentFingerprint,
        public string $dedupeFamily,
        public ?string $supersededVersionId,
    ) {}
}
