<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('thought_comment_reads', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('thought_id')->constrained('thoughts')->cascadeOnDelete();
            $table->timestamp('last_read_at');
            $table->primary(['user_id', 'thought_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('thought_comment_reads');
    }
};
