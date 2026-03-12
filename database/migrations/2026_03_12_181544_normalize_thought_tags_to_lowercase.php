<?php

use App\Models\Thought;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Normalize all existing thoughts' metadata tags to lowercase.
     */
    public function up(): void
    {
        Thought::query()->chunk(100, function ($thoughts): void {
            foreach ($thoughts as $thought) {
                $metadata = $thought->metadata ?? [];
                if (! isset($metadata['tags']) || ! is_array($metadata['tags'])) {
                    continue;
                }
                $normalized = Thought::normalizeMetadataTags($metadata);
                if ($normalized['tags'] !== $metadata['tags']) {
                    $thought->update(['metadata' => $normalized]);
                }
            }
        });
    }

    /**
     * Reverse the migrations.
     * Cannot restore original casing.
     */
    public function down(): void
    {
        // No-op: original tag casing cannot be restored
    }
};
