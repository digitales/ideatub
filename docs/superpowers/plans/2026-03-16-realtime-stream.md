# Real-time Stream, Home, and Ideas — Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** When a new thought is created (web, MCP, or any source), open tabs on Stream, Home, or Ideas update without full page reload. Support two drivers: Reverb (production) and polling (local), toggled via config.

**Architecture:** Backend broadcasts `ThoughtCreated` to a private user channel when Reverb is enabled; optional polling endpoint returns `has_new` for the user since a timestamp. Front end subscribes (Echo) or polls; on "new thought" runs a shared refetch handler that replaces the list HTML for the current page (Stream, Home, or Ideas). Refetch uses existing or new AJAX endpoints that return first-page HTML.

**Tech Stack:** Laravel Broadcasting, Laravel Reverb (optional), Laravel Echo + Pusher JS (when Reverb), Blade, vanilla JS. Spec: `docs/superpowers/specs/2026-03-16-realtime-stream-design.md`

---

## File structure

| File | Responsibility |
|------|----------------|
| `config/realtime.php` | New: `realtime.driver` (`reverb` / `polling`), read from `REALTIME_DRIVER` env (default `polling`). |
| `config/broadcasting.php` | Add/publish if missing: Reverb connection config; default connection from env. |
| `config/reverb.php` | Add via `php artisan reverb:install` or publish: Reverb server config. |
| `routes/channels.php` | New: Authorize `private-App.Models.User.{id}` for authenticated user. |
| `app/Events/ThoughtCreated.php` | New: ShouldBroadcast event with thought payload (thought_id, user_id, parent_id, metadata). |
| `app/Listeners/BroadcastThoughtCreated.php` | New: Listens to `Thought::created`; dispatches `ThoughtCreated` only when `config('realtime.driver') === 'reverb'`. |
| `app/Providers/AppServiceProvider.php` or `EventServiceProvider` | Register Thought::created → BroadcastThoughtCreated. |
| `routes/web.php` | Add `GET /api/thoughts/realtime-check` (auth) → controller method. |
| `app/Http/Controllers/Api/RealtimeCheckController.php` | New: `realtimeCheck(Request $request)` — returns `{ has_new: bool }` (or `latest_id`, `latest_created_at`) using `since` query param. |
| `app/Http/Controllers/IdeaController.php` | Add AJAX branch: when no search and `$request->ajax()`, return JSON with recent thoughts HTML + total. Add method or param for ideas first-page HTML when AJAX. |
| `resources/views/layouts/idea.blade.php` | Pass `realtime` config (driver, reverb key/host/port etc.) to front end via `@stack('scripts')` or a script block; only when on Stream/Home/Ideas pass page type. |
| `resources/js/realtime.js` or inline in partials | New: Shared refetch handler; Echo subscribe (if reverb) or polling interval (if polling); only run on Stream, Home, Ideas. |
| `resources/views/idea/stream.blade.php` | Include realtime script with `page=stream` and stream refetch URL. |
| `resources/views/idea/index.blade.php` | Include realtime script with `page=index` and index refetch URL (when no query). |
| `resources/views/idea/ideas.blade.php` | Include realtime script with `page=ideas` and ideas refetch URL. |
| `tests/Feature/ThoughtCreatedBroadcastTest.php` | New: Assert Thought::created dispatches ThoughtCreated when driver=reverb; payload and channel. |
| `tests/Feature/RealtimeCheckEndpointTest.php` | New: Auth required; returns has_new true/false; since param. |
| `tests/Feature/BroadcastingAuthTest.php` | New: POST /broadcasting/auth 200 for own user channel, 403 for other. |

---

## Chunk 1: Config and backend foundation (realtime driver, event, channel, listener)

### Task 1.1: Realtime config and env

**Files:** Create `config/realtime.php`, Modify `.env.example`

- [ ] **Step 1: Create config file**

Create `config/realtime.php`:

```php
<?php

return [
    'driver' => env('REALTIME_DRIVER', 'polling'),
];
```

