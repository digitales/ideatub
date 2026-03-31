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
            $table->foreignId('research_skill_version_id')->constrained('research_skill_versions')->cascadeOnDelete();
            $table->string('source');
            $table->string('status');
            $table->string('workflow_type_snapshot');
            $table->json('context_options_snapshot');
            $table->json('output_shape_snapshot')->nullable();
            $table->string('intensity_snapshot')->nullable();
            $table->unsignedInteger('current_stage')->default(0);
            $table->unsignedInteger('total_stages')->default(0);
            $table->json('usage_metadata')->nullable();
            $table->foreignUuid('final_research_thought_id')->nullable()->constrained('thoughts')->nullOnDelete();
            $table->text('error_summary')->nullable();
            $table->timestamps();
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
