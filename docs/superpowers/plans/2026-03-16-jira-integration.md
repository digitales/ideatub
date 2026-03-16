# Jira integration implementation plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ingest Jira activity (issues created/updated/commented, admin-style actions) as thoughts with type `jira` and project as tag; user-provided API key; on-demand sync from web and MCP. Discoverable via search and Stream.

**Architecture:** Per-user Jira credentials (site URL + API token) stored encrypted in `user_jira_credentials`. `JiraSyncService::fetchEvents()` calls Jira Cloud REST API and returns event arrays. `SyncUserJiraActivity` job runs for a user, calls the service, then for each event checks idempotency and creates Thought (embedding via OpenRouter). Evernote sync is skipped for thoughts with `source = 'jira'`. Settings UI and MCP tool `sync_jira` trigger the job.

**Tech Stack:** Laravel 12, PostgreSQL, Laravel HTTP client (Basic auth), existing OpenRouterService and Thought model.

**Spec:** `docs/superpowers/specs/2026-03-16-jira-integration-design.md`

---

## File structure

| File | Responsibility |
|------|----------------|
| `database/migrations/xxxx_create_user_jira_credentials_table.php` | Table: user_id, jira_site_url, jira_api_token (encrypted), jira_email (nullable), timestamps |
| `app/Models/UserJiraCredential.php` | Eloquent model; encrypted cast for token; belongsTo User |
| `app/Services/JiraSyncService.php` | fetchEvents(User, days): array of event arrays; HTTP to Jira search + issue expand changelog/comments; parse into events; throws InvalidJiraCredentialsException on 401/403 |
| `app/Exceptions/InvalidJiraCredentialsException.php` | Optional: dedicated exception for 401/403 so job/UI can show "Invalid Jira credentials" (or use a generic exception in v1) |
| `app/Jobs/SyncUserJiraActivity.php` | Load credentials, call JiraSyncService, idempotency check per event, embed + create Thought |
| `app/Http/Controllers/JiraSettingsController.php` | index (show form + sync button), store (validate + save credentials), destroy (disconnect), sync (dispatch job) |
| `resources/views/settings/jira.blade.php` | Form: site URL, API token (password), email (for Jira auth); Connect / Disconnect / Sync now |
| `app/Http/Controllers/Api/McpController.php` | Add sync_jira to tools list, knownMethods, dispatch; handler dispatches job and returns message |
| `app/Models/Thought.php` | In boot: skip dispatching SyncThoughtToEvernote when `$thought->source === 'jira'` |
| `config/services.php` | Add `jira.enabled` (env JIRA_ENABLED, default true), `jira.default_days` (e.g. 14) |
| `tests/Unit/JiraSyncServiceTest.php` | Mock HTTP; assert fetchEvents return shape and event keys |
| `tests/Feature/JiraSyncJobTest.php` | Sync creates thoughts with type jira and tags; idempotency (run twice, same count) |
| `tests/Feature/JiraSettingsTest.php` | Store credentials, disconnect, sync dispatches job |
| `tests/Feature/McpApiTest.php` | Add test for sync_jira (authorized user, job dispatched, message returned) |

---

## Chunk 1: Credentials storage and config

### Task 1.1: Migration for user_jira_credentials

**Files:**
- Create: `database/migrations/YYYY_MM_DD_HHMMSS_create_user_jira_credentials_table.php`

- [ ] **Step 1: Create migration**

Run: `php artisan make:migration create_user_jira_credentials_table`

- [ ] **Step 2: Define schema**

In the migration `up()` method:

```php
Schema::create('user_jira_credentials', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->constrained()->cascadeOnDelete();
    $table->string('jira_site_url', 500);
    $table->text('jira_api_token'); // stored encrypted via model cast
    $table->string('jira_email', 255)->nullable(); // Jira Cloud Basic auth email; null = use user->email
    $table->timestamps();
    $table->unique('user_id');
});
```

- [ ] **Step 3: Run migration**

Run: `php artisan migrate`
Expected: Migration runs successfully.

- [ ] **Step 4: Commit**

```bash
git add database/migrations/*_create_user_jira_credentials_table.php
git commit -m "feat(jira): add user_jira_credentials migration"
```

### Task 1.2: UserJiraCredential model

**Files:**
- Create: `app/Models/UserJiraCredential.php`
- Modify: `app/Models/User.php` (add relation)

- [ ] **Step 1: Create model**

