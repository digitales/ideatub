# Security Review Findings — IdeaTub

**Date:** 2026-04-05
**Scope:** Breadth-first scan of authentication, authorization, webhook handling, API input validation, sensitive data handling, and security headers.
**Method:** Static code review. No dynamic testing or infrastructure assessment.

---

## Summary

| Severity | Count |
|----------|-------|
| Critical | 1 |
| High     | 4 |
| Medium   | 5 |
| Low      | 4 |

---

## Critical

### C1 — Stored XSS on public shared research page via unescaped CommonMark HTML

**File:** `app/Http/Controllers/SharedResearchViewController.php:83–91`, `resources/views/shared_research/readonly.blade.php:9,16`

`CommonMarkConverter` is instantiated with no options and no HTML sanitizer extension. By spec, CommonMark allows raw HTML pass-through — any `<script>`, `<iframe>`, or event-attribute HTML in thought content is emitted verbatim into the rendered output. That HTML is then rendered via `{!! $root_html !!}` and `{!! $section->content_html !!}`, bypassing Blade's auto-escaping.

The `/r/{token}` route is **publicly accessible without authentication**. An attacker who can create or influence the content of a shareable thought (via the web form, MCP API, or the email/Jira ingest pipeline) can craft a share link that executes arbitrary JavaScript in any visitor's browser.

**Also affected (authenticated context):** `resources/views/idea/show.blade.php:35,37` and `resources/views/idea/partials/research_content.blade.php:3,11` — `IdeaController::renderDemoSafeMarkdown` also uses a bare `CommonMarkConverter` with no `html_input` restriction. This is lower risk (requires an authenticated session) but is the same class of issue. The inbox view (`inbox/index.blade.php`) uses `html_input: 'strip'` and is already protected.

**Remediation:**
- Add the `DisallowedRawHtmlExtension` from CommonMark to strip raw HTML before output:
  ```php
  use League\CommonMark\Extension\DisallowedRawHtml\DisallowedRawHtmlExtension;
  $converter = new CommonMarkConverter([]);
  $converter->getEnvironment()->addExtension(new DisallowedRawHtmlExtension());
  ```
- Or pass `html_input: 'strip'` via environment configuration (the same mechanism already used in the inbox view). This is simpler:
  ```php
  $converter = new CommonMarkConverter(['html_input' => 'strip', 'allow_unsafe_links' => false]);
  ```
- Or run the HTML output through `ezyang/htmlpurifier` before passing to Blade.
- Apply to every bare `CommonMarkConverter` instantiation in `SharedResearchViewController` and `IdeaController`. `HelpController` loads from static files in the repo so the risk is lower but it should still be fixed for consistency.

---

## High

### H1 — No brute-force protection on email/password login

**File:** `app/Http/Controllers/Auth/AuthenticatedSessionController.php:23–39`, `routes/web.php:94`

There is no `throttle` middleware and no `RateLimiter` configuration on the `POST /login` route. The login endpoint accepts unlimited attempts. There is also no `RouteServiceProvider` defining a login rate limiter. An attacker can enumerate credentials at full network speed.

**Remediation:**
Apply Laravel's built-in throttle to the login route:
```php
Route::post('/login', [AuthenticatedSessionController::class, 'store'])
    ->middleware('throttle:5,1');  // 5 attempts per minute
```
Or configure a named rate limiter in `AppServiceProvider::boot()` via `RateLimiter::for('login', ...)` and reference it as `throttle:login`.

---

### H2 — No brute-force protection on shared research password endpoint

**File:** `app/Http/Controllers/SharedResearchViewController.php:49–60`, `routes/web.php:52`

`POST /r/{token}` verifies a user-supplied password against a bcrypt hash with no rate limiting. An attacker who obtains a share URL can brute force its optional password at bcrypt speed (~100–1000 hashes/sec per thread depending on cost factor).

**Remediation:**
Apply throttle middleware to the route or add a per-token `RateLimiter` check inside the controller (e.g. 10 attempts per 15 minutes keyed by `token` + IP).

---

### H3 — MCP API key accepted in URL query string

**File:** `app/Http/Controllers/Api/McpController.php:404`

```php
$key = $request->query('key') ?? $request->header('x-ideatub-key');
```

When passed via `?key=...`, the API key appears in server access logs, Nginx/proxy logs, browser history, and any third-party analytics. Header transmission (`x-ideatub-key`) is safe; query-string transmission is not.

**Remediation:**
Remove `$request->query('key')` from `resolveUser()`. Accept keys only via the `x-ideatub-key` header (or `Authorization: Bearer`). Update client documentation accordingly.

---

### H4 — No security response headers (CSP, X-Frame-Options, HSTS, X-Content-Type-Options)

**File:** `bootstrap/app.php` (middleware stack)

