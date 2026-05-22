# On-page document share discoverability — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make public readonly document sharing discoverable on stream/type cards and thought detail via a visible Share control, inline create modal, and redirect-back store flow — without changing share eligibility or database schema.

**Architecture:** Add `DocumentShareReturnTo` to validate safe post-create redirects. Extend `SharedResearchController::store` to redirect to `return_to` when valid, else existing index behavior. Introduce `document_share_widget` Blade partial (Alpine modal + states) used on detail and stream cards; keep password/revoke management on Shared documents index.

**Tech Stack:** Laravel 12, PHP 8.2+, Blade, Alpine.js (existing), Pest feature tests, `ResearchShare` model.

**Spec:** `docs/superpowers/specs/2026-05-22-document-share-on-page-discovery-design.md`

---

## File map

| File | Role |
|------|------|
| `app/Support/SharedResearch/DocumentShareReturnTo.php` | Validate/normalize `return_to` URLs |
| `tests/Unit/Support/DocumentShareReturnToTest.php` | Unit tests for allowlist / open-redirect rejection |
| `app/Http/Controllers/SharedResearchController.php` | `store()` redirect-back + session flash for created URL |
| `resources/views/idea/partials/document_share_widget.blade.php` | Modal, form, Shared badge, copy UI |
| `resources/views/idea/partials/thought_detail_document_share_links.blade.php` | Replace body with `@include` widget |
| `resources/views/idea/stream_thoughts.blade.php` | Top-right: widget + ⋮ menu |
| `resources/views/idea/index_thought_cards.blade.php` | Same layout if cards use actions |
| `resources/views/idea/partials/thought_card_actions.blade.php` | Remove Share entries from ⋮ (Edit/Delete only) |
| `app/View/Presenters/Thoughts/StreamThoughtCardPresenter.php` | `hasDocumentShare(): bool` |
| `resources/views/help.blade.php` | One-line discoverability hint |
| `tests/Feature/SharedResearchControllerTest.php` | Redirect-back cases; preserve index default |
| `tests/Feature/ThoughtShowPageTest.php` | Widget + modal form, not index `?create=` link |
| `tests/Feature/ThoughtTypePagesTest.php` | Stream: visible Share / data attribute, not index URL in menu |

---

### Task 1: `DocumentShareReturnTo` validator

**Files:**
- Create: `app/Support/SharedResearch/DocumentShareReturnTo.php`
- Create: `tests/Unit/Support/DocumentShareReturnToTest.php`

- [ ] **Step 1: Write failing unit tests**

```php
<?php

namespace Tests\Unit\Support;

use App\Support\SharedResearch\DocumentShareReturnTo;
use Tests\TestCase;

class DocumentShareReturnToTest extends TestCase
{
    public function test_accepts_thought_detail_path(): void
    {
        $id = '00000000-0000-4000-8000-000000000001';
        $url = url('/thoughts/'.$id);

        $this->assertSame($url, DocumentShareReturnTo::resolve($url));
    }

    public function test_accepts_stream_paths(): void
    {
        foreach (['/stream', '/stream/plans', '/stream/meetings', '/stream/research'] as $path) {
            $url = url($path);
            $this->assertSame($url, DocumentShareReturnTo::resolve($url), $path);
        }
    }

    public function test_accepts_home_index(): void
    {
        $url = url('/');
        $this->assertSame($url, DocumentShareReturnTo::resolve($url));
    }

    public function test_rejects_external_url(): void
    {
        $this->assertNull(DocumentShareReturnTo::resolve('https://evil.example/thoughts/x'));
    }

    public function test_rejects_disallowed_path(): void
    {
        $this->assertNull(DocumentShareReturnTo::resolve(url('/login')));
    }

    public function test_null_and_empty_return_null(): void
    {
        $this->assertNull(DocumentShareReturnTo::resolve(null));
        $this->assertNull(DocumentShareReturnTo::resolve(''));
    }
}
```

- [ ] **Step 2: Run tests (expect FAIL)**

```bash
cd /Users/rosstweedie/Sites/ideatub && php artisan test tests/Unit/Support/DocumentShareReturnToTest.php
```

- [ ] **Step 3: Implement resolver**

