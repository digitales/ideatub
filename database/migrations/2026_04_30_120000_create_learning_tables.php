<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('learning_projects', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('slug', 128);
            $table->string('title');
            $table->string('content_root');
            $table->string('source_url', 2048)->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'slug']);
        });

        Schema::create('learning_research_documents', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('learning_project_id')->constrained('learning_projects')->cascadeOnDelete();
            $table->string('slug', 128);
            $table->string('title');
            $table->text('summary')->nullable();
            $table->string('category', 128)->nullable();
            $table->string('source_url', 2048)->nullable();
            $table->longText('body_markdown');
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['learning_project_id', 'slug']);
        });

        Schema::create('learning_lessons', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('learning_project_id')->constrained('learning_projects')->cascadeOnDelete();
            $table->string('slug', 128);
            $table->string('title');
            $table->string('stage', 128)->nullable();
            $table->string('difficulty', 64)->nullable();
            $table->unsignedInteger('order')->default(0);
            $table->text('summary')->nullable();
            $table->json('goals')->nullable();
            $table->json('related_research_slugs')->nullable();
            $table->longText('body_markdown');
            $table->unsignedInteger('content_version')->default(1);
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['learning_project_id', 'slug']);
            $table->index(['learning_project_id', 'order']);
        });

        Schema::create('learning_quizzes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('learning_lesson_id')->constrained('learning_lessons')->cascadeOnDelete();
            $table->string('title');
            $table->unsignedTinyInteger('passing_score')->default(70);
            $table->timestamps();

            $table->unique('learning_lesson_id');
        });

        Schema::create('learning_quiz_questions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('learning_quiz_id')->constrained('learning_quizzes')->cascadeOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->text('prompt');
            $table->json('options');
            $table->unsignedTinyInteger('correct_option_index');
            $table->text('explanation')->nullable();
            $table->timestamps();

            $table->index(['learning_quiz_id', 'sort_order']);
        });

        Schema::create('learning_lesson_progress', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('learning_lesson_id')->constrained('learning_lessons')->cascadeOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->string('bookmark_position', 512)->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'learning_lesson_id']);
        });

        Schema::create('learning_quiz_attempts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('learning_quiz_id')->constrained('learning_quizzes')->cascadeOnDelete();
            $table->unsignedTinyInteger('score')->default(0);
            $table->boolean('passed')->default(false);
            $table->timestamps();

            $table->index(['user_id', 'learning_quiz_id']);
        });

        Schema::create('learning_quiz_responses', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('learning_quiz_attempt_id')->constrained('learning_quiz_attempts')->cascadeOnDelete();
            $table->foreignUuid('learning_quiz_question_id')->constrained('learning_quiz_questions')->cascadeOnDelete();
            $table->unsignedTinyInteger('selected_option_index')->nullable();
            $table->boolean('correct')->default(false);
            $table->timestamps();

            $table->unique(['learning_quiz_attempt_id', 'learning_quiz_question_id']);
        });

        Schema::create('learning_lesson_notes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('learning_lesson_id')->constrained('learning_lessons')->cascadeOnDelete();
            $table->text('body');
            $table->timestamps();

            $table->index(['user_id', 'learning_lesson_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('learning_lesson_notes');
        Schema::dropIfExists('learning_quiz_responses');
        Schema::dropIfExists('learning_quiz_attempts');
        Schema::dropIfExists('learning_lesson_progress');
        Schema::dropIfExists('learning_quiz_questions');
        Schema::dropIfExists('learning_quizzes');
        Schema::dropIfExists('learning_lessons');
        Schema::dropIfExists('learning_research_documents');
        Schema::dropIfExists('learning_projects');
    }
};
