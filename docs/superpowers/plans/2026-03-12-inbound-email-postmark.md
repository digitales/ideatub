# Inbound email (Postmark) — Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let users “email a thought into IdeaTub” via Postmark inbound: one webhook endpoint (secret path), user resolution by primary or added inbound addresses, thoughts created with `source = 'email'` and attachment names in metadata; unmatched emails stored for later analysis.

**Architecture:** New tables `user_inbound_addresses` and `unmatched_inbound_emails`; a single `POST /webhooks/postmark/inbound/{token}` route protected by token-in-path; a service class to parse Postmark JSON, resolve user, enforce idempotency, and either create a Thought or store in unmatched; settings UI at `/settings/inbound-emails` (add/remove inbound addresses, show capture address) following MCP keys pattern.

**Tech Stack:** Laravel 12, Blade, existing OpenRouterService and Thought model. No new frontend dependencies.

**Spec:** `docs/superpowers/specs/2026-03-12-inbound-email-postmark-design.md`

---

## File structure

| File | Responsibility |
|------|----------------|
| `database/migrations/YYYY_MM_DD_HHMMSS_create_user_inbound_addresses_table.php` | Create `user_inbound_addresses` (user_id, email unique, timestamps). |
| `database/migrations/YYYY_MM_DD_HHMMSS_create_unmatched_inbound_emails_table.php` | Create `unmatched_inbound_emails` (message_id unique, from_email, to_email, subject, body_text, received_at, payload_json nullable, timestamps). |
| `app/Models/UserInboundAddress.php` | Model; belongsTo User; fillable user_id, email; normalise email on set. |
| `app/Models/UnmatchedInboundEmail.php` | Model; fillable message_id, from_email, to_email, subject, body_text, received_at, payload_json. |
| `app/Models/User.php` | Add `userInboundAddresses()` hasMany relationship. |
| `app/Services/PostmarkInboundService.php` | Process Postmark JSON: body text, resolve user (email + inbound addresses), idempotency by MessageID, create Thought or UnmatchedInboundEmail; return void. |
| `app/Http/Middleware/ValidatePostmarkInboundSecret.php` | Check route param `token` === config('services.postmark_inbound.webhook_secret'); else 404. |
| `app/Http/Controllers/PostmarkInboundController.php` | No auth; parse JSON, call PostmarkInboundService, return 200 or 4xx/5xx. |
| `routes/web.php` | Register webhook route (no auth); register settings routes (auth). |
| `config/services.php` | Add `postmark_inbound` key with `webhook_secret` from env. |
| `app/Http/Controllers/InboundEmailController.php` | index (list primary + inbound addresses, capture URL); store (add address); destroy (remove address). |
| `app/Policies/UserInboundAddressPolicy.php` | viewAny/create true; view/delete only if address belongs to user. |
| `resources/views/settings/inbound-emails.blade.php` | List primary email + inbound addresses; add form; delete per address; show capture address and help. |
| `resources/views/layouts/idea.blade.php` | Add “Inbound email” link in avatar dropdown (next to MCP key). |
| `tests/Feature/PostmarkInboundWebhookTest.php` | Token validation (404 on wrong token); 200 + thought for matched user; 200 + unmatched row for unknown sender; idempotency; empty body. |
| `tests/Feature/InboundEmailSettingsTest.php` | Auth required; list and add and remove inbound addresses; validation. |
| `.env.example` | Add `POSTMARK_INBOUND_WEBHOOK_SECRET=` (comment: used as path segment in webhook URL). |

---

## Chunk 1: Migrations and models

### Task 1.1: Migration `user_inbound_addresses`

**Files:** Create migration, Modify `app/Models/User.php`

- [ ] **Step 1: Create migration**

Run: `php artisan make:migration create_user_inbound_addresses_table`

Edit the new migration in `database/migrations/`. In `up()`:

```php
Schema::create('user_inbound_addresses', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->constrained()->cascadeOnDelete();
    $table->string('email', 255);
    $table->timestamps();
    $table->unique('email');
});
```

In `down()`: `Schema::dropIfExists('user_inbound_addresses');`

- [ ] **Step 2: Run migration**

Run: `php artisan migrate`

Expected: Migration runs successfully.

- [ ] **Step 3: Add User relationship**

In `app/Models/User.php`, add to the relationships section:

