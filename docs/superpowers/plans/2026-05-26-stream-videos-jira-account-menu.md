# Stream Videos tab and Jira account menu — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a **Videos** stream collection tab (videos stay on All thoughts), remove **Jira** from stream segment tabs, and expose Jira activity via an account-menu link under Inbox.

**Architecture:** Extend `ThoughtTypeNavigation` with a `video` type and `show_in_stream_nav` per type; stream tabs iterate `orderedStreamNavTypes()` only. Add `IdeaController::streamVideos()` mirroring `streamArticles()`. Insert a gated Jira link in `layouts/idea.blade.php` after Inbox. No new video card UI — reuse existing stream presenters.

**Tech Stack:** Laravel 12, PHP 8.2, Blade, Pest/PHPUnit feature tests, `Thought::matchingCanonicalMetadataType()` scopes

**Spec:** `docs/superpowers/specs/2026-05-26-stream-videos-jira-account-menu-design.md`

---

## File structure

| File | Action |
|------|--------|
| `app/Support/ThoughtTypeNavigation.php` | Add `video`, `show_in_stream_nav`, `orderedStreamNavTypes()`, `showInStreamNav()`, video resolution |
| `app/Http/Controllers/IdeaController.php` | Add `streamVideos()` |
| `routes/web.php` | Register `GET /stream/videos` before `/stream` |
| `resources/views/idea/partials/stream_type_nav.blade.php` | Use `orderedStreamNavTypes()` |
| `resources/views/idea/stream.blade.php` | Empty state for `video` collection |
| `resources/views/layouts/idea.blade.php` | Account-menu Jira link |
| `tests/Unit/Support/ThoughtTypeNavigationTest.php` | Update expectations + new methods |
| `tests/Feature/ThoughtTypePagesTest.php` | Videos collection tests |
| `tests/Feature/StreamPageTest.php` | Nav: Videos in, Jira out; Jira page nav assertion |
| `tests/Feature/VideoStreamDisplayTest.php` | Optional: assert on `/stream/videos` |
| `tests/Feature/AccountMenuJiraLinkTest.php` | Create: account menu Jira visibility |

---

### Task 1: Extend `ThoughtTypeNavigation` (video + stream nav filter)

**Files:**
- Modify: `app/Support/ThoughtTypeNavigation.php`
- Modify: `tests/Unit/Support/ThoughtTypeNavigationTest.php`

- [ ] **Step 1: Update unit tests (red)**

In `tests/Unit/Support/ThoughtTypeNavigationTest.php`:

1. Change `test_ordered_nav_types_match_spec` to assert **full registry** keys:

```php
$this->assertSame(
    ['jira', 'email', 'research', 'plan', 'meeting', 'article', 'video'],
    ThoughtTypeNavigation::orderedNavTypes()
);
```

2. Add `test_ordered_stream_nav_types_excludes_jira_and_includes_video`:

```php
$this->assertSame(
    ['email', 'research', 'plan', 'meeting', 'article', 'video'],
    ThoughtTypeNavigation::orderedStreamNavTypes()
);
```

3. Add `test_show_in_stream_nav_for_jira_and_video`:

```php
$this->assertFalse(ThoughtTypeNavigation::showInStreamNav('jira'));
$this->assertTrue(ThoughtTypeNavigation::showInStreamNav('video'));
$this->assertTrue(ThoughtTypeNavigation::showInStreamNav('email'));
```

4. Add video label/route tests:

```php
$this->assertSame('Videos', ThoughtTypeNavigation::collectionLabel('video'));
$this->assertSame('Video', ThoughtTypeNavigation::thoughtDisplayLabel('video'));
$this->assertSame('idea.stream.videos', ThoughtTypeNavigation::routeName('video'));
```

5. Extend `test_resolve_thought_to_type_key_from_source_and_metadata`:

```php
$video = new Thought(['source' => 'video', 'metadata' => ['type' => 'video']]);
$this->assertSame('video', ThoughtTypeNavigation::resolveThoughtToTypeKey($video));

$videoMetaOnly = new Thought(['source' => 'web', 'metadata' => ['type' => 'video']]);
$this->assertSame('video', ThoughtTypeNavigation::resolveThoughtToTypeKey($videoMetaOnly));
```

- [ ] **Step 2: Run unit tests — expect FAIL**

```bash
cd /Users/rosstweedie/Sites/ideatub && php artisan test tests/Unit/Support/ThoughtTypeNavigationTest.php --stop-on-failure
```

