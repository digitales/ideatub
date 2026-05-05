<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('working_memories', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('scope_type', 32);
            $table->string('scope_key', 191);
            $table->foreignUuid('latest_version_id')->nullable();
            $table->string('freshness_state', 32)->default('stale');
            $table->timestamp('last_refreshed_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'scope_type', 'scope_key'], 'working_memories_scope_unique');
            $table->index(['user_id', 'scope_type']);
        });

        Schema::create('working_memory_versions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('working_memory_id')->constrained('working_memories')->cascadeOnDelete();
            $table->string('build_type', 32);
            $table->longText('summary_markdown');
            $table->json('key_concepts_json')->nullable();
            $table->json('active_threads_json')->nullable();
            $table->json('open_questions_json')->nullable();
            $table->json('next_actions_json')->nullable();
            $table->decimal('confidence_score', 5, 2)->default(0);
            $table->timestamp('source_window_start')->nullable();
            $table->timestamp('source_window_end')->nullable();
            $table->timestamps();

            $table->index(['working_memory_id', 'build_type']);
            $table->index(['working_memory_id', 'created_at']);
        });

        Schema::create('working_memory_inputs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('working_memory_version_id')->constrained('working_memory_versions')->cascadeOnDelete();
            $table->foreignUuid('thought_id')->constrained('thoughts')->cascadeOnDelete();
            $table->string('contribution_type', 32);
            $table->decimal('weight', 5, 2)->default(0);
            $table->timestamps();

            $table->unique(
                ['working_memory_version_id', 'thought_id'],
                'working_memory_inputs_version_thought_unique'
            );
            $table->index(['working_memory_version_id', 'contribution_type'], 'working_memory_inputs_version_type_idx');
            $table->index('thought_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('working_memory_inputs');
        Schema::dropIfExists('working_memory_versions');
        Schema::dropIfExists('working_memories');
    }
};
