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
        Schema::create('unmatched_inbound_emails', function (Blueprint $table) {
            $table->id();
            $table->string('message_id', 255)->unique();
            $table->string('from_email');
            $table->string('to_email')->nullable();
            $table->string('subject', 1024)->nullable();
            $table->text('body_text')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->json('payload_json')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('unmatched_inbound_emails');
    }
};
