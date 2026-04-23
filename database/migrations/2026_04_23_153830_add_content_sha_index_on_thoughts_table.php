<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasIndex('thoughts', 'thoughts_user_content_sha256_idx')) {
            return;
        }

        Schema::table('thoughts', function (Blueprint $table): void {
            $table->index(
                ['user_id', 'content_sha256'],
                'thoughts_user_content_sha256_idx'
            );
        });
    }

    public function down(): void
    {
        if (! Schema::hasIndex('thoughts', 'thoughts_user_content_sha256_idx')) {
            return;
        }

        Schema::table('thoughts', function (Blueprint $table): void {
            $table->dropIndex('thoughts_user_content_sha256_idx');
        });
    }
};