```php
/**
 * Get the inbound email addresses for the user (for capture-by-email).
 */
public function userInboundAddresses()
{
    return $this->hasMany(UserInboundAddress::class);
}
```

Add `use App\Models\UserInboundAddress;` if needed.

- [ ] **Step 4: Commit**

```bash
git add database/migrations/*_create_user_inbound_addresses_table.php app/Models/User.php
git commit -m "feat: add user_inbound_addresses migration and User relationship"
```

---

### Task 1.2: Model `UserInboundAddress`

**Files:** Create `app/Models/UserInboundAddress.php`, Create `app/Policies/UserInboundAddressPolicy.php`

- [ ] **Step 1: Create model**

Create `app/Models/UserInboundAddress.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserInboundAddress extends Model
{
    protected $fillable = [
        'user_id',
        'email',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Normalise email to lowercase and trim before saving.
     */
    protected static function booted(): void
    {
        static::saving(function (UserInboundAddress $model): void {
            $model->email = mb_strtolower(trim($model->email));
        });
    }
}
```

- [ ] **Step 2: Create policy**

Create `app/Policies/UserInboundAddressPolicy.php` (same pattern as `UserMcpKeyPolicy`):

```php
<?php

namespace App\Policies;

use App\Models\User;
use App\Models\UserInboundAddress;

class UserInboundAddressPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, UserInboundAddress $userInboundAddress): bool
    {
        return $userInboundAddress->user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function delete(User $user, UserInboundAddress $userInboundAddress): bool
    {
        return $userInboundAddress->user_id === $user->id;
    }
}
```

- [ ] **Step 3: Commit**

```bash
git add app/Models/UserInboundAddress.php app/Policies/UserInboundAddressPolicy.php
git commit -m "feat: add UserInboundAddress model and policy"
```

---

### Task 1.3: Migration and model `unmatched_inbound_emails`

**Files:** Create migration, Create `app/Models/UnmatchedInboundEmail.php`

- [ ] **Step 1: Create migration**

Run: `php artisan make:migration create_unmatched_inbound_emails_table`

In `up()`:

```php
Schema::create('unmatched_inbound_emails', function (Blueprint $table) {
    $table->id();
    $table->string('message_id', 255)->unique();
    $table->string('from_email');
    $table->string('to_email')->nullable();
    $table->string('subject', 1024)->nullable();
    $table->text('body_text')->nullable();
    $table->timestamp('received_at')->nullable();
    $table->json('payload_json')->nullable();
    $table->timestamps();
});
```

In `down()`: `Schema::dropIfExists('unmatched_inbound_emails');`

- [ ] **Step 2: Run migration**

Run: `php artisan migrate`

- [ ] **Step 3: Create model**

Create `app/Models/UnmatchedInboundEmail.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UnmatchedInboundEmail extends Model
{
    protected $fillable = [
        'message_id',
        'from_email',
        'to_email',
        'subject',
        'body_text',
        'received_at',
        'payload_json',
    ];

    protected function casts(): array
    {
        return [
            'received_at' => 'datetime',
            'payload_json' => 'array',
        ];
    }
}
```

- [ ] **Step 4: Commit**

```bash
git add database/migrations/*_create_unmatched_inbound_emails_table.php app/Models/UnmatchedInboundEmail.php
git commit -m "feat: add unmatched_inbound_emails table and model"
```

---

## Chunk 2: Postmark webhook (config, middleware, service, controller)

### Task 2.1: Config and webhook route with token check

**Files:** Modify `config/services.php`, Create `app/Http/Middleware/ValidatePostmarkInboundSecret.php`, Modify `bootstrap/app.php` or `app/Http/Kernel.php` (if needed), Modify `routes/web.php`

- [ ] **Step 1: Add config**

In `config/services.php`, add (e.g. after `evernote`):

```php
'postmark_inbound' => [
    'webhook_secret' => env('POSTMARK_INBOUND_WEBHOOK_SECRET'),
],
```

- [ ] **Step 2: Create middleware**

Run: `php artisan make:middleware ValidatePostmarkInboundSecret`

Edit `app/Http/Middleware/ValidatePostmarkInboundSecret.php`:

```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ValidatePostmarkInboundSecret
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->route('token');
        $secret = config('services.postmark_inbound.webhook_secret');

        if (! is_string($token) || $token === '' || $secret === null || $secret === '' || $token !== $secret) {
            return response('', 404);
        }

        return $next($request);
    }
}
```

