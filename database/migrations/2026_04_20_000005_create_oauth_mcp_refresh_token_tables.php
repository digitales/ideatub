<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('oauth_mcp_refresh_token_families', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('client_id')->constrained('oauth_mcp_clients')->cascadeOnDelete();
            $table->string('resource', 512);
            $table->string('scope')->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('issued_at');
            $table->timestamp('absolute_expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoked_reason', 32)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'revoked_at']);
        });

        Schema::create('oauth_mcp_refresh_tokens', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('family_id')->constrained('oauth_mcp_refresh_token_families')->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->ulid('replaced_by_id')->nullable();
            $table->timestamps();

            $table->index('family_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('oauth_mcp_refresh_tokens');
        Schema::dropIfExists('oauth_mcp_refresh_token_families');
    }
};
