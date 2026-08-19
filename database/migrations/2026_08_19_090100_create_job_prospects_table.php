<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_prospects', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('company', 255);
            $table->string('role_title', 255);
            $table->string('source', 20);
            $table->string('url', 500)->nullable();
            $table->string('salary_signal', 255)->nullable();
            $table->unsignedTinyInteger('fit_score')->nullable();
            $table->string('status', 20)->default('new');
            $table->timestamp('discovered_at')->useCurrent();
            $table->timestamp('scored_at')->nullable();
            $table->text('notes')->nullable();
            $table->uuid('promoted_application_id')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_prospects');
    }
};