- [ ] **Step 2: Document env in .env.example**

Add to `.env.example` (or create if missing):

```
REALTIME_DRIVER=polling
```

For production with Reverb, set `REALTIME_DRIVER=reverb`. Leave unset or `polling` for local.

- [ ] **Step 3: Commit**

```bash
git add config/realtime.php .env.example
git commit -m "config: add realtime driver (reverb vs polling)"
```

### Task 1.2: Install Reverb and broadcasting config (optional for local; required for Reverb driver)

**Files:** Run artisan commands; Modify `config/broadcasting.php`, `config/reverb.php` if published

- [ ] **Step 1: Install Reverb**

Run: `composer require laravel/reverb`  
Then: `php artisan reverb:install`

Follow prompts. This typically adds/publishes `config/reverb.php` and updates `config/broadcasting.php` with a `reverb` connection. If the installer does not add a default connection, set `BROADCAST_CONNECTION=reverb` in `.env` when using Reverb.

- [ ] **Step 2: Ensure default broadcast connection is configurable**

In `config/broadcasting.php`, ensure the `default` connection is `env('BROADCAST_CONNECTION', 'null')`. When `REALTIME_DRIVER=reverb`, app should set `BROADCAST_CONNECTION=reverb` (e.g. in AppServiceProvider or env). For polling-only, leave default `null` so no broadcast is sent.

- [ ] **Step 3: Commit**

```bash
git add config/broadcasting.php config/reverb.php composer.json composer.lock
git commit -m "chore: add Laravel Reverb and broadcasting config"
```

### Task 1.3: Channels authorization

**Files:** Create `routes/channels.php`, Register in `bootstrap/app.php` or provider

- [ ] **Step 1: Write failing test — channel auth**

Create `tests/Feature/BroadcastingAuthTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BroadcastingAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_authorize_own_private_channel(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/broadcasting/auth', [
            'socket_id' => '123.456',
            'channel_name' => 'private-App.Models.User.' . $user->id,
        ]);

        $response->assertStatus(200);
    }

    public function test_user_cannot_authorize_another_users_channel(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        $response = $this->actingAs($user1)->postJson('/broadcasting/auth', [
            'socket_id' => '123.456',
            'channel_name' => 'private-App.Models.User.' . $user2->id,
        ]);

        $response->assertStatus(403);
    }

    public function test_guest_cannot_authorize_channel(): void
    {
        $user = User::factory()->create();

        $response = $this->postJson('/broadcasting/auth', [
            'socket_id' => '123.456',
            'channel_name' => 'private-App.Models.User.' . $user->id,
        ]);

        $response->assertStatus(401);
    }
}
```

Run: `php artisan test tests/Feature/BroadcastingAuthTest.php`  
Expected: FAIL (channels not registered or 404/500).

- [ ] **Step 2: Create channels file**

Create `routes/channels.php`:

```php
<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});
```

- [ ] **Step 3: Register broadcast routes and load channels**

Call `Broadcast::routes(['middleware' => ['web', 'auth']]);` in `App\Providers\AppServiceProvider::boot()` so `/broadcasting/auth` is available. Add `use Illuminate\Support\Facades\Broadcast;` at the top of the provider. Laravel loads `routes/channels.php` automatically when that file exists. If your app does not load it, add in `boot()`: `require base_path('routes/channels.php');`.

- [ ] **Step 4: Run test**

