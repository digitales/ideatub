<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('thoughts', function (Blueprint $table) {
            $table->boolean('is_visible_in_stream')->default(true)->after('source_metadata');
            $table->string('visibility_reason')->nullable()->after('is_visible_in_stream');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('thoughts', function (Blueprint $table) {
            $table->dropColumn(['is_visible_in_stream', 'visibility_reason']);
        });
    }
};