```php
<?php

namespace App\Support\SharedResearch;

final class DocumentShareReturnTo
{
    /** @var list<string> */
    private const ALLOWED_PATH_PREFIXES = [
        '/stream',
        '/thoughts/',
    ];

    public static function resolve(?string $returnTo): ?string
    {
        if ($returnTo === null || trim($returnTo) === '') {
            return null;
        }

        $parsed = parse_url($returnTo);
        $path = $parsed['path'] ?? null;
        if (! is_string($path) || $path === '') {
            return null;
        }

        $appHost = parse_url(config('app.url'), PHP_URL_HOST);
        if (isset($parsed['host']) && $parsed['host'] !== $appHost) {
            return null;
        }

        if ($path === '/') {
            return url('/');
        }

        foreach (self::ALLOWED_PATH_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return url($path).(isset($parsed['query']) ? '?'.$parsed['query'] : '');
            }
        }

        return null;
    }
}
```

- [ ] **Step 4: Run tests (expect PASS)**

```bash
php artisan test tests/Unit/Support/DocumentShareReturnToTest.php
```

- [ ] **Step 5: Commit**

```bash
git add app/Support/SharedResearch/DocumentShareReturnTo.php tests/Unit/Support/DocumentShareReturnToTest.php
git commit -m "feat(share): validate document share return_to URLs"
```

---

### Task 2: `SharedResearchController::store` redirect-back

**Files:**
- Modify: `app/Http/Controllers/SharedResearchController.php`
- Modify: `tests/Feature/SharedResearchControllerTest.php`

- [ ] **Step 1: Add failing feature tests**

Add to `SharedResearchControllerTest.php`:

```php
public function test_store_with_return_to_redirects_to_thought_detail(): void
{
    $user = User::factory()->create();
    $thought = Thought::factory()->create([
        'user_id' => $user->id,
        'content' => 'Plan for return_to',
        'parent_id' => null,
        'source' => 'web',
        'metadata' => ['type' => 'plan'],
    ]);
    $returnTo = route('thoughts.show', $thought);

    $response = $this->actingAs($user)->post(route('shared-research.store'), [
        'thought_id' => $thought->id,
        'return_to' => $returnTo,
        '_token' => csrf_token(),
    ]);

    $share = ResearchShare::where('thought_id', $thought->id)->first();
    $this->assertNotNull($share);
    $response->assertRedirect($returnTo);
    $response->assertSessionHas('success');
    $response->assertSessionHas('document_share_url', url(route('shared-research.show', $share->token)));
}

public function test_store_with_invalid_return_to_falls_back_to_index(): void
{
    $user = User::factory()->create();
    $thought = Thought::factory()->create(['user_id' => $user->id] + self::SHAREABLE_ROOT);

    $response = $this->actingAs($user)->post(route('shared-research.store'), [
        'thought_id' => $thought->id,
        'return_to' => 'https://evil.example/phish',
        '_token' => csrf_token(),
    ]);

    $share = ResearchShare::where('thought_id', $thought->id)->first();
    $response->assertRedirect(route('shared-research.index', ['share' => $share->id]));
}

public function test_store_already_shared_with_return_to_redirects_back_with_error(): void
{
    $user = User::factory()->create();
    $thought = Thought::factory()->create(['user_id' => $user->id] + self::SHAREABLE_ROOT);
    ResearchShare::factory()->create(['user_id' => $user->id, 'thought_id' => $thought->id]);
    $returnTo = route('thoughts.show', $thought);

    $response = $this->actingAs($user)->post(route('shared-research.store'), [
        'thought_id' => $thought->id,
        'return_to' => $returnTo,
        '_token' => csrf_token(),
    ]);

    $response->assertRedirect($returnTo);
    $response->assertSessionHas('error');
}
```

Keep `test_store_creates_share_and_redirects_with_share_param` — no `return_to` → index redirect (unchanged).

- [ ] **Step 2: Run new tests (expect FAIL)**

```bash
php artisan test tests/Feature/SharedResearchControllerTest.php --filter=return_to
```

- [ ] **Step 3: Implement store redirect helper**

At end of successful create in `store()`:

```php
use App\Support\SharedResearch\DocumentShareReturnTo;

// after ResearchShare::create(...)
$shareUrl = url(route('shared-research.show', $share->token));
$returnTo = DocumentShareReturnTo::resolve($request->input('return_to'));

if ($returnTo !== null) {
    return redirect()->to($returnTo)
        ->with('success', 'Share link created.')
        ->with('document_share_url', $shareUrl);
}

return redirect()
    ->route('shared-research.index', ['share' => $share->id])
    ->with('success', 'Link created. Copy below.');
```