Run: `php artisan test tests/Feature/BroadcastingAuthTest.php`  
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add routes/channels.php bootstrap/app.php app/Providers/AppServiceProvider.php tests/Feature/BroadcastingAuthTest.php
git commit -m "feat: authorize private user channel for broadcasting"
```

### Task 1.4: ThoughtCreated event

**Files:** Create `app/Events/ThoughtCreated.php`

- [ ] **Step 1: Create event**

Run: `php artisan make:event ThoughtCreated`

Edit `app/Events/ThoughtCreated.php`:

- Implement `ShouldBroadcast`.
- Constructor: accept `Thought $thought`.
- `broadcastOn()`: return `new PrivateChannel('App.Models.User.' . $this->thought->user_id)`.
- `broadcastWith()`: return `['thought_id' => $this->thought->id, 'user_id' => $this->thought->user_id, 'parent_id' => $this->thought->parent_id, 'metadata' => $this->thought->metadata ?? []]`.
- `broadcastAs()`: return `'ThoughtCreated'` (event name for Echo).

- [ ] **Step 2: Commit**

```bash
git add app/Events/ThoughtCreated.php
git commit -m "feat: add ThoughtCreated broadcast event"
```

### Task 1.5: Broadcast ThoughtCreated on Thought::created (only when driver is reverb)

**Files:** Create `app/Listeners/BroadcastThoughtCreated.php`, Register in `AppServiceProvider` or `EventServiceProvider`

- [ ] **Step 1: Write failing test**

In `tests/Feature/ThoughtCreatedBroadcastTest.php` (create file):

```php
<?php

namespace Tests\Feature;

use App\Events\ThoughtCreated;
use App\Models\Thought;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class ThoughtCreatedBroadcastTest extends TestCase
{
    use RefreshDatabase;

    public function test_thought_created_dispatches_event_when_reverb_driver(): void
    {
        config(['realtime.driver' => 'reverb']);
        Event::fake([ThoughtCreated::class]);

        $user = User::factory()->create();
        $thought = Thought::factory()->create(['user_id' => $user->id, 'parent_id' => null]);

        Event::assertDispatched(ThoughtCreated::class, function ($e) use ($thought) {
            return $e->thought->id === $thought->id
                && $e->thought->user_id === $thought->user_id;
        });
    }

    public function test_thought_created_does_not_dispatch_when_polling_driver(): void
    {
        config(['realtime.driver' => 'polling']);
        Event::fake([ThoughtCreated::class]);

        $user = User::factory()->create();
        Thought::factory()->create(['user_id' => $user->id]);

        Event::assertNotDispatched(ThoughtCreated::class);
    }
}
```

Run: `php artisan test tests/Feature/ThoughtCreatedBroadcastTest.php`  
Expected: FAIL (listener not registered or event not dispatched).

- [ ] **Step 2: Create listener**

Run: `php artisan make:listener BroadcastThoughtCreated --event=Illuminate\Database\Eloquent\Events\ModelCreated`

Actually we need to listen to the model event. In Laravel, use `Thought::created` with a closure or observer. Create a dedicated listener that listens to a custom event or use an observer.

Simpler: create `app/Listeners/BroadcastThoughtCreated.php` that handles `Illuminate\Database\Eloquent\Events\ModelCreated` and checks if the model is a Thought — but ModelCreated is generic. Better: create a custom event `ThoughtWasCreated` fired from the model's `created` boot, or use Laravel's Observer.

Recommended: use an Observer. Create `app/Observers/ThoughtObserver.php` with `created(Thought $thought)`: if `config('realtime.driver') === 'reverb'`, `broadcast(new ThoughtCreated($thought))`. Register observer in AppServiceProvider for Thought.

- [ ] **Step 2 (alternative): Listener for model event**

Create `app/Listeners/BroadcastThoughtCreated.php`:

```php
<?php

namespace App\Listeners;

use App\Events\ThoughtCreated;
use App\Models\Thought;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;

