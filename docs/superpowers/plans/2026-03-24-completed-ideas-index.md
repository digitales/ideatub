# Completed Ideas Index Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Hide completed ideas from the active `Ideas` list and `Ideas to revisit`, add a dedicated `Completed ideas` index, and support reopening completed ideas from thought detail only.

**Architecture:** Keep ideas as `Thought` records with metadata and extend the existing completion flow by storing `metadata.completed_at` when ideas are completed. Reuse the current `ideas.toggle-completed` endpoint, but update it so list pages and thought detail can share the same completion state transition while redirecting back to the originating page. Add one new completed-ideas route/view and centralize completed-idea ordering so SQLite and PostgreSQL behave consistently.

**Tech Stack:** Laravel 12, PHP 8.2+, Blade, Pest/PHPUnit feature tests, existing `Thought` metadata JSON patterns

**Spec:** `docs/superpowers/specs/2026-03-24-completed-ideas-index-design.md`

---

## File structure (create/modify overview)

| Responsibility | Files |
|----------------|-------|
| Idea completion query helpers and timestamp accessors | `app/Models/Thought.php` |
| Cross-database SQL for completed idea ordering | `app/Support/IdeaCompletedAtSql.php` |
| Active/completed ideas controllers and completion toggle semantics | `app/Http/Controllers/IdeaController.php` |
| Incomplete-only revisit selection | `app/Services/IdeasToRevisitService.php` |
| Ideas section routes | `routes/web.php` |
| Shared ideas nav | `resources/views/idea/partials/ideas_section_nav.blade.php` |
| Active ideas list behavior | `resources/views/idea/partials/ideas_list.blade.php` |
| Completed ideas page | `resources/views/idea/completed.blade.php`, `resources/views/idea/partials/completed_ideas_list.blade.php` |
| Reopen affordance on thought detail | `resources/views/idea/partials/thought_detail_header.blade.php` |
| Model/query tests | `tests/Unit/Models/ThoughtTest.php`, `tests/Unit/Services/IdeasToRevisitServiceTest.php` |
| Active/completed page tests | `tests/Feature/IdeaIdeasTest.php`, `tests/Feature/CompletedIdeasPageTest.php`, `tests/Feature/IdeasToRevisitPageTest.php` |
| Thought detail reopen tests | `tests/Feature/ThoughtShowPageTest.php` |
| Shared nav assertions | `tests/Concerns/AssertsIdeasSectionNav.php` |

---

## Task 1: Add idea completion query helpers and completed-at ordering support

**Files:**
- Create: `app/Support/IdeaCompletedAtSql.php`
- Modify: `app/Models/Thought.php`
- Test: `tests/Unit/Models/ThoughtTest.php`

- [ ] **Step 1: Write the failing model tests**

Add tests in `tests/Unit/Models/ThoughtTest.php` for:

