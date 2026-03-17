# Shareable readonly research — Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let owners share a readonly link to an entire research note (root thought + sections) with optional per-share password and expiry; no WebSockets on the share view; minimal branding.

**Architecture:** New `research_shares` table (user_id, thought_id, token, password_hash, expires_at). Public route `GET /r/{token}` resolves share, checks expiry, optional password (form + signed cookie), then renders root + children via Blade. Owner routes under auth: list/create/update/delete shares; one share per thought in v1. Share action on root thought card and dedicated Shared research page with copy/revoke/password/expiry.

**Tech Stack:** Laravel 12, PHP 8.2+, Blade, Tailwind (existing), Cookie (signed).

**Spec:** `docs/superpowers/specs/2026-03-17-shareable-research-design.md`

---

## File structure

| File | Responsibility |
|------|----------------|
| `database/migrations/2026_03_17_200000_create_research_shares_table.php` | research_shares schema |
| `app/Models/ResearchShare.php` | Model: user, thought, token, password_hash, expires_at; relations; token generation |
| `app/Policies/ResearchSharePolicy.php` | view/update/delete: only share owner |
| `app/Http/Controllers/SharedResearchViewController.php` | GET /r/{token}: resolve, 404/410, password gate, render readonly view |
| `app/Http/Controllers/SharedResearchController.php` | GET/POST/PATCH/DELETE /shared-research: index, store, update, destroy |
| `resources/views/layouts/minimal.blade.php` | Minimal layout (no nav; optional footer “Shared via IdeaTub”) |
| `resources/views/shared_research/readonly.blade.php` | Readonly root + sections content |
| `resources/views/shared_research/password_form.blade.php` | “Enter password to view” form |
| `resources/views/shared_research/index.blade.php` | List shares, copy link, revoke, set password/expiry, “Share another” |
| `resources/views/idea/partials/thought_card_actions.blade.php` | Add Share action for root thoughts (existing partial) |
| `routes/web.php` | GET /r/{token}; auth group: shared-research routes |
| `app/Providers/AuthServiceProvider.php` | Register ResearchSharePolicy (if policy registration is per-model) |

---

## Chunk 1: Data layer — migration, model, policy

### Task 1.1: Migration `research_shares`

**Files:**
- Create: `database/migrations/2026_03_17_200000_create_research_shares_table.php`

- [ ] **Step 1: Create migration**

Run: `php artisan make:migration create_research_shares_table`

Then edit the created file to match the spec. `thoughts.id` is UUID; use `foreignUuid('thought_id')` and reference `thoughts.id`.

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('research_shares', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('thought_id')->constrained('thoughts')->cascadeOnDelete();
            $table->string('token', 64)->unique();
            $table->string('password_hash')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->index('token');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('research_shares');
    }
};
```

- [ ] **Step 2: Run migration**

Run: `php artisan migrate --path=database/migrations/2026_03_17_200000_create_research_shares_table.php`  
Expected: Migration ran successfully.

- [ ] **Step 3: Commit**

```bash
git add database/migrations/2026_03_17_200000_create_research_shares_table.php
git commit -m "feat(shares): add research_shares migration"
```

### Task 1.2: Model and policy

**Files:**
- Create: `app/Models/ResearchShare.php`
- Create: `app/Policies/ResearchSharePolicy.php`
- Modify: `app/Providers/AppServiceProvider.php` or `AuthServiceProvider.php` (if policies are registered there)

- [ ] **Step 1: Create model**

Run: `php artisan make:model ResearchShare`

Implement fillable, casts, relations, and static token generator:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ResearchShare extends Model
{
    protected $fillable = [
        'user_id',
        'thought_id',
        'token',
        'password_hash',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function thought(): BelongsTo
    {
        return $this->belongsTo(Thought::class);
    }

    public static function generateToken(): string
    {
        return Str::random(32);
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
```

- [ ] **Step 2: Create policy**

Run: `php artisan make:policy ResearchSharePolicy --model=ResearchShare`

Implement: `view`, `update`, `delete` — only when `$user->id === $researchShare->user_id`. No `create` on the policy (create is authorized via thought ownership in the controller).