No `Content-Security-Policy`, `X-Frame-Options`, `Strict-Transport-Security`, or `X-Content-Type-Options` headers are configured anywhere in the application. Absent CSP increases the exploitability of the XSS findings above. Absent `X-Frame-Options: DENY` allows clickjacking. Absent HSTS allows SSL-strip downgrade attacks.

**Remediation:**
Add a `SecurityHeaders` middleware (or use `spatie/laravel-csp`) in the global web middleware stack. Minimum headers:
```php
$response->header('X-Frame-Options', 'DENY');
$response->header('X-Content-Type-Options', 'nosniff');
$response->header('Strict-Transport-Security', 'max-age=63072000; includeSubDomains');
```
For CSP, start with `Content-Security-Policy-Report-Only`, iterate until violation reports drop to zero, then switch to `Content-Security-Policy`.

---

## Medium

### M1 — MCP API key hashed with plain SHA-256 (no HMAC)

**File:** `app/Models/UserMcpKey.php:59–62`

```php
return hash(self::KEY_HASH_ALGO, $plainKey);  // KEY_HASH_ALGO = 'sha256'
```

Keys are hashed with plain SHA-256 without a server-side secret. If the `user_mcp_keys` table is exfiltrated, the hashes can be attacked with a GPU. The key format `ideatub_` + 32 chars from `Str::random(32)` (base62, ~190 bits entropy) makes brute-force computationally infeasible in practice, but using HMAC-SHA256 with `APP_KEY` is better practice and costs nothing.

**Remediation:**
```php
return hash_hmac('sha256', $plainKey, config('app.key'));
```
Requires a one-time migration to re-hash existing keys (users must re-issue keys, or migrate can store both hashes during transition).

---

### M2 — Session data not encrypted at rest

**File:** `config/session.php:50`

```php
'encrypt' => env('SESSION_ENCRYPT', false),
```

Session data is stored in the `sessions` database table in plaintext by default. If the database is accessed without application context (e.g. direct DB dump), all session data is readable. The session cookie itself is encrypted, but the server-side record is not.

**Remediation:**
Set `SESSION_ENCRYPT=true` in production `.env`. This has a small performance overhead but protects session data at rest.

---

### M3 — GitHub OAuth: null email can cause registration failure or user confusion

**File:** `app/Http/Controllers/SocialAuthController.php:70–94`

GitHub allows users to keep their email private. In that case `$githubUser->email` is `null`. The code does:
```php
User::where('github_id', $githubUser->id)->orWhere('email', $githubUser->email)
```
When `$githubUser->email` is null, this becomes `WHERE github_id = X OR email IS NULL`, which could accidentally match any user with no email set. After that, a new user is created with `email = null`, which will fail with a DB constraint violation if `email` has a `NOT NULL` constraint (turning into a silent catch → generic login error).

**Remediation:**
Two fixes needed. First, guard against null email before lookup/create:
```php
if (empty($githubUser->email)) {
    return redirect()->route('login')
        ->with('error', 'GitHub account must have a public email. Please set one in GitHub settings.');
}
```
Second, make the `orWhere` conditional so it only fires when email is non-null (otherwise `orWhere('email', null)` becomes `OR email IS NULL` and can match unintended rows):
```php
$query = User::where('github_id', $githubUser->id);
if ($githubUser->email !== null) {
    $query->orWhere('email', $githubUser->email);
}
$user = $query->first();
```

---

### M4 — No payload size limit on thought content via API

**File:** `app/Http/Controllers/Api/ThoughtsApiController.php:113`, `app/Http/Controllers/Api/McpController.php` (dispatch methods)

The `content` field in `POST /api/thoughts` is validated as `'required|string'` with no `max:` rule. Very large payloads can cause high memory usage during embedding computation (via OpenRouter) and database write, and could time out or exhaust resources. The web controller caps content at `max:65535`; the API doesn't.