Run: `php artisan make:model UserJiraCredential`

- [ ] **Step 2: Implement model**

In `app/Models/UserJiraCredential.php`:

- Fillable: `user_id`, `jira_site_url`, `jira_api_token`, `jira_email`
- Cast: `jira_api_token` => `encrypted` (Laravel encrypted cast)
- `belongsTo(User::class)`
- Optional: mutator or accessor so `getDecryptedToken()` is available for the service (or use attribute directly; encrypted cast decrypts on read)

- [ ] **Step 3: Add User relation**

In `app/Models/User.php` add:

```php
public function jiraCredential(): HasOne
{
    return $this->hasOne(UserJiraCredential::class);
}
```

- [ ] **Step 4: Commit**

```bash
git add app/Models/UserJiraCredential.php app/Models/User.php
git commit -m "feat(jira): add UserJiraCredential model with encrypted token"
```

### Task 1.3: Config and feature toggle

**Files:**
- Modify: `config/services.php`

- [ ] **Step 1: Add jira config**

Append to `config/services.php`:

```php
'jira' => [
    'enabled' => filter_var(env('JIRA_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
    'default_days' => (int) env('JIRA_SYNC_DAYS', 14),
],
```

When `jira.enabled` is false: do not show Jira in the UI (nav, settings page) and do not expose `sync_jira` in MCP (see Chunks 4 and 5).

- [ ] **Step 2: Commit**

```bash
git add config/services.php
git commit -m "feat(jira): add jira.default_days config"
```

---

## Chunk 2: JiraSyncService

### Task 2.1: JiraSyncService and fetchEvents (minimal implementation)

**Files:**
- Create: `app/Services/JiraSyncService.php`
- Create: `tests/Unit/JiraSyncServiceTest.php`

Jira Cloud auth: Basic with email + API token. Use `$user->email` if `jira_email` is null.

- [ ] **Step 1: Write failing test**

In `tests/Unit/JiraSyncServiceTest.php`: test that when HTTP is mocked to return a minimal search response (one issue with changelog), `fetchEvents` returns an array of events and each event has keys `jira_event_id`, `content`, `metadata`, `source_metadata`. Use `Http::fake()` to stub `*/rest/api/3/search*` and `*/rest/api/3/issue/*`.

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Unit/JiraSyncServiceTest.php`
Expected: FAIL (class or method missing).

- [ ] **Step 3: Implement JiraSyncService**

In `app/Services/JiraSyncService.php`:

- Constructor or method: needs User (to get credentials and email for Basic auth).
- `fetchEvents(User $user, int $days = 14): array`
  - Load credential via `$user->jiraCredential`; if null, return [].
  - Build base URL from `jira_site_url` (trim trailing slash).
  - Auth: Basic with `jira_email ?? $user->email` and decrypted `jira_api_token`.
  - JQL: `(reporter = currentUser() OR assignee = currentUser()) AND updated >= -{$days}d`. Request `GET /rest/api/3/search?jql=...&maxResults=100&expand=changelog`.
  - For each issue: get issue key, summary, project.key, created, updated; build `created` event; from changelog.histories filter by current user (compare author.accountId to current user’s accountId — you need to fetch current user’s accountId from `GET /rest/api/3/myself` once and cache); build `updated` events; fetch comments `GET /rest/api/3/issue/{idOrKey}/comment`, filter by author; build `comment` events.
  - Each event: `jira_event_id`, `content` (short string), `metadata` (type, tags with jira + project key lowercased), `source_metadata` (jira_event_id, jira_issue_key, jira_issue_summary, jira_project_key, jira_event_type, jira_updated_at or jira_created_at, jira_link).
  - Normalize tags with `Thought::normalizeMetadataTags(['tags' => $tags])` and use that in metadata.
  - On 401/403: throw a dedicated exception (e.g. `InvalidJiraCredentialsException`) so job can surface message. On 429: retry with backoff (or throw). On 5xx/timeout: throw.

- [ ] **Step 4: Run test**

Run: `php artisan test tests/Unit/JiraSyncServiceTest.php`
Expected: PASS (or fix until pass).

- [ ] **Step 5: Commit**

```bash
git add app/Services/JiraSyncService.php tests/Unit/JiraSyncServiceTest.php
git commit -m "feat(jira): add JiraSyncService::fetchEvents with Jira API client"
```

**Note:** Jira Cloud uses `accountId` for current user. When comparing changelog author to “current user”, call `GET /rest/api/3/myself` once per sync to get the authenticated user’s `accountId` and use that for filtering.

---

## Chunk 3: Sync job and Evernote skip

### Task 3.1: SyncUserJiraActivity job

**Files:**
- Create: `app/Jobs/SyncUserJiraActivity.php`
- Create: `tests/Feature/JiraSyncJobTest.php`

- [ ] **Step 1: Write failing feature test**

In `tests/Feature/JiraSyncJobTest.php`:
- Create user with Jira credential (site URL, token, email).
- Mock or fake JiraSyncService to return 1 event (or use a real stub that returns correct shape).
- Run job `SyncUserJiraActivity::dispatch($user->id)` and process (e.g. `Bus::fake()` then dispatch then `Bus::assertDispatched` and run the job manually, or run job synchronously).
- Assert one Thought exists with source=jira, metadata.type=jira, metadata.tags containing 'jira' and project key.
- Assert idempotency: run job again (same event id), still one Thought.

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/JiraSyncJobTest.php`
Expected: FAIL.

