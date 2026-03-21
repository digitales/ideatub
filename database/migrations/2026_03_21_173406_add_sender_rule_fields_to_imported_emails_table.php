<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * `processing_status` semantics: legacy import pipeline values include `pending`,
     * `filtered`, and `imported`. For sender-policy and newsletter research, Fastmail
     * rows may also use `review_queued`, `research_queued`, `research_completed`,
     * `research_partial`, `research_skipped`, and `research_failed` (mirroring Postmark
     * `captured_inbound_emails` lifecycle).
     */
    public function up(): void
    {
        Schema::table('imported_emails', function (Blueprint $table) {
            $table->string('processing_status', 32)->default('pending')->change();

            $table->string('rule_action', 32)->nullable();
            $table->string('rule_email', 255)->nullable();
            $table->foreignId('review_inbox_item_id')->nullable()->constrained('inbox_items')->nullOnDelete();
            $table->foreignUuid('research_thought_id')->nullable()->constrained('thoughts')->nullOnDelete();
            $table->json('processing_metadata_json')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('imported_emails', function (Blueprint $table) {
            $table->dropForeign(['review_inbox_item_id']);
            $table->dropForeign(['research_thought_id']);
            $table->dropColumn([
                'rule_action',
                'rule_email',
                'review_inbox_item_id',
                'research_thought_id',
                'processing_metadata_json',
            ]);

            $table->string('processing_status', 20)->default('pending')->change();
        });
    }
};
