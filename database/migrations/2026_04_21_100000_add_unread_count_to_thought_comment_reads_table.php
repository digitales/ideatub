<?php

use App\Services\Comments\ResearchCommentUnreadService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('thought_comment_reads', function (Blueprint $table) {
            $table->unsignedInteger('unread_count')->default(0);
        });

        app(ResearchCommentUnreadService::class)->reconcileStoredCounts();
    }

    public function down(): void
    {
        Schema::table('thought_comment_reads', function (Blueprint $table) {
            $table->dropColumn('unread_count');
        });
    }
};
