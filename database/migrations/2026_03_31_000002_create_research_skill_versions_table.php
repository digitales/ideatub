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
        Schema::create('research_skill_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('research_skill_id')->constrained('research_skills')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('workflow_type');
            $table->text('instructions');
            $table->json('context_options');
            $table->json('output_shape')->nullable();
            $table->string('intensity')->nullable();
            $table->boolean('is_auto_run_eligible')->default(false);
            $table->timestamps();

            $table->unique(['research_skill_id', 'version']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('research_skill_versions');
    }
};
