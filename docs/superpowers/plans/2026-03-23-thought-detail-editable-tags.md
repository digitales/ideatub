# Thought Detail Editable Tags Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make tags editable on the thought detail page, including thoughts that currently have no tags.

**Architecture:** Reuse the existing shared tag editor instead of building a new detail-page-only flow. The implementation should only expose the already-working `thought_tag_row` partial on the thought detail header in editable mode and add regression tests that lock in the expected detail-page rendering.

**Tech Stack:** Laravel 12, Blade, Alpine.js, PHPUnit-style `TestCase` feature tests, existing `IdeaController::updateTags()` endpoint

---

## File Structure

- Modify: `resources/views/idea/partials/thought_detail_header.blade.php`  
  Always render the shared tag row on the thought detail page and enable its existing editable mode.

- Modify: `tests/Feature/ThoughtShowPageTest.php`  
  Add detail-page regression coverage for editable tags and the empty-tag state.

- Reference: `resources/views/idea/partials/thought_tag_row.blade.php`  
  Existing shared tag UI that should be reused unchanged unless the tests prove a small compatibility fix is required.

- Reference: `resources/js/app.js`  
  Existing `thoughtTagRow` Alpine component that already powers add/remove/edit behavior in list views.

- Reference: `tests/Feature/UpdateThoughtTagsTest.php`  
  Existing endpoint coverage for authorization, normalization, deduplication, and empty-tag updates. Do not duplicate this behavior unnecessarily in the detail-page test file.

- Reference: `docs/superpowers/specs/2026-03-23-thought-detail-editable-tags-design.md`  
  Source of truth for scope and expected UX.

## Task 1: Expose The Existing Editable Tag Row On Thought Detail Pages

**Files:**
- Modify: `tests/Feature/ThoughtShowPageTest.php`
- Modify: `resources/views/idea/partials/thought_detail_header.blade.php`
- Modify if needed: `resources/views/idea/partials/thought_tag_row.blade.php`
- Test: `tests/Feature/ThoughtShowPageTest.php`

- [ ] **Step 1: Write the failing thought-detail tests for editable tags**

Add focused tests to `tests/Feature/ThoughtShowPageTest.php` that prove the detail page renders the shared editable tag row correctly.

Cover these cases:
- a thought detail page with existing tags renders the tag editor affordance
- a thought detail page with no tags still renders the tag row so the user can start editing and add the first tag

Use test names with a `detail_tag` substring so the targeted filter command below only runs the new cases.

Example test shape for an existing-tag thought:

```php
public function test_thought_detail_tag_editor_renders_for_existing_tags(): void
{
    $user = User::factory()->create();
    $thought = Thought::factory()->create([
        'user_id' => $user->id,
        'content' => 'Tagged thought',
        'metadata' => ['tags' => ['plan:test']],
        'embedding' => null,
    ]);

    $response = $this->actingAs($user)->get(route('thoughts.show', $thought));

    $response->assertOk();
    $response->assertSee('plan:test');
    $response->assertSee(route('ideas.update-tags', $thought), false);
    $response->assertSee('Edit tags', false);
}
```

Here, `Edit tags` refers to the existing accessible name (`aria-label="Edit tags"`), not the visible button text alone.

Example test shape for an empty-tag thought:

```php
public function test_thought_detail_tag_editor_renders_when_thought_has_no_tags(): void
{
    $user = User::factory()->create();
    $thought = Thought::factory()->create([
        'user_id' => $user->id,
        'content' => 'Untagged thought',
        'metadata' => ['tags' => []],
        'embedding' => null,
    ]);

    $response = $this->actingAs($user)->get(route('thoughts.show', $thought));

    $response->assertOk();
    $response->assertSee(route('ideas.update-tags', $thought), false);
    $response->assertSee('Edit tags', false);
}
```

- [ ] **Step 2: Run the targeted tests and verify they fail**

Run:

```bash
php artisan test tests/Feature/ThoughtShowPageTest.php --filter=detail_tag
```

Expected:
- FAIL because `thought_detail_header.blade.php` currently hides the tag row for empty tags and passes `editable => false`

- [ ] **Step 3: Make the smallest Blade change to reuse the existing tag editor**

Update `resources/views/idea/partials/thought_detail_header.blade.php` so it:
- always renders the existing `<div class="mt-4">` wrapper for the tag row
- always includes `idea.partials.thought_tag_row`
- passes `editable => true`
- removes the `@if ($tags !== [])` gate