```php
public function test_scope_incomplete_ideas_excludes_completed_true_but_keeps_missing_completed_flag(): void
{
    $user = User::factory()->create();

    $incomplete = Thought::factory()->create([
        'user_id' => $user->id,
        'metadata' => ['type' => 'idea', 'completed' => false],
    ]);
    $missingCompleted = Thought::factory()->create([
        'user_id' => $user->id,
        'metadata' => ['type' => 'idea'],
    ]);
    Thought::factory()->create([
        'user_id' => $user->id,
        'metadata' => ['type' => 'idea', 'completed' => true],
    ]);

    $ideas = Thought::query()->where('user_id', $user->id)->ideas()->incompleteIdeas()->get();

    $this->assertCount(2, $ideas);
    $this->assertEqualsCanonicalizing([$incomplete->id, $missingCompleted->id], $ideas->pluck('id')->all());
}

public function test_scope_completed_ideas_returns_only_completed_ideas(): void
{
    $user = User::factory()->create();
    $completed = Thought::factory()->create([
        'user_id' => $user->id,
        'metadata' => ['type' => 'idea', 'completed' => true, 'completed_at' => now()->toIso8601String()],
    ]);
    Thought::factory()->create([
        'user_id' => $user->id,
        'metadata' => ['type' => 'idea', 'completed' => false],
    ]);

    $ideas = Thought::query()->where('user_id', $user->id)->ideas()->completedIdeas()->get();

    $this->assertSame([$completed->id], $ideas->pluck('id')->all());
}

public function test_get_idea_completed_at_returns_timestamp_or_null(): void
{
    $user = User::factory()->create();
    $completed = Thought::factory()->create([
        'user_id' => $user->id,
        'metadata' => ['type' => 'idea', 'completed' => true, 'completed_at' => '2026-03-24T15:00:00+00:00'],
    ]);
    $incomplete = Thought::factory()->create([
        'user_id' => $user->id,
        'metadata' => ['type' => 'idea', 'completed' => false],
    ]);

    $this->assertSame('2026-03-24T15:00:00+00:00', $completed->getIdeaCompletedAt()?->toIso8601String());
    $this->assertNull($incomplete->getIdeaCompletedAt());
}

public function test_completed_idea_ordering_puts_timestamped_rows_before_legacy_rows(): void
{
    $user = User::factory()->create();

    $older = Thought::factory()->create([
        'user_id' => $user->id,
        'metadata' => ['type' => 'idea', 'completed' => true, 'completed_at' => '2026-03-23T10:00:00+00:00'],
        'updated_at' => now()->subHours(2),
    ]);
    $newer = Thought::factory()->create([
        'user_id' => $user->id,
        'metadata' => ['type' => 'idea', 'completed' => true, 'completed_at' => '2026-03-24T10:00:00+00:00'],
        'updated_at' => now()->subHour(),
    ]);
    $legacy = Thought::factory()->create([
        'user_id' => $user->id,
        'metadata' => ['type' => 'idea', 'completed' => true],
        'updated_at' => now(),
    ]);

    $ids = Thought::query()
        ->where('user_id', $user->id)
        ->ideas()
        ->completedIdeas()
        ->orderByRaw(IdeaCompletedAtSql::missingTimestampExpression().' ASC')
        ->orderByRaw(IdeaCompletedAtSql::parsedTimestampExpression().' DESC')
        ->orderByDesc('updated_at')
        ->orderByDesc('id')
        ->pluck('id')
        ->all();

    $this->assertSame([$newer->id, $older->id, $legacy->id], $ids);
}
```

- [ ] **Step 2: Run the model tests to verify they fail**

Run: `php artisan test tests/Unit/Models/ThoughtTest.php`

Expected: FAIL because `incompleteIdeas()`, `completedIdeas()`, and `getIdeaCompletedAt()` do not exist yet.

- [ ] **Step 3: Add minimal model helpers and completed-at SQL support**

In `app/Models/Thought.php` add:

```php
public function scopeIncompleteIdeas(Builder $query): Builder
{
    return $query->where(function (Builder $q): void {
        $q->whereNull('metadata->completed')
            ->orWhere('metadata->completed', '!=', true);
    });
}

public function scopeCompletedIdeas(Builder $query): Builder
{
    return $query->where('metadata->completed', true);
}

public function getIdeaCompletedAt(): ?Carbon
{
    $value = $this->metadata['completed_at'] ?? null;

    if (! is_string($value) || trim($value) === '') {
        return null;
    }

    try {
        return Carbon::parse($value);
    } catch (\Throwable) {
        return null;
    }
}
```

Create `app/Support/IdeaCompletedAtSql.php` to centralize completed ordering expressions for both DB drivers:

```php
final class IdeaCompletedAtSql
{
    public static function parsedTimestampExpression(): string { /* parsed metadata.completed_at or null */ }

    public static function missingTimestampExpression(): string { /* 0 for timestamped rows, 1 for legacy rows */ }
}
```

Implementation requirements for the SQL helper:

