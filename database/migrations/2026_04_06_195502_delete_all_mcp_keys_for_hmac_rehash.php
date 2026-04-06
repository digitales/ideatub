<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Existing SHA-256 hashes are incompatible with HMAC-SHA256.
        // Users must issue new MCP keys after this migration runs.
        DB::table('user_mcp_keys')->delete();
    }

    public function down(): void
    {
        // Keys cannot be restored — originals were not stored.
    }
};
