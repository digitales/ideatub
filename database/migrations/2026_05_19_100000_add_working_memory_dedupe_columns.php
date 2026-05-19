<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('thoughts', function (Blueprint $table): void {
            if (! Schema::hasColumn('thoughts', 'content_fingerprint')) {
                $table->char('content_fingerprint', 64)->nullable()->after('content_sha256');
                $table->index(['user_id', 'content_fingerprint'], 'thoughts_user_content_fingerprint_idx');
            }
        });

        Schema::table('working_memory_versions', function (Blueprint $table): void {
            if (! Schema::hasColumn('working_memory_versions', 'content_fingerprint')) {
                $table->char('content_fingerprint', 64)->nullable()->after('authoring_status');
                $table->index(
                    ['working_memory_id', 'content_fingerprint'],
                    'wm_versions_memory_fingerprint_idx'
                );
            }
            if (! Schema::hasColumn('working_memory_versions', 'superseded_at')) {
                $table->timestamp('superseded_at')->nullable();
                $table->uuid('superseded_by_version_id')->nullable();
                $table->foreign('superseded_by_version_id')
                    ->references('id')
                    ->on('working_memory_versions')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('working_memory_versions', function (Blueprint $table): void {
            if (Schema::hasColumn('working_memory_versions', 'superseded_by_version_id')) {
                $table->dropForeign(['superseded_by_version_id']);
            }
            foreach (['superseded_at', 'superseded_by_version_id', 'content_fingerprint'] as $col) {
                if (Schema::hasColumn('working_memory_versions', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
        Schema::table('thoughts', function (Blueprint $table): void {
            if (Schema::hasColumn('thoughts', 'content_fingerprint')) {
                $table->dropIndex('thoughts_user_content_fingerprint_idx');
                $table->dropColumn('content_fingerprint');
            }
        });
    }
};
