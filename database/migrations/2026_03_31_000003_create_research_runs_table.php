<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('research_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('idea_thought_id')->constrained('thoughts')->cascadeOnDelete();
            $table->foreignId('research_skill_id')->constrained('research_skills')->cascadeOnDelete();
            $table->foreignId('research_skill_version_id');
            $table->string('source')->default('web');
            $table->string('status');
            $table->string('workflow_type_snapshot');
            $table->json('context_options_snapshot')->nullable();
            $table->json('output_shape_snapshot')->nullable();
            $table->string('intensity_snapshot');
            $table->unsignedInteger('current_stage')->default(0);
            $table->unsignedInteger('total_stages')->default(1);
            $table->json('usage_metadata')->nullable();
            $table->foreignUuid('final_research_thought_id')->nullable()->constrained('thoughts')->nullOnDelete();
            $table->text('error_summary')->nullable();
            $table->timestamps();

            $table->foreign(['research_skill_id', 'research_skill_version_id'])
                ->references(['research_skill_id', 'id'])
                ->on('research_skill_versions')
                ->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('research_runs');
    }
};