- [ ] **Step 3: Register middleware alias**

In Laravel 11+, add alias in `bootstrap/app.php` inside the `withMiddleware` callback. If you have `$middleware->alias([...])`, add:

```php
'postmark.inbound.secret' => \App\Http\Middleware\ValidatePostmarkInboundSecret::class,
```

(If the project uses `app/Http/Kernel.php` instead, add the alias to `$middlewareAliases`.)

- [ ] **Step 4: Add webhook route**

In `routes/web.php`, add **outside** the `auth` middleware group (e.g. after Stripe webhook):

```php
use App\Http\Controllers\PostmarkInboundController;

// Postmark inbound email webhook (secret in path; no auth)
Route::post('/webhooks/postmark/inbound/{token}', [PostmarkInboundController::class, 'handle'])
    ->middleware('postmark.inbound.secret')
    ->name('webhooks.postmark.inbound');
```

- [ ] **Step 5: Commit**

```bash
git add config/services.php app/Http/Middleware/ValidatePostmarkInboundSecret.php bootstrap/app.php routes/web.php
git commit -m "feat: add Postmark inbound webhook route and secret middleware"
```

---

### Task 2.2: PostmarkInboundService (parse, resolve user, create thought or unmatched)

**Files:** Create `app/Services/PostmarkInboundService.php`

- [ ] **Step 1: Write failing test (service logic can be covered by webhook test)**

Proceed to Task 2.3; service behaviour will be tested via PostmarkInboundWebhookTest.

- [ ] **Step 2: Create service**

Create `app/Services/PostmarkInboundService.php`:

Responsibilities:

1. **Body text:** From payload use `TextBody` if non-empty string; else strip HTML from `HtmlBody` (e.g. `strip_tags`) and use that. If both empty, return early (no thought, no unmatched).
2. **From email:** `$from = $payload['From'] ?? $payload['FromFull']['Email'] ?? ''`; normalise: `mb_strtolower(trim($from))`.
3. **Resolve user:** Find user where `users.email` = normalised from, OR exists in `user_inbound_addresses` for that user. If multiple users (edge case), prefer `users.email` match then lowest `user_id`. If none, treat as unmatched.
4. **Idempotency:** If we have a user, check `Thought::query()->where('user_id', $user->id)->where('source', 'email')->where('source_metadata->message_id', $messageId)->exists()`. If true, return. For unmatched path, check `UnmatchedInboundEmail::query()->where('message_id', $messageId)->exists()`; if true, return.
5. **Attachment names:** From `Attachments` array (if present), collect `$attachments[]['Name']` into array of strings.
6. **Thought:** Build `source_metadata`: message_id, from, subject, date, attachment_names (optional to, reply_to). Call OpenRouterService to embed content and extract metadata (reuse Thought::normalizeMetadataTags). Create Thought with content, embedding, metadata, user_id, source='email', source_metadata.
7. **Unmatched:** Create UnmatchedInboundEmail with message_id, from_email, to_email (from ToFull[0].Email or To), subject, body_text, received_at (parse Date if possible, else null), payload_json (optional: store minimal keys to avoid huge payloads).

Signature: `public function process(array $payload): void`. Throw nothing on “unknown user” or “empty body”; only throw for real errors (e.g. DB failure) so controller can return 5xx.

Implement the service with the above logic. Inject `OpenRouterService` in the constructor; use it for `embed($content)` and `extractMetadata($content)`. Use `Thought`, `User`, `UserInboundAddress`, `UnmatchedInboundEmail`. For Date parsing use `Carbon::parse($payload['Date'] ?? null)` in a try/catch and set `received_at` to null on failure.

- [ ] **Step 3: Commit**

```bash
git add app/Services/PostmarkInboundService.php
git commit -m "feat: add PostmarkInboundService to process inbound email"
```

---

### Task 2.3: PostmarkInboundController

**Files:** Create `app/Http/Controllers/PostmarkInboundController.php`

- [ ] **Step 1: Create controller**

Create `app/Http/Controllers/PostmarkInboundController.php`:

- In `handle(Request $request)`:
  - Get JSON: `$payload = $request->all();` (Laravel parses JSON body automatically).
  - If payload is empty or not array, return `response()->json(['error' => 'Invalid payload'], 422)`.
  - Try: call `app(PostmarkInboundService::class)->process($payload); return response('', 200);`
  - Catch \Throwable: `report($e); return response()->json(['error' => 'Processing failed'], 503);`
