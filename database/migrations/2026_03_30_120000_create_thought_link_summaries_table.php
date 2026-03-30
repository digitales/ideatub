<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('thought_link_summaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('source_thought_id')->constrained('thoughts')->cascadeOnDelete();
            $table->foreignUuid('parent_research_thought_id')->nullable()->constrained('thoughts')->nullOnDelete();
            $table->string('source_type', 64);
            $table->string('stored_email_type', 64)->nullable();
            $table->unsignedBigInteger('stored_email_id')->nullable();
            $table->text('original_url');
            $table->text('normalized_url');
            $table->string('normalized_url_hash', 64);
            $table->string('newsletter_section_label', 255)->nullable();
            $table->unsignedInteger('newsletter_section_order')->nullable();
            $table->text('source_excerpt')->nullable();
            $table->string('classification', 32);
            $table->string('processing_status', 32);
            $table->unsignedSmallInteger('fetch_status_code')->nullable();
            $table->string('resolved_title', 1024)->nullable();
            $table->text('summary_text')->nullable();
            $table->string('support_judgment', 32)->nullable();
            $table->text('why_it_matters')->nullable();
            $table->text('quality_notes')->nullable();
            $table->integer('usefulness_score')->nullable();
            $table->unsignedInteger('section_rank')->nullable();
            $table->string('failure_stage', 32)->nullable();
            $table->string('failure_reason', 255)->nullable();
            $table->string('content_fingerprint', 64)->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['parent_research_thought_id', 'newsletter_section_order']);
            $table->index(['processing_status', 'classification']);
        });

        DB::statement(
            'CREATE UNIQUE INDEX thought_link_summaries_unique_source_url
            ON thought_link_summaries (source_thought_id, normalized_url_hash, parent_research_thought_id)
            WHERE parent_research_thought_id IS NOT NULL'
        );

        DB::statement(
            'CREATE UNIQUE INDEX thought_link_summaries_unique_source_url_without_parent
            ON thought_link_summaries (source_thought_id, normalized_url_hash)
            WHERE parent_research_thought_id IS NULL'
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS thought_link_summaries_unique_source_url');
        DB::statement('DROP INDEX IF EXISTS thought_link_summaries_unique_source_url_without_parent');
        Schema::dropIfExists('thought_link_summaries');
    }
};
