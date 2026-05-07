<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('working_memory_versions', function (Blueprint $table): void {
            $table->json('section_references_json')->nullable()->after('references_json');
        });
    }

    public function down(): void
    {
        Schema::table('working_memory_versions', function (Blueprint $table): void {
            $table->dropColumn('section_references_json');
        });
    }
};
