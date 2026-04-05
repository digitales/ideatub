# Security Fixes Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Resolve all findings from the 2026-04-05 security review, from C1 (stored XSS) through the low-severity hardening items.

**Architecture:** All changes are surgical — no new services or architectural patterns. The XSS fix adds a CommonMark config option; rate limiting uses Laravel's built-in `RateLimiter`/`throttle` middleware; security headers are a new single-responsibility middleware class; remaining items are one-to-two-line fixes.

**Tech Stack:** Laravel 12, PHP 8.2, PHPUnit (run tests with `php artisan test`), PostgreSQL.

**Spec:** `docs/superpowers/specs/2026-04-05-security-review-findings.md`

---

## Files Changed

| File | Change |
|------|--------|
| `app/Http/Controllers/SharedResearchViewController.php` | Add `html_input: strip` to CommonMarkConverter |
| `app/Http/Controllers/IdeaController.php` | Add `html_input: strip` to CommonMarkConverter |
| `app/Http/Controllers/HelpController.php` | Add `html_input: strip` to CommonMarkConverter |
| `app/Providers/AppServiceProvider.php` | Register login rate limiter |
| `routes/web.php` | Apply throttle to login, shared-research POST, OAuth register |
| `app/Http/Middleware/SecurityHeaders.php` | **New** — sets X-Frame-Options, X-Content-Type-Options, HSTS |
| `bootstrap/app.php` | Register SecurityHeaders middleware in web stack |
| `app/Http/Controllers/Api/McpController.php` | Remove `query('key')` auth method |
| `app/Http/Controllers/SocialAuthController.php` | Guard GitHub null email + fix orWhere |
| `app/Http/Controllers/Api/ThoughtsApiController.php` | Add `max:65535` to content field |
| `app/Http/Middleware/ValidatePostmarkInboundSecret.php` | Use `hash_equals` |
| `.env.example` | Set `APP_DEBUG=false`, `SESSION_ENCRYPT=true` |
| `app/Http/Controllers/McpKeyController.php` | Accept optional label from request |
| `app/Models/UserMcpKey.php` | Switch to HMAC-SHA256 for key hashing |
| `database/migrations/TIMESTAMP_rehash_mcp_keys.php` | **New** — delete all existing key hashes (force re-issue) |
| `tests/Feature/SecurityHeadersTest.php` | **New** |
| `tests/Feature/LoginRateLimitTest.php` | **New** |
| `tests/Feature/SharedResearchPasswordRateLimitTest.php` | **New** |
| `tests/Feature/ThoughtsApiTest.php` | Add content-size and MCP-key-in-URL tests |
| `tests/Feature/PostmarkInboundWebhookTest.php` | Already tests secret validation; add constant-time note |
| `tests/Feature/SocialAuthTest.php` | **New** — null email guard |
| `tests/Unit/Models/UserMcpKeyTest.php` | **New** — HMAC hash test |

---

## Task 1: Fix XSS — Add `html_input: strip` to all CommonMarkConverter usages

**Spec ref:** C1

Fixes stored XSS on the public shared research page and authenticated thought detail view by preventing CommonMark from emitting raw HTML from user content.

**Files:**
- Modify: `app/Http/Controllers/SharedResearchViewController.php`
- Modify: `app/Http/Controllers/IdeaController.php`
- Modify: `app/Http/Controllers/HelpController.php`
- Test: `tests/Feature/SharedResearchXssTest.php` (new)

- [ ] **Step 1: Write the failing XSS test**