- SQLite: use `datetime(json_extract(metadata, '$.completed_at'))`
- PostgreSQL: parse `metadata->>'completed_at'` only when present and ISO-like, otherwise yield `NULL`
- the helper must support this order:
  1. timestamped completed ideas first
  2. newest `completed_at` first
  3. legacy completed ideas after timestamped rows
  4. legacy fallback order by `updated_at DESC`, then `id DESC`

- [ ] **Step 4: Re-run the model tests**

Run: `php artisan test tests/Unit/Models/ThoughtTest.php`

Expected: PASS

- [ ] **Step 5: Commit the helper/model slice**

```bash
git add app/Models/Thought.php app/Support/IdeaCompletedAtSql.php tests/Unit/Models/ThoughtTest.php
git commit -m "feat(ideas): add completed idea query helpers"
```

---

## Task 2: Make active ideas and revisit surfaces incomplete-only

**Files:**
- Modify: `app/Http/Controllers/IdeaController.php`
- Modify: `app/Services/IdeasToRevisitService.php`
- Modify: `resources/views/idea/partials/ideas_list.blade.php`
- Test: `tests/Feature/IdeaIdeasTest.php`
- Test: `tests/Feature/IdeasToRevisitPageTest.php`
- Test: `tests/Unit/Services/IdeasToRevisitServiceTest.php`

- [ ] **Step 1: Write the failing active-ideas and revisit tests**

Update/add tests in `tests/Feature/IdeaIdeasTest.php`. Replace the old `test_ideas_list_shows_completed_state_per_idea()` assertion set with incomplete-only expectations so the suite no longer encodes the pre-change behavior.

Use tests like:

```php
public function test_ideas_page_shows_only_incomplete_ideas(): void
{
    $user = User::factory()->create();

    Thought::factory()->create([
        'user_id' => $user->id,
        'content' => 'Active idea',
        'metadata' => ['type' => 'idea', 'completed' => false, 'logged_date' => '2025-03-01'],
    ]);
    Thought::factory()->create([
        'user_id' => $user->id,
        'content' => 'Completed idea',
        'metadata' => ['type' => 'idea', 'completed' => true, 'completed_at' => now()->toIso8601String(), 'logged_date' => '2025-03-02'],
    ]);

    $response = $this->actingAs($user)->get(route('idea.ideas'));

    $response->assertSee('Active idea');
    $response->assertDontSee('Completed idea');
}

public function test_patch_toggle_completed_sets_completed_at_when_marking_complete(): void
{
    $user = User::factory()->create();
    $thought = Thought::factory()->create([
        'user_id' => $user->id,
        'metadata' => ['type' => 'idea', 'completed' => false, 'logged_date' => now()->toDateString()],
    ]);

    $response = $this->actingAs($user)
        ->from(route('idea.ideas'))
        ->patch(route('ideas.toggle-completed', $thought), ['_token' => csrf_token()]);

    $response->assertRedirect(route('idea.ideas'));
    $thought->refresh();
    $this->assertTrue($thought->isIdeaCompleted());
    $this->assertNotNull($thought->metadata['completed_at'] ?? null);
}
```

Add/update a service test in `tests/Unit/Services/IdeasToRevisitServiceTest.php`, and add a feature-level revisit assertion in `tests/Feature/IdeasToRevisitPageTest.php` so the spec requirement is covered at both layers:

```php
#[Test]
public function excludes_completed_ideas_even_when_completed_at_is_present(): void
{
    $user = User::factory()->create();

    $incomplete = Thought::factory()->create([
        'user_id' => $user->id,
        'metadata' => ['type' => 'idea', 'completed' => false, 'logged_date' => '2025-01-01'],
    ]);
    Thought::factory()->create([
        'user_id' => $user->id,
        'metadata' => ['type' => 'idea', 'completed' => true, 'completed_at' => now()->toIso8601String(), 'logged_date' => '2025-01-02'],
    ]);

    $result = (new IdeasToRevisitService)->forUser($user);

    $this->assertSame([$incomplete->id], array_map(fn ($thought) => $thought->id, $result));
}
```

