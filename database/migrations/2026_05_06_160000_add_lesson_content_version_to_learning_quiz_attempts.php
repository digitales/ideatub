<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('learning_quiz_attempts', function (Blueprint $table): void {
            $table->unsignedInteger('lesson_content_version')->nullable()->after('passed');
        });
    }

    public function down(): void
    {
        Schema::table('learning_quiz_attempts', function (Blueprint $table): void {
            $table->dropColumn('lesson_content_version');
        });
    }
};