- [ ] **Step 3: Create job**

Run: `php artisan make:job SyncUserJiraActivity`

Implement:
- Constructor: `user_id`, optional `days` (default from config).
- handle: Load User; load jiraCredential; if null return. Call `JiraSyncService::fetchEvents($user, $days)`. For each event: if Thought::where('user_id', $user->id)->where('source', 'jira')->where('source_metadata->jira_event_id', $event['source_metadata']['jira_event_id'])->exists() then skip. Else: get content, metadata, source_metadata from event; call OpenRouterService::embed($content); create Thought with content, embedding, metadata, user_id, source='jira', source_metadata. Use Thought::normalizeMetadataTags for metadata.tags if not already normalized by service.

- [ ] **Step 4: Run test**

Run: `php artisan test tests/Feature/JiraSyncJobTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Jobs/SyncUserJiraActivity.php tests/Feature/JiraSyncJobTest.php
git commit -m "feat(jira): add SyncUserJiraActivity job with idempotency"
```

### Task 3.2: Skip Evernote sync for jira thoughts

**Files:**
- Modify: `app/Models/Thought.php`

- [ ] **Step 1: Add condition in boot**

In the `$dispatchSync` closure, add at the start:

```php
if ($thought->source === 'jira') {
    return;
}
```

- [ ] **Step 2: Run tests**

Run: `php artisan test`
Expected: All pass.

- [ ] **Step 3: Commit**

```bash
git add app/Models/Thought.php
git commit -m "feat(jira): skip Evernote sync for thoughts with source jira"
```

---

## Chunk 4: Settings UI and controller

### Task 4.1: JiraSettingsController and routes

**Files:**
- Create: `app/Http/Controllers/JiraSettingsController.php`
- Modify: `routes/web.php`
- Create: `resources/views/settings/jira.blade.php`

- [ ] **Step 1: Create controller**

Run: `php artisan make:controller JiraSettingsController`

Implement:
- `index(Request $request)`: Get user’s jiraCredential; return view with credential (for “connected” state; do not pass token, only whether connected and site URL for display).
- `store(Request $request)`: Validate jira_site_url (required, url, max 500), jira_api_token (required, string), jira_email (nullable, email). Create or update user’s UserJiraCredential (one per user). Redirect to index with success. Never echo token back.
- `destroy(Request $request)`: Delete user’s jiraCredential; redirect with success.
- `sync(Request $request)`: Dispatch SyncUserJiraActivity for current user (with optional days from request or config). Redirect to index with flash “Jira sync started.”

- [ ] **Step 2: Add routes (only when Jira enabled)**

In `routes/web.php` inside auth group, register Jira routes only when `config('services.jira.enabled', true)`:

```php
if (config('services.jira.enabled', true)) {
    Route::get('/settings/jira', [JiraSettingsController::class, 'index'])->name('settings.jira.index');
    Route::post('/settings/jira', [JiraSettingsController::class, 'store'])->name('settings.jira.store');
    Route::delete('/settings/jira', [JiraSettingsController::class, 'destroy'])->name('settings.jira.destroy');
    Route::post('/settings/jira/sync', [JiraSettingsController::class, 'sync'])->name('settings.jira.sync');
}
```

Add use for JiraSettingsController. When disabled, these routes are not registered (direct URL hits will 404).

- [ ] **Step 3: Create view**

