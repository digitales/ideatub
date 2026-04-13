<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('project_thought', function (Blueprint $table) {
            $table->foreignUuid('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignUuid('thought_id')->constrained('thoughts')->cascadeOnDelete();
            $table->unsignedInteger('sort_order');
            $table->timestamps();
            $table->primary(['project_id', 'thought_id']);
            $table->unique(['project_id', 'sort_order']);
        });

        Schema::create('thought_links', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('from_thought_id')->constrained('thoughts')->cascadeOnDelete();
            $table->foreignUuid('to_thought_id')->constrained('thoughts')->cascadeOnDelete();
            $table->string('link_type', 32);
            $table->text('note')->nullable();
            $table->timestamps();
            $table->unique(['from_thought_id', 'to_thought_id', 'link_type'], 'thought_links_from_to_type_unique');
        });

        Schema::create('project_shares', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('project_id')->constrained('projects')->cascadeOnDelete();
            $table->string('token', 64)->unique();
            $table->string('password_hash')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_shares');
        Schema::dropIfExists('thought_links');
        Schema::dropIfExists('project_thought');
        Schema::dropIfExists('projects');
    }
};