class BroadcastThoughtCreated
{
    public function handle(object $event): void
    {
        $model = $event->model ?? null;
        if (! $model instanceof Thought) {
            return;
        }
        if (config('realtime.driver') !== 'reverb') {
            return;
        }
        broadcast(new ThoughtCreated($model))->toOthers();
    }
}
```

Laravel does not fire a generic ModelCreated event by default. So we need to fire from the model. Easiest: in `Thought::boot()`, after the existing `static::created($dispatchSync)` closure, add another closure that dispatches `ThoughtCreated` when driver is reverb. That way we don't need to listen to a generic event.

- [ ] **Step 2 (revised): Dispatch from Thought model boot**

In `app/Models/Thought.php`, in `boot()`, add:

```php
static::created(function (Thought $thought): void {
    if (config('realtime.driver') === 'reverb') {
        broadcast(new \App\Events\ThoughtCreated($thought))->toOthers();
    }
});
```

Then the test: we need to assert that when we create a Thought with driver=reverb, the ThoughtCreated event is broadcast. We can Event::fake(ThoughtCreated::class) and then Thought::create(...); then assert the event was dispatched. So the test stays the same; implementation is in Thought::boot().

- [ ] **Step 3: Implement in Thought model**

In `app/Models/Thought.php`, add at the top: `use App\Events\ThoughtCreated;`. In `boot()`, after the existing `static::created($dispatchSync);`, add:

```php
static::created(function (Thought $thought): void {
    if (config('realtime.driver') === 'reverb') {
        broadcast(new ThoughtCreated($thought))->toOthers();
    }
});
```

- [ ] **Step 4: Run test**

Run: `php artisan test tests/Feature/ThoughtCreatedBroadcastTest.php`  
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Models/Thought.php tests/Feature/ThoughtCreatedBroadcastTest.php
git commit -m "feat: broadcast ThoughtCreated when realtime driver is reverb"
```

---

## Chunk 2: Polling endpoint and refetch endpoints

### Task 2.1: Realtime check endpoint (polling)

**Files:** Create `app/Http/Controllers/Api/RealtimeCheckController.php`, Modify `routes/web.php`, Create `tests/Feature/RealtimeCheckEndpointTest.php`

- [ ] **Step 1: Write failing test**

Create `tests/Feature/RealtimeCheckEndpointTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Thought;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RealtimeCheckEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_realtime_check_requires_auth(): void
    {
        $response = $this->getJson(route('api.thoughts.realtime-check'));

        $response->assertStatus(401);
    }

    public function test_realtime_check_returns_has_new_false_when_no_new_thoughts_since(): void
    {
        $user = User::factory()->create();
        $thought = Thought::factory()->create(['user_id' => $user->id, 'created_at' => now()->subMinutes(5)]);

        $response = $this->actingAs($user)->getJson(route('api.thoughts.realtime-check', [
            'since' => $thought->created_at->subSecond()->toIso8601String(),
        ]));

        $response->assertOk();
        $response->assertJsonPath('has_new', false);
    }

    public function test_realtime_check_returns_has_new_true_when_new_thought_after_since(): void
    {
        $user = User::factory()->create();
        $old = Thought::factory()->create(['user_id' => $user->id, 'created_at' => now()->subMinutes(5)]);
        $new = Thought::factory()->create(['user_id' => $user->id, 'created_at' => now()]);

        $response = $this->actingAs($user)->getJson(route('api.thoughts.realtime-check', [
            'since' => $old->created_at->toIso8601String(),
        ]));

        $response->assertOk();
        $response->assertJsonPath('has_new', true);
    }
}
```

Run: `php artisan test tests/Feature/RealtimeCheckEndpointTest.php`  
Expected: FAIL (route or method missing).

- [ ] **Step 2: Add route**

In `routes/web.php`, inside the `auth` middleware group, add:

```php
Route::get('/api/thoughts/realtime-check', [App\Http\Controllers\Api\RealtimeCheckController::class, 'realtimeCheck'])->name('api.thoughts.realtime-check');
```

- [ ] **Step 3: Create controller**

Create `app/Http/Controllers/Api/RealtimeCheckController.php`:

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Thought;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RealtimeCheckController extends Controller
{
    public function realtimeCheck(Request $request): JsonResponse
    {
        $request->validate(['since' => 'required|date']);

        $since = $request->input('since');
        $hasNew = Thought::query()
            ->where('user_id', $request->user()->id)
            ->where('created_at', '>', $since)
            ->exists();

        return response()->json(['has_new' => $hasNew]);
    }
}
```

- [ ] **Step 4: Run test**

Run: `php artisan test tests/Feature/RealtimeCheckEndpointTest.php`  
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Api/RealtimeCheckController.php routes/web.php tests/Feature/RealtimeCheckEndpointTest.php
git commit -m "feat: add realtime-check endpoint for polling driver"
```

