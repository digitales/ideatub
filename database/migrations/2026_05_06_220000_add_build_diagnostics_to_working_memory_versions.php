<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('working_memory_versions', function (Blueprint $table): void {
            $table->json('build_diagnostics_json')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('working_memory_versions', function (Blueprint $table): void {
            $table->dropColumn('build_diagnostics_json');
        });
    }
};