```php
<?php

namespace App\Policies;

use App\Models\ResearchShare;
use App\Models\User;

class ResearchSharePolicy
{
    public function view(User $user, ResearchShare $researchShare): bool
    {
        return $researchShare->user_id === $user->id;
    }

    public function update(User $user, ResearchShare $researchShare): bool
    {
        return $researchShare->user_id === $user->id;
    }

    public function delete(User $user, ResearchShare $researchShare): bool
    {
        return $researchShare->user_id === $user->id;
    }
}
```

- [ ] **Step 3: Register policy**

Laravel 11+ typically auto-discovers policies when named `ResearchSharePolicy` for `ResearchShare`. If your app registers policies explicitly, add in `AppServiceProvider::boot()` or `AuthServiceProvider`:

```php
Gate::policy(ResearchShare::class, ResearchSharePolicy::class);
```

- [ ] **Step 4: Commit**

```bash
git add app/Models/ResearchShare.php app/Policies/ResearchSharePolicy.php
git commit -m "feat(shares): add ResearchShare model and policy"
```

---

## Chunk 2: Public view — GET /r/{token}, readonly page, password gate

### Task 2.1: Route and controller skeleton (no password yet)

**Files:**
- Create: `app/Http/Controllers/SharedResearchViewController.php`
- Modify: `routes/web.php`

- [ ] **Step 1: Add route**

In `routes/web.php`, add **outside** the `auth` middleware group (e.g. after guest routes, before auth group):

```php
Route::get('/r/{token}', [App\Http\Controllers\SharedResearchViewController::class, 'show'])
    ->name('shared-research.show');
```

- [ ] **Step 2: Create controller**

Run: `php artisan make:controller SharedResearchViewController`

Implement `show(string $token)`:
1. `$share = ResearchShare::where('token', $token)->first();` if null → abort(404, 'Link not found or no longer available.').
2. If `$share->isExpired()` → abort(410, 'This link has expired.').
3. If `$share->password_hash` is set: check signed cookie (see Task 2.3); for now assume no password (skip check so we can render content).
4. Load thought: `$thought = $share->thought;` if null → abort(404).
5. Load sections: `$sections = $thought->comments()->orderBy('created_at')->get();`
6. Return `view('shared_research.readonly', ['root' => $thought, 'sections' => $sections])`.

- [ ] **Step 3: Commit**

```bash
git add app/Http/Controllers/SharedResearchViewController.php routes/web.php
git commit -m "feat(shares): add GET /r/{token} route and controller"
```

### Task 2.2: Minimal layout and readonly view

**Files:**
- Create: `resources/views/layouts/minimal.blade.php`
- Create: `resources/views/shared_research/readonly.blade.php`

- [ ] **Step 1: Minimal layout**

Create a layout that extends no nav: same Vite assets and Tailwind, minimal branding. Optional small footer “Shared via IdeaTub”.

```blade
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Research')</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="font-sans antialiased min-h-screen" style="background: linear-gradient(135deg, #eef2ff 0%, #f3f0ff 50%, #f0f5ff 100%);">
    <main class="max-w-[600px] mx-auto px-6 py-12">
        @yield('content')
    </main>
    @hasSection('footer')
        @yield('footer')
    @else
        <footer class="max-w-[600px] mx-auto px-6 py-4 text-center">
            <p class="text-[11px] text-slate-brand/40">Shared via IdeaTub</p>
        </footer>
    @endif
</body>
</html>
```

- [ ] **Step 2: Readonly view**

Root content + sections; reuse IdeaTub text styles (e.g. `text-deep-indigo`, `text-slate-brand`). No edit/delete/reply.

```blade
@extends('layouts.minimal')

@section('title', Str::limit($root->content, 50))

@section('content')
<div class="rounded-xl border border-memory-violet/15 bg-white/80 px-4 py-4">
    <div class="whitespace-pre-line text-[13.5px] text-deep-indigo leading-relaxed">{{ $root->content }}</div>
    @if($sections->isNotEmpty())
        <ul class="mt-4 space-y-3 border-t border-memory-violet/10 pt-4">
            @foreach($sections as $section)
                <li>
                    <div class="whitespace-pre-line text-[12.5px] text-slate-brand leading-relaxed">{{ $section->content }}</div>
                    <p class="text-[10px] text-slate-brand/40 mt-1">{{ $section->created_at->diffForHumans() }}</p>
                </li>
            @endforeach
        </ul>
    @endif
</div>
@endsection
```

