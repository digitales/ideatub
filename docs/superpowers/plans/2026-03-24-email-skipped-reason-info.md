# Email Skipped Reason Info Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Show the stored newsletter-research skip reason on email list cards, stream cards, and the email detail page, with an info icon that supports hover and click/tap.

**Architecture:** Reuse the existing shared Blade partial for newsletter research status so list, stream, and detail stay in sync. Keep the change presentation-only by reading the existing `newsletter_research.reason` field, adding lightweight Alpine state for the info popover, and extending feature tests around rendered HTML.

**Tech Stack:** Laravel, Blade, Alpine.js, Tailwind CSS, Pest/PHPUnit feature tests

---

## File Structure

- Modify: `resources/views/idea/partials/email_newsletter_research_status.blade.php`
  Responsibility: render the newsletter research status badge, optional research link, skipped-reason info icon, popover, and inline helper text.
- Modify: `resources/views/idea/partials/thought_detail_email_sidebar.blade.php`
  Responsibility: include the shared newsletter status partial on the email detail page in the sidebar metadata area.
- Modify: `tests/Feature/EmailThoughtStatusDisplayTest.php`
  Responsibility: cover skipped-reason rendering on index, stream, and detail pages, plus guard cases for missing reason and non-skipped statuses.
- Reuse without direct modification: `resources/views/idea/index_thought_cards.blade.php`, `resources/views/idea/stream_thoughts.blade.php`
  Responsibility: these views already include the shared status partial, so changes in the partial should automatically reach both list surfaces.

### Task 1: Add Failing Coverage For Skipped Reasons

**Files:**
- Modify: `tests/Feature/EmailThoughtStatusDisplayTest.php`
- Test: `tests/Feature/EmailThoughtStatusDisplayTest.php`

- [ ] **Step 1: Write the failing test for skipped reason on index and stream**

```php
public function test_email_thought_research_skipped_with_reason_shows_reason_and_info_ui_on_index_and_stream(): void
{
    $user = User::factory()->create();

    Thought::factory()->create([
        'user_id' => $user->id,
        'parent_id' => null,
        'content' => 'Skipped newsletter email',
        'source' => 'email',
        'source_metadata' => [
            'newsletter_research' => [
                'status' => 'research_skipped',
                'reason' => 'Not enough meaningful content to research.',
            ],
        ],
    ]);

    $index = $this->actingAs($user)->get(route('idea.index'));
    $index->assertSee('data-email-research-status="research_skipped"', false);
    $index->assertSee('Skipped: Not enough meaningful content to research.', false);
    $index->assertSee('Why research was skipped', false);

    $stream = $this->actingAs($user)->get(route('idea.stream'));
    $stream->assertSee('data-email-research-status="research_skipped"', false);
    $stream->assertSee('Skipped: Not enough meaningful content to research.', false);
    $stream->assertSee('Why research was skipped', false);
}
```

- [ ] **Step 2: Run the skipped-reason test to verify it fails**

Run: `php artisan test tests/Feature/EmailThoughtStatusDisplayTest.php --filter=skipped_with_reason`

Expected: FAIL because the current partial does not render the reason text or info trigger.

- [ ] **Step 3: Write the failing test for detail-page skipped reason rendering**

```php
public function test_email_thought_detail_page_shows_skipped_reason_and_info_ui(): void
{
    $user = User::factory()->create();

    $thought = Thought::factory()->create([
        'user_id' => $user->id,
        'source' => 'email',
        'content' => 'Email body',
        'source_metadata' => [
            'newsletter_research' => [
                'status' => 'research_skipped',
                'reason' => 'Not enough meaningful content to research.',
            ],
        ],
    ]);

    $response = $this->actingAs($user)->get(route('thoughts.show', $thought));

    $response->assertSee('data-email-research-status="research_skipped"', false);
    $response->assertSee('Skipped: Not enough meaningful content to research.', false);
    $response->assertSee('Why research was skipped', false);
}
```