### Task 2.2: Home (index) refetch — AJAX first-page HTML when no search

**Files:** Modify `app/Http/Controllers/IdeaController.php`, Ensure front end can call with Accept: application/json

- [ ] **Step 1: Add AJAX branch in index() for recent-only**

In `IdeaController::index()`, when there is no search (`$query === ''`), after building `$thoughts` (recent thoughts), add:

If `$request->ajax()` (or `$request->wantsJson()`), render `idea.index_thought_cards` with `$thoughts` and `replyableIndexStart => 0`, return JSON `{ html: $html, total: $thoughts->count() }` (for recent we use a Collection, so count()). Do not return the full view.

- [ ] **Step 2: Implement**

In the branch where `$query === ''` (the else branch that builds recent thoughts), before `$replyingTo = null;`, add:

```php
if ($request->ajax()) {
    $html = view('idea.index_thought_cards', ['thoughts' => $thoughts, 'replyableIndexStart' => 0])->render();
    return response()->json([
        'html' => $html,
        'total' => $thoughts->count(),
    ]);
}
```

- [ ] **Step 3: Commit**

```bash
git add app/Http/Controllers/IdeaController.php
git commit -m "feat: index returns recent thoughts HTML for AJAX (realtime refetch)"
```

### Task 2.3: Ideas refetch — AJAX first-page HTML

**Files:** Modify `app/Http/Controllers/IdeaController.php`, Add route or reuse ideas with AJAX

- [ ] **Step 1: Add AJAX branch in ideas()**

In `IdeaController::ideas()`, after building `$ideas` and `$researchByIdea`, add: if `$request->ajax()`, render the ideas list partial (the part that loops ideas) and return JSON `{ html: $html }`. Need a partial that renders just the ideas list; check `resources/views/idea/ideas.blade.php` for the list structure. If there is no partial, render the main content area that contains the ideas. Example: create `resources/views/idea/partials/ideas_list.blade.php` that contains the loop over `$ideas` and research blocks, then in `ideas()`:

```php
if ($request->ajax()) {
    $html = view('idea.partials.ideas_list', ['ideas' => $ideas, 'researchByIdea' => $researchByIdea])->render();
    return response()->json(['html' => $html]);
}
```

- [ ] **Step 2: Extract partial if needed**

If `ideas.blade.php` has the list inline, extract the list into `idea/partials/ideas_list.blade.php` and @include it in both the full view and the AJAX response.

- [ ] **Step 3: Commit**

```bash
git add app/Http/Controllers/IdeaController.php resources/views/idea/ideas.blade.php resources/views/idea/partials/ideas_list.blade.php
git commit -m "feat: ideas page returns first-page HTML for AJAX (realtime refetch)"
```

---

## Chunk 3: Front-end — realtime config, shared handler, Echo + polling, page integration

### Task 3.1: Pass realtime config to front end (layout)

**Files:** Modify `resources/views/layouts/idea.blade.php`

- [ ] **Step 1: Add script config for realtime**

