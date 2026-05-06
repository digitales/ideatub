<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('working_memory_versions', function (Blueprint $table): void {
            $table->json('structured_sections_json')->nullable();
            $table->json('references_json')->nullable();
            $table->decimal('citation_coverage', 5, 2)->nullable();
            $table->string('authoring_status', 32)->nullable();
            $table->text('validation_error')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('working_memory_versions', function (Blueprint $table): void {
            $table->dropColumn([
                'structured_sections_json',
                'references_json',
                'citation_coverage',
                'authoring_status',
                'validation_error',
            ]);
        });
    }
};