- [ ] **Step 3: Commit**

```bash
git add resources/views/layouts/minimal.blade.php resources/views/shared_research/readonly.blade.php
git commit -m "feat(shares): minimal layout and readonly research view"
```

### Task 2.3: Password form and cookie gate

**Files:**
- Create: `resources/views/shared_research/password_form.blade.php`
- Modify: `app/Http/Controllers/SharedResearchViewController.php`

- [ ] **Step 1: Cookie name and validation helper**

Use a cookie name scoped to this share so changing one share’s password doesn’t affect others. Either `research_share_<token>` (token is 32 chars; acceptable) or `research_share_<short_hash>` (e.g. first 16 chars of token or hash). Store a signed value (e.g. token or `1`); in controller verify signed. Laravel: `Cookie::queue(cookie()->make('research_share_'.$token, $token, 60 * 24)->secure(false)->httpOnly(true));` (24h). Use same name when forgetting on password change.

- [ ] **Step 2: Password form view**

Blade that extends `layouts.minimal`: title “Enter password to view”, form POST to same URL `route('shared-research.show', $token)` with method POST and `@csrf`. Field: `password`, label “Password”, button “View”. Display `$error` if passed (e.g. “Incorrect password”).

- [ ] **Step 3: Controller logic**

In `show($token)`:
- After 404/410 checks, if `$share->password_hash` is not null:
  - If request is POST with `password`: verify `Hash::check($request->password, $share->password_hash)`. If true: queue cookie (name `research_share_<token>`, value token, 24h, signed), redirect to GET same URL. If false: return password form view with error “Incorrect password”, HTTP 401 (per spec).
  - If request is GET: check cookie. If valid signed cookie for this token, proceed to load thought and render readonly. Otherwise return password form view (no error).
- Else (no password): load thought and render readonly.

- [ ] **Step 4: POST route**

