<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_batches', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('project_id')->nullable()->constrained()->nullOnDelete();
            $table->string('root_folder_name', 255)->nullable();
            $table->string('source', 64);
            $table->string('status', 32);
            $table->unsignedInteger('file_count');
            $table->unsignedBigInteger('total_bytes');
            $table->unsignedInteger('processed_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);
            $table->boolean('no_chunking')->default(false);
            $table->boolean('skip_ai_metadata')->default(false);
            $table->json('options')->nullable();
            $table->string('staging_path', 512);
            $table->string('laravel_batch_id', 64)->nullable();
            $table->timestamp('completion_notified_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index('status');
        });

        Schema::create('import_batch_files', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('import_batch_id')
                ->constrained('import_batches')
                ->cascadeOnDelete();
            $table->string('relative_path', 1024);
            $table->string('original_filename', 512);
            $table->unsignedBigInteger('size_bytes');
            $table->char('sha256', 64)->nullable()->index();
            $table->string('status', 32);
            $table->foreignUuid('thought_id')->nullable()
                ->constrained('thoughts')->nullOnDelete();
            $table->string('error_code', 64)->nullable();
            $table->string('error_message', 1024)->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['import_batch_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_batch_files');
        Schema::dropIfExists('import_batches');
    }
};
