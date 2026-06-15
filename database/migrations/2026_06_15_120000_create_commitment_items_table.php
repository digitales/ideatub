<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commitment_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 50);
            $table->string('status', 20)->default('open');
            $table->string('title', 500);
            $table->text('body')->nullable();
            $table->foreignUuid('project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->string('scope_type', 32)->nullable();
            $table->string('scope_key', 191)->nullable();
            $table->foreignUuid('source_thought_id')->nullable()->constrained('thoughts')->nullOnDelete();
            $table->foreignUuid('source_version_id')->nullable()->constrained('working_memory_versions')->nullOnDelete();
            $table->string('external_key', 100)->nullable();
            $table->string('external_url', 500)->nullable();
            $table->string('owner_label', 120)->nullable();
            $table->timestamp('due_at')->nullable();
            $table->timestamp('snoozed_until')->nullable();
            $table->string('dedupe_key', 191);
            $table->json('source_data')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['user_id', 'type']);
            $table->index(['user_id', 'snoozed_until']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("CREATE UNIQUE INDEX commitment_items_user_dedupe_open_unique ON commitment_items (user_id, dedupe_key) WHERE status = 'open'");
        } else {
            Schema::table('commitment_items', function (Blueprint $table): void {
                $table->unique(['user_id', 'dedupe_key', 'status'], 'commitment_items_user_dedupe_status_unique');
            });
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS commitment_items_user_dedupe_open_unique');
        }

        Schema::dropIfExists('commitment_items');
    }
};
