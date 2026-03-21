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
        Schema::create('captured_inbound_emails', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('message_id');
            $table->string('sender_email', 255);
            $table->string('subject', 1024)->nullable();
            $table->longText('body_text')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->string('rule_action', 32)->nullable();
            $table->string('rule_email', 255)->nullable();
            $table->foreignUuid('thought_id')->nullable()->constrained('thoughts')->nullOnDelete();
            $table->foreignUuid('research_thought_id')->nullable()->constrained('thoughts')->nullOnDelete();
            $table->foreignId('review_inbox_item_id')->nullable()->constrained('inbox_items')->nullOnDelete();
            $table->string('processing_status', 32);
            $table->json('processing_metadata_json')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'message_id']);
            $table->index(['user_id', 'processing_status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('captured_inbound_emails');
    }
};