Create `tests/Feature/SharedResearchXssTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\ResearchShare;
use App\Models\Thought;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SharedResearchXssTest extends TestCase
{
    use RefreshDatabase;

    public function test_script_tag_in_shared_research_is_stripped(): void
    {
        $user = User::factory()->create();
        $thought = Thought::factory()->create([
            'user_id' => $user->id,
            'content' => '<script>alert("xss")</script>',
            'source' => 'web',
        ]);
        $share = ResearchShare::factory()->create([
            'user_id' => $user->id,
            'thought_id' => $thought->id,
        ]);

        $response = $this->get('/r/' . $share->token);

        $response->assertStatus(200);
        $response->assertDontSee('<script>alert("xss")</script>', false);
        $response->assertDontSee('<script>', false);
    }

    public function test_javascript_href_in_shared_research_is_stripped(): void
    {
        $user = User::factory()->create();
        $thought = Thought::factory()->create([
            'user_id' => $user->id,
            'content' => '[click me](javascript:alert(1))',
            'source' => 'web',
        ]);
        $share = ResearchShare::factory()->create([
            'user_id' => $user->id,
            'thought_id' => $thought->id,
        ]);

        $response = $this->get('/r/' . $share->token);

        $response->assertStatus(200);
        $response->assertDontSee('javascript:alert', false);
    }
}
```

- [ ] **Step 2: Check ResearchShare factory exists; create if not**

```bash
php artisan make:factory ResearchShareFactory --model=ResearchShare
```

If the factory already exists at `database/factories/ResearchShareFactory.php`, skip this step. Check that it generates a `token` field: it should call `ResearchShare::generateToken()` or `Str::random(32)`.

- [ ] **Step 3: Run test to verify it fails**

```bash
php artisan test tests/Feature/SharedResearchXssTest.php
```

Expected: FAIL — the `<script>` tag appears in the response (CommonMark passes it through).

- [ ] **Step 4: Fix `SharedResearchViewController` — add `html_input: strip`**

In `app/Http/Controllers/SharedResearchViewController.php`, find every `new CommonMarkConverter` call (there is one in `renderReadonly`) and add config options:

```php
// Before:
$converter = new CommonMarkConverter;

// After:
$converter = new CommonMarkConverter(['html_input' => 'strip', 'allow_unsafe_links' => false]);
```

- [ ] **Step 5: Fix `IdeaController` — add `html_input: strip`**

In `app/Http/Controllers/IdeaController.php`, find every `new CommonMarkConverter` call (in the `show` method) and apply the same change:

```php
$converter = new CommonMarkConverter(['html_input' => 'strip', 'allow_unsafe_links' => false]);
```

- [ ] **Step 6: Fix `HelpController` — add `html_input: strip`**

In `app/Http/Controllers/HelpController.php`, in `loadExamplePrompts`:

```php
// Before:
$converter = new CommonMarkConverter;

// After:
$converter = new CommonMarkConverter(['html_input' => 'strip', 'allow_unsafe_links' => false]);
```

- [ ] **Step 7: Run tests to verify they pass**

```bash
php artisan test tests/Feature/SharedResearchXssTest.php
```

Expected: PASS.

- [ ] **Step 8: Run full suite to check for regressions**

```bash
php artisan test
```

Expected: all tests pass (or pre-existing failures only).

- [ ] **Step 9: Commit**

```bash
git add app/Http/Controllers/SharedResearchViewController.php \
        app/Http/Controllers/IdeaController.php \
        app/Http/Controllers/HelpController.php \
        tests/Feature/SharedResearchXssTest.php \
        database/factories/ResearchShareFactory.php
git commit -m "fix(security): strip raw HTML in CommonMark output to prevent XSS (C1)"
```

---

## Task 2: Add login rate limiting

**Spec ref:** H1

Prevents brute-force attacks against the email/password login endpoint.

**Files:**
- Modify: `app/Providers/AppServiceProvider.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/LoginRateLimitTest.php` (new)

- [ ] **Step 1: Write the failing rate-limit test**

Create `tests/Feature/LoginRateLimitTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class LoginRateLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('login:' . request()->ip());
    }

    public function test_too_many_login_attempts_returns_429(): void
    {
        User::factory()->create(['email' => 'user@example.com']);

        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', [
                'email' => 'user@example.com',
                'password' => 'wrong-password',
            ]);
        }

        $response = $this->post('/login', [
            'email' => 'user@example.com',
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(429);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
php artisan test tests/Feature/LoginRateLimitTest.php
```

Expected: FAIL — 6th attempt returns 422 (wrong password) not 429.

