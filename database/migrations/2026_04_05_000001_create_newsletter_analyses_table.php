<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('newsletter_analyses', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('research_thought_id')->unique()->constrained('thoughts')->cascadeOnDelete();
            $table->foreignUuid('source_thought_id')->constrained('thoughts')->cascadeOnDelete();
            $table->string('stored_email_type', 64)->nullable();
            $table->unsignedBigInteger('stored_email_id')->nullable();
            $table->string('status', 32)->default('queued');
            $table->text('summary')->nullable();
            $table->json('key_points')->nullable();
            $table->json('positives_mentioned')->nullable();
            $table->json('negatives_mentioned')->nullable();
            $table->json('highlights')->nullable();
            $table->text('quality_notes')->nullable();
            $table->string('failure_reason', 255)->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('newsletter_analyses');
    }
};
