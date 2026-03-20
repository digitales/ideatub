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
        Schema::create('imported_emails', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('mail_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('mail_sync_run_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider', 50);
            $table->string('provider_message_id', 255);
            $table->string('provider_thread_id', 255)->nullable();
            $table->string('provider_mailbox_id', 255)->nullable();
            $table->string('provider_mailbox_name', 255)->nullable();
            $table->string('direction', 20);
            $table->string('subject', 1024)->nullable();
            $table->json('from_json')->nullable();
            $table->json('to_json')->nullable();
            $table->json('cc_json')->nullable();
            $table->json('participants_json')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->longText('body_text')->nullable();
            $table->text('summary')->nullable();
            $table->json('message_metadata_json')->nullable();
            $table->string('content_fingerprint', 64)->nullable();
            $table->foreignUuid('thought_id')->nullable()->constrained('thoughts')->nullOnDelete();
            $table->timestamp('thought_deleted_at')->nullable();
            $table->string('processing_status', 20)->default('pending');
            $table->unsignedInteger('failure_count')->default(0);
            $table->text('failure_reason')->nullable();
            $table->timestamps();

            $table->unique(['mail_account_id', 'provider_message_id']);
            $table->index(['user_id', 'processing_status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('imported_emails');
    }
};