Add `Route::post('/r/{token}', [SharedResearchViewController::class, 'unlock'])->name('shared-research.unlock');` and implement `unlock(Request $request, string $token)`: find share, 404/410 same as show; if no password_hash → redirect GET; verify password; set cookie; redirect GET. Or keep single route and in `show()` detect POST and run unlock logic (single action for GET and POST to same URL). Spec says “POST to same URL”; so in `show()` branch on `$request->isMethod('post')` for unlock.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/SharedResearchViewController.php resources/views/shared_research/password_form.blade.php routes/web.php
git commit -m "feat(shares): password form and per-share cookie gate"
```

---

## Chunk 3: Owner CRUD — Shared research page and controller

### Task 3.1: Routes and SharedResearchController index/store

**Files:**
- Create: `app/Http/Controllers/SharedResearchController.php`
- Modify: `routes/web.php`

- [ ] **Step 1: Add routes inside auth group**

```php
Route::get('/shared-research', [SharedResearchController::class, 'index'])->name('shared-research.index');
Route::post('/shared-research', [SharedResearchController::class, 'store'])->name('shared-research.store');
Route::patch('/shared-research/{researchShare}', [SharedResearchController::class, 'update'])->name('shared-research.update');
Route::delete('/shared-research/{researchShare}', [SharedResearchController::class, 'destroy'])->name('shared-research.destroy');
```

Use `researchShare` for route model binding (Laravel will resolve `ResearchShare` by id).

- [ ] **Step 2: Controller index and store**

Run: `php artisan make:controller SharedResearchController`

- `index()`: `ResearchShare::where('user_id', auth()->id())->with('thought')->orderByDesc('created_at')->get()`. Return `view('shared_research.index', ['shares' => $shares])`. Pass `request('share')` for focus id.
- `store(Request $request)`: Validate `thought_id` (required, exists:thoughts,id), `password` (nullable, string, min:1 if present), `expires_at` (nullable, date, after:now). Load thought; authorize `thought->user_id === auth()->id()` and `$thought->parent_id === null`. Check one share per thought: `ResearchShare::where('thought_id', $thought->id)->exists()` → redirect to `shared-research.index` with error flash “This research is already shared; manage it below.” Else create: token = ResearchShare::generateToken(), password_hash = $request->password ? Hash::make($request->password) : null, expires_at = $request->expires_at. Redirect to `shared-research.index` with success and new share id for focus (`?share=id`).

- [ ] **Step 3: Commit**

```bash
git add app/Http/Controllers/SharedResearchController.php routes/web.php
git commit -m "feat(shares): shared-research index and store"
```

### Task 3.2: Controller update and destroy

**Files:**
- Modify: `app/Http/Controllers/SharedResearchController.php`

- [ ] **Step 1: update(Request $request, ResearchShare $researchShare)**

Authorize: `$this->authorize('update', $researchShare)`. Validate: `password` (nullable, string), `password_remove` (optional boolean), `expires_at` (nullable, date). If password_remove or password provided: set `password_hash` to null or Hash::make($request->password). Set `expires_at` from request. Save. When changing password, clear the share-access cookie for this token (response with `Cookie::forget('research_share_'.$researchShare->token)`). Redirect back with success.

- [ ] **Step 2: destroy(ResearchShare $researchShare)**

Authorize delete. Delete the record. Redirect to shared-research.index with flash “Share revoked.”

- [ ] **Step 3: Commit**

```bash
git add app/Http/Controllers/SharedResearchController.php
git commit -m "feat(shares): update and destroy share"
```

### Task 3.3: Shared research index view

**Files:**
- Create: `resources/views/shared_research/index.blade.php`

- [ ] **Step 1: List and actions**

Extend `layouts.idea`. Title “Shared research”. Link “Share another” to `shared-research.index` with query or a simple create form (thought picker: link to Stream, or dropdown of user’s top-level thoughts). For each share: preview (e.g. `Str::limit($share->thought->content, 80)`), full share URL `route('shared-research.show', $share->token)` (use `url()` for full URL), “Copy” button (clipboard), “Protected” / “No password”, “Expires &lt;date&gt;” or “Never”, buttons/links: “Set/Change password”, “Set expiry”, “Revoke” (form DELETE). Use `request('share')` to add `id="share-{{ $share->id }}"` and optionally scroll into view or expand that row.

- [ ] **Step 2: Create flow on page**

“Share another”: form GET shared-research with thought_id (or show modal/form). Form POST to store with thought_id, optional password, optional expires_at. After POST, redirect to index with `?share=<new_id>` and flash “Link created. Copy below.”

- [ ] **Step 3: Copy-to-clipboard**

Use `navigator.clipboard.writeText(url)` in a small script or Alpine on Copy button; show brief “Copied” feedback.

- [ ] **Step 4: Commit**

```bash
git add resources/views/shared_research/index.blade.php
git commit -m "feat(shares): shared research index view and create flow"
```

---

## Chunk 4: Thought card Share action and stream integration

### Task 4.1: Load research shares in stream (and index if needed)

**Files:**
- Modify: `app/Http/Controllers/IdeaController.php` (stream method and optionally index)

- [ ] **Step 1: Stream**

In `stream()`, after paginating thoughts, load research shares for those thought ids: `$shareByThoughtId = ResearchShare::whereIn('thought_id', $thoughts->pluck('id'))->where('user_id', auth()->id())->get()->keyBy('thought_id');`. Pass to view: `'shareByThoughtId' => $shareByThoughtId`. In `stream.blade.php` pass `shareByThoughtId` into `stream_thoughts` partial.

- [ ] **Step 2: Stream view**

In `resources/views/idea/stream.blade.php`, the include is `@include('idea.stream_thoughts', ['thoughts' => $thoughts, 'showFullSections' => (bool) $tag, 'shareByThoughtId' => $shareByThoughtId ?? collect()])`. In stream_thoughts, for each thought (only root thoughts are in stream), `$share = $shareByThoughtId[$thought->id] ?? null`.

- [ ] **Step 3: Commit**

```bash
git add app/Http/Controllers/IdeaController.php resources/views/idea/stream.blade.php resources/views/idea/stream_thoughts.blade.php
git commit -m "feat(shares): load shareByThoughtId in stream"
```

### Task 4.2: Share action in thought card actions

**Files:**
- Modify: `resources/views/idea/partials/thought_card_actions.blade.php`
- Modify: `resources/views/idea/stream_thoughts.blade.php` (pass share for this thought)

- [ ] **Step 1: Pass share into partial**

In `stream_thoughts.blade.php`, each thought is root (stream shows only top-level). Pass `'share' => $shareByThoughtId[$thought->id] ?? null` into the card. In `thought_card_actions`, only show the Share action when the thought is a root (`$thought->parent_id === null`), so section/child thoughts in other views (e.g. index_thought_cards) don’t get a Share link. So: `@include('idea.partials.thought_card_actions', ['thought' => $thought, 'editable' => ..., 'share' => $shareByThoughtId[$thought->id] ?? null])`.

- [ ] **Step 2: Add Share in dropdown**

In thought_card_actions: only show Share/Copy link when `$thought->parent_id === null` (root thought), so section/child thoughts in other views (e.g. index) don’t get a Share link. If `$share` is null, show “Share” link to `route('shared-research.index', ['create' => $thought->id])` or to a create URL with thought_id. If `$share` is set, show “Copy link” (or “Manage share”) linking to `shared-research.index` with `?share=<id>` and optionally copy URL to clipboard. Implement “Share” as a link; “Copy link” can be a button that copies `url(route('shared-research.show', $share->token))`.

- [ ] **Step 3: Shared-research index create from thought**

If URL has `?create=<thought_id>`, pre-fill thought_id in the “Share another” form and optionally auto-submit or show the form expanded. Controller store already accepts thought_id; from stream, user clicks “Share” → goes to shared-research?create=uuid → form with that thought selected, user adds optional password/expiry and submits.

- [ ] **Step 4: Commit**

```bash
git add resources/views/idea/partials/thought_card_actions.blade.php resources/views/idea/stream_thoughts.blade.php resources/views/shared_research/index.blade.php
git commit -m "feat(shares): Share action on thought card and create from thought"
```

---

## Chunk 5: Feature tests

**Files:**
- Create: `tests/Feature/SharedResearchTest.php` (or split into multiple test files if preferred)

- [ ] **Step 1: Test public view — 200, 404, 410**

- Unauthenticated GET `/r/{token}` with valid share (no password): 200, body contains root content.
- GET `/r/invalid`: 404.
- Share with expires_at in past: 410.

- [ ] **Step 2: Test password gate**

- Share with password: GET returns password form (200). POST with wrong password: 200 with error. POST with correct password: redirect to GET, then GET returns 200 with content. Cookie present in response after successful POST.

- [ ] **Step 3: Test owner create/list/revoke**

- Authenticated POST create: thought_id required, thought must be owner’s and root; success redirects to index. Second create for same thought: redirect with “already shared”.
- GET shared-research: list only current user’s shares.
- DELETE share: 204 or redirect; GET /r/{token} then 404.

- [ ] **Step 4: Test update password and cookie invalidation**

- PATCH to set password on share that had none; then GET /r/{token} shows form. After setting cookie, PATCH to change password; next GET with same cookie: should require password again (cookie forgotten or invalidated).

- [ ] **Step 5: Run tests**

Run: `php artisan test tests/Feature/SharedResearchTest.php`  
Expected: All tests pass.

- [ ] **Step 6: Commit**

```bash
git add tests/Feature/SharedResearchTest.php
git commit -m "test(shares): feature tests for share view, password, CRUD"
```

---

## Chunk 6: Nav link and polish

- [ ] **Step 1: Add “Shared research” to app nav**

In `layouts/idea.blade.php` (or wherever nav links are), add a link to `route('shared-research.index')` so users can open the dedicated page (e.g. “Shared” or “Shared research”).

- [ ] **Step 2: Help or docs**

Optionally add one line to Help or CLAUDE.md: how to share a research note (Share on card or Shared research page).

- [ ] **Step 3: Commit**

```bash
git add resources/views/layouts/idea.blade.php
git commit -m "feat(shares): nav link to Shared research"
```

---

## Summary checklist

| Chunk | Tasks |
|-------|--------|
| 1 | Migration; Model + Policy |
| 2 | GET /r/{token} controller; minimal layout + readonly view; password form + cookie |
| 3 | shared-research routes; index, store, update, destroy; index view |
| 4 | Stream loads shareByThoughtId; thought card Share action; create from thought |
| 5 | Feature tests |
| 6 | Nav link and optional docs |

Plan complete. Execute in order; run tests after each chunk.