Copy structure from `resources/views/settings/inbound-emails.blade.php`: card, title “Jira”, form for store (site URL, email, API token password), “Connect” / “Save”; if connected show “Disconnect” (form to destroy) and “Sync Jira now” (form POST to settings.jira.sync). Show flash success/error. Use same layout and styling.

- [ ] **Step 4: Add nav link (only when Jira enabled)**

In `resources/views/layouts/idea.blade.php` (or wherever settings dropdown is), add the Jira link only when config('services.jira.enabled', true); e.g. @if(config('services.jira.enabled', true)) ... link to `route('settings.jira.index')`: “Jira”.

- [ ] **Step 5: Policy (optional)**

If the app uses policies for settings, add policy for UserJiraCredential (user can only manage own). Otherwise controller uses $request->user() only.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/JiraSettingsController.php routes/web.php resources/views/settings/jira.blade.php resources/views/layouts/idea.blade.php
git commit -m "feat(jira): add Jira settings page and sync trigger"
```

---

## Chunk 5: MCP sync_jira tool

**Files:**
- Modify: `app/Http/Controllers/Api/McpController.php`
- Modify: `tests/Feature/McpApiTest.php`

When `config('services.jira.enabled', true)` is false: do not include `sync_jira` in the methods list or tools list; if a client calls it anyway, return method not found or "Jira integration is disabled".

- [ ] **Step 1: Add sync_jira to methods list (only when Jira enabled)**

In McpController, in all places that list methods (e.g. initialize response, legacy knownMethods, tools/call knownMethods, dispatch match), include `'sync_jira'` only when `config('services.jira.enabled', true)`. Build the methods array conditionally (e.g. merge base methods with `config('services.jira.enabled') ? ['sync_jira'] : []`) so when disabled, sync_jira is not advertised and not callable.

- [ ] **Step 2: Add tool definition in respondToolsList**

Add array entry for sync_jira: name, description (e.g. “Sync your Jira activity into IdeaTub for the last N days. Use when the user wants to refresh Jira tickets or before a meeting.”), inputSchema with optional days (integer).

- [ ] **Step 3: Add dispatch case**

In `dispatch()` match, add `'sync_jira' => $this->syncJira($params)`.

- [ ] **Step 4: Implement syncJira**

Private method `syncJira(array $params): array`: get days from params (default config('services.jira.default_days', 14)). Get user from Auth::user() (already set by resolveUser). Dispatch SyncUserJiraActivity::dispatch($user->id, $days). Return array with message string, e.g. “Jira sync started for the last {$days} days. You can search or browse recent thoughts for your Jira activity.”

- [ ] **Step 5: Add test in McpApiTest**

Test that authenticated MCP request with method sync_jira dispatches SyncUserJiraActivity (use Bus::fake()) and returns success message.

- [ ] **Step 6: Run tests**

Run: `php artisan test tests/Feature/McpApiTest.php`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/Api/McpController.php tests/Feature/McpApiTest.php
git commit -m "feat(jira): add MCP sync_jira tool"
```

---

## Chunk 6: Docs and env

**Files:**
- Modify: `.env.example`
- Create or modify: `docs/jira-integration.md` (optional, or add to README)

- [ ] **Step 1: .env.example**

Add:

```
# Jira sync (optional): default days to fetch on sync
# Set to false to hide Jira from the interface and MCP
JIRA_ENABLED=true
JIRA_SYNC_DAYS=14
```

- [ ] **Step 2: Short doc**

Create `docs/jira-integration.md`: Jira integration uses per-user credentials (Settings → Jira). Store site URL and API token; sync creates thoughts with type jira and project tag. Search and Stream show them. MCP tool sync_jira triggers sync. The integration can be turned off with `JIRA_ENABLED=false`; when off, Jira is removed from the UI and MCP.

- [ ] **Step 3: Commit**

Add the files you created or modified (e.g. `.env.example` and either `docs/jira-integration.md` or `README.md`), then:

```bash
git add .env.example docs/jira-integration.md
# OR if you added to README: git add .env.example README.md
git commit -m "docs: Jira integration env and usage"
```

---

## Execution handoff

After all chunks are implemented and tests pass:

- Run full test suite: `php artisan test`
- Manually: connect Jira in settings, trigger sync, check Stream filtered by tag `jira` and by project tag.

**Plan complete and saved to `docs/superpowers/plans/2026-03-16-jira-integration.md`. Ready to execute?**