The intended minimal result is:

```blade
<div class="mt-4">
    @include('idea.partials.thought_tag_row', ['thought' => $thought, 'editable' => true])
</div>
```

Do not add a new tag editor or new routes. Reuse the existing shared partial exactly unless the tests reveal a small compatibility issue.

Important compatibility note:
- once the detail header always includes the shared tag row, existing thought detail tests may begin exercising thoughts whose `metadata` is `null`
- if that causes failures, make the smallest null-safe compatibility fix in `resources/views/idea/partials/thought_tag_row.blade.php` so tag extraction safely handles `metadata === null` or a missing `tags` key
- keep that fix narrow; do not broaden the scope into shared tag UI redesign

- [ ] **Step 4: Re-run the targeted tests and verify they pass**

Run:

```bash
php artisan test tests/Feature/ThoughtShowPageTest.php --filter=detail_tag
```

Expected:
- PASS for the new editable-tag detail-page assertions

- [ ] **Step 5: Run the full thought detail feature file**

Run:

```bash
php artisan test tests/Feature/ThoughtShowPageTest.php
```

Expected:
- PASS with no regressions to the existing thought detail page behavior

- [ ] **Step 6: Commit**

```bash
git add resources/views/idea/partials/thought_detail_header.blade.php resources/views/idea/partials/thought_tag_row.blade.php tests/Feature/ThoughtShowPageTest.php
git commit -m "feat: enable tag editing on thought detail pages"
```

## Task 2: Final Verification And Polish

**Files:**
- Modify: `resources/views/idea/partials/thought_detail_header.blade.php`
- Modify if needed: `resources/views/idea/partials/thought_tag_row.blade.php`
- Modify: `tests/Feature/ThoughtShowPageTest.php`
- Test: `tests/Feature/ThoughtShowPageTest.php`
- Test: `tests/Feature/UpdateThoughtTagsTest.php`

- [ ] **Step 1: Review the rendered detail header for consistency with list views**

Check that the detail page:
- preserves the existing spacing around the tag row
- shows the same first-step interaction as list views (`Edit` first, then add/remove controls inside edit mode)
- does not introduce duplicate tag UI elsewhere in the detail header

If the review exposes a mismatch, make the smallest adjustment in the existing partial usage rather than changing the shared tag component behavior broadly.

- [ ] **Step 2: Run the affected endpoint and detail-page tests together**

Run:

```bash
php artisan test tests/Feature/ThoughtShowPageTest.php tests/Feature/UpdateThoughtTagsTest.php
```

Expected:
- PASS, proving the detail page exposes the existing editor without regressing tag update behavior

This combined run is also the regression proof for the spec’s backend-related bullets that are already covered in `tests/Feature/UpdateThoughtTagsTest.php`, including:
- first tag creation through the existing endpoint
- non-owner update rejection
- normalization, deduplication, and empty-tag persistence

- [ ] **Step 3: Check diagnostics for the touched files**

Use IDE diagnostics on:
- `resources/views/idea/partials/thought_detail_header.blade.php`
- `tests/Feature/ThoughtShowPageTest.php`

Fix any newly introduced issues before finishing.

- [ ] **Step 4: Commit any polish changes only if there is something new to commit**

```bash
git add resources/views/idea/partials/thought_detail_header.blade.php resources/views/idea/partials/thought_tag_row.blade.php tests/Feature/ThoughtShowPageTest.php
git commit -m "test: cover thought detail editable tags"
```

If the review produced no additional code or test changes, skip this commit.

## Notes For Execution

- Follow `@superpowers/test-driven-development` strictly: add the new failing detail-page tests first, run them, then make the minimal Blade change.
- Do not duplicate backend tag mutation logic on the detail page. The existing `ideas.update-tags` endpoint is already the source of truth.
- `tests/Feature/UpdateThoughtTagsTest.php` already covers endpoint behavior such as first-tag creation, forbidden cross-user updates, normalization, and empty arrays; use it as regression coverage rather than rewriting the same assertions in `ThoughtShowPageTest.php` unless a true detail-page integration gap appears.
- Prefer a narrow change set. This feature should not alter stream cards, idea cards, the shared tag row behavior, or backend tag normalization unless a failing test proves that is necessary.
