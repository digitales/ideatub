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
        Schema::create('meeting_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('meeting_thought_id')->constrained('thoughts')->cascadeOnDelete();
            $table->foreignId('meeting_skill_id')->constrained('meeting_skills')->cascadeOnDelete();
            $table->foreignId('meeting_skill_version_id');
            $table->string('source')->default('web');
            $table->string('status');
            $table->string('workflow_type_snapshot');
            $table->json('context_options_snapshot')->nullable();
            $table->json('output_shape_snapshot')->nullable();
            $table->json('core_categories_snapshot')->nullable();
            $table->json('custom_categories_snapshot')->nullable();
            $table->string('intensity_snapshot');
            $table->unsignedInteger('current_stage')->default(0);
            $table->unsignedInteger('total_stages')->default(1);
            $table->json('usage_metadata')->nullable();
            $table->foreignUuid('final_meeting_thought_id')->nullable()->constrained('thoughts')->nullOnDelete();
            $table->text('error_summary')->nullable();
            $table->timestamps();

            $table->foreign(['meeting_skill_id', 'meeting_skill_version_id'])
                ->references(['meeting_skill_id', 'id'])
                ->on('meeting_skill_versions')
                ->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('meeting_runs');
    }
};