- [ ] **Step 4: Run the detail-page test to verify it fails**

Run: `php artisan test tests/Feature/EmailThoughtStatusDisplayTest.php --filter=detail_page_shows_skipped_reason`

Expected: FAIL because the detail page does not currently include the shared newsletter status partial.

- [ ] **Step 5: Write guard tests for missing reason and non-skipped statuses**

```php
public function test_skipped_status_without_reason_renders_only_badge_without_info_ui(): void
{
    $user = User::factory()->create();

    Thought::factory()->create([
        'user_id' => $user->id,
        'parent_id' => null,
        'content' => 'Skipped without reason',
        'source' => 'email',
        'source_metadata' => [
            'newsletter_research' => [
                'status' => 'research_skipped',
                'reason' => '   ',
            ],
        ],
    ]);

    $response = $this->actingAs($user)->get(route('idea.index'));

    $response->assertSee('data-email-research-status="research_skipped"', false);
    $response->assertDontSee('Why research was skipped', false);
    $response->assertDontSee('Skipped:', false);
}

public function test_non_skipped_status_does_not_render_skipped_reason_ui(): void
{
    $user = User::factory()->create();

    Thought::factory()->create([
        'user_id' => $user->id,
        'parent_id' => null,
        'content' => 'Queued email',
        'source' => 'email',
        'source_metadata' => [
            'newsletter_research' => [
                'status' => 'research_queued',
                'reason' => 'Not enough meaningful content to research.',
            ],
        ],
    ]);

    $response = $this->actingAs($user)->get(route('idea.index'));

    $response->assertSee('data-email-research-status="research_queued"', false);
    $response->assertDontSee('Why research was skipped', false);
    $response->assertDontSee('Skipped:', false);
}
```

- [ ] **Step 6: Run the full status display test file and verify the new assertions fail for the expected reasons**

Run: `php artisan test tests/Feature/EmailThoughtStatusDisplayTest.php`

Expected: FAIL in new skipped-reason assertions, while existing status tests remain green.

- [ ] **Step 7: Write the escaping regression test before any Blade implementation**

```php
public function test_skipped_reason_is_escaped_in_rendered_output(): void
{
    $user = User::factory()->create();

    Thought::factory()->create([
        'user_id' => $user->id,
        'parent_id' => null,
        'content' => 'Escaping case',
        'source' => 'email',
        'source_metadata' => [
            'newsletter_research' => [
                'status' => 'research_skipped',
                'reason' => '<script>alert(1)</script>',
            ],
        ],
    ]);

    $response = $this->actingAs($user)->get(route('idea.index'));

    $response->assertDontSee('<script>alert(1)</script>', false);
    $response->assertSeeText('<script>alert(1)</script>', false);
}
```

- [ ] **Step 8: Run the escaping test and verify current behavior before implementing**

Run: `php artisan test tests/Feature/EmailThoughtStatusDisplayTest.php --filter=escaped`

Expected: PASS if Blade escaping is already in place, otherwise FAIL and drive the safe implementation.

### Task 2: Implement Shared Skipped-Reason UI

**Files:**
- Modify: `resources/views/idea/partials/email_newsletter_research_status.blade.php`
- Modify: `resources/views/idea/partials/thought_detail_email_sidebar.blade.php`
- Test: `tests/Feature/EmailThoughtStatusDisplayTest.php`

- [ ] **Step 1: Add minimal skipped-reason extraction to the shared partial**

```blade
@php
    $skipReason = is_array($nr) && is_string($nr['reason'] ?? null)
        ? trim((string) $nr['reason'])
        : null;
    $showSkipReasonUi = $researchStatus === 'research_skipped' && $skipReason !== null && $skipReason !== '';
@endphp
```

- [ ] **Step 2: Render the status as a wrapper with the existing badge hook preserved**