For error paths (`! $isVisibleInStream`, `! isShareableDocumentRoot`, already shared): when `DocumentShareReturnTo::resolve($request->input('return_to'))` is non-null, redirect there with `withErrors` / `with('error', ...)` instead of `shared-research.index`.

- [ ] **Step 4: Run full controller tests**

```bash
php artisan test tests/Feature/SharedResearchControllerTest.php
```

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/SharedResearchController.php tests/Feature/SharedResearchControllerTest.php
git commit -m "feat(share): redirect store back to document context"
```

---

### Task 3: `document_share_widget` partial

**Files:**
- Create: `resources/views/idea/partials/document_share_widget.blade.php`

- [ ] **Step 1: Create partial**

Props (set by parent before `@include`):

- `$thought` — `Thought` model
- `$share` — `?ResearchShare`
- `$returnTo` — current page URL string (`url()->current()` or `route('thoughts.show', $thought)`)
- `$placement` — `'detail'` | `'card'` (controls compact vs full layout)

Behavior:

- **Not shared:** button **Share** sets `shareModalOpen = true`.
- **Shared:** pill **Shared** + **Copy link** + **Open** (+ **Manage** on detail only).
- **Modal:** `POST route('shared-research.store')` with `@csrf`, hidden `thought_id`, `return_to`, optional `password`, `expires_at`. Match field classes from `shared_research/index.blade.php`.
- **Success flash:** if `session('document_share_url')`, show teal banner with URL + Copy button (Alpine or inline `@click` clipboard).
- **Errors:** show `@error` / session error under modal title.
- **Demo:** wrap in `@if (! app(\App\Services\DemoMode::class)->enabled())` when used on owner pages (parent passes `$editable` or check auth owner).

Alpine `x-data` example on wrapper:

```html
<div x-data="{ shareModalOpen: @json($errors->has('password') || $errors->has('expires_at')) }" class="...">
```

Use `fixed inset-0 z-[60]` dialog pattern from `capture_box.blade.php` (backdrop + `role="dialog"`).

- [ ] **Step 2: Manual smoke** (optional during dev): load detail on eligible plan — modal opens, form posts.

- [ ] **Step 3: Commit**

```bash
git add resources/views/idea/partials/document_share_widget.blade.php
git commit -m "feat(ui): document share widget with create modal"
```

---

### Task 4: Wire thought detail page

**Files:**
- Modify: `resources/views/idea/partials/thought_detail_document_share_links.blade.php`
- Modify: `resources/views/idea/show.blade.php` (only if success flash block belongs at page level)

- [ ] **Step 1: Replace detail share links partial**

```blade
@php
    $thought = $thoughtDetail->thought();
@endphp
@include('idea.partials.document_share_widget', [
    'thought' => $thought,
    'share' => $thoughtDetail->documentShare(),
    'returnTo' => route('thoughts.show', $thought),
    'placement' => 'detail',
])
```

- [ ] **Step 2: Update failing detail test**

In `ThoughtShowPageTest::test_shareable_document_detail_shows_create_share_link`:

- `assertSee('Create share link')` → `assertSee('Share', false)` and `assertSee('name="return_to"', false)` and `assertSee(route('shared-research.store'), false)`
- `assertDontSee(route('shared-research.index', ['create' => $thought->id], false), false)`

Add `test_shareable_document_detail_shows_shared_badge_when_link_exists`:

```php
ResearchShare::factory()->create(['user_id' => $owner->id, 'thought_id' => $thought->id]);
$response = $this->actingAs($owner)->get(route('thoughts.show', $thought));
$response->assertSee('Shared', false);
$response->assertSee('Copy link', false);
```

- [ ] **Step 3: Run tests**

```bash
php artisan test tests/Feature/ThoughtShowPageTest.php --filter=share
```

- [ ] **Step 4: Commit**

```bash
git add resources/views/idea/partials/thought_detail_document_share_links.blade.php tests/Feature/ThoughtShowPageTest.php
git commit -m "feat(ui): inline document share on thought detail"
```

---

### Task 5: Stream / index cards — visible widget outside ⋮

**Files:**
- Modify: `resources/views/idea/stream_thoughts.blade.php`
- Modify: `resources/views/idea/index_thought_cards.blade.php` (if same top-right pattern)
- Modify: `resources/views/idea/partials/thought_card_actions.blade.php`
- Modify: `app/View/Presenters/Thoughts/StreamThoughtCardPresenter.php`

- [ ] **Step 1: Add presenter accessor**

```php
public function hasDocumentShare(): bool
{
    return $this->share !== null;
}
```

Same on `IdeaIndexCardPresenter` if index cards expose share.

- [ ] **Step 2: Change stream card header**

Replace single `thought_card_actions` include with:

```blade
<div class="absolute top-3 right-3 flex items-center gap-2">
    @if ($card->editable() && $card->documentShareEligible())
        @include('idea.partials.document_share_widget', [
            'thought' => $card->thought(),
            'share' => $card->share(),
            'returnTo' => url()->current(),
            'placement' => 'card',
        ])
    @endif
    @include('idea.partials.thought_card_actions', [
        'thought' => $card->thought(),
        'editable' => $card->editable(),
        'share' => $card->share(),
        'documentShareEligible' => $card->documentShareEligible(),
    ])
