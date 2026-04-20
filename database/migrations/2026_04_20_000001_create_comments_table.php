<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comments', function (Blueprint $table) {
            $table->id();
            $table->string('commentable_type', 64);
            $table->string('commentable_id', 36);
            $table->foreignId('author_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('author_name', 100)->nullable();
            $table->text('content');
            $table->string('format', 16)->default('plain');
            $table->string('ip_hash', 64)->nullable();
            $table->string('import_source', 32)->nullable();
            $table->timestamps();

            $table->index(['commentable_type', 'commentable_id', 'created_at'], 'comments_commentable_created_idx');
            $table->index(['author_user_id', 'created_at'], 'comments_author_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comments');
    }
};