In `resources/views/layouts/idea.blade.php`, before `@stack('scripts')`, add a script block that sets a global config (only when auth and on a page that needs realtime — we'll add page-specific scripts in stream, index, ideas). For simplicity, set config once in layout when auth:

```blade
@auth
<script>
window.ideatub = window.ideatub || {};
window.ideatub.realtime = @json([
    'driver' => config('realtime.driver'),
    'reverb_key' => config('broadcasting.connections.reverb.key'),
    'reverb_host' => config('broadcasting.connections.reverb.options.host'),
    'reverb_port' => config('broadcasting.connections.reverb.options.port'),
    'reverb_scheme' => config('broadcasting.connections.reverb.options.scheme', 'https'),
    'user_id' => auth()->id(),
    'recent_url' => route('idea.index'),
    'stream_url' => route('idea.stream'),
    'ideas_url' => route('idea.ideas'),
    'realtime_check_url' => route('api.thoughts.realtime-check'),
]);
</script>
@endauth
```

Guard reverb_key/host/port with null if reverb config is missing so JS does not break.

- [ ] **Step 2: Implement with null-safe reverb config**

Use `config('broadcasting.connections.reverb.key')` etc.; if Reverb is not installed these may be null — in that case the front end will use polling.

- [ ] **Step 3: Commit**

```bash
git add resources/views/layouts/idea.blade.php
git commit -m "feat: pass realtime driver and URLs to front end"
```

### Task 3.2: Shared refetch handler and driver logic (Stream)

**Files:** Modify `resources/views/idea/stream.blade.php`

- [ ] **Step 1: Add refetch and driver init for Stream**

In `stream.blade.php`, inside `@push('scripts')` (create one if not present), add script that:
- Reads `window.ideatub.realtime` (if missing, exit).
- Defines `refetchStream()`: fetch stream first page (current tag if any) with Accept: application/json, get `data.html`, replace `#stream-thoughts-list` innerHTML, update `#stream-showing-count` and `#stream-total-count` from response if present.
- If driver is `reverb` and reverb_key exists: load Echo (dynamic import or assume Echo is available), subscribe to `private-App.Models.User.${userId}`, listen for `ThoughtCreated`, call `refetchStream()`.
- If driver is `polling` or reverb not available: setInterval every 20s, call `realtime_check_url?since=<last_known_created_at>`, if `has_new` then `refetchStream()`. Use the first thought's `created_at` in the list for `since` (from data attribute or parse from DOM), or page load time.
- Only run when `#stream-thoughts-list` exists (page has thoughts).

Stream refetch URL: same as load more but page=1: `data-stream-base-url` from sentinel or from a data attribute on a container; add `data-stream-refetch-url` to the stream container with the first-page URL.

- [ ] **Step 2: Add data attribute for refetch URL**

In `stream.blade.php`, on the div that wraps the list (or on a new data element), set `data-stream-refetch-url="{{ $tagSlug ? route('idea.stream', ['tag' => $tagSlug]) : route('idea.stream') }}?page=1"` so the script can fetch with Accept: application/json.

- [ ] **Step 3: Implement script (concise)**

Use vanilla JS: refetchStream fetches the refetch URL with headers `Accept: application/json`, `X-Requested-With: XMLHttpRequest`, then replaces list and counts. For Echo, if laravel-echo and pusher-js are not yet added, add them in a follow-up; for this task, implement only the polling path so the feature works locally. In a subsequent task add Echo.

- [ ] **Step 4: Commit**

```bash
git add resources/views/idea/stream.blade.php
git commit -m "feat(stream): realtime refetch via polling"
```

### Task 3.3: Add Laravel Echo and Reverb client (when driver is reverb)

**Files:** `package.json`, `resources/js/app.js` or new Echo bootstrap, Layout script

- [ ] **Step 1: Install Echo and Pusher**

Run: `npm install laravel-echo pusher-js`

- [ ] **Step 2: Bootstrap Echo when driver is reverb**

In the layout or in a small JS file that runs on Stream/Home/Ideas, when `window.ideatub.realtime.driver === 'reverb'` and reverb_key is set, create Echo instance with Pusher driver, using credentials from `window.ideatub.realtime`. Laravel Echo expects `window.Echo` or similar. Use the auth endpoint `/broadcasting/auth` (default). Ensure CSRF token is sent (cookie or header). Document in plan that Echo must be initialized after the config script.

- [ ] **Step 3: Subscribe and call refetch on ThoughtCreated**

In the same script that runs on Stream (and later Home/Ideas), after Echo is ready: `Echo.private('App.Models.User.' + userId).listen('.ThoughtCreated', () => refetchStream());`. Use the event name that matches `broadcastAs()` (e.g. `.ThoughtCreated`).

- [ ] **Step 4: Commit**

```bash
git add package.json package-lock.json resources/views/idea/stream.blade.php
git commit -m "feat: add Echo and subscribe to ThoughtCreated on Stream when reverb"
```

### Task 3.4: Home (index) realtime refetch

**Files:** Modify `resources/views/idea/index.blade.php`

- [ ] **Step 1: Add realtime script for index**

Only when there is no search (`@if(!$query)`), add a script that: defines `refetchIndex()` (fetch `idea.index` with Accept: application/json, replace `#index-thoughts-list` with `data.html`, update count if present). If driver is reverb, subscribe and on ThoughtCreated call refetchIndex(); if polling, poll realtime-check and on has_new call refetchIndex(). Use the same pattern as Stream (data attribute for refetch URL: `{{ route('idea.index') }}` with Accept: application/json).

- [ ] **Step 2: Commit**

```bash
git add resources/views/idea/index.blade.php
git commit -m "feat(index): realtime refetch for recent thoughts"
```

### Task 3.5: Ideas page realtime refetch

**Files:** Modify `resources/views/idea/ideas.blade.php`

- [ ] **Step 1: Add realtime script for ideas**

Add script: refetchIdeas() fetches ideas URL with Accept: application/json, replaces the ideas list container with response.html. On ThoughtCreated (or has_new), call refetchIdeas() only if the event payload suggests an idea (metadata.type === 'idea') — optional for v1; simpler to always refetch on any new thought. Add data attribute for ideas list container id and refetch URL.

- [ ] **Step 2: Commit**

```bash
git add resources/views/idea/ideas.blade.php
git commit -m "feat(ideas): realtime refetch for ideas list"
```

### Task 3.6: Optional — fallback to polling when Reverb fails

**Files:** Modify realtime scripts in stream, index, ideas

- [ ] **Step 1: On Echo connection error, start polling**

If Echo errors (e.g. connection refused), set driver to polling and start the interval. Improves resilience when Reverb is misconfigured.

- [ ] **Step 2: Commit**

```bash
git add resources/views/idea/stream.blade.php resources/views/idea/index.blade.php resources/views/idea/ideas.blade.php
git commit -m "feat(realtime): fallback to polling when Echo fails"
```

---

## Chunk 4: Testing and docs

### Task 4.1: Manual testing checklist

- [ ] **Step 1: Document manual test steps**

Add to the plan or to `docs/` a short checklist: (1) Set REALTIME_DRIVER=polling, open Stream, create thought via MCP or second tab, confirm list updates within ~20s. (2) Set REALTIME_DRIVER=reverb and run Reverb server, open Stream, create thought elsewhere, confirm list updates immediately. (3) Repeat for Home and Ideas.

- [ ] **Step 2: Commit**

```bash
git add docs/superpowers/plans/2026-03-16-realtime-stream.md
git commit -m "docs: realtime manual test checklist"
```

---

## Manual testing checklist

After implementation, verify:

1. **Polling (REALTIME_DRIVER=polling or unset)**
   - Open Stream (or Home, or Ideas) in a browser; ensure you have at least one thought/idea.
   - In another tab or via MCP, create a new thought.
   - Within ~20 seconds the first tab’s list should update without refresh (new thought appears).

2. **Reverb (REALTIME_DRIVER=reverb, Reverb server running)**
   - Set `REALTIME_DRIVER=reverb` and `BROADCAST_CONNECTION=reverb` in `.env`; run `php artisan reverb` (or use Laravel Cloud WebSockets).
   - Open Stream (or Home, or Ideas); create a thought via MCP or another tab.
   - The open tab’s list should update within a second (no 20s delay).

3. **All three pages**
   - Repeat for Stream, Home (recent thoughts, no search), and Ideas so that each page’s list updates when a new thought (or idea) is created elsewhere.

---

## Execution handoff

After completing the plan (and any plan-document review): **"Plan complete and saved to `docs/superpowers/plans/2026-03-16-realtime-stream.md`. Ready to execute?"**

Use **superpowers:subagent-driven-development** (if subagents available) or **superpowers:executing-plans** to implement.