</div>
```

Increase card `pr-*` if badge overlaps title (e.g. `pr-24` when eligible).

- [ ] **Step 3: Strip Share from ⋮ menu**

In `thought_card_actions.blade.php`, remove the `@if ($isRootThought && $documentShareEligible)` block (lines 29–52). Keep Edit/Delete only.

- [ ] **Step 4: Update stream feature test**

`ThoughtTypePagesTest::test_main_stream_shows_document_share_menu_only_for_shareable_roots`:

- Assert `data-document-share` or plain text **Share** visible for eligible (widget button).
- `assertDontSee(route('shared-research.index', ['create' => $eligible->id], false), false)` — create no longer deep-links to index from card.
- Add test: eligible + existing `ResearchShare` → assert **Shared** visible.

- [ ] **Step 5: Run tests**

```bash
php artisan test tests/Feature/ThoughtTypePagesTest.php --filter=share
php artisan test tests/Feature/ThoughtShowPageTest.php --filter=share
```

- [ ] **Step 6: Commit**

```bash
git add resources/views/idea/stream_thoughts.blade.php resources/views/idea/partials/thought_card_actions.blade.php app/View/Presenters/Thoughts/StreamThoughtCardPresenter.php tests/Feature/ThoughtTypePagesTest.php
git commit -m "feat(ui): visible document share on stream cards"
```

---

### Task 6: Help copy and regression sweep

**Files:**
- Modify: `resources/views/help.blade.php`

- [ ] **Step 1: Extend Share documents bullet**

After existing Share documents sentence, add: “On an eligible document, use **Share** on the Stream card or thought detail page to create and copy a link without leaving the page.”

- [ ] **Step 2: Run related feature suite**

```bash
php artisan test tests/Feature/SharedResearchControllerTest.php tests/Feature/SharedResearchIndexTest.php tests/Feature/ThoughtShowPageTest.php tests/Feature/ThoughtTypePagesTest.php tests/Unit/Support/DocumentShareReturnToTest.php
```

- [ ] **Step 3: Commit**

```bash
git add resources/views/help.blade.php
git commit -m "docs(help): mention on-page document sharing"
```

---

## Spec coverage checklist

| Spec requirement | Task |
|------------------|------|
| Visible Share outside ⋮ | Task 5 |
| Inline modal create | Task 3–5 |
| Shared badge when link exists | Task 3–5 |
| `return_to` validation | Task 1–2 |
| Index manage unchanged | Task 2 (no `return_to` → index) |
| Eligibility unchanged | No task (reuse existing) |
| Demo mode gate | Task 3–4 |
| Feature tests | Tasks 1–2, 4–5 |
| Help hint | Task 6 |

## Out of scope (per spec)

- JSON store API, inline revoke/password, eligibility expansion, nav promotion of Shared documents index.

---

## Execution handoff

Plan complete and saved to `docs/superpowers/plans/2026-05-22-document-share-on-page-discovery.md`.

**Two execution options:**

1. **Subagent-driven (recommended)** — fresh subagent per task, review between tasks  
2. **Inline execution** — run tasks in this session with executing-plans checkpoints  

Which approach do you want?
