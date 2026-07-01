<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('thought_suggested_links', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('from_thought_id')->constrained('thoughts')->cascadeOnDelete();
            $table->foreignUuid('to_thought_id')->constrained('thoughts')->cascadeOnDelete();
            $table->float('distance');
            $table->timestamp('dismissed_at')->nullable();
            $table->timestamp('promoted_at')->nullable();
            $table->timestamp('computed_at');
            $table->timestamps();

            $table->unique(['from_thought_id', 'to_thought_id']);
            $table->index(['user_id', 'from_thought_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('thought_suggested_links');
    }
};