**Remediation:**
Add `max:65535` (or a similar limit matching OpenRouter's token limits) to the content rule in `ThoughtsApiController::store` and to the params validation in the corresponding MCP `dispatch` methods.

---

### M5 — Postmark webhook token comparison is not constant-time

**File:** `app/Http/Middleware/ValidatePostmarkInboundSecret.php:16`

```php
if (... || $token !== $secret) {
```

String comparison with `!==` is not constant-time and is technically vulnerable to timing attacks. Practical exploitability is near-zero (network jitter dominates) but it's trivial to fix.

**Remediation:**
```php
if (... || !hash_equals($secret, $token)) {
```

---

## Low

### L1 — `.env.example` sets `APP_DEBUG=true`

**File:** `.env.example`

The example file ships with `APP_DEBUG=true`. If a developer copies this example to create a production `.env` without changing the value, detailed exception stack traces (including file paths, class names, and local variable values) are exposed to end users.

**Remediation:**
Set `APP_DEBUG=false` in `.env.example`. Add `APP_DEBUG=false` to any production deployment checklist.

---

### L2 — No email verification required before accessing authenticated routes

**File:** `app/Http/Controllers/Auth/RegisteredUserController.php:28–45`, `routes/web.php`

New users are created via `User::create(...)` and immediately logged in without requiring email verification. Social auth users (`handleGoogleCallback`, `handleGithubCallback`) have `email_verified_at` set on creation, but email/password registrations do not enforce `Illuminate\Auth\Middleware\EnsureEmailIsVerified` on authenticated routes.

**Remediation:**
Apply `verified` middleware to authenticated routes if email verification is a product requirement. This is an optional control — acceptable to leave off if open registration is intentional — but worth a deliberate decision.

---

### L3 — OAuth dynamic client registration open to any caller

**File:** `app/Http/Controllers/OAuthServerController.php:24–43`

`POST /oauth/register` (RFC 7591 dynamic client registration) is unauthenticated and rate-limited only by the global web throttle (which is Laravel's default: none unless configured). Any caller who knows the endpoint and provides a valid allowed redirect host can register a new client. The allowlist check in `normalizeRedirectUris` limits the blast radius, but bulk registration of clients could pollute the `oauth_mcp_clients` table.

**Remediation:**
Apply a throttle (e.g. `throttle:10,1`) to `POST /oauth/register`. Consider whether initial registration token validation is appropriate for the use case.

---

### L4 — MCP key label hardcoded; no user-supplied label validation

**File:** `app/Http/Controllers/McpKeyController.php:44`

```php
'label' => 'Created in IdeaTub',
```

All keys get the same label, making it impossible to distinguish keys from different clients if a user has multiple. Minor UX issue but also means a revoked key from a compromised client can't be identified if multiple keys exist.

**Remediation:**
Accept an optional `label` from the request with `max:64` validation and store it. Fall back to `'Created in IdeaTub'` if none supplied.

---

## Not Found / Confirmed OK

- **SQL injection:** All DB queries use Eloquent parameterised queries or `whereRaw` with bound parameters (`['embedding <=> ?::vector <= ?', [$vector, $maxDistance]]`). No raw string interpolation into SQL found.
- **CSRF:** Enabled globally. Only exemption is the Postmark inbound webhook, which has its own secret-based auth.
- **Stripe webhook signature:** `WebhookController` extends Laravel Cashier's `CashierController`, which validates Stripe's `Stripe-Signature` header.
- **JWT validation:** `OAuthMcpJwtService::verifyAccessToken` checks `aud`, `iss`, and expiry via `firebase/php-jwt`. Algorithm is RS256 (asymmetric). No `alg: none` bypass possible.
- **Authorization on owned resources:** Policies (`ThoughtPolicy`, `UserMcpKeyPolicy`, `ResearchSharePolicy`) correctly gate on `user_id === $user->id`. Controllers invoke `$this->authorize(...)` appropriately for owned resources.
- **Password hashing:** BCrypt via Laravel's `hashed` cast and `Hash::make()`. No plaintext storage.
- **Email credential encryption:** `MailAccount::credentials_json` uses `encrypted:array` cast (Laravel envelope encryption with APP_KEY).
- **OAuth PKCE:** S256 challenge method enforced. Code verifier validated with `hash_equals`. Authorization codes expire (600s TTL, `used_at` set on use). One-time use enforced.
- **Open redirect:** OAuth consent redirect validated against client's registered URIs. No unvalidated `redirect_uri` usage.
- **Stripe webhook CSRF:** The Stripe webhook route (`POST /stripe/webhook`) is not listed in the `validateCsrfTokens(except: [...])` in `bootstrap/app.php`. Laravel Cashier's `WebhookController` base class marks its route group with `withoutMiddleware(VerifyCsrfToken::class)` internally, which overrides the global CSRF check. The webhook has been working in production, confirming this bypass is in effect. Stripe's own HMAC-SHA256 signature verification (`Stripe-Signature` header) provides equivalent or stronger protection.
- **Inbox email body XSS:** `resources/views/inbox/index.blade.php:54` renders email bodies via `Str::markdown($body, ['html_input' => 'strip', 'allow_unsafe_links' => false])`. The `html_input: strip` option instructs CommonMark's HTML renderer to remove all raw HTML before output. This is the correct mitigation and is already applied.

---

## Recommended Priority Order

1. **C1** — Fix XSS in CommonMark output (public shared page is immediately exploitable by any visitor)
2. **H1** — Add login rate limiting
3. **H2** — Add shared research password rate limiting
4. **H4** — Add security response headers (CSP + HSTS + frame options)
5. **H3** — Remove MCP key from query string
6. **M3** — Guard GitHub null email (both the guard and the conditional orWhere)
7. **M4** — Add content size limit on API
8. Remaining medium and low items in any order
