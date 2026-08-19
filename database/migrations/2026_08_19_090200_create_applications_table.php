<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('applications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUuid('job_prospect_id')->nullable()->constrained('job_prospects')->nullOnDelete();
            $table->string('role_title', 255);
            $table->string('stage', 20)->default('researching');
            $table->string('source', 20)->nullable();
            $table->integer('salary_min')->nullable();
            $table->integer('salary_max')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->foreignUuid('research_thought_id')->nullable()->constrained('thoughts')->nullOnDelete();
            $table->longText('cv_markdown')->nullable();
            $table->longText('cover_letter_markdown')->nullable();
            $table->string('cv_pdf_path', 500)->nullable();
            $table->string('cover_letter_pdf_path', 500)->nullable();
            $table->timestamp('cv_exported_at')->nullable();
            $table->timestamp('cover_letter_exported_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'stage']);
            $table->index(['user_id', 'last_activity_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('applications');
    }
};