- No auth; middleware already applied to route.

- [ ] **Step 2: Commit**

```bash
git add app/Http/Controllers/PostmarkInboundController.php
git commit -m "feat: add PostmarkInboundController for inbound webhook"
```

---

### Task 2.4: Feature tests for Postmark webhook

**Files:** Create `tests/Feature/PostmarkInboundWebhookTest.php`

- [ ] **Step 1: Write tests**

Create `tests/Feature/PostmarkInboundWebhookTest.php`:

- Use `RefreshDatabase`.
- Set in setUp or each test: `config(['services.postmark_inbound.webhook_secret' => 'test-secret-123']);`
- Test: `test_wrong_token_returns_404`: POST to `/webhooks/postmark/inbound/wrong` with valid JSON → assert 404.
- Test: `test_empty_body_returns_200_and_no_thought`: payload with From, MessageID, empty TextBody and HtmlBody → 200, no Thought created, no UnmatchedInboundEmail.
- Test: `test_matched_user_creates_thought`: User with email `sender@example.com`; payload From that email, MessageID, TextBody "Hello"; → 200, one Thought with source=email, content "Hello", source_metadata contains message_id.
- Test: `test_unmatched_sender_stores_in_unmatched`: payload From `unknown@example.com`, MessageID, TextBody "Hi"; no user with that email → 200, one UnmatchedInboundEmail row with from_email unknown@example.com.
- Test: `test_idempotency_same_message_id`: same as matched_user_creates_thought, send same payload twice → 200 both times, only one Thought.
- Test: `test_inbound_address_matches_user`: User A; add UserInboundAddress for user A with email `alias@example.com`. Payload From `alias@example.com`, TextBody "Via alias". → 200, one Thought for user A.
- Test: `test_attachment_names_in_source_metadata`: payload with Attachments [['Name' => 'file.pdf']]. Matched user. → Thought source_metadata has attachment_names => ['file.pdf'].

Use minimal Postmark-shaped payloads (From, MessageID, TextBody, optional HtmlBody, Attachments, ToFull, Subject, Date).

- [ ] **Step 2: Run tests**

Run: `php artisan test tests/Feature/PostmarkInboundWebhookTest.php`

Fix any failures until all pass.

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/PostmarkInboundWebhookTest.php
git commit -m "test: Postmark inbound webhook feature tests"
```

---

## Chunk 3: Settings UI (inbound email addresses)

### Task 3.1: Routes and InboundEmailController

**Files:** Modify `routes/web.php`, Create `app/Http/Controllers/InboundEmailController.php`

- [ ] **Step 1: Add routes**

In `routes/web.php`, inside the `auth` middleware group (after MCP key routes):

```php
use App\Http\Controllers\InboundEmailController;