- [ ] **Step 3: Implement navigation helper**

In `app/Support/ThoughtTypeNavigation.php`:

1. Extend the `@var` docblock on `TYPE_DEFINITIONS` to include optional `show_in_stream_nav: bool` (default true when absent).

2. Add `'show_in_stream_nav' => false` to the `jira` entry.

3. Append `video` definition:

```php
'video' => [
    'collection_label' => 'Videos',
    'thought_label' => 'Video',
    'route_name' => 'idea.stream.videos',
    'stored_values' => ['video'],
    'show_in_stream_nav' => true,
],
```

4. Add methods:

```php
public static function showInStreamNav(string $canonicalType): bool
{
    $key = self::normalizeTypeKey($canonicalType);
    if ($key === null || ! isset(self::TYPE_DEFINITIONS[$key])) {
        return false;
    }

    return self::TYPE_DEFINITIONS[$key]['show_in_stream_nav'] ?? true;
}

/**
 * @return list<string>
 */
public static function orderedStreamNavTypes(): array
{
    return array_values(array_filter(
        self::orderedNavTypes(),
        fn (string $key): bool => self::showInStreamNav($key)
    ));
}
```

5. In `resolveThoughtToTypeKey()`, after the `article` source branch, add:

```php
if ($sourceKey === 'video') {
    return 'video';
}
```

And after the `meeting` metadata branch:

```php
if ($metaType === 'video') {
    return 'video';
}
```

- [ ] **Step 4: Run unit tests — expect PASS**

```bash
php artisan test tests/Unit/Support/ThoughtTypeNavigationTest.php
```

- [ ] **Step 5: Commit**

```bash
git add app/Support/ThoughtTypeNavigation.php tests/Unit/Support/ThoughtTypeNavigationTest.php
git commit -m "feat: add video type and stream-nav visibility to ThoughtTypeNavigation"
```

---

### Task 2: Videos stream route and controller

**Files:**
- Modify: `routes/web.php`
- Modify: `app/Http/Controllers/IdeaController.php`
- Modify: `tests/Feature/ThoughtTypePagesTest.php`

- [ ] **Step 1: Add failing feature tests (red)**

In `tests/Feature/ThoughtTypePagesTest.php` add:

```php
public function test_videos_type_page_shows_only_video_roots(): void
{
    $user = User::factory()->create();
    Thought::factory()->create([
        'user_id' => $user->id,
        'content' => 'YouTube capture',
        'parent_id' => null,
        'source' => 'video',
        'metadata' => ['type' => 'video', 'video_id' => 'vid001', 'tags' => []],
    ]);
    Thought::factory()->create([
        'user_id' => $user->id,
        'content' => 'Plain note',
        'parent_id' => null,
        'source' => 'web',
    ]);

    $response = $this->actingAs($user)->get(route('idea.stream.videos'));

    $response->assertOk();
    $response->assertSee('Videos', false);
    $response->assertSee('YouTube capture');
    $response->assertDontSee('Plain note');
}

public function test_videos_stream_shows_empty_state_when_no_matching_thoughts_exist(): void
{
    $user = User::factory()->create();
    Thought::factory()->create([
        'user_id' => $user->id,
        'content' => 'Other',
        'parent_id' => null,
    ]);

    $response = $this->actingAs($user)->get(route('idea.stream.videos'));

    $response->assertOk();
    $response->assertSee('No videos yet.', false);
}
```

- [ ] **Step 2: Run tests — expect FAIL** (route/method missing)

```bash
php artisan test tests/Feature/ThoughtTypePagesTest.php --filter=videos
```

- [ ] **Step 3: Register route**

In `routes/web.php`, with other stream routes (before `Route::get('/stream', ...)`):

```php
Route::get('/stream/videos', [IdeaController::class, 'streamVideos'])->name('idea.stream.videos');
```

- [ ] **Step 4: Add controller method**

In `app/Http/Controllers/IdeaController.php`, after `streamArticles()`:

```php
/**
 * Video thoughts matching metadata.type video (top-level roots).
 */
public function streamVideos(Request $request): View|JsonResponse
{
    $request->validate(['page' => 'nullable|integer|min:1']);
    $page = (int) $request->input('page', 1);

    $thoughts = Thought::query()
        ->where('user_id', auth()->id())
        ->visibleInStream()
        ->topLevel()
        ->matchingCanonicalMetadataType('video')
        ->with(['childThoughts' => fn ($q) => $q->orderBy('created_at')])
        ->orderByDesc('created_at')
        ->paginate(self::STREAM_PAGE_SIZE, ['*'], 'page', $page);

    return $this->streamCollectionResponse(
        $request,
        $thoughts,
        'video',
        fn (LengthAwarePaginator $thoughts) => $thoughts->isNotEmpty()
            ? $thoughts->first()->created_at->toIso8601String()
            : null
    );
}
```

- [ ] **Step 5: Run tests — expect PASS**

```bash
php artisan test tests/Feature/ThoughtTypePagesTest.php --filter=videos
```

- [ ] **Step 6: Commit**

```bash
git add routes/web.php app/Http/Controllers/IdeaController.php tests/Feature/ThoughtTypePagesTest.php
git commit -m "feat: add /stream/videos typed collection page"
```

---

### Task 3: Stream UI — nav, empty state, video cards on Videos tab

**Files:**
- Modify: `resources/views/idea/partials/stream_type_nav.blade.php`
- Modify: `resources/views/idea/stream.blade.php`
- Modify: `tests/Feature/StreamPageTest.php`
- Modify: `tests/Feature/VideoStreamDisplayTest.php` (one test targeting videos route)

- [ ] **Step 1: Update stream type nav partial**

In `resources/views/idea/partials/stream_type_nav.blade.php`, replace:

```blade
@foreach (ThoughtTypeNavigation::orderedNavTypes() as $typeKey)
```

with:

```blade
@foreach (ThoughtTypeNavigation::orderedStreamNavTypes() as $typeKey)
```

- [ ] **Step 2: Add video empty state**

In `resources/views/idea/stream.blade.php`, in the empty-state `@if` chain (after `meeting`, before `@elseif($tag)`):

```blade
@elseif($__collectionKey === 'video')
    No videos yet. <a href="{{ route('idea.index') }}" class="text-memory-violet hover:underline">Capture a video from the home page</a>.
```

- [ ] **Step 3: Update `StreamPageTest` nav helper**

In `tests/Feature/StreamPageTest.php`, update `assertStreamTypeNav()` `$expectedLinks`:

```php
$expectedLinks = [
    'All thoughts' => route('idea.stream'),
    'Emails' => route('idea.stream.emails'),
    'Research' => route('idea.stream.research'),
    'Plans' => route('idea.stream.plans'),
    'Meetings' => route('idea.stream.meetings'),
    'Articles' => route('idea.stream.articles'),
    'Videos' => route('idea.stream.videos'),
];
```

Remove `'Jira' => route('idea.stream.jira')`.

Add assertion that Jira href is absent from nav:

```php
$jiraLink = $xpath->query(".//a[@href='".route('idea.stream.jira')."']", $nav)->item(0);
$this->assertNull($jiraLink, 'Jira must not appear in stream type nav.');
```

- [ ] **Step 4: Fix Jira stream active-tab test**

Replace `test_typed_stream_page_marks_active_type_navigation_option` (Jira variant) with:

```php
public function test_jira_stream_page_does_not_mark_jira_tab_in_type_navigation(): void
{
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('idea.stream.jira'));

    $response->assertOk();
    $response->assertSee('Jira', false); // page title
    $this->assertStreamTypeNav($response, route('idea.stream')); // no Jira tab active
}
```

Add:

```php
public function test_videos_stream_page_marks_active_type_navigation_option(): void
{
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('idea.stream.videos'));

    $response->assertOk();
    $this->assertStreamTypeNav($response, route('idea.stream.videos'));
}
```

Search `StreamPageTest` for other references to Jira in `assertStreamTypeNav` / `$expectedLinks` map (~line 544) and align.

- [ ] **Step 5: Assert video card on Videos tab**

In `tests/Feature/VideoStreamDisplayTest.php`, duplicate the first test’s setup but request `route('idea.stream.videos')` instead of `route('idea.stream')`; assert same `data-thought-kind="video"` behaviour.

- [ ] **Step 6: Run tests**

```bash
php artisan test tests/Feature/StreamPageTest.php tests/Feature/VideoStreamDisplayTest.php
```

- [ ] **Step 7: Commit**

```bash
git add resources/views/idea/partials/stream_type_nav.blade.php resources/views/idea/stream.blade.php tests/Feature/StreamPageTest.php tests/Feature/VideoStreamDisplayTest.php
git commit -m "feat: stream nav Videos tab and remove Jira from segment control"
```

