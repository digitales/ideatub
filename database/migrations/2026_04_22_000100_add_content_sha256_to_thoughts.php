<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('thoughts', 'content_sha256')) {
            return;
        }

        Schema::table('thoughts', function (Blueprint $table): void {
            $table->char('content_sha256', 64)->nullable()->after('content');
            // $table->index(['user_id', 'content_sha256'], 'thoughts_user_content_sha256_idx');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('thoughts', 'content_sha256')) {
            return;
        }

        Schema::table('thoughts', function (Blueprint $table): void {
            // $table->dropIndex('thoughts_user_content_sha256_idx');
            $table->dropColumn('content_sha256');
        });
    }
};