```php
public function test_revisit_page_does_not_show_completed_idea_with_completed_at(): void
{
    $user = User::factory()->create();

    Thought::factory()->create([
        'user_id' => $user->id,
        'content' => 'Archived idea',
        'metadata' => ['type' => 'idea', 'completed' => true, 'completed_at' => now()->toIso8601String(), 'logged_date' => '2025-01-01'],
    ]);

    $response = $this->actingAs($user)->get(route('idea.revisit'));

    $response->assertDontSee('Archived idea');
}
```

- [ ] **Step 2: Run the failing tests**

Run: `php artisan test tests/Feature/IdeaIdeasTest.php tests/Feature/IdeasToRevisitPageTest.php tests/Unit/Services/IdeasToRevisitServiceTest.php`

Expected: FAIL because `/ideas` still shows completed ideas and the toggle does not write `completed_at`.

- [ ] **Step 3: Update the controller, service, and active list partial**

In `app/Http/Controllers/IdeaController.php` update:

```php
$ideas = Thought::query()
    ->where('user_id', auth()->id())
    ->ideas()
    ->incompleteIdeas()
    ->orderByDesc('created_at')
    ->paginate(20);
```

Update `toggleCompleted()` so it writes/clears `completed_at` and redirects back to the originating page:

```php
$completed = ! $thought->isIdeaCompleted();
$metadata = array_merge($thought->metadata ?? [], [
    'type' => 'idea',
    'completed' => $completed,
    'logged_date' => $thought->metadata['logged_date'] ?? $thought->created_at->toDateString(),
    'completed_at' => $completed ? now()->toIso8601String() : null,
]);

$thought->update(['metadata' => $metadata]);

return $request->expectsJson()
    ? response()->json(['completed' => $completed, 'completed_at' => $metadata['completed_at']])
    : redirect()->back(status: 302, headers: [], fallback: route('idea.ideas'))
        ->with('success', $completed ? 'Marked as complete.' : 'Marked as incomplete.');
```

In `app/Services/IdeasToRevisitService.php`, replace the inline completion filter with `->incompleteIdeas()`.

In `resources/views/idea/partials/ideas_list.blade.php` keep the completion checkbox, but treat the list as incomplete-only:

- remove any expectation that completed ideas stay visible in the list
- keep the row-level checkbox submit flow
- keep `data-thought-id` so DOM removal/refetch continues to work cleanly

- [ ] **Step 4: Re-run the tests**

Run: `php artisan test tests/Feature/IdeaIdeasTest.php tests/Feature/IdeasToRevisitPageTest.php tests/Unit/Services/IdeasToRevisitServiceTest.php`

Expected: PASS

- [ ] **Step 5: Commit the incomplete-only behavior**

```bash
git add app/Http/Controllers/IdeaController.php app/Services/IdeasToRevisitService.php resources/views/idea/partials/ideas_list.blade.php tests/Feature/IdeaIdeasTest.php tests/Feature/IdeasToRevisitPageTest.php tests/Unit/Services/IdeasToRevisitServiceTest.php
git commit -m "feat(ideas): keep active ideas lists incomplete only"
```

---

## Task 3: Add the completed ideas route, page, nav tab, and ordering

**Files:**
- Modify: `routes/web.php`
- Modify: `app/Http/Controllers/IdeaController.php`
- Modify: `resources/views/idea/partials/ideas_section_nav.blade.php`
- Create: `resources/views/idea/completed.blade.php`
- Create: `resources/views/idea/partials/completed_ideas_list.blade.php`
- Modify: `tests/Concerns/AssertsIdeasSectionNav.php`
- Create: `tests/Feature/CompletedIdeasPageTest.php`
- Modify: `tests/Feature/IdeasToRevisitPageTest.php`
- Modify: `tests/Feature/IdeaIdeasTest.php`

