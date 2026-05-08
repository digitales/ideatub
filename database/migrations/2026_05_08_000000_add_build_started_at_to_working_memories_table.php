<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('working_memories', function (Blueprint $table): void {
            $table->timestamp('build_started_at')->nullable()->after('last_refreshed_at');
        });
    }

    public function down(): void
    {
        Schema::table('working_memories', function (Blueprint $table): void {
            $table->dropColumn('build_started_at');
        });
    }
};
