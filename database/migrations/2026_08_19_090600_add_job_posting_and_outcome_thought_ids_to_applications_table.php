<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table): void {
            $table->foreignUuid('job_posting_thought_id')->nullable()->after('research_thought_id')->constrained('thoughts')->nullOnDelete();
            $table->foreignUuid('outcome_thought_id')->nullable()->after('job_posting_thought_id')->constrained('thoughts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('job_posting_thought_id');
            $table->dropConstrainedForeignId('outcome_thought_id');
        });
    }
};
