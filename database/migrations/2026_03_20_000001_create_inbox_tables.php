<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inbox_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('generator_type', 100);
            $table->string('title', 255);
            $table->text('body');
            $table->string('status', 20)->default('pending');
            $table->timestamp('snoozed_until')->nullable();
            $table->timestamp('generated_at');
            $table->timestamp('actioned_at')->nullable();
            $table->string('dedupe_key', 191);
            $table->json('source_data')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['user_id', 'snoozed_until']);
            $table->index(['user_id', 'generated_at']);
        });

        Schema::create('inbox_item_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inbox_item_id')->constrained()->cascadeOnDelete();
            $table->string('action_type', 50);
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        // Active dedupe: only one pending item per user + dedupe key.
        DB::statement("CREATE UNIQUE INDEX inbox_items_user_dedupe_pending_unique ON inbox_items (user_id, dedupe_key) WHERE status = 'pending'");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS inbox_items_user_dedupe_pending_unique');
        Schema::dropIfExists('inbox_item_actions');
        Schema::dropIfExists('inbox_items');
    }
};
