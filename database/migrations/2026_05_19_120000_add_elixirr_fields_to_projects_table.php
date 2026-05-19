<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->foreignUuid('parent_project_id')
                ->nullable()
                ->after('user_id')
                ->constrained('projects')
                ->nullOnDelete();
            $table->string('elixirr_client_slug', 64)->nullable()->after('description');
            $table->string('elixirr_project_slug', 64)->nullable()->after('elixirr_client_slug');
            $table->index(['user_id', 'elixirr_client_slug']);
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_project_id');
            $table->dropColumn(['elixirr_client_slug', 'elixirr_project_slug']);
        });
    }
};
