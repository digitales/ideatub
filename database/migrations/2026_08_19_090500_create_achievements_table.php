<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('achievements', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('tag', 100);
            $table->text('bullet_text');
            $table->integer('times_used')->default(0);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('retired_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'tag']);
            $table->index(['user_id', 'retired_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('achievements');
    }
};
