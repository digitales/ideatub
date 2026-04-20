<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class OAuthMcpRefreshTokenMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_refresh_token_families_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('oauth_mcp_refresh_token_families'));
        foreach ([
            'id', 'user_id', 'client_id', 'resource', 'scope',
            'user_agent', 'ip_address', 'last_used_at',
            'issued_at', 'absolute_expires_at',
            'revoked_at', 'revoked_reason',
            'created_at', 'updated_at',
        ] as $col) {
            $this->assertTrue(
                Schema::hasColumn('oauth_mcp_refresh_token_families', $col),
                "missing column {$col} on oauth_mcp_refresh_token_families"
            );
        }
    }

    public function test_refresh_tokens_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('oauth_mcp_refresh_tokens'));
        foreach ([
            'id', 'family_id', 'token_hash', 'expires_at',
            'used_at', 'replaced_by_id',
            'created_at', 'updated_at',
        ] as $col) {
            $this->assertTrue(
                Schema::hasColumn('oauth_mcp_refresh_tokens', $col),
                "missing column {$col} on oauth_mcp_refresh_tokens"
            );
        }
    }
}