- [ ] **Step 1: Write the failing completed-page and nav tests**

Create `tests/Feature/CompletedIdeasPageTest.php` with coverage for:

```php
public function test_guest_is_redirected_from_completed_ideas_page(): void
{
    $this->get(route('idea.completed'))->assertRedirect(route('login'));
}

public function test_completed_ideas_page_shows_only_completed_ideas_newest_first(): void
{
    $user = User::factory()->create();

    $newer = Thought::factory()->create([
        'user_id' => $user->id,
        'content' => 'Newest completed',
        'metadata' => ['type' => 'idea', 'completed' => true, 'completed_at' => '2026-03-24T11:00:00+00:00', 'logged_date' => '2026-03-01'],
        'updated_at' => now()->subMinute(),
    ]);
    $older = Thought::factory()->create([
        'user_id' => $user->id,
        'content' => 'Older completed',
        'metadata' => ['type' => 'idea', 'completed' => true, 'completed_at' => '2026-03-23T11:00:00+00:00', 'logged_date' => '2026-02-01'],
        'updated_at' => now()->subHour(),
    ]);
    $legacy = Thought::factory()->create([
        'user_id' => $user->id,
        'content' => 'Legacy completed',
        'metadata' => ['type' => 'idea', 'completed' => true, 'logged_date' => '2026-01-01'],
        'updated_at' => now(),
    ]);
    Thought::factory()->create([
        'user_id' => $user->id,
        'content' => 'Still active',
        'metadata' => ['type' => 'idea', 'completed' => false, 'logged_date' => '2026-03-05'],
    ]);

    $response = $this->actingAs($user)->get(route('idea.completed'));

    $response->assertOk();
    $this->assertIdeasSectionNav($response, 'completed');
    $response->assertSee('Newest completed');
    $response->assertSee('Older completed');
    $response->assertSee('Legacy completed');
    $response->assertSee('Mar 24, 2026');
    $response->assertDontSee('Still active');
    $html = $response->getContent();
    preg_match_all('/data-completed-idea-id="([^"]+)"/', $html, $matches);
    $this->assertSame([$newer->id, $older->id, $legacy->id], $matches[1]);
}

public function test_completed_ideas_page_shows_empty_state(): void
{
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('idea.completed'));

    $response->assertSee('No completed ideas yet.');
}
```

Update `tests/Concerns/AssertsIdeasSectionNav.php` so it expects links for:

- `route('idea.ideas')`
- `route('idea.revisit')`
- `route('idea.completed')`

Update `tests/Feature/IdeaIdeasTest.php` and `tests/Feature/IdeasToRevisitPageTest.php` to continue asserting the shared nav with the new `completed` link present.

- [ ] **Step 2: Run the failing page tests**

Run: `php artisan test tests/Feature/CompletedIdeasPageTest.php tests/Feature/IdeaIdeasTest.php tests/Feature/IdeasToRevisitPageTest.php`

Expected: FAIL because `idea.completed` does not exist and the shared nav still has only two tabs.

- [ ] **Step 3: Implement the completed ideas page**

In `routes/web.php` add:

```php
Route::get('/ideas/completed', [IdeaController::class, 'completedIdeas'])->name('idea.completed');
```

In `app/Http/Controllers/IdeaController.php` add:

```php
public function completedIdeas(): View
{
    $missingCompletedAtSql = IdeaCompletedAtSql::missingTimestampExpression();
    $completedAtSql = IdeaCompletedAtSql::parsedTimestampExpression();

    $ideas = Thought::query()
        ->where('user_id', auth()->id())
        ->ideas()
        ->completedIdeas()
        ->orderByRaw($missingCompletedAtSql.' ASC')
        ->orderByRaw($completedAtSql.' DESC')
        ->orderByDesc('updated_at')
        ->orderByDesc('id')
        ->paginate(20);

    return view('idea.completed', ['ideas' => $ideas]);
}
```