---

### Task 4: Account menu Jira link

**Files:**
- Modify: `resources/views/layouts/idea.blade.php`
- Create: `tests/Feature/AccountMenuJiraLinkTest.php`

- [ ] **Step 1: Add failing feature tests**

Create `tests/Feature/AccountMenuJiraLinkTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountMenuJiraLinkTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_account_menu_shows_jira_link_when_jira_enabled(): void
    {
        config(['services.jira.enabled' => true]);
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('idea.index'));

        $response->assertOk();
        $response->assertSee('data-testid="account-menu-jira-link"', false);
        $response->assertSee(route('idea.stream.jira'), false);
    }

    public function test_account_menu_hides_jira_link_when_jira_disabled(): void
    {
        config(['services.jira.enabled' => false]);
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('idea.index'));

        $response->assertOk();
        $response->assertDontSee('data-testid="account-menu-jira-link"', false);
    }
}
```

- [ ] **Step 2: Run tests — expect FAIL**

```bash
php artisan test tests/Feature/AccountMenuJiraLinkTest.php
```

- [ ] **Step 3: Add Blade link**

In `resources/views/layouts/idea.blade.php`, immediately after the Inbox `</a>` closing tag (before Shared documents):

```blade
@if(\App\Support\ThoughtTypeNavigation::isAvailable('jira'))
    <a href="{{ route('idea.stream.jira') }}" data-testid="account-menu-jira-link" class="block px-4 py-2 text-sm text-slate-brand hover:text-deep-indigo hover:bg-memory-violet/5 transition-colors">
        Jira
    </a>
@endif
```

- [ ] **Step 4: Run tests — expect PASS**

```bash
php artisan test tests/Feature/AccountMenuJiraLinkTest.php
```

- [ ] **Step 5: Commit**

```bash
git add resources/views/layouts/idea.blade.php tests/Feature/AccountMenuJiraLinkTest.php
git commit -m "feat: add Jira activity link to account menu"
```

---

### Task 5: Regression sweep and thought badges

**Files:**
- Verify: `tests/Feature/ThoughtTypePagesTest.php` (Jira page still works)
- Verify: `tests/Feature/ThoughtShowPageTest.php` (Jira badge links)
- Verify: `tests/Feature/IdeaPageTest.php` (nav if any Jira assertions)

- [ ] **Step 1: Run focused regression suite**

```bash
php artisan test \
  tests/Unit/Support/ThoughtTypeNavigationTest.php \
  tests/Feature/ThoughtTypePagesTest.php \
  tests/Feature/StreamPageTest.php \
  tests/Feature/VideoStreamDisplayTest.php \
  tests/Feature/AccountMenuJiraLinkTest.php \
  --stop-on-failure
```

- [ ] **Step 2: Fix any failures**

Common fixes:

- `ThoughtTypePagesTest` or `IdeaPageTest` asserting Jira appears in stream nav → update to account menu or remove.
- `StreamPageTest` full `$expectedLinks` map at ~line 544 including Jira → align with Task 3.
- Badge tests should still pass unchanged (`routeName('jira')` unchanged).

- [ ] **Step 3: Run Pint on touched PHP files**

```bash
vendor/bin/pint --dirty
```

- [ ] **Step 4: Final commit (if fixes were needed)**

```bash
git add -A
git commit -m "test: fix stream nav regressions after Jira menu move"
```

---

## Spec coverage checklist

| Spec requirement | Task |
|------------------|------|
| Videos tab `/stream/videos` | Task 2 |
| Videos on All thoughts | No code change (already included); verify via existing `VideoStreamDisplayTest` on `idea.stream` |
| Jira removed from stream nav | Task 3 |
| Jira account menu under Inbox | Task 4 |
| `show_in_stream_nav` / `orderedStreamNavTypes()` | Task 1 + Task 3 |
| `resolveThoughtToTypeKey` for video | Task 1 |
| Video empty state | Task 3 |
| Jira badges still link to `/stream/jira` | Task 5 (no change expected) |
| Tests per spec table | Tasks 1–5 |

## Self-review (plan vs spec)

- All spec sections mapped to tasks; no TBD steps.
- Video filter uses `matchingCanonicalMetadataType('video')` — same scope family as plans/meetings.
- Jira stream route and controller unchanged; only discovery path moves.
- Articles tab was already in nav but missing from older `assertStreamTypeNav` — plan adds Articles + Videos together for parity.