```blade
<div class="inline-flex flex-col gap-1">
    <div class="inline-flex items-center gap-1.5">
        <span data-email-research-status="{{ $researchStatus }}">...</span>
    </div>
</div>
```

- [ ] **Step 3: Add the info trigger and Alpine-powered hover/click popover**

```blade
<div
    x-data="{ open: false }"
    @mouseenter="open = true"
    @mouseleave="open = false"
    @focusin="open = true"
    @focusout="open = false"
    @keydown.escape.window="open = false"
    class="relative"
>
    <button
        type="button"
        aria-label="Why research was skipped"
        :aria-expanded="open ? 'true' : 'false'"
        aria-controls="email-research-skip-{{ $thought->id }}"
        @click="open = ! open"
    >
        <!-- inline svg icon -->
    </button>

    <div
        id="email-research-skip-{{ $thought->id }}"
        x-cloak
        x-show="open"
        @click.outside="open = false"
    >
        {{ $skipReason }}
    </div>
</div>
```

- [ ] **Step 4: Add the muted inline helper text for skipped reasons**

```blade
@if ($showSkipReasonUi)
    <span class="text-[10.5px] text-slate-brand/55 break-words max-w-[22rem]">
        Skipped: {{ $skipReason }}
    </span>
@endif
```

- [ ] **Step 5: Include the shared partial on the email detail sidebar**

```blade
@include('idea.partials.email_newsletter_research_status', ['thought' => $thought])
```

Placement: add it in `resources/views/idea/partials/thought_detail_email_sidebar.blade.php` near the email metadata/actions block so the current newsletter status is visible on detail pages too.
Default behavior: use the same inline helper text on detail pages as on list cards for consistency.

- [ ] **Step 6: Keep non-skipped behavior unchanged**

Implementation note:
- retain the optional `View research` link behavior for completed/partial statuses
- do not render the skip info icon or inline helper when status is not `research_skipped`
- do not render the skip info icon or inline helper when the trimmed reason is blank
- use the existing list and stream includes without changing `resources/views/idea/index_thought_cards.blade.php` or `resources/views/idea/stream_thoughts.blade.php`

- [ ] **Step 7: Run the status display test file and verify it passes**

Run: `php artisan test tests/Feature/EmailThoughtStatusDisplayTest.php`

Expected: PASS with existing queued/partial/completed coverage still green and new skipped-reason assertions now passing.

### Task 3: Final Verification

**Files:**
- Modify: `resources/views/idea/partials/email_newsletter_research_status.blade.php`
- Modify: `resources/views/idea/partials/thought_detail_email_sidebar.blade.php`
- Modify: `tests/Feature/EmailThoughtStatusDisplayTest.php`

- [ ] **Step 1: Run the targeted feature tests**

Run: `php artisan test tests/Feature/EmailThoughtStatusDisplayTest.php`

Expected: PASS

- [ ] **Step 2: Run a broader related test file if needed**

Run: `php artisan test tests/Feature/EmailResearchControllerTest.php`

Expected: PASS to confirm no regression in newsletter research flows.

- [ ] **Step 3: Check lints/diagnostics for changed files**

Run: Cursor `ReadLints` on:
- `resources/views/idea/partials/email_newsletter_research_status.blade.php`
- `resources/views/idea/partials/thought_detail_email_sidebar.blade.php`
- `tests/Feature/EmailThoughtStatusDisplayTest.php`

Expected: no new diagnostics introduced by the change.

- [ ] **Step 4: Commit**

```bash
git add resources/views/idea/partials/email_newsletter_research_status.blade.php \
        resources/views/idea/partials/thought_detail_email_sidebar.blade.php \
        tests/Feature/EmailThoughtStatusDisplayTest.php \
        docs/superpowers/specs/2026-03-24-email-skipped-reason-info-design.md \
        docs/superpowers/plans/2026-03-24-email-skipped-reason-info.md
git commit -m "feat: explain skipped email research status"
```
