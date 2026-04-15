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
        Schema::create('meeting_skill_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meeting_skill_id')->constrained('meeting_skills')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('workflow_type');
            $table->text('instructions')->default('');
            $table->json('context_options')->nullable();
            $table->json('output_shape')->nullable();
            $table->json('core_categories')->nullable();
            $table->json('custom_categories')->nullable();
            $table->string('intensity');
            $table->boolean('is_auto_run_eligible')->default(false);
            $table->timestamps();

            $table->unique(['meeting_skill_id', 'version']);
            $table->unique(['meeting_skill_id', 'id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('meeting_skill_versions');
    }
};