- [ ] **Step 3: Register a named login rate limiter**

In `app/Providers/AppServiceProvider.php`, add to the `boot()` method:

```php
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Http\Request;

// Inside boot():
RateLimiter::for('login', function (Request $request) {
    return Limit::perMinute(5)->by($request->ip());
});
```

- [ ] **Step 4: Apply throttle middleware to the login route**

In `routes/web.php`, update the `POST /login` route (it's inside the `guest` middleware group):

```php
Route::post('/login', [AuthenticatedSessionController::class, 'store'])
    ->middleware('throttle:login');
```

- [ ] **Step 5: Run test to verify it passes**

```bash
php artisan test tests/Feature/LoginRateLimitTest.php
```

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Providers/AppServiceProvider.php routes/web.php \
        tests/Feature/LoginRateLimitTest.php
git commit -m "fix(security): add login rate limiting — 5 attempts/min per IP (H1)"
```

---

## Task 3: Rate-limit shared research password and OAuth client registration

**Spec ref:** H2, L3

Prevents brute-force of share passwords and bulk OAuth client registration.

**Files:**
- Modify: `routes/web.php`
- Test: `tests/Feature/SharedResearchPasswordRateLimitTest.php` (new)

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/SharedResearchPasswordRateLimitTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\ResearchShare;
use App\Models\Thought;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class SharedResearchPasswordRateLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_too_many_password_attempts_returns_429(): void
    {
        $user = User::factory()->create();
        $thought = Thought::factory()->create(['user_id' => $user->id]);
        $share = ResearchShare::factory()->create([
            'user_id' => $user->id,
            'thought_id' => $thought->id,
            'password_hash' => bcrypt('correct'),
        ]);

        // Clear any stale rate limiter state
        RateLimiter::clear('shared-research-password:' . $share->token . ':' . request()->ip());

        for ($i = 0; $i < 10; $i++) {
            $this->post('/r/' . $share->token, ['password' => 'wrong']);
        }

        $response = $this->post('/r/' . $share->token, ['password' => 'wrong']);
        $response->assertStatus(429);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
php artisan test tests/Feature/SharedResearchPasswordRateLimitTest.php
```

Expected: FAIL — 11th attempt returns 401 not 429.

- [ ] **Step 3: Register rate limiter and apply to routes**

Add a rate limiter in `AppServiceProvider::boot()`:

```php
RateLimiter::for('shared-research-password', function (Request $request) {
    $token = $request->route('token') ?? 'unknown';
    return Limit::perMinutes(15, 10)->by($token . ':' . $request->ip());
});
```

In `routes/web.php`, add throttle to the shared research route (both GET and POST share the same route definition, so scope to POST):

```php
Route::get('/r/{token}', [SharedResearchViewController::class, 'show'])
    ->name('shared-research.show');
Route::post('/r/{token}', [SharedResearchViewController::class, 'show'])
    ->middleware('throttle:shared-research-password');
```

Also add throttle to OAuth dynamic client registration:

```php
Route::post('oauth/register', [OAuthServerController::class, 'register'])
    ->middleware('throttle:10,1');
```

- [ ] **Step 4: Run test to verify it passes**

```bash
php artisan test tests/Feature/SharedResearchPasswordRateLimitTest.php
```

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add routes/web.php app/Providers/AppServiceProvider.php \
        tests/Feature/SharedResearchPasswordRateLimitTest.php
git commit -m "fix(security): rate-limit shared research password and OAuth registration (H2, L3)"
```

---

## Task 4: Add security response headers middleware

**Spec ref:** H4

Adds `X-Frame-Options`, `X-Content-Type-Options`, and `Strict-Transport-Security` to all web responses.

**Files:**
- Create: `app/Http/Middleware/SecurityHeaders.php`
- Modify: `bootstrap/app.php`
- Test: `tests/Feature/SecurityHeadersTest.php` (new)

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/SecurityHeadersTest.php`:

```php
<?php

namespace Tests\Feature;

use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    public function test_web_response_includes_x_frame_options(): void
    {
        $response = $this->get('/welcome');
        $response->assertHeader('X-Frame-Options', 'DENY');
    }

    public function test_web_response_includes_x_content_type_options(): void
    {
        $response = $this->get('/welcome');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_web_response_includes_hsts(): void
    {
        $response = $this->get('/welcome');
        $response->assertHeader('Strict-Transport-Security', 'max-age=63072000; includeSubDomains');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
php artisan test tests/Feature/SecurityHeadersTest.php
```

Expected: FAIL — headers not present.

- [ ] **Step 3: Create `SecurityHeaders` middleware**

Create `app/Http/Middleware/SecurityHeaders.php`:

```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Strict-Transport-Security', 'max-age=63072000; includeSubDomains');

        return $response;
    }
}
```

- [ ] **Step 4: Register in the web middleware stack**

In `bootstrap/app.php`, add to the `web` append list:

```php
$middleware->web(append: [
    \App\Http\Middleware\CheckOperationLimit::class,
    \App\Http\Middleware\SecurityHeaders::class,  // add this line
]);
```

- [ ] **Step 5: Run test to verify it passes**

```bash
php artisan test tests/Feature/SecurityHeadersTest.php
```

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Middleware/SecurityHeaders.php bootstrap/app.php \
        tests/Feature/SecurityHeadersTest.php
git commit -m "fix(security): add X-Frame-Options, X-Content-Type-Options, HSTS headers (H4)"
```

---

## Task 5: Remove MCP API key from URL query string

**Spec ref:** H3

API keys passed via `?key=` appear in server access logs and proxy logs. Remove this auth method; the `x-ideatub-key` header remains.

**Files:**
- Modify: `app/Http/Controllers/Api/McpController.php`
- Test: `tests/Feature/McpKeySettingsTest.php` (existing — add a test)

- [ ] **Step 1: Write the failing test**

Open `tests/Feature/McpKeySettingsTest.php` and add:

```php
public function test_mcp_key_in_query_string_is_rejected(): void
{
    $user = User::factory()->create();
    $plainKey = 'ideatub_' . str_repeat('a', 32);
    $user->userMcpKeys()->create([
        'key_hash' => \App\Models\UserMcpKey::hashKey($plainKey),
        'label' => 'Test key',
    ]);

    $response = $this->postJson('/api/mcp?key=' . $plainKey, [
        'jsonrpc' => '2.0',
        'method' => 'thought_stats',
        'id' => 1,
    ]);

    $response->assertStatus(401);
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
php artisan test tests/Feature/McpKeySettingsTest.php::test_mcp_key_in_query_string_is_rejected
```

Expected: FAIL — query-string key currently works (returns 200).

- [ ] **Step 3: Remove query-string key lookup from `McpController::resolveUser`**

In `app/Http/Controllers/Api/McpController.php`, find `resolveUser` (~line 404) and change:

```php
// Before:
$key = $request->query('key') ?? $request->header('x-ideatub-key');

// After:
$key = $request->header('x-ideatub-key');
```

Also remove the `GET /api/mcp` response that advertises `?key=...`:

```php
// Before (in show()):
'auth' => 'Send key via ?key=... or x-ideatub-key header',

// After:
'auth' => 'Send key via x-ideatub-key header or OAuth Bearer token',
```

- [ ] **Step 4: Run test to verify it passes**

```bash
php artisan test tests/Feature/McpKeySettingsTest.php
```

Expected: all tests in the file pass.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Api/McpController.php \
        tests/Feature/McpKeySettingsTest.php
git commit -m "fix(security): reject MCP API key in query string — header only (H3)"
```

---

## Task 6: Fix GitHub OAuth null email

**Spec ref:** M3

When a GitHub user's email is private, `$githubUser->email` is null. This causes an `orWhere('email', null)` SQL clause that can match unintended rows, and a subsequent `User::create(['email' => null])` that violates the DB NOT NULL constraint.

**Files:**
- Modify: `app/Http/Controllers/SocialAuthController.php`
- Test: `tests/Feature/SocialAuthTest.php` (new)

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/SocialAuthTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Facades\Socialite;
use Tests\TestCase;

class SocialAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_github_callback_with_null_email_redirects_to_login_with_error(): void
    {
        $fakeGithubUser = (object) [
            'id' => '12345',
            'name' => 'Test User',
            'nickname' => 'testuser',
            'email' => null,
        ];

        Socialite::shouldReceive('driver->user')->andReturn($fakeGithubUser);

        $response = $this->get('/auth/github/callback');

        $response->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_github_callback_with_null_email_does_not_create_user(): void
    {
        $fakeGithubUser = (object) [
            'id' => '99999',
            'name' => 'Ghost User',
            'nickname' => 'ghost',
            'email' => null,
        ];

        Socialite::shouldReceive('driver->user')->andReturn($fakeGithubUser);

        $this->get('/auth/github/callback');

        $this->assertDatabaseMissing('users', ['github_id' => '99999']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
php artisan test tests/Feature/SocialAuthTest.php
```

Expected: FAIL — null email currently causes an exception or unexpected redirect.

- [ ] **Step 3: Fix `handleGithubCallback`**

In `app/Http/Controllers/SocialAuthController.php`, replace `handleGithubCallback` with:

```php
public function handleGithubCallback()
{
    try {
        $githubUser = Socialite::driver('github')->user();

        if (empty($githubUser->email)) {
            return redirect()->route('login')
                ->with('error', 'Your GitHub account must have a public email address. Please add one in GitHub Settings → Profile.');
        }

        $query = User::where('github_id', $githubUser->id);
        if ($githubUser->email !== null) {
            $query->orWhere('email', $githubUser->email);
        }
        $user = $query->first();

        if ($user) {
            if (!$user->github_id) {
                $user->update(['github_id' => $githubUser->id]);
            }
        } else {
            $user = User::create([
                'name' => $githubUser->name ?? $githubUser->nickname,
                'email' => $githubUser->email,
                'github_id' => $githubUser->id,
                'email_verified_at' => now(),
            ]);
        }

        Auth::login($user, true);

        return redirect()->intended(route('idea.index'));
    } catch (\Exception $e) {
        return redirect()->route('login')->with('error', 'Unable to login with GitHub. Please try again.');
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

```bash
php artisan test tests/Feature/SocialAuthTest.php
```

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/SocialAuthController.php \
        tests/Feature/SocialAuthTest.php
git commit -m "fix(security): guard GitHub OAuth against null email and fix orWhere null match (M3)"
```

---

## Task 7: Add content size limit on API endpoints

**Spec ref:** M4

The web controller limits `content` to 65535 chars; the API and MCP endpoints do not, allowing very large payloads that could exhaust memory during embedding computation.

**Files:**
- Modify: `app/Http/Controllers/Api/ThoughtsApiController.php`
- Modify: `app/Http/Controllers/Api/McpController.php`
- Test: `tests/Feature/ThoughtsApiTest.php` (existing — add test)

- [ ] **Step 1: Write the failing test**

Open `tests/Feature/ThoughtsApiTest.php` and add:

```php
public function test_store_thought_with_content_over_limit_returns_422(): void
{
    $user = User::factory()->create();
    $token = $this->validBearerToken($user);  // helper already in the file

    $response = $this->withHeader('Authorization', 'Bearer ' . $token)
        ->postJson('/api/thoughts', [
            'content' => str_repeat('a', 65536),
        ]);

    $response->assertStatus(422);
}
```

(If the test file doesn't have a `validBearerToken` helper, look at how existing bearer-auth tests create one and follow the same pattern.)

- [ ] **Step 2: Run test to verify it fails**

```bash
php artisan test tests/Feature/ThoughtsApiTest.php::test_store_thought_with_content_over_limit_returns_422
```

Expected: FAIL — 65536-char content currently succeeds.

- [ ] **Step 3: Add `max:65535` to `ThoughtsApiController::store`**

In `app/Http/Controllers/Api/ThoughtsApiController.php`, update the validation rule:

```php
// Before:
'content' => 'required|string',

// After:
'content' => 'required|string|max:65535',
```

- [ ] **Step 4: Add content limits to MCP `captureThought` and `capturePlan` dispatch methods**

In `app/Http/Controllers/Api/McpController.php`, find the private methods that handle `capture_thought`, `capture_plan`, and meeting aliases. Each one reads `$params['content']` without a size check. Add a guard at the top of each method (or in a shared helper):

```php
// At the top of dispatch methods that accept content:
if (isset($params['content']) && mb_strlen((string) $params['content']) > 65535) {
    throw new \InvalidArgumentException('Content must be 65535 characters or fewer.');
}
```

The methods to update: `captureThought`, `capturePlan`, `captureMeeting` (and its aliases route to `captureMeeting`), `captureIdea`. Each has a `$params['content']` read — add the check before any processing.

- [ ] **Step 5: Run tests to verify they pass**

```bash
php artisan test tests/Feature/ThoughtsApiTest.php
```

Expected: all tests pass.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Api/ThoughtsApiController.php \
        app/Http/Controllers/Api/McpController.php \
        tests/Feature/ThoughtsApiTest.php
git commit -m "fix(security): add 65535-char content limit on API and MCP endpoints (M4)"
```

---

## Task 8: Use constant-time comparison in Postmark webhook middleware

**Spec ref:** M5

Timing-safe comparison using `hash_equals` prevents theoretical timing-based token enumeration.

**Files:**
- Modify: `app/Http/Middleware/ValidatePostmarkInboundSecret.php`

No new tests needed — `tests/Feature/PostmarkInboundWebhookTest.php` already tests that invalid tokens return 404 and valid tokens proceed. The change is a one-liner with no behavioural difference.

- [ ] **Step 1: Apply the fix**

In `app/Http/Middleware/ValidatePostmarkInboundSecret.php`, change line 16:

```php
// Before:
if (! is_string($token) || $token === '' || $secret === null || $secret === '' || $token !== $secret) {

// After:
if (! is_string($token) || $token === '' || $secret === null || $secret === '' || ! hash_equals($secret, $token)) {
```

- [ ] **Step 2: Run existing tests to confirm no regression**

```bash
php artisan test tests/Feature/PostmarkInboundWebhookTest.php
```

Expected: all pass.

- [ ] **Step 3: Commit**

```bash
git add app/Http/Middleware/ValidatePostmarkInboundSecret.php
git commit -m "fix(security): use hash_equals for Postmark webhook token comparison (M5)"
```

---

## Task 9: Hardening bundle — env, session encryption, MCP key label

**Spec ref:** L1, L2 (advisory), L4, M2

Small one-off fixes that don't warrant individual tasks.

**Files:**
- Modify: `.env.example`
- Modify: `app/Http/Controllers/McpKeyController.php`

- [ ] **Step 1: Fix `.env.example`**

In `.env.example`, change:
```
APP_DEBUG=true
```
to:
```
APP_DEBUG=false
```

Add (or update if present):
```
SESSION_ENCRYPT=true
```

- [ ] **Step 2: Accept a user-supplied MCP key label**

In `app/Http/Controllers/McpKeyController.php`, update `store`:

```php
public function store(Request $request): RedirectResponse
{
    $this->authorize('create', UserMcpKey::class);

    $validated = $request->validate([
        'label' => 'nullable|string|max:64',
    ]);

    $plainKey = 'ideatub_'.Str::random(32);
    $keyHash = UserMcpKey::hashKey($plainKey);

    $request->user()->userMcpKeys()->create([
        'key_hash' => $keyHash,
        'label' => $validated['label'] ?? 'Created in IdeaTub',
    ]);

    return redirect()
        ->route('settings.mcp-keys.index')
        ->with('new_mcp_key', $plainKey);
}
```

- [ ] **Step 3: Run existing MCP key tests**

```bash
php artisan test tests/Feature/McpKeySettingsTest.php
```

Expected: all pass.

- [ ] **Step 4: Commit**

```bash
git add .env.example app/Http/Controllers/McpKeyController.php
git commit -m "fix(security): set APP_DEBUG=false, SESSION_ENCRYPT=true in env.example; accept MCP key label (L1, L4, M2)"
```

---

## Task 10: Switch MCP key hashing to HMAC-SHA256

**Spec ref:** M1

**⚠️ Destructive migration:** All existing MCP keys must be deleted because their SHA-256 hashes cannot be converted to HMAC hashes without the original plain keys. Users will need to issue new keys. **Deploy this in a maintenance window and notify users in advance.**

**Files:**
- Modify: `app/Models/UserMcpKey.php`
- Create: `database/migrations/TIMESTAMP_rehash_mcp_keys_to_hmac.php`
- Test: `tests/Unit/Models/UserMcpKeyTest.php` (new)

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Models/UserMcpKeyTest.php`:

```php
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

        // Plain SHA-256 would equal this:
        $plainSha256 = hash('sha256', $plain);
        $this->assertNotEquals($plainSha256, $hash, 'hashKey must not produce plain SHA-256');
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
```

- [ ] **Step 2: Run test to verify it fails**

```bash
php artisan test tests/Unit/Models/UserMcpKeyTest.php
```

Expected: the first assertion fails — current implementation uses plain SHA-256.

- [ ] **Step 3: Update `UserMcpKey::hashKey`**

In `app/Models/UserMcpKey.php`, update `hashKey`:

```php
public static function hashKey(string $plainKey): string
{
    return hash_hmac('sha256', $plainKey, config('app.key'));
}
```

- [ ] **Step 4: Create migration to delete all existing keys**

```bash
php artisan make:migration delete_all_mcp_keys_for_hmac_rehash
```

In the generated migration file:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Existing SHA-256 hashes are incompatible with the new HMAC-SHA256 scheme.
        // Users must issue new MCP keys after this migration runs.
        DB::table('user_mcp_keys')->delete();
    }

    public function down(): void
    {
        // Keys cannot be restored — they were hashed and the originals are not stored.
    }
};
```

- [ ] **Step 5: Run the migration locally**

```bash
php artisan migrate
```

Expected: migration runs, existing keys deleted.

- [ ] **Step 6: Run tests**

```bash
php artisan test tests/Unit/Models/UserMcpKeyTest.php
php artisan test tests/Feature/McpKeySettingsTest.php
```

Expected: all pass.

- [ ] **Step 7: Run full test suite**

```bash
php artisan test
```

Expected: all pass (or pre-existing failures only).

- [ ] **Step 8: Commit**

```bash
git add app/Models/UserMcpKey.php \
        database/migrations/*rehash_mcp_keys* \
        tests/Unit/Models/UserMcpKeyTest.php
git commit -m "fix(security): switch MCP key hashing to HMAC-SHA256; migrate deletes existing keys (M1)

⚠️ Breaking: all users must issue new MCP keys after this migration.
Deploy in a maintenance window. Existing keys are invalidated."
```

---

## Deployment Notes

- **Task 10 (HMAC migration)** must be announced to users before deployment. All existing MCP API keys are invalidated. Consider posting an in-app notice before the deploy.
- **Task 4 (H4) partially resolves the spec finding.** `X-Frame-Options`, `X-Content-Type-Options`, and HSTS are implemented. `Content-Security-Policy` is intentionally deferred — it requires iterating on a report-only policy until violations reach zero before enforcing. Track this as a follow-on task: add `Content-Security-Policy-Report-Only` first, monitor the report endpoint for a week, then enforce.
- After deploying Task 4 (security headers), verify via browser DevTools that headers appear on all web responses. HSTS should only be enabled if the app is served exclusively over HTTPS.
- After deploying Task 2 (login rate limiting), test the login flow manually to confirm the error message is user-friendly on 429.
- `SESSION_ENCRYPT=true` from Task 9 will invalidate all existing sessions on next request — users will be logged out. Schedule accordingly.