Create `resources/views/idea/completed.blade.php` following the same page shell as `ideas.blade.php` / `revisit.blade.php`:

- title `Completed ideas`
- intro text explaining this page holds finished ideas
- shared nav with `active => 'completed'`
- empty state `No completed ideas yet.`
- include `idea.partials.completed_ideas_list`

Create `resources/views/idea/partials/completed_ideas_list.blade.php` with each row showing:

- truncated idea content
- original logged date via `$thought->getLoggedDate()`
- completed date via one explicit local user-facing format, reused consistently in page/tests, e.g. `$thought->getIdeaCompletedAt()?->setTimezone(config('app.timezone'))->format('M j, Y')`
- link to `route('thoughts.show', $thought)`

Add a stable row hook for ordering assertions:

```php
<li data-completed-idea-id="{{ $thought->id }}">
```

Update `resources/views/idea/partials/ideas_section_nav.blade.php` to add the third tab and `completed` active-state handling.

- [ ] **Step 4: Re-run the completed-page and nav tests**

Run: `php artisan test tests/Feature/CompletedIdeasPageTest.php tests/Feature/IdeaIdeasTest.php tests/Feature/IdeasToRevisitPageTest.php`

Expected: PASS

- [ ] **Step 5: Commit the page and nav**

```bash
git add routes/web.php app/Http/Controllers/IdeaController.php resources/views/idea/partials/ideas_section_nav.blade.php resources/views/idea/completed.blade.php resources/views/idea/partials/completed_ideas_list.blade.php tests/Concerns/AssertsIdeasSectionNav.php tests/Feature/CompletedIdeasPageTest.php tests/Feature/IdeaIdeasTest.php tests/Feature/IdeasToRevisitPageTest.php
git commit -m "feat(ideas): add completed ideas page"
```

---

## Task 4: Add reopen-from-detail-only behavior for completed ideas

**Files:**
- Modify: `resources/views/idea/partials/thought_detail_header.blade.php`
- Test: `tests/Feature/ThoughtShowPageTest.php`
- Test: `tests/Feature/CompletedIdeasPageTest.php`

- [ ] **Step 1: Write the failing detail-page tests**

Add tests in `tests/Feature/ThoughtShowPageTest.php` for:

```php
public function test_completed_idea_detail_shows_reopen_button(): void
{
    $user = User::factory()->create();
    $thought = Thought::factory()->create([
        'user_id' => $user->id,
        'metadata' => ['type' => 'idea', 'completed' => true, 'completed_at' => '2026-03-24T11:00:00+00:00'],
    ]);

    $response = $this->actingAs($user)->get(route('thoughts.show', $thought));

    $response->assertOk();
    $response->assertSee('Mark as incomplete');
    $response->assertSee(route('ideas.toggle-completed', $thought), false);
}

public function test_reopening_completed_idea_from_detail_returns_it_to_active_lists(): void
{
    $user = User::factory()->create();
    $thought = Thought::factory()->create([
        'user_id' => $user->id,
        'content' => 'Reopen me',
        'metadata' => ['type' => 'idea', 'completed' => true, 'completed_at' => '2026-03-24T11:00:00+00:00', 'logged_date' => '2026-03-01'],
    ]);

    $response = $this->actingAs($user)
        ->from(route('thoughts.show', $thought))
        ->patch(route('ideas.toggle-completed', $thought), ['_token' => csrf_token()]);

    $response->assertRedirect(route('thoughts.show', $thought));

    $thought->refresh();
    $this->assertFalse($thought->isIdeaCompleted());
    $this->assertNull($thought->metadata['completed_at'] ?? null);

    $this->actingAs($user)->get(route('idea.ideas'))->assertSee('Reopen me');
    $this->actingAs($user)->get(route('idea.completed'))->assertDontSee('Reopen me');
}
```

