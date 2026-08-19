<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_prospects', function (Blueprint $table): void {
            $table->foreign('promoted_application_id')->references('id')->on('applications')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('job_prospects', function (Blueprint $table): void {
            $table->dropForeign(['promoted_application_id']);
        });
    }
};
