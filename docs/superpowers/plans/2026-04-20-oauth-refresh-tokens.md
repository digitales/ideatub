# OAuth MCP Refresh Tokens Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add OAuth 2.1-style refresh tokens to IdeaTub's MCP OAuth server so Claude, ChatGPT, and other MCP clients stop forcing hourly re-consent, with rotation + reuse detection, an RFC 7009 revocation endpoint, and a user-facing Connected Apps settings page.

**Architecture:** A new `OAuthMcpRefreshTokenService` owns issuance, rotation, and revocation. Refresh tokens are opaque 64-char strings, stored as SHA-256 hashes in `oauth_mcp_refresh_tokens` rows; each row belongs to a family (`oauth_mcp_refresh_token_families`). Access tokens remain unchanged (RS256 JWT, 1h). Rotation happens in a DB transaction; replay of a rotated token revokes the whole family. A `/settings/connected-apps` Blade page lists and revokes families.

**Tech Stack:** Laravel 11+, PHP 8.2+, PHPUnit 11, Firebase JWT (existing), MySQL-compatible migrations (ULID primary keys), Blade + Tailwind (existing `settings/*.blade.php` pattern).

**Spec:** `docs/superpowers/specs/2026-04-20-oauth-refresh-tokens-design.md`

---

## Task 1: Migration for `oauth_mcp_refresh_token_families` and `oauth_mcp_refresh_tokens`

**Files:**
- Create: `database/migrations/2026_04_20_000001_create_oauth_mcp_refresh_token_tables.php`
- Test: `tests/Feature/OAuthMcpRefreshTokenMigrationTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/OAuthMcpRefreshTokenMigrationTest.php`:

```php
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
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=OAuthMcpRefreshTokenMigrationTest`
Expected: FAIL with `Failed asserting that false is true` (tables don't exist).

- [ ] **Step 3: Write the migration**

Create `database/migrations/2026_04_20_000001_create_oauth_mcp_refresh_token_tables.php`:

```php
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
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=OAuthMcpRefreshTokenMigrationTest`
Expected: 2 passed.

- [ ] **Step 5: Commit**

```bash
git add database/migrations/2026_04_20_000001_create_oauth_mcp_refresh_token_tables.php tests/Feature/OAuthMcpRefreshTokenMigrationTest.php
git commit -m "feat(oauth-mcp): add refresh token family + token migrations"
```

---

## Task 2: Eloquent models `OauthMcpRefreshTokenFamily` and `OauthMcpRefreshToken`

**Files:**
- Create: `app/Models/OauthMcpRefreshTokenFamily.php`
- Create: `app/Models/OauthMcpRefreshToken.php`
- Test: `tests/Feature/OauthMcpRefreshTokenModelsTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/OauthMcpRefreshTokenModelsTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\OauthMcpClient;
use App\Models\OauthMcpRefreshToken;
use App\Models\OauthMcpRefreshTokenFamily;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OauthMcpRefreshTokenModelsTest extends TestCase
{
    use RefreshDatabase;

    public function test_family_and_token_relationships(): void
    {
        $user = User::factory()->create();
        $client = OauthMcpClient::create([
            'redirect_uris' => ['https://claude.ai/api/mcp/auth_callback'],
        ]);

        $family = OauthMcpRefreshTokenFamily::create([
            'user_id' => $user->id,
            'client_id' => $client->id,
            'resource' => 'https://example.test/api/mcp',
            'scope' => 'ideatub:mcp',
            'issued_at' => now(),
            'absolute_expires_at' => now()->addDays(90),
        ]);

        $token = OauthMcpRefreshToken::create([
            'family_id' => $family->id,
            'token_hash' => str_repeat('a', 64),
            'expires_at' => now()->addDays(30),
        ]);

        $this->assertTrue($family->refreshTokens->contains($token));
        $this->assertTrue($token->family->is($family));
        $this->assertTrue($family->user->is($user));
        $this->assertTrue($family->client->is($client));
    }

    public function test_active_scope_excludes_revoked_and_absolutely_expired_families(): void
    {
        $user = User::factory()->create();
        $client = OauthMcpClient::create([
            'redirect_uris' => ['https://claude.ai/api/mcp/auth_callback'],
        ]);

        $active = OauthMcpRefreshTokenFamily::create([
            'user_id' => $user->id, 'client_id' => $client->id,
            'resource' => 'https://example.test/api/mcp',
            'issued_at' => now(), 'absolute_expires_at' => now()->addDays(30),
        ]);
        OauthMcpRefreshTokenFamily::create([
            'user_id' => $user->id, 'client_id' => $client->id,
            'resource' => 'https://example.test/api/mcp',
            'issued_at' => now()->subDays(100),
            'absolute_expires_at' => now()->subDay(),
        ]);
        OauthMcpRefreshTokenFamily::create([
            'user_id' => $user->id, 'client_id' => $client->id,
            'resource' => 'https://example.test/api/mcp',
            'issued_at' => now(), 'absolute_expires_at' => now()->addDays(30),
            'revoked_at' => now(),
        ]);

        $ids = OauthMcpRefreshTokenFamily::active()->pluck('id')->all();
        $this->assertSame([$active->id], $ids);
    }

    public function test_usable_scope_excludes_used_and_expired_tokens(): void
    {
        $user = User::factory()->create();
        $client = OauthMcpClient::create([
            'redirect_uris' => ['https://claude.ai/api/mcp/auth_callback'],
        ]);
        $family = OauthMcpRefreshTokenFamily::create([
            'user_id' => $user->id, 'client_id' => $client->id,
            'resource' => 'https://example.test/api/mcp',
            'issued_at' => now(), 'absolute_expires_at' => now()->addDays(90),
        ]);

        $usable = OauthMcpRefreshToken::create([
            'family_id' => $family->id,
            'token_hash' => str_repeat('a', 64),
            'expires_at' => now()->addDays(30),
        ]);
        OauthMcpRefreshToken::create([
            'family_id' => $family->id,
            'token_hash' => str_repeat('b', 64),
            'expires_at' => now()->subDay(),
        ]);
        OauthMcpRefreshToken::create([
            'family_id' => $family->id,
            'token_hash' => str_repeat('c', 64),
            'expires_at' => now()->addDays(30),
            'used_at' => now(),
        ]);

        $ids = OauthMcpRefreshToken::usable()->pluck('id')->all();
        $this->assertSame([$usable->id], $ids);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=OauthMcpRefreshTokenModelsTest`
Expected: FAIL — `Class "App\Models\OauthMcpRefreshTokenFamily" not found`.

- [ ] **Step 3: Write the family model**

Create `app/Models/OauthMcpRefreshTokenFamily.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OauthMcpRefreshTokenFamily extends Model
{
    use HasUlids;

    protected $table = 'oauth_mcp_refresh_token_families';

    protected $fillable = [
        'user_id',
        'client_id',
        'resource',
        'scope',
        'user_agent',
        'ip_address',
        'last_used_at',
        'issued_at',
        'absolute_expires_at',
        'revoked_at',
        'revoked_reason',
    ];

    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
            'issued_at' => 'datetime',
            'absolute_expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(OauthMcpClient::class, 'client_id');
    }

    public function refreshTokens(): HasMany
    {
        return $this->hasMany(OauthMcpRefreshToken::class, 'family_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query
            ->whereNull('revoked_at')
            ->where('absolute_expires_at', '>', now());
    }
}
```

- [ ] **Step 4: Write the token model**

Create `app/Models/OauthMcpRefreshToken.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OauthMcpRefreshToken extends Model
{
    use HasUlids;

    protected $table = 'oauth_mcp_refresh_tokens';

    protected $fillable = [
        'family_id',
        'token_hash',
        'expires_at',
        'used_at',
        'replaced_by_id',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
        ];
    }

    public function family(): BelongsTo
    {
        return $this->belongsTo(OauthMcpRefreshTokenFamily::class, 'family_id');
    }

    public function replacedBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replaced_by_id');
    }

    public function scopeUsable(Builder $query): Builder
    {
        return $query
            ->whereNull('used_at')
            ->where('expires_at', '>', now());
    }
}
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test --filter=OauthMcpRefreshTokenModelsTest`
Expected: 3 passed.

- [ ] **Step 6: Commit**

```bash
git add app/Models/OauthMcpRefreshTokenFamily.php app/Models/OauthMcpRefreshToken.php tests/Feature/OauthMcpRefreshTokenModelsTest.php
git commit -m "feat(oauth-mcp): add refresh token family + token Eloquent models"
```

---

## Task 3: Config additions for refresh-token TTLs

**Files:**
- Modify: `config/oauth-mcp.php`
- Modify: `.env.example`
- Test: `tests/Feature/OAuthMcpConfigTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/OAuthMcpConfigTest.php`:

```php
<?php

namespace Tests\Feature;

use Tests\TestCase;

class OAuthMcpConfigTest extends TestCase
{
    public function test_config_defines_refresh_token_ttls(): void
    {
        $this->assertSame(3600, config('oauth-mcp.access_token_ttl_seconds'));
        $this->assertSame(60 * 60 * 24 * 30, config('oauth-mcp.refresh_token_ttl_seconds'));
        $this->assertSame(60 * 60 * 24 * 90, config('oauth-mcp.refresh_token_absolute_lifetime_seconds'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=OAuthMcpConfigTest`
Expected: FAIL on the `refresh_token_*` assertions (null).

- [ ] **Step 3: Update `config/oauth-mcp.php`**

In `config/oauth-mcp.php`, replace the `access_token_ttl_seconds` line and add the new keys directly beneath:

```php
    'access_token_ttl_seconds' => (int) env('OAUTH_MCP_ACCESS_TOKEN_TTL', 3600),

    'refresh_token_ttl_seconds' => (int) env('OAUTH_MCP_REFRESH_TOKEN_TTL', 60 * 60 * 24 * 30),

    'refresh_token_absolute_lifetime_seconds' => (int) env('OAUTH_MCP_REFRESH_TOKEN_ABSOLUTE_LIFETIME', 60 * 60 * 24 * 90),
```

- [ ] **Step 4: Update `.env.example`**

Append near the existing `OAUTH_MCP_*` env block:

```
OAUTH_MCP_ACCESS_TOKEN_TTL=3600
OAUTH_MCP_REFRESH_TOKEN_TTL=2592000
OAUTH_MCP_REFRESH_TOKEN_ABSOLUTE_LIFETIME=7776000
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=OAuthMcpConfigTest`
Expected: 1 passed.

- [ ] **Step 6: Commit**

```bash
git add config/oauth-mcp.php .env.example tests/Feature/OAuthMcpConfigTest.php
git commit -m "feat(oauth-mcp): add refresh_token_ttl + absolute_lifetime config"
```

---

## Task 4: `OAuthMcpRefreshTokenService` — issueForCodeExchange

**Files:**
- Create: `app/Services/OAuthMcpRefreshTokenService.php`
- Test: `tests/Unit/OAuthMcpRefreshTokenServiceIssueTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/OAuthMcpRefreshTokenServiceIssueTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Models\OauthMcpClient;
use App\Models\OauthMcpRefreshToken;
use App\Models\OauthMcpRefreshTokenFamily;
use App\Models\User;
use App\Services\OAuthMcpRefreshTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class OAuthMcpRefreshTokenServiceIssueTest extends TestCase
{
    use RefreshDatabase;

    public function test_issue_for_code_exchange_creates_family_and_first_token(): void
    {
        config()->set('oauth-mcp.refresh_token_ttl_seconds', 60 * 60 * 24 * 30);
        config()->set('oauth-mcp.refresh_token_absolute_lifetime_seconds', 60 * 60 * 24 * 90);

        $user = User::factory()->create();
        $client = OauthMcpClient::create([
            'redirect_uris' => ['https://claude.ai/api/mcp/auth_callback'],
        ]);
        $request = Request::create('/oauth/token', 'POST', server: [
            'HTTP_USER_AGENT' => 'claude-test/1.0',
            'REMOTE_ADDR' => '10.0.0.1',
        ]);

        $service = app(OAuthMcpRefreshTokenService::class);

        $result = $service->issueForCodeExchange(
            $user,
            $client,
            'https://example.test/api/mcp',
            'ideatub:mcp',
            $request
        );

        $this->assertIsString($result['raw']);
        $this->assertSame(64, strlen($result['raw']));
        $this->assertInstanceOf(OauthMcpRefreshTokenFamily::class, $result['family']);

        $family = $result['family']->fresh();
        $this->assertSame($user->id, $family->user_id);
        $this->assertSame($client->id, $family->client_id);
        $this->assertSame('https://example.test/api/mcp', $family->resource);
        $this->assertSame('ideatub:mcp', $family->scope);
        $this->assertSame('claude-test/1.0', $family->user_agent);
        $this->assertSame('10.0.0.1', $family->ip_address);
        $this->assertNull($family->revoked_at);
        $this->assertTrue($family->absolute_expires_at->gt(now()->addDays(89)));

        $token = OauthMcpRefreshToken::where('family_id', $family->id)->firstOrFail();
        $this->assertSame(hash('sha256', $result['raw']), $token->token_hash);
        $this->assertNull($token->used_at);
        $this->assertTrue($token->expires_at->gt(now()->addDays(29)));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=OAuthMcpRefreshTokenServiceIssueTest`
Expected: FAIL — `Class "App\Services\OAuthMcpRefreshTokenService" not found`.

- [ ] **Step 3: Create the service skeleton + issue method**

Create `app/Services/OAuthMcpRefreshTokenService.php`:

```php
<?php

namespace App\Services;

use App\Models\OauthMcpClient;
use App\Models\OauthMcpRefreshToken;
use App\Models\OauthMcpRefreshTokenFamily;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OAuthMcpRefreshTokenService
{
    /**
     * Create a new token family + its first refresh token row.
     *
     * @return array{family: OauthMcpRefreshTokenFamily, raw: string}
     */
    public function issueForCodeExchange(
        User $user,
        OauthMcpClient $client,
        string $resource,
        ?string $scope,
        Request $request,
    ): array {
        $now = Carbon::now();
        $absoluteCap = $now->copy()->addSeconds((int) config('oauth-mcp.refresh_token_absolute_lifetime_seconds'));

        return DB::transaction(function () use ($user, $client, $resource, $scope, $request, $now, $absoluteCap) {
            $family = OauthMcpRefreshTokenFamily::create([
                'user_id' => $user->id,
                'client_id' => $client->id,
                'resource' => OAuthMcpJwtService::normalizeResourceUrl($resource),
                'scope' => $scope,
                'user_agent' => $this->truncate($request->userAgent(), 512),
                'ip_address' => $request->ip(),
                'last_used_at' => null,
                'issued_at' => $now,
                'absolute_expires_at' => $absoluteCap,
            ]);

            $raw = Str::random(64);

            OauthMcpRefreshToken::create([
                'family_id' => $family->id,
                'token_hash' => hash('sha256', $raw),
                'expires_at' => $this->cappedRefreshExpiry($now, $family),
            ]);

            return ['family' => $family, 'raw' => $raw];
        });
    }

    private function cappedRefreshExpiry(Carbon $now, OauthMcpRefreshTokenFamily $family): Carbon
    {
        $rolling = $now->copy()->addSeconds((int) config('oauth-mcp.refresh_token_ttl_seconds'));

        return $rolling->lt($family->absolute_expires_at) ? $rolling : $family->absolute_expires_at->copy();
    }

    private function truncate(?string $value, int $max): ?string
    {
        if ($value === null) {
            return null;
        }

        return strlen($value) > $max ? substr($value, 0, $max) : $value;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=OAuthMcpRefreshTokenServiceIssueTest`
Expected: 1 passed.

- [ ] **Step 5: Commit**

```bash
git add app/Services/OAuthMcpRefreshTokenService.php tests/Unit/OAuthMcpRefreshTokenServiceIssueTest.php
git commit -m "feat(oauth-mcp): OAuthMcpRefreshTokenService::issueForCodeExchange"
```

---

## Task 5: `OAuthMcpRefreshTokenService::rotate` with reuse detection

**Files:**
- Modify: `app/Services/OAuthMcpRefreshTokenService.php`
- Test: `tests/Unit/OAuthMcpRefreshTokenServiceRotateTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/OAuthMcpRefreshTokenServiceRotateTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Models\OauthMcpClient;
use App\Models\OauthMcpRefreshToken;
use App\Models\OauthMcpRefreshTokenFamily;
use App\Models\User;
use App\Services\OAuthMcpRefreshTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use RuntimeException;
use Tests\TestCase;

class OAuthMcpRefreshTokenServiceRotateTest extends TestCase
{
    use RefreshDatabase;

    private OAuthMcpRefreshTokenService $service;

    private User $user;

    private OauthMcpClient $client;

    private OauthMcpRefreshTokenFamily $family;

    private string $rawToken;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('oauth-mcp.refresh_token_ttl_seconds', 60 * 60 * 24 * 30);
        config()->set('oauth-mcp.refresh_token_absolute_lifetime_seconds', 60 * 60 * 24 * 90);

        $this->service = app(OAuthMcpRefreshTokenService::class);
        $this->user = User::factory()->create();
        $this->client = OauthMcpClient::create([
            'redirect_uris' => ['https://claude.ai/api/mcp/auth_callback'],
        ]);

        $issue = $this->service->issueForCodeExchange(
            $this->user,
            $this->client,
            'https://example.test/api/mcp',
            'ideatub:mcp',
            Request::create('/oauth/token', 'POST'),
        );
        $this->family = $issue['family'];
        $this->rawToken = $issue['raw'];
    }

    public function test_rotate_issues_new_token_and_invalidates_old(): void
    {
        $oldHash = hash('sha256', $this->rawToken);

        $result = $this->service->rotate(
            $this->rawToken,
            $this->client->id,
            'https://example.test/api/mcp',
            null,
            Request::create('/oauth/token', 'POST', server: ['REMOTE_ADDR' => '10.0.0.9']),
        );

        $this->assertTrue($result['user']->is($this->user));
        $this->assertSame('https://example.test/api/mcp', $result['resource']);
        $this->assertSame('ideatub:mcp', $result['scope']);
        $this->assertIsString($result['raw']);
        $this->assertNotSame($this->rawToken, $result['raw']);

        $old = OauthMcpRefreshToken::where('token_hash', $oldHash)->firstOrFail();
        $this->assertNotNull($old->used_at);
        $this->assertNotNull($old->replaced_by_id);

        $new = OauthMcpRefreshToken::where('token_hash', hash('sha256', $result['raw']))->firstOrFail();
        $this->assertSame($this->family->id, $new->family_id);

        $this->family->refresh();
        $this->assertNotNull($this->family->last_used_at);
        $this->assertSame('10.0.0.9', $this->family->ip_address);
    }

    public function test_replay_of_rotated_token_revokes_family_with_reuse_detected(): void
    {
        $this->service->rotate(
            $this->rawToken, $this->client->id,
            'https://example.test/api/mcp', null,
            Request::create('/oauth/token', 'POST'),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('invalid_grant');

        try {
            $this->service->rotate(
                $this->rawToken, $this->client->id,
                'https://example.test/api/mcp', null,
                Request::create('/oauth/token', 'POST'),
            );
        } finally {
            $this->family->refresh();
            $this->assertNotNull($this->family->revoked_at);
            $this->assertSame('reuse_detected', $this->family->revoked_reason);
        }
    }

    public function test_unknown_token_fails_invalid_grant(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('invalid_grant');

        $this->service->rotate(
            str_repeat('z', 64), $this->client->id,
            'https://example.test/api/mcp', null,
            Request::create('/oauth/token', 'POST'),
        );
    }

    public function test_client_mismatch_fails_invalid_grant(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('invalid_grant');

        $this->service->rotate(
            $this->rawToken, 'other-client-id',
            'https://example.test/api/mcp', null,
            Request::create('/oauth/token', 'POST'),
        );
    }

    public function test_resource_mismatch_fails_invalid_grant(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('invalid_grant');

        $this->service->rotate(
            $this->rawToken, $this->client->id,
            'https://other.test/api/mcp', null,
            Request::create('/oauth/token', 'POST'),
        );
    }

    public function test_revoked_family_fails_invalid_grant(): void
    {
        $this->family->update(['revoked_at' => now(), 'revoked_reason' => 'user']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('invalid_grant');

        $this->service->rotate(
            $this->rawToken, $this->client->id,
            'https://example.test/api/mcp', null,
            Request::create('/oauth/token', 'POST'),
        );
    }

    public function test_expired_token_fails_invalid_grant(): void
    {
        OauthMcpRefreshToken::query()
            ->where('family_id', $this->family->id)
            ->update(['expires_at' => now()->subDay()]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('invalid_grant');

        $this->service->rotate(
            $this->rawToken, $this->client->id,
            'https://example.test/api/mcp', null,
            Request::create('/oauth/token', 'POST'),
        );
    }

    public function test_scope_upgrade_rejected_downgrade_allowed(): void
    {
        $result = $this->service->rotate(
            $this->rawToken, $this->client->id,
            'https://example.test/api/mcp', 'ideatub:mcp',
            Request::create('/oauth/token', 'POST'),
        );
        $this->assertSame('ideatub:mcp', $result['scope']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('invalid_scope');
        $this->service->rotate(
            $result['raw'], $this->client->id,
            'https://example.test/api/mcp', 'ideatub:mcp ideatub:admin',
            Request::create('/oauth/token', 'POST'),
        );
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=OAuthMcpRefreshTokenServiceRotateTest`
Expected: FAIL — `rotate` method undefined.

- [ ] **Step 3: Implement `rotate` and supporting methods**

In `app/Services/OAuthMcpRefreshTokenService.php`, add the following public methods and private helpers. Insert `rotate()` and `revokeFamily()` as public methods and `validateScopeSubset()` as a private helper; keep the existing `issueForCodeExchange`, `cappedRefreshExpiry`, `truncate`.

```php
    /**
     * Validate + rotate a refresh token. Revokes the family on replay.
     *
     * @return array{user: \App\Models\User, resource: string, scope: ?string, raw: string}
     */
    public function rotate(
        string $rawToken,
        string $clientId,
        string $resource,
        ?string $requestedScope,
        Request $request,
    ): array {
        $hash = hash('sha256', $rawToken);
        $normalizedResource = OAuthMcpJwtService::normalizeResourceUrl($resource);

        $startLevel = DB::transactionLevel();
        DB::beginTransaction();

        try {
            $token = OauthMcpRefreshToken::where('token_hash', $hash)->lockForUpdate()->first();
            if (! $token) {
                DB::rollBack();
                throw new \RuntimeException('invalid_grant');
            }

            $family = OauthMcpRefreshTokenFamily::lockForUpdate()->find($token->family_id);
            if (! $family) {
                DB::rollBack();
                throw new \RuntimeException('invalid_grant');
            }

            if ($family->revoked_at !== null || now()->gt($family->absolute_expires_at)) {
                DB::rollBack();
                throw new \RuntimeException('invalid_grant');
            }

            if ($token->used_at !== null) {

                // Reuse detected — burn the family and commit that revocation before raising.
                $this->revokeFamily($family, 'reuse_detected');
                DB::commit();

                throw new \RuntimeException('invalid_grant');
            }

            if ($family->client_id !== $clientId) {

                DB::rollBack();
                throw new \RuntimeException('invalid_grant');
            }

            if ($family->resource !== $normalizedResource) {
                DB::rollBack();

                throw new \RuntimeException('invalid_grant');
            }

            if (now()->gt($token->expires_at)) {

                DB::rollBack();
                throw new \RuntimeException('invalid_grant');
            }

            try {
                $this->validateScopeSubset($family->scope, $requestedScope);
            } catch (\RuntimeException $e) {
                DB::rollBack();
                throw $e;
            }

            $effectiveScope = $requestedScope ?? $family->scope;

            $now = now();
            $newRaw = Str::random(64);

            $new = OauthMcpRefreshToken::create([
                'family_id' => $family->id,
                'token_hash' => hash('sha256', $newRaw),
                'expires_at' => $this->cappedRefreshExpiry($now, $family),
            ]);

            $token->update([
                'used_at' => $now,
                'replaced_by_id' => $new->id,
            ]);

            $family->update([
                'last_used_at' => $now,
                'user_agent' => $this->truncate($request->userAgent(), 512) ?? $family->user_agent,
                'ip_address' => $request->ip() ?? $family->ip_address,
            ]);


            DB::commit();

            return [
                'user' => $family->user,
                'resource' => $family->resource,
                'scope' => $effectiveScope,
                'raw' => $newRaw,
            ];

        } catch (\Throwable $e) {
            // Only roll back savepoints WE created. Never touch the caller's transaction.
            while (DB::transactionLevel() > $startLevel) {
                try {
                    DB::rollBack();
                } catch (\Throwable $ignored) {
                    break;
                }
            }
            throw $e;
        }
    }

    public function revokeFamily(OauthMcpRefreshTokenFamily $family, string $reason): void
    {
        if ($family->revoked_at !== null) {
            return;
        }

        $family->update([
            'revoked_at' => now(),
            'revoked_reason' => $reason,
        ]);
    }

    public function revokeByRawToken(string $rawToken, string $reason, ?string $clientId = null): void
    {
        $hash = hash('sha256', $rawToken);
        $token = OauthMcpRefreshToken::where('token_hash', $hash)->first();
        if (! $token) {
            return;
        }
        $family = $token->family;
        if ($clientId !== null && $family->client_id !== $clientId) {
            return;
        }
        $this->revokeFamily($family, $reason);
    }

    private function validateScopeSubset(?string $familyScope, ?string $requestedScope): void
    {
        if ($requestedScope === null || $requestedScope === '') {
            return;
        }

        $family = array_filter(explode(' ', (string) $familyScope));
        $requested = array_filter(explode(' ', $requestedScope));

        foreach ($requested as $scope) {
            if (! in_array($scope, $family, true)) {
                throw new \RuntimeException('invalid_scope');
            }
        }
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=OAuthMcpRefreshTokenServiceRotateTest`
Expected: 7 passed.

- [ ] **Step 5: Commit**

```bash
git add app/Services/OAuthMcpRefreshTokenService.php tests/Unit/OAuthMcpRefreshTokenServiceRotateTest.php
git commit -m "feat(oauth-mcp): rotate + revokeFamily + revokeByRawToken with reuse detection"
```

---

## Task 6: Extend `POST /oauth/token` authorization_code grant to return `refresh_token`

**Files:**
- Modify: `app/Http/Controllers/OAuthServerController.php` (around lines 91–137)
- Test: `tests/Feature/OAuthMcpAuthorizationCodeRefreshIssuanceTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/OAuthMcpAuthorizationCodeRefreshIssuanceTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\OauthMcpAuthorizationCode;
use App\Models\OauthMcpClient;
use App\Models\OauthMcpRefreshToken;
use App\Models\OauthMcpRefreshTokenFamily;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OAuthMcpAuthorizationCodeRefreshIssuanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_token_endpoint_returns_refresh_token_and_persists_family(): void
    {
        config()->set('oauth-mcp.issuer', 'https://example.test');
        config()->set('oauth-mcp.resource', 'https://example.test/api/mcp');

        $user = User::factory()->create();
        $client = OauthMcpClient::create([
            'redirect_uris' => ['https://claude.ai/api/mcp/auth_callback'],
        ]);

        $verifier = str_repeat('a', 64);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        $code = OauthMcpAuthorizationCode::create([
            'code' => 'test-code',
            'client_id' => $client->id,
            'user_id' => $user->id,
            'redirect_uri' => 'https://claude.ai/api/mcp/auth_callback',
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
            'resource' => 'https://example.test/api/mcp',
            'scope' => 'ideatub:mcp',
            'expires_at' => now()->addMinutes(5),
        ]);

        $response = $this->postJson('/oauth/token', [
            'grant_type' => 'authorization_code',
            'code' => 'test-code',
            'redirect_uri' => 'https://claude.ai/api/mcp/auth_callback',
            'client_id' => $client->id,
            'code_verifier' => $verifier,
            'resource' => 'https://example.test/api/mcp',
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure(['access_token', 'token_type', 'expires_in', 'refresh_token', 'scope']);
        $this->assertSame(64, strlen($response->json('refresh_token')));

        $family = OauthMcpRefreshTokenFamily::where('user_id', $user->id)->firstOrFail();
        $this->assertSame($client->id, $family->client_id);
        $this->assertSame('https://example.test/api/mcp', $family->resource);

        $hash = hash('sha256', $response->json('refresh_token'));
        $this->assertTrue(OauthMcpRefreshToken::where('token_hash', $hash)->exists());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=OAuthMcpAuthorizationCodeRefreshIssuanceTest`
Expected: FAIL on `assertJsonStructure` because `refresh_token` is missing.

- [ ] **Step 3: Modify `OAuthServerController::token()` (authorization_code branch)**

In `app/Http/Controllers/OAuthServerController.php`, inject the new service and extend the `authorization_code` grant. Replace the current `token()` method body so that after `$code->update(['used_at' => now()])` and access-token issuance, a refresh token is also issued and included in the JSON response.

Update the constructor:

```php
    public function __construct(
        private OAuthMcpJwtService $jwt,
        private \App\Services\OAuthMcpRefreshTokenService $refreshTokens,
    ) {}
```

Replace the `token()` method entirely with:

```php
    public function token(Request $request): Response
    {
        $grantType = (string) $request->input('grant_type');

        if ($grantType === 'authorization_code') {
            return $this->tokenAuthorizationCode($request);
        }

        if ($grantType === 'refresh_token') {
            return $this->tokenRefresh($request);
        }

        return response()->json([
            'error' => 'unsupported_grant_type',
        ], 400);
    }

    private function tokenAuthorizationCode(Request $request): Response
    {
        $request->validate([
            'grant_type' => 'required|in:authorization_code',
            'code' => 'required|string',
            'redirect_uri' => 'required|url',
            'client_id' => 'required|string',
            'code_verifier' => 'required|string',
            'resource' => 'required|url',
        ]);

        $code = OauthMcpAuthorizationCode::query()
            ->where('code', $request->code)
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->first();

        if (! $code) {
            return response()->json(['error' => 'invalid_grant', 'error_description' => 'Invalid or expired code'], 400);
        }

        if ($code->client_id !== $request->client_id || $code->redirect_uri !== $request->redirect_uri) {
            return response()->json(['error' => 'invalid_grant'], 400);
        }

        if (OAuthMcpJwtService::normalizeResourceUrl((string) $code->resource)
            !== OAuthMcpJwtService::normalizeResourceUrl((string) $request->resource)) {
            return response()->json(['error' => 'invalid_grant', 'error_description' => 'Resource mismatch'], 400);
        }

        $expectedChallenge = hash('sha256', $request->code_verifier, true);
        $expectedChallenge = rtrim(strtr(base64_encode($expectedChallenge), '+/', '-_'), '=');
        if (! hash_equals($expectedChallenge, $code->code_challenge)) {
            return response()->json(['error' => 'invalid_grant', 'error_description' => 'Invalid code_verifier'], 400);
        }

        $code->update(['used_at' => now()]);

        $accessToken = $this->jwt->issueAccessToken($code->user, $request->resource);

        $issued = $this->refreshTokens->issueForCodeExchange(
            $code->user,
            $code->client,
            (string) $request->resource,
            $code->scope ?? config('oauth-mcp.scope'),
            $request,
        );

        return response()->json([
            'access_token' => $accessToken,
            'token_type' => 'Bearer',
            'expires_in' => config('oauth-mcp.access_token_ttl_seconds', 3600),
            'refresh_token' => $issued['raw'],
            'scope' => $code->scope ?? config('oauth-mcp.scope'),
        ]);
    }

    private function tokenRefresh(Request $request): Response
    {
        // Implemented in Task 7.
        return response()->json(['error' => 'unsupported_grant_type'], 400);
    }
```

Confirm the top of the file imports the needed symbols. Existing imports already cover `OauthMcpAuthorizationCode`, `OauthMcpClient`, `OAuthMcpJwtService`, `Request`, `Response`, `Str`; no new imports required because the service uses its fully-qualified name in the constructor.

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=OAuthMcpAuthorizationCodeRefreshIssuanceTest`
Expected: 1 passed.

- [ ] **Step 5: Run the pre-existing OAuth test suite to catch regressions**

Run: `php artisan test --filter=OAuthMcp`
Expected: all previous OAuth MCP tests still pass.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/OAuthServerController.php tests/Feature/OAuthMcpAuthorizationCodeRefreshIssuanceTest.php
git commit -m "feat(oauth-mcp): issue refresh_token on authorization_code grant"
```

---

## Task 7: Implement `grant_type=refresh_token` handler on `/oauth/token`

**Files:**
- Modify: `app/Http/Controllers/OAuthServerController.php` (replace `tokenRefresh()`)
- Test: `tests/Feature/OAuthMcpRefreshTokenGrantTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/OAuthMcpRefreshTokenGrantTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\OauthMcpClient;
use App\Models\OauthMcpRefreshToken;
use App\Models\User;
use App\Services\OAuthMcpRefreshTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class OAuthMcpRefreshTokenGrantTest extends TestCase
{
    use RefreshDatabase;

    private function setupTokens(): array
    {
        config()->set('oauth-mcp.issuer', 'https://example.test');
        config()->set('oauth-mcp.resource', 'https://example.test/api/mcp');

        $user = User::factory()->create();
        $client = OauthMcpClient::create([
            'redirect_uris' => ['https://claude.ai/api/mcp/auth_callback'],
        ]);
        $issue = app(OAuthMcpRefreshTokenService::class)->issueForCodeExchange(
            $user,
            $client,
            'https://example.test/api/mcp',
            'ideatub:mcp',
            Request::create('/oauth/token', 'POST'),
        );

        return ['user' => $user, 'client' => $client, 'family' => $issue['family'], 'raw' => $issue['raw']];
    }

    public function test_refresh_token_grant_rotates_and_returns_new_tokens(): void
    {
        ['client' => $client, 'raw' => $raw] = $this->setupTokens();

        $response = $this->postJson('/oauth/token', [
            'grant_type' => 'refresh_token',
            'refresh_token' => $raw,
            'client_id' => $client->id,
            'resource' => 'https://example.test/api/mcp',
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure(['access_token', 'token_type', 'expires_in', 'refresh_token', 'scope']);
        $this->assertNotSame($raw, $response->json('refresh_token'));

        $oldHash = hash('sha256', $raw);
        $this->assertNotNull(OauthMcpRefreshToken::where('token_hash', $oldHash)->first()->used_at);
    }

    public function test_replay_of_rotated_token_returns_invalid_grant_and_revokes_family(): void
    {
        ['client' => $client, 'family' => $family, 'raw' => $raw] = $this->setupTokens();

        $this->postJson('/oauth/token', [
            'grant_type' => 'refresh_token',
            'refresh_token' => $raw,
            'client_id' => $client->id,
            'resource' => 'https://example.test/api/mcp',
        ])->assertStatus(200);

        $response = $this->postJson('/oauth/token', [
            'grant_type' => 'refresh_token',
            'refresh_token' => $raw,
            'client_id' => $client->id,
            'resource' => 'https://example.test/api/mcp',
        ]);

        $response->assertStatus(400);
        $response->assertJson(['error' => 'invalid_grant']);

        $family->refresh();
        $this->assertNotNull($family->revoked_at);
        $this->assertSame('reuse_detected', $family->revoked_reason);
    }

    public function test_unknown_refresh_token_returns_invalid_grant(): void
    {
        ['client' => $client] = $this->setupTokens();

        $response = $this->postJson('/oauth/token', [
            'grant_type' => 'refresh_token',
            'refresh_token' => str_repeat('z', 64),
            'client_id' => $client->id,
            'resource' => 'https://example.test/api/mcp',
        ]);

        $response->assertStatus(400)->assertJson(['error' => 'invalid_grant']);
    }

    public function test_unsupported_grant_type_returns_error(): void
    {
        $response = $this->postJson('/oauth/token', [
            'grant_type' => 'client_credentials',
        ]);
        $response->assertStatus(400)->assertJson(['error' => 'unsupported_grant_type']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=OAuthMcpRefreshTokenGrantTest`
Expected: FAIL — stubbed `tokenRefresh()` returns `unsupported_grant_type` for every case.

- [ ] **Step 3: Implement `tokenRefresh()`**

Replace the stub `tokenRefresh()` method in `app/Http/Controllers/OAuthServerController.php` with:

```php
    private function tokenRefresh(Request $request): Response
    {
        $request->validate([
            'grant_type' => 'required|in:refresh_token',
            'refresh_token' => 'required|string',
            'client_id' => 'required|string',
            'resource' => 'required|url',
            'scope' => 'nullable|string',
        ]);

        try {
            $result = $this->refreshTokens->rotate(
                (string) $request->input('refresh_token'),
                (string) $request->input('client_id'),
                (string) $request->input('resource'),
                $request->input('scope'),
                $request,
            );
        } catch (\RuntimeException $e) {
            $error = $e->getMessage();
            if (! in_array($error, ['invalid_grant', 'invalid_scope'], true)) {
                $error = 'invalid_grant';
            }

            return response()->json(['error' => $error], 400);
        }

        $accessToken = $this->jwt->issueAccessToken($result['user'], $result['resource']);

        return response()->json([
            'access_token' => $accessToken,
            'token_type' => 'Bearer',
            'expires_in' => config('oauth-mcp.access_token_ttl_seconds', 3600),
            'refresh_token' => $result['raw'],
            'scope' => $result['scope'] ?? config('oauth-mcp.scope'),
        ]);
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=OAuthMcpRefreshTokenGrantTest`
Expected: 4 passed.

- [ ] **Step 5: Run the full OAuth MCP suite**

Run: `php artisan test --filter=OAuthMcp`
Expected: all green.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/OAuthServerController.php tests/Feature/OAuthMcpRefreshTokenGrantTest.php
git commit -m "feat(oauth-mcp): implement grant_type=refresh_token with rotation"
```

---

## Task 8: `POST /oauth/revoke` endpoint (RFC 7009)

**Files:**
- Modify: `app/Http/Controllers/OAuthServerController.php` (add `revoke()`)
- Modify: `routes/web.php` (around line 56, after the existing `oauth/token` route)
- Modify: `bootstrap/app.php` (CSRF exceptions)
- Test: `tests/Feature/OAuthMcpRevokeEndpointTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/OAuthMcpRevokeEndpointTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\OauthMcpClient;
use App\Models\User;
use App\Services\OAuthMcpRefreshTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class OAuthMcpRevokeEndpointTest extends TestCase
{
    use RefreshDatabase;

    private function issue(): array
    {
        $user = User::factory()->create();
        $client = OauthMcpClient::create([
            'redirect_uris' => ['https://claude.ai/api/mcp/auth_callback'],
        ]);
        $issue = app(OAuthMcpRefreshTokenService::class)->issueForCodeExchange(
            $user, $client, 'https://example.test/api/mcp', 'ideatub:mcp',
            Request::create('/oauth/token', 'POST'),
        );

        return [$client, $issue['family'], $issue['raw']];
    }

    public function test_revoke_refresh_token_returns_200_and_revokes_family(): void
    {
        [$client, $family, $raw] = $this->issue();

        $response = $this->postJson('/oauth/revoke', [
            'token' => $raw,
            'token_type_hint' => 'refresh_token',
            'client_id' => $client->id,
        ]);

        $response->assertStatus(200);

        $family->refresh();
        $this->assertNotNull($family->revoked_at);
        $this->assertSame('user', $family->revoked_reason);

        $this->postJson('/oauth/token', [
            'grant_type' => 'refresh_token',
            'refresh_token' => $raw,
            'client_id' => $client->id,
            'resource' => 'https://example.test/api/mcp',
        ])->assertStatus(400)->assertJson(['error' => 'invalid_grant']);
    }

    public function test_revoke_unknown_token_returns_200(): void
    {
        [$client] = $this->issue();

        $response = $this->postJson('/oauth/revoke', [
            'token' => str_repeat('z', 64),
            'client_id' => $client->id,
        ]);

        $response->assertStatus(200);
    }

    public function test_revoke_access_token_hint_is_noop_200(): void
    {
        [$client] = $this->issue();

        $response = $this->postJson('/oauth/revoke', [
            'token' => 'not-a-refresh-token',
            'token_type_hint' => 'access_token',
            'client_id' => $client->id,
        ]);

        $response->assertStatus(200);
    }

    public function test_revoke_endpoint_is_csrf_exempt(): void
    {
        [$client, , $raw] = $this->issue();

        $response = $this->post('/oauth/revoke', [
            'token' => $raw,
            'client_id' => $client->id,
        ], ['Accept' => 'application/json']);

        $this->assertNotEquals(419, $response->status());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=OAuthMcpRevokeEndpointTest`
Expected: FAIL — 404 on `/oauth/revoke`.

- [ ] **Step 3: Add CSRF exemption**

In `bootstrap/app.php`, add `'oauth/revoke'` to the `validateCsrfTokens` `except` array so it reads:

```php
        $middleware->validateCsrfTokens(except: [
            'webhooks/postmark/inbound/*',
            'oauth/register',
            'oauth/token',
            'oauth/revoke',
        ]);
```

- [ ] **Step 4: Add the route**

In `routes/web.php`, inside the `if (config('oauth-mcp.enabled', true))` block, add a new line after the existing `Route::post('oauth/token', ...)`:

```php
    Route::post('oauth/revoke', [OAuthServerController::class, 'revoke']);
```

- [ ] **Step 5: Add the `revoke()` controller method**

In `app/Http/Controllers/OAuthServerController.php`, add this public method next to `token()`:

```php
    /**
     * RFC 7009 OAuth 2.0 Token Revocation.
     * Always returns 200 regardless of whether the token existed.
     */
    public function revoke(Request $request): Response
    {
        $request->validate([
            'token' => 'required|string',
            'client_id' => 'required|string',
            'token_type_hint' => 'nullable|in:refresh_token,access_token',
        ]);

        $hint = (string) $request->input('token_type_hint', 'refresh_token');
        if ($hint === 'refresh_token') {
            $this->refreshTokens->revokeByRawToken(
                (string) $request->input('token'),
                'user',
                (string) $request->input('client_id'),
            );
        }

        return response()->json([], 200);
    }
```

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test --filter=OAuthMcpRevokeEndpointTest`
Expected: 4 passed.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/OAuthServerController.php routes/web.php bootstrap/app.php tests/Feature/OAuthMcpRevokeEndpointTest.php
git commit -m "feat(oauth-mcp): add RFC 7009 /oauth/revoke endpoint"
```

---

## Task 9: Advertise `refresh_token` grant + `revocation_endpoint` in well-known metadata

**Files:**
- Modify: `app/Http/Controllers/OAuthWellKnownController.php` (`authorizationServer()`)
- Test: `tests/Feature/OAuthMcpWellKnownRefreshMetadataTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/OAuthMcpWellKnownRefreshMetadataTest.php`:

```php
<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OAuthMcpWellKnownRefreshMetadataTest extends TestCase
{
    use RefreshDatabase;

    public function test_authorization_server_metadata_advertises_refresh_and_revoke(): void
    {
        config()->set('oauth-mcp.issuer', 'https://example.test');

        $response = $this->getJson('/.well-known/oauth-authorization-server');

        $response->assertStatus(200);
        $this->assertContains('refresh_token', $response->json('grant_types_supported'));
        $this->assertSame(
            'https://example.test/oauth/revoke',
            $response->json('revocation_endpoint')
        );
        $this->assertContains('none', $response->json('revocation_endpoint_auth_methods_supported'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=OAuthMcpWellKnownRefreshMetadataTest`
Expected: FAIL (`refresh_token` not in `grant_types_supported`).

- [ ] **Step 3: Update `authorizationServer()`**

In `app/Http/Controllers/OAuthWellKnownController.php`, replace the `authorizationServer()` method body with:

```php
    public function authorizationServer(): JsonResponse
    {
        $issuer = OAuthMcpJwtService::normalizeResourceUrl(rtrim((string) config('oauth-mcp.issuer'), '/'));

        return response()->json([
            'issuer' => $issuer,
            'authorization_endpoint' => $issuer.'/oauth/authorize',
            'token_endpoint' => $issuer.'/oauth/token',
            'registration_endpoint' => $issuer.'/oauth/register',
            'revocation_endpoint' => $issuer.'/oauth/revoke',
            'revocation_endpoint_auth_methods_supported' => ['none'],
            'code_challenge_methods_supported' => ['S256'],
            'scopes_supported' => [config('oauth-mcp.scope')],
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
        ]);
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=OAuthMcpWellKnownRefreshMetadataTest`
Expected: 1 passed.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/OAuthWellKnownController.php tests/Feature/OAuthMcpWellKnownRefreshMetadataTest.php
git commit -m "feat(oauth-mcp): advertise refresh_token grant + revocation_endpoint"
```

---

## Task 10: Connected Apps controller + routes

**Files:**
- Create: `app/Http/Controllers/Settings/ConnectedAppsController.php`
- Modify: `routes/web.php` (add routes next to existing `settings.mcp-keys.*`, inside the `auth` middleware group around line 219)
- Test: `tests/Feature/ConnectedAppsControllerTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/ConnectedAppsControllerTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\OauthMcpClient;
use App\Models\OauthMcpRefreshTokenFamily;
use App\Models\User;
use App\Services\OAuthMcpRefreshTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class ConnectedAppsControllerTest extends TestCase
{
    use RefreshDatabase;

    private function makeFamily(User $user): OauthMcpRefreshTokenFamily
    {
        $client = OauthMcpClient::create([
            'redirect_uris' => ['https://claude.ai/api/mcp/auth_callback'],
        ]);

        return app(OAuthMcpRefreshTokenService::class)
            ->issueForCodeExchange(
                $user, $client,
                'https://example.test/api/mcp', 'ideatub:mcp',
                Request::create('/oauth/token', 'POST'),
            )['family'];
    }

    public function test_index_requires_authentication(): void
    {
        $this->get('/settings/connected-apps')->assertRedirect('/login');
    }

    public function test_index_lists_only_own_active_families(): void
    {
        $user = User::factory()->create();
        $mine = $this->makeFamily($user);
        $other = $this->makeFamily(User::factory()->create());
        $revoked = $this->makeFamily($user);
        app(OAuthMcpRefreshTokenService::class)->revokeFamily($revoked, 'user');

        $response = $this->actingAs($user)->get('/settings/connected-apps');

        $response->assertStatus(200);
        $response->assertSee($mine->client->redirect_uris[0]);
        $response->assertDontSee($other->id);
        $response->assertDontSee($revoked->id);
    }

    public function test_destroy_revokes_own_family(): void
    {
        $user = User::factory()->create();
        $family = $this->makeFamily($user);

        $response = $this->actingAs($user)
            ->delete('/settings/connected-apps/'.$family->id);

        $response->assertRedirect('/settings/connected-apps');

        $family->refresh();
        $this->assertNotNull($family->revoked_at);
        $this->assertSame('user', $family->revoked_reason);
    }

    public function test_destroy_returns_403_on_cross_user(): void
    {
        $user = User::factory()->create();
        $other = $this->makeFamily(User::factory()->create());

        $response = $this->actingAs($user)
            ->delete('/settings/connected-apps/'.$other->id);

        $response->assertStatus(403);

        $other->refresh();
        $this->assertNull($other->revoked_at);
    }

    public function test_destroy_all_revokes_all_own_active_families(): void
    {
        $user = User::factory()->create();
        $f1 = $this->makeFamily($user);
        $f2 = $this->makeFamily($user);
        $other = $this->makeFamily(User::factory()->create());

        $response = $this->actingAs($user)->delete('/settings/connected-apps');
        $response->assertRedirect('/settings/connected-apps');

        $this->assertNotNull($f1->fresh()->revoked_at);
        $this->assertNotNull($f2->fresh()->revoked_at);
        $this->assertNull($other->fresh()->revoked_at);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=ConnectedAppsControllerTest`
Expected: FAIL — route `/settings/connected-apps` not defined.

- [ ] **Step 3: Create the controller**

Create `app/Http/Controllers/Settings/ConnectedAppsController.php`:

```php
<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\OauthMcpRefreshTokenFamily;
use App\Services\OAuthMcpRefreshTokenService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class ConnectedAppsController extends Controller
{
    public function __construct(private OAuthMcpRefreshTokenService $refreshTokens) {}

    public function index(Request $request): View
    {
        $families = OauthMcpRefreshTokenFamily::active()
            ->where('user_id', $request->user()->id)
            ->with('client')
            ->orderByDesc('last_used_at')
            ->orderByDesc('issued_at')
            ->get();

        return view('settings.connected-apps', [
            'families' => $families,
        ]);
    }

    public function destroy(Request $request, OauthMcpRefreshTokenFamily $family): RedirectResponse
    {
        if ($family->user_id !== $request->user()->id) {
            throw new AccessDeniedHttpException;
        }

        $this->refreshTokens->revokeFamily($family, 'user');

        return redirect()
            ->route('settings.connected-apps.index')
            ->with('success', 'App disconnected.');
    }

    public function destroyAll(Request $request): RedirectResponse
    {
        $count = 0;
        OauthMcpRefreshTokenFamily::active()
            ->where('user_id', $request->user()->id)
            ->get()
            ->each(function (OauthMcpRefreshTokenFamily $family) use (&$count) {
                $this->refreshTokens->revokeFamily($family, 'user');
                $count++;
            });

        return redirect()
            ->route('settings.connected-apps.index')
            ->with('success', $count === 1
                ? '1 connected app disconnected.'
                : "{$count} connected apps disconnected.");
    }
}
```

- [ ] **Step 4: Register routes**

In `routes/web.php`, find the `// MCP key management` block (around line 215). Directly beneath the four `settings.mcp-keys.*` routes add:

```php
    // OAuth MCP connected apps (Claude, ChatGPT, etc.)
    Route::get('/settings/connected-apps', [\App\Http\Controllers\Settings\ConnectedAppsController::class, 'index'])
        ->name('settings.connected-apps.index');
    Route::delete('/settings/connected-apps/{family}', [\App\Http\Controllers\Settings\ConnectedAppsController::class, 'destroy'])
        ->name('settings.connected-apps.destroy');
    Route::delete('/settings/connected-apps', [\App\Http\Controllers\Settings\ConnectedAppsController::class, 'destroyAll'])
        ->name('settings.connected-apps.destroy-all');
```

- [ ] **Step 5: Run test (expect missing view)**

Run: `php artisan test --filter=ConnectedAppsControllerTest`
Expected: FAIL — `View [settings.connected-apps] not found`. This is expected; the view is added in Task 11.

- [ ] **Step 6: Commit controller + routes (partial — view comes next)**

Skip commit for now; roll controller + view together in Task 11 to keep tests green between commits.

---

## Task 11: Connected Apps Blade view

**Files:**
- Create: `resources/views/settings/connected-apps.blade.php`

- [ ] **Step 1: Create the view**

Create `resources/views/settings/connected-apps.blade.php`:

```blade
@extends('layouts.idea')

@section('title', 'Connected apps — IdeaTub')

@section('content')
<div class="max-w-[720px] mx-auto px-6 pt-16 pb-24">
    <h1 class="text-[28px] font-semibold text-deep-indigo leading-snug mb-2">Connected apps</h1>
    <p class="text-sm text-slate-brand mb-8">OAuth-connected AI tools like Claude and ChatGPT. Revoking a connection forces the client to go through consent again.</p>

    @if (session('success'))
        <div class="mb-6 rounded-xl bg-neural-teal/10 border border-neural-teal/25 px-4 py-3 text-sm text-neural-teal">
            {{ session('success') }}
        </div>
    @endif

    <div class="rounded-2xl border border-memory-violet/20 bg-white/80 backdrop-blur p-6 shadow-[0_4px_24px_rgba(109,106,247,0.08)] mb-6">
        <div class="flex items-start justify-between gap-4 mb-4">
            <h2 class="text-lg font-semibold text-deep-indigo">Your connected apps</h2>
            @if ($families->isNotEmpty())
                <form method="POST" action="{{ route('settings.connected-apps.destroy-all') }}" onsubmit="return confirm('Disconnect all connected apps? Each will need to re-consent.');">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="text-xs font-medium text-red-600 hover:text-red-700 hover:underline">Disconnect all</button>
                </form>
            @endif
        </div>

        @if ($families->isEmpty())
            <p class="text-sm text-slate-brand">No OAuth-connected apps. Claude, ChatGPT, and other MCP clients you connect will appear here.</p>
        @else
            <ul class="space-y-4">
                @foreach ($families as $family)
                    @php
                        $host = optional(parse_url($family->client->redirect_uris[0] ?? '', PHP_URL_HOST)) ?: \Illuminate\Support\Str::limit($family->client_id, 16);
                    @endphp
                    <li class="flex flex-wrap items-start justify-between gap-4 rounded-xl border border-memory-violet/10 bg-white/60 px-4 py-3">
                        <div class="min-w-0 flex-1 space-y-1">
                            <p class="text-sm font-semibold text-deep-indigo">{{ $host }}</p>
                            <p class="text-[11px] text-slate-brand">Scope: <code class="bg-white/80 px-1 rounded">{{ $family->scope ?? 'ideatub:mcp' }}</code></p>
                            <p class="text-[11px] text-slate-brand">Connected {{ $family->issued_at->diffForHumans() }} · Expires {{ $family->absolute_expires_at->diffForHumans() }}</p>
                            @if ($family->last_used_at)
                                <p class="text-[11px] text-slate-brand/70">Last used {{ $family->last_used_at->diffForHumans() }}@if ($family->ip_address) · IP {{ $family->ip_address }}@endif</p>
                            @endif
                            @if ($family->user_agent)
                                <p class="text-[11px] text-slate-brand/60 truncate">{{ $family->user_agent }}</p>
                            @endif
                        </div>
                        <form method="POST" action="{{ route('settings.connected-apps.destroy', $family) }}" class="shrink-0 inline" onsubmit="return confirm('Disconnect this app? It will need to re-consent.');">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="text-xs font-medium text-red-600 hover:text-red-700 hover:underline">Disconnect</button>
                        </form>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</div>
@endsection
```

- [ ] **Step 2: Run the connected-apps test suite**

Run: `php artisan test --filter=ConnectedAppsControllerTest`
Expected: 5 passed.

- [ ] **Step 3: Commit controller + routes + view together**

```bash
git add app/Http/Controllers/Settings/ConnectedAppsController.php routes/web.php resources/views/settings/connected-apps.blade.php tests/Feature/ConnectedAppsControllerTest.php
git commit -m "feat(settings): Connected apps UI with per-family revocation"
```

---

## Task 12: Profile menu link to Connected apps

**Files:**
- Modify: `resources/views/settings/profile.blade.php` (around line 107, after the existing `MCP key` link)

- [ ] **Step 1: Add the link**

In `resources/views/settings/profile.blade.php`, insert a new `<a>` directly after the `MCP key` link so the block reads:

```blade
            <a href="{{ route('settings.mcp-keys.index') }}" class="text-slate-brand hover:text-memory-violet">MCP key</a>
            <a href="{{ route('settings.connected-apps.index') }}" class="text-slate-brand hover:text-memory-violet">Connected apps</a>
```

- [ ] **Step 2: Smoke-check the profile page loads**

Run: `php artisan test --filter=ConnectedAppsControllerTest::test_index_lists_only_own_active_families`
Expected: still passing (route helper resolves; no new test needed — route existence is already verified).

- [ ] **Step 3: Commit**

```bash
git add resources/views/settings/profile.blade.php
git commit -m "feat(settings): link Connected apps from profile menu"
```

---

## Task 13: Full suite regression + rollout notes

**Files:**
- Modify: `docs/superpowers/specs/2026-04-20-oauth-refresh-tokens-design.md` (append a short "Implemented in PLAN_COMMIT_SHA" note at the top, optional)

- [ ] **Step 1: Run the whole OAuth + Settings suite**

Run: `php artisan test --filter='OAuthMcp|ConnectedApps'`
Expected: All previous + all new tests pass. No warnings about unreplayable SQL (migration order).

- [ ] **Step 2: Run the full test suite**

Run: `php artisan test`
Expected: All tests pass. Watch for any unrelated breakage; fix only if introduced by this plan.

- [ ] **Step 3: Verify well-known metadata via curl (local)**

Start the app locally if not already running (`php artisan serve`). Run:

```bash
curl -s http://localhost:8000/.well-known/oauth-authorization-server | jq '{grant_types_supported, revocation_endpoint}'
```

Expected output includes `"refresh_token"` in `grant_types_supported` and a `revocation_endpoint` of `http://localhost:8000/oauth/revoke`.

- [ ] **Step 4: Manual end-to-end via PHPUnit-driven curl (optional smoke)**

Skip if CI-only. Otherwise re-issue an MCP key for Claude and verify Claude can reconnect once, then stays connected across the next hour (observe no new consent prompt).

- [ ] **Step 5: Rollout**

In production:

1. Deploy the code.
2. Run the migration: `php artisan migrate --force`.
3. If `OAUTH_MCP_ACCESS_TOKEN_TTL` was raised as an interim fix, set it back to `3600` in the deployment's environment and restart PHP-FPM.
4. Existing Claude/ChatGPT connections were issued before the refresh-token path existed and will need one final re-consent within the next hour of use. Communicate this if needed.
5. Monitor logs for `oauth.mcp.refresh.reuse_detected` (should be zero under normal operation) and for generic `oauth/token` 400s.

- [ ] **Step 6: Final commit (only if spec was updated)**

If you appended an "Implemented in PLAN_COMMIT_SHA" note to the spec:

```bash
git add docs/superpowers/specs/2026-04-20-oauth-refresh-tokens-design.md
git commit -m "docs(specs): mark OAuth MCP refresh tokens spec implemented"
```

Otherwise, skip. The plan's earlier commits are the deliverable.

---

## Plan Self-Review

**Spec coverage checklist (every Included item from the spec maps to a task):**

- Refresh-token issuance on auth-code grant → **Task 6**.
- `grant_type=refresh_token` with rotation + reuse detection → **Tasks 4, 5, 7**.
- RFC 7009 `/oauth/revoke` → **Task 8**.
- Well-known metadata updated → **Task 9**.
- Migration for families + tokens → **Task 1**.
- Reset `access_token_ttl_seconds` to 3600 → **Task 3 default** + **Task 13 Step 5**.
- New config keys → **Task 3**.
- `/settings/connected-apps` UI with list / revoke one / revoke all → **Tasks 10, 11, 12**.
- Tests for issuance, rotation, reuse, absolute lifetime, revoke endpoint, UI revocation, well-known → **Tasks 1, 2, 4, 5, 6, 7, 8, 9, 10**.

**Type / method signature consistency:**

- `OAuthMcpRefreshTokenService::issueForCodeExchange(User, OauthMcpClient, string, ?string, Request): array{family, raw}` — defined in Task 4, consumed in Task 6.
- `::rotate(string, string, string, ?string, Request): array{user, resource, scope, raw}` — defined in Task 5, consumed in Task 7.
- `::revokeFamily(OauthMcpRefreshTokenFamily, string): void` and `::revokeByRawToken(string, string, ?string): void` — defined in Task 5, consumed in Tasks 8 and 10.
- Controller constructor gains `OAuthMcpRefreshTokenService $refreshTokens` in Task 6; used in Tasks 7 and 8.
- Model scopes `OauthMcpRefreshTokenFamily::active()` and `OauthMcpRefreshToken::usable()` — defined in Task 2, consumed in Task 10.

**Placeholder scan:** None. All steps include complete code, exact file paths, and concrete commands.
