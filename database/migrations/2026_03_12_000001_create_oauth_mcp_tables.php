<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('oauth_mcp_clients', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->json('redirect_uris'); // array of allowed redirect URIs
            $table->timestamps();
        });

        Schema::create('oauth_mcp_authorization_codes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('code', 128)->unique();
            $table->foreignUlid('client_id')->constrained('oauth_mcp_clients')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('redirect_uri', 512);
            $table->string('code_challenge', 128);
            $table->string('code_challenge_method', 10); // S256
            $table->string('resource', 512); // audience (MCP server URL)
            $table->string('scope')->nullable();
            $table->string('state')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('oauth_mcp_authorization_codes');
        Schema::dropIfExists('oauth_mcp_clients');
    }
};
