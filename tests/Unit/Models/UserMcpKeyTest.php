<?php

namespace Tests\Unit\Models;

use App\Models\UserMcpKey;
use Tests\TestCase;

class UserMcpKeyTest extends TestCase
{
    public function test_hash_key_produces_hmac_not_plain_sha256(): void
    {
        $plain = 'ideatub_testabc123';
        $hash = UserMcpKey::hashKey($plain);

        $plainSha256 = hash('sha256', $plain);
        $this->assertNotEquals($plainSha256, $hash, 'hashKey must not produce plain SHA-256');

        // Also assert it matches the expected HMAC value directly
        $expectedHmac = hash_hmac('sha256', $plain, config('app.key'));
        $this->assertEquals($expectedHmac, $hash, 'hashKey must produce HMAC-SHA256 keyed on app.key');
    }

    public function test_same_key_produces_same_hash(): void
    {
        $plain = 'ideatub_testabc123';
        $this->assertEquals(UserMcpKey::hashKey($plain), UserMcpKey::hashKey($plain));
    }

    public function test_find_by_plain_key_returns_null_for_unknown_key(): void
    {
        $this->assertNull(UserMcpKey::findByPlainKey('ideatub_doesnotexist'));
    }
}