Extend `tests/Feature/CompletedIdeasPageTest.php` with:

```php
public function test_completed_ideas_page_does_not_show_inline_reopen_control(): void
{
    $user = User::factory()->create();
    $thought = Thought::factory()->create([
        'user_id' => $user->id,
        'content' => 'Archive row',
        'metadata' => ['type' => 'idea', 'completed' => true, 'completed_at' => now()->toIso8601String()],
    ]);

    $response = $this->actingAs($user)->get(route('idea.completed'));

    $response->assertSee('Archive row');
    $response->assertDontSee('Mark as incomplete');
    $response->assertSee(route('thoughts.show', $thought), false);
}
```

- [ ] **Step 2: Run the failing detail/completed tests**

Run: `php artisan test tests/Feature/ThoughtShowPageTest.php tests/Feature/CompletedIdeasPageTest.php`

Expected: FAIL because thought detail does not yet expose a reopen control for completed ideas.

- [ ] **Step 3: Implement the detail-page reopen affordance**

In `resources/views/idea/partials/thought_detail_header.blade.php`, add a completed-idea-only form near the header metadata:

```php
@if (($thought->metadata['type'] ?? null) === 'idea' && $thought->isIdeaCompleted())
    <form method="POST" action="{{ route('ideas.toggle-completed', $thought) }}">
        @csrf
        @method('PATCH')
        <button type="submit" class="...">Mark as incomplete</button>
    </form>
@endif
```

Implementation requirements:

- show this control only for completed ideas
- do not add the same control to `resources/views/idea/completed.blade.php`
- rely on the `redirect()->back()` change from Task 2 so the detail action returns to `route('thoughts.show', $thought)`

- [ ] **Step 4: Re-run the detail/completed tests**

Run: `php artisan test tests/Feature/ThoughtShowPageTest.php tests/Feature/CompletedIdeasPageTest.php`

Expected: PASS

- [ ] **Step 5: Commit the reopen flow**

```bash
git add resources/views/idea/partials/thought_detail_header.blade.php tests/Feature/ThoughtShowPageTest.php tests/Feature/CompletedIdeasPageTest.php
git commit -m "feat(ideas): reopen completed ideas from detail"
```

---

## Task 5: Final verification

**Files:**
- Modify: none expected
- Test: `tests/Unit/Models/ThoughtTest.php`
- Test: `tests/Unit/Services/IdeasToRevisitServiceTest.php`
- Test: `tests/Feature/IdeaIdeasTest.php`
- Test: `tests/Feature/IdeasToRevisitPageTest.php`
- Test: `tests/Feature/CompletedIdeasPageTest.php`
- Test: `tests/Feature/ThoughtShowPageTest.php`

- [ ] **Step 1: Run the targeted verification suite**

Run:

```bash
php artisan test \
  tests/Unit/Models/ThoughtTest.php \
  tests/Unit/Services/IdeasToRevisitServiceTest.php \
  tests/Feature/IdeaIdeasTest.php \
  tests/Feature/IdeasToRevisitPageTest.php \
  tests/Feature/CompletedIdeasPageTest.php \
  tests/Feature/ThoughtShowPageTest.php
```

Expected: PASS

If a PostgreSQL test database is configured for this repo, repeat at least:

```bash
php artisan test tests/Feature/CompletedIdeasPageTest.php
```

under PostgreSQL as a smoke check for `IdeaCompletedAtSql`.

- [ ] **Step 2: Smoke-check the main user flows manually**

Verify in the browser:

- add a new idea on `/ideas`
- mark it complete from `/ideas` and confirm it disappears from the active list
- confirm it does not appear on `/ideas/revisit`
- confirm it appears on `/ideas/completed`
- open the idea detail and mark it incomplete
- confirm it reappears on `/ideas` and disappears from `/ideas/completed`

- [ ] **Step 3: Commit any final cleanups only if needed**

```bash
git status
```

Expected: clean working tree, or only intentional follow-up changes.