Route::get('/settings/inbound-emails', [InboundEmailController::class, 'index'])->name('settings.inbound-emails.index');
Route::post('/settings/inbound-emails', [InboundEmailController::class, 'store'])->name('settings.inbound-emails.store');
Route::delete('/settings/inbound-emails/{userInboundAddress}', [InboundEmailController::class, 'destroy'])->name('settings.inbound-emails.destroy');
```

- [ ] **Step 2: Create controller**

Create `app/Http/Controllers/InboundEmailController.php`:

- `index(Request $request)`: authorize `viewAny`, UserInboundAddress::class. Get user's primary email and `$request->user()->userInboundAddresses;`. Build capture URL: e.g. `config('app.url')` or a dedicated config for the Postmark inbound address (for display only; we don't have per-user URLs in v1—use a single app inbound address from config or env, e.g. `config('services.postmark_inbound.capture_address', 'capture@yourdomain.com')`). Return view `settings.inbound-emails` with `primaryEmail`, `inboundAddresses`, `captureAddress`.
- `store(Request $request)`: authorize `create`, UserInboundAddress::class. Validate `email` => `required|email|max:255`. Normalise to lowercase trim. Check unique: `UserInboundAddress::query()->where('email', $email)->exists()` then redirect back with error. Create `$request->user()->userInboundAddresses()->create(['email' => $email]);` redirect to index with success.
- `destroy(Request $request, UserInboundAddress $userInboundAddress)`: authorize `delete`, $userInboundAddress. Delete; redirect to index with success.

- [ ] **Step 3: Add capture address to config**

In `config/services.php` under `postmark_inbound`, add:

```php
'capture_address' => env('POSTMARK_INBOUND_CAPTURE_ADDRESS', ''),
```

So the app can show "Send emails to: {address}" in the UI. Optional for tests.

- [ ] **Step 4: Commit**

```bash
git add routes/web.php app/Http/Controllers/InboundEmailController.php config/services.php
git commit -m "feat: add inbound email settings routes and controller"
```

---

### Task 3.2: View and nav link

**Files:** Create `resources/views/settings/inbound-emails.blade.php`, Modify `resources/views/layouts/idea.blade.php`

- [ ] **Step 1: Create view**

Create `resources/views/settings/inbound-emails.blade.php` extending `layouts.idea`. Follow structure of `settings/mcp-keys.blade.php`:

- Title "Inbound email" or "Email capture".
- Short copy: "Emails you send from any of the addresses below will become thoughts. Send to your capture address from your email client or Fastmail."
- Show `$captureAddress` if set (e.g. "Your capture address: {{ $captureAddress }}"); if empty, show "Configure POSTMARK_INBOUND_CAPTURE_ADDRESS to show your capture address."
- List primary email: "Primary account email: {{ $primaryEmail }} (always allowed)."
- List inbound addresses with delete button each (form DELETE to `route('settings.inbound-emails.destroy', $address)`).
- Form to add: POST to `route('settings.inbound-emails.store')`, csrf, input name="email" type="email", placeholder "Add another email", button "Add address".
- Success/error flash messages like MCP keys page.

- [ ] **Step 2: Add nav link**

In `resources/views/layouts/idea.blade.php`, in the avatar dropdown, add a link before "Log out":

```blade
<a href="{{ route('settings.inbound-emails.index') }}" class="block px-4 py-2 text-sm text-slate-brand hover:text-deep-indigo hover:bg-memory-violet/5 transition-colors">
    Inbound email
</a>
```

- [ ] **Step 3: Commit**

```bash
git add resources/views/settings/inbound-emails.blade.php resources/views/layouts/idea.blade.php
git commit -m "feat: add inbound email settings view and nav link"
```

---

### Task 3.3: Feature tests for inbound email settings

**Files:** Create `tests/Feature/InboundEmailSettingsTest.php`

- [ ] **Step 1: Write tests**

Create `tests/Feature/InboundEmailSettingsTest.php`:

- test_guest_redirected_to_login for GET settings.inbound-emails.index
- test_authenticated_user_sees_page: 200, sees "Inbound email", sees primary email
- test_user_can_add_inbound_address: POST with email, assert redirect, assert UserInboundAddress exists for user
- test_user_can_remove_inbound_address: create UserInboundAddress for user, DELETE destroy route → redirect, record deleted
- test_user_cannot_remove_other_users_address: create address for user B, actingAs user A delete → 403 or 404
- test_duplicate_email_rejected: add same email twice (second time for same or another user); expect validation/unique error

- [ ] **Step 2: Run tests**

Run: `php artisan test tests/Feature/InboundEmailSettingsTest.php`

Fix until all pass.

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/InboundEmailSettingsTest.php
git commit -m "test: inbound email settings feature tests"
```

---

## Chunk 4: Documentation and env example

### Task 4.1: .env.example and README (optional)

**Files:** Modify `.env.example`, optionally `README.md` or `docs/`

- [ ] **Step 1: Add env vars**

In `.env.example`, add:

```
# Postmark inbound: secret used as path segment in webhook URL (e.g. /webhooks/postmark/inbound/SECRET)
POSTMARK_INBOUND_WEBHOOK_SECRET=
# Optional: address to show in settings (e.g. capture@in.yourdomain.com)
POSTMARK_INBOUND_CAPTURE_ADDRESS=
```

- [ ] **Step 2: Commit**

```bash
git add .env.example
git commit -m "docs: add Postmark inbound env vars to .env.example"
```

---

## Execution handoff

After all tasks are done:

1. Run full test suite: `php artisan test`
2. Manually verify: visit `/settings/inbound-emails`, add/remove an address; send a test POST to the webhook (with correct token) using curl and a minimal Postmark payload.

Plan complete and saved to `docs/superpowers/plans/2026-03-12-inbound-email-postmark.md`. Ready to execute?
