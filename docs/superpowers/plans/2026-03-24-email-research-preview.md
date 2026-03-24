# Email Research Preview Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a compact research-page-style preview to the email thought detail page by reusing shared research rendering and showing the root content plus up to the first two sections.

**Architecture:** Keep research lookup in `IdeaController`, but expand the email-detail flow to build a small preview view model instead of only a URL. Extract the repeated research-body markup into a shared Blade partial that supports both preview mode and full-detail mode, then compose the email page so the preview renders beneath the email body while the sidebar stays metadata-focused.

**Tech Stack:** Laravel 12, Blade, Tailwind utility classes, Pest/PHPUnit feature tests via `php artisan test`

---

## File Structure

**Create:**

- `resources/views/idea/partials/research_content.blade.php` — shared renderer for root research HTML and ordered sections, supporting preview and full-detail modes.

**Modify:**

- `app/Http/Controllers/IdeaController.php` — build an email research preview view model, keep auth parity with the full research route, and pass preview data into the detail view.
- `resources/views/idea/show.blade.php` — render a main-column stack for email body plus research preview.
- `resources/views/idea/research_show.blade.php` — switch the full research page to the shared partial without changing page chrome or related-email behavior.
- `resources/views/idea/partials/thought_detail_email_sidebar.blade.php` — remove the now-redundant sidebar `View research` link.
- `tests/Feature/ThoughtShowPageTest.php` — cover preview rendering, section limits, and missing/inaccessible states on the email detail page.
- `tests/Feature/ResearchShowTest.php` — protect the full research page rendering after the shared partial extraction.

## Task 1: Add Happy-Path Preview Coverage

**Files:**

- Modify: `tests/Feature/ThoughtShowPageTest.php`
- Test: `tests/Feature/ThoughtShowPageTest.php`

- [ ] **Step 1: Write the failing happy-path preview test**

```php
public function test_email_thought_detail_shows_research_preview_with_root_and_first_two_sections_only(): void
{
    $owner = User::factory()->create();
    $researchRoot = Thought::factory()->create([
        'user_id' => $owner->id,
        'parent_id' => null,
        'content' => "# Research Brief\n\nIntro paragraph.",
        'source' => 'web',
        'metadata' => ['type' => 'research', 'tags' => []],
    ]);

    Thought::factory()->create([
        'user_id' => $owner->id,
        'parent_id' => $researchRoot->id,
        'content' => "## Section One\n\nFirst section body.",
    ]);
    Thought::factory()->create([
        'user_id' => $owner->id,
        'parent_id' => $researchRoot->id,
        'content' => "## Section Two\n\nSecond section body.",
    ]);
    Thought::factory()->create([
        'user_id' => $owner->id,
        'parent_id' => $researchRoot->id,
        'content' => "## Section Three\n\nThird section body.",
    ]);

    $emailThought = Thought::factory()->create([
        'user_id' => $owner->id,
        'source' => 'email',
        'content' => 'Email body',
        'source_metadata' => ['research_thought_id' => $researchRoot->id],
    ]);

    $response = $this->actingAs($owner)->get(route('thoughts.show', $emailThought));

    $response->assertOk();
    $response->assertSee('Research preview', false);
    $response->assertSee('Intro paragraph.', false);
    $response->assertSee('Section One', false);
    $response->assertSee('Section Two', false);
    $response->assertDontSee('Section Three', false);
    $response->assertSee('View full research', false);
    $response->assertSee(route('idea.research.show', $researchRoot), false);
    $response->assertDontSee('>View research<', false);
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test tests/Feature/ThoughtShowPageTest.php --filter=shows_research_preview_with_root_and_first_two_sections_only`

Expected: FAIL because the email detail page only exposes `View research` and has no preview card or section rendering yet.

- [ ] **Step 3: Rewrite the existing positive sidebar-link test to the new preview contract**

Update `test_email_thought_detail_shows_view_research_link_when_source_metadata_matches_imported_email_research_thought()` so it becomes a preview-focused test that asserts:

```php
$response->assertSee('Research preview', false);
$response->assertSee('View full research', false);
$response->assertSee(route('idea.research.show', $researchThought), false);
$response->assertDontSee('>View research<', false);
```

- [ ] **Step 4: Re-run the tests to verify they still fail for the expected reason**

Run: `php artisan test tests/Feature/ThoughtShowPageTest.php --filter=research`

Expected: FAIL with missing preview content, not a syntax or factory error.

- [ ] **Step 5: Commit the red test**

```bash
git add tests/Feature/ThoughtShowPageTest.php
git commit -m "test: add email research preview happy path coverage"
```

## Task 2: Add Missing-State and Section-Count Coverage

**Files:**

- Modify: `tests/Feature/ThoughtShowPageTest.php`
- Test: `tests/Feature/ThoughtShowPageTest.php`

- [ ] **Step 1: Write the failing missing/inaccessible preview tests**

Add full runnable feature tests for:

```php
public function test_email_thought_detail_omits_research_preview_when_linked_research_is_missing(): void
{
    $owner = User::factory()->create();
    $missingResearchId = (string) Str::uuid();

    $emailThought = Thought::factory()->create([
        'user_id' => $owner->id,
        'source' => 'email',
        'content' => 'Email body',
        'source_metadata' => ['research_thought_id' => $missingResearchId],
    ]);

    $response = $this->actingAs($owner)->get(route('thoughts.show', $emailThought));

    $response->assertOk();
    $response->assertDontSee('Research preview', false);
    $response->assertDontSee('View full research', false);
    $response->assertDontSee('>View research<', false);
}

public function test_email_thought_detail_omits_research_preview_when_linked_research_belongs_to_another_user(): void
{
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $foreignResearch = Thought::factory()->create([
        'user_id' => $other->id,
        'parent_id' => null,
        'content' => '# Foreign research',
        'source' => 'web',
        'metadata' => ['type' => 'research', 'tags' => []],
    ]);

    $emailThought = Thought::factory()->create([
        'user_id' => $owner->id,
        'source' => 'email',
        'content' => 'Email body',
        'source_metadata' => ['research_thought_id' => $foreignResearch->id],
    ]);

    $response = $this->actingAs($owner)->get(route('thoughts.show', $emailThought));

    $response->assertOk();
    $response->assertDontSee('Research preview', false);
    $response->assertDontSee('View full research', false);
    $response->assertDontSee('>View research<', false);
}

public function test_email_thought_detail_shows_preview_when_research_has_root_only(): void
{
    $owner = User::factory()->create();
    $researchRoot = Thought::factory()->create([
        'user_id' => $owner->id,
        'parent_id' => null,
        'content' => "# Brief\n\nRoot-only intro.",
        'source' => 'web',
        'metadata' => ['type' => 'research', 'tags' => []],
    ]);

    $emailThought = Thought::factory()->create([
        'user_id' => $owner->id,
        'source' => 'email',
        'content' => 'Email body',
        'source_metadata' => ['research_thought_id' => $researchRoot->id],
    ]);

    $response = $this->actingAs($owner)->get(route('thoughts.show', $emailThought));

    $response->assertOk();
    $response->assertSee('Research preview', false);
    $response->assertSee('Root-only intro.', false);
    $response->assertSee('View full research', false);
}

public function test_email_thought_detail_shows_preview_when_root_is_empty_but_sections_exist(): void
{
    $owner = User::factory()->create();
    $researchRoot = Thought::factory()->create([
        'user_id' => $owner->id,
        'parent_id' => null,
        'content' => '',
        'source' => 'web',
        'metadata' => ['type' => 'research', 'tags' => []],
    ]);

    Thought::factory()->create([
        'user_id' => $owner->id,
        'parent_id' => $researchRoot->id,
        'content' => "## Section One\n\nVisible section body.",
    ]);

    $emailThought = Thought::factory()->create([
        'user_id' => $owner->id,
        'source' => 'email',
        'content' => 'Email body',
        'source_metadata' => ['research_thought_id' => $researchRoot->id],
    ]);

    $response = $this->actingAs($owner)->get(route('thoughts.show', $emailThought));

    $response->assertOk();
    $response->assertSee('Research preview', false);
    $response->assertSee('Section One', false);
    $response->assertSee('View full research', false);
}

public function test_email_thought_detail_omits_preview_when_root_and_sections_are_empty(): void
{
    $owner = User::factory()->create();
    $researchRoot = Thought::factory()->create([
        'user_id' => $owner->id,
        'parent_id' => null,
        'content' => '',
        'source' => 'web',
        'metadata' => ['type' => 'research', 'tags' => []],
    ]);

    $emailThought = Thought::factory()->create([
        'user_id' => $owner->id,
        'source' => 'email',
        'content' => 'Email body',
        'source_metadata' => ['research_thought_id' => $researchRoot->id],
    ]);

    $response = $this->actingAs($owner)->get(route('thoughts.show', $emailThought));

    $response->assertOk();
    $response->assertDontSee('Research preview', false);
    $response->assertDontSee('View full research', false);
}
```

- [ ] **Step 2: Run the new tests to verify they fail**

Run: `php artisan test tests/Feature/ThoughtShowPageTest.php --filter=email_thought_detail_`

Expected: At least the new preview-related assertions fail because the page still uses the old link-only behavior.

- [ ] **Step 3: Add the one-section contract test**

Add:

```php
public function test_email_thought_detail_shows_preview_when_research_has_exactly_one_section(): void
{
    $owner = User::factory()->create();
    $researchRoot = Thought::factory()->create([
        'user_id' => $owner->id,
        'parent_id' => null,
        'content' => "# Brief\n\nSingle-section intro.",
        'source' => 'web',
        'metadata' => ['type' => 'research', 'tags' => []],
    ]);

    Thought::factory()->create([
        'user_id' => $owner->id,
        'parent_id' => $researchRoot->id,
        'content' => "## Section One\n\nOnly section body.",
    ]);

    $emailThought = Thought::factory()->create([
        'user_id' => $owner->id,
        'source' => 'email',
        'content' => 'Email body',
        'source_metadata' => ['research_thought_id' => $researchRoot->id],
    ]);

    $response = $this->actingAs($owner)->get(route('thoughts.show', $emailThought));

    $response->assertOk();
    $response->assertSee('Research preview', false);
    $response->assertSee('Single-section intro.', false);
    $response->assertSee('Section One', false);
    $response->assertDontSee('Section Two', false);
    $response->assertSee('View full research', false);
}
```

- [ ] **Step 4: Re-run the targeted test file**

Run: `php artisan test tests/Feature/ThoughtShowPageTest.php --filter=email_thought_detail_`

Expected: FAIL only on the new preview assertions.

- [ ] **Step 5: Commit the expanded red coverage**

```bash
git add tests/Feature/ThoughtShowPageTest.php
git commit -m "test: cover email research preview edge cases"
```

## Task 3: Protect Full Research Rendering Before Refactor

**Files:**

- Modify: `tests/Feature/ResearchShowTest.php`
- Test: `tests/Feature/ResearchShowTest.php`

- [ ] **Step 1: Write a failing regression test for full research rendering**

```php
public function test_research_show_still_renders_all_sections_after_shared_partial_refactor(): void
{
    $owner = User::factory()->create();
    $root = Thought::factory()->create([
        'user_id' => $owner->id,
        'parent_id' => null,
        'content' => "# Research title\n\nRoot body.",
        'metadata' => ['type' => 'research', 'tags' => []],
    ]);

    Thought::factory()->create([
        'user_id' => $owner->id,
        'parent_id' => $root->id,
        'content' => "## Section One\n\nBody one.",
    ]);
    Thought::factory()->create([
        'user_id' => $owner->id,
        'parent_id' => $root->id,
        'content' => "## Section Two\n\nBody two.",
    ]);
    Thought::factory()->create([
        'user_id' => $owner->id,
        'parent_id' => $root->id,
        'content' => "## Section Three\n\nBody three.",
    ]);

    $response = $this->actingAs($owner)->get(route('idea.research.show', $root));

    $response->assertOk();
    $response->assertSee('Root body.', false);
    $response->assertSee('Section One', false);
    $response->assertSee('Section Two', false);
    $response->assertSee('Section Three', false);
}
```

- [ ] **Step 2: Run the regression test to confirm the current baseline passes**

Run: `php artisan test tests/Feature/ResearchShowTest.php --filter=still_renders_all_sections_after_shared_partial_refactor`

Expected: PASS. This test is the guardrail for the upcoming view extraction.

- [ ] **Step 3: Commit the passing guardrail**

```bash
git add tests/Feature/ResearchShowTest.php
git commit -m "test: lock full research rendering before partial extraction"
```

## Task 4: Extract the Shared Research Content Partial

**Files:**

- Create: `resources/views/idea/partials/research_content.blade.php`
- Modify: `resources/views/idea/research_show.blade.php`
- Test: `tests/Feature/ResearchShowTest.php`

- [ ] **Step 1: Create the shared partial with preview/full inputs**

Implement a partial shaped like:

```blade
@props([
    'label' => 'Research',
    'rootHtml',
    'sections',
    'footerUrl' => null,
    'footerLabel' => null,
])

<div class="rounded-2xl border border-memory-violet/20 bg-white/80 backdrop-blur p-6 md:p-8 shadow-[0_4px_24px_rgba(109,106,247,0.08)]">
    <p class="text-[11px] font-semibold tracking-[0.1em] uppercase text-memory-violet/80 mb-4">{{ $label }}</p>
    <div class="prose ...">
        {!! $rootHtml !!}
    </div>

    @if($sections->isNotEmpty())
        <ul class="mt-8 space-y-8 border-t border-memory-violet/10 pt-8 list-none pl-0">
            @foreach($sections as $section)
                <li>
                    <div class="prose ...">
                        {!! $section->content_html !!}
                    </div>
                </li>
            @endforeach
        </ul>
    @endif

    @if($footerUrl && $footerLabel)
        <p class="mt-6">
            <a href="{{ $footerUrl }}" class="text-[13px] font-medium text-memory-violet hover:underline">{{ $footerLabel }}</a>
        </p>
    @endif
</div>
```

Preserve the current research page’s distinct typography by carrying over the existing root prose class string and the existing section prose class string separately. Do not silently collapse them into one shared class list unless you intentionally update the full-page design and adjust tests accordingly.

- [ ] **Step 2: Refactor the full research page to use the new partial without changing behavior**

Replace the duplicated root/section markup in `resources/views/idea/research_show.blade.php` with an include/component call, while keeping:

- back link
- related email card
- created-at text

- [ ] **Step 3: Run the full research test file**

Run: `php artisan test tests/Feature/ResearchShowTest.php`

Expected: PASS, including the new all-sections regression test.

- [ ] **Step 4: Make only the minimal styling adjustments needed**

If the created-at timestamp or spacing needs to stay outside the shared partial, keep it in `research_show.blade.php` rather than overloading the partial API.

- [ ] **Step 5: Commit the extraction**

```bash
git add resources/views/idea/partials/research_content.blade.php resources/views/idea/research_show.blade.php tests/Feature/ResearchShowTest.php
git commit -m "refactor: extract shared research content partial"
```

## Task 5: Build the Email Preview View Model in the Controller

**Files:**

- Modify: `app/Http/Controllers/IdeaController.php`
- Test: `tests/Feature/ThoughtShowPageTest.php`

- [ ] **Step 1: Add a failing expectation that the preview uses the linked research route**

In the happy-path email preview test, assert the rendered page includes:

```php
$response->assertSee(route('idea.research.show', $researchRoot), false);
```

- [ ] **Step 2: Run the targeted happy-path test to verify it fails**

Run: `php artisan test tests/Feature/ThoughtShowPageTest.php --filter=shows_research_preview_with_root_and_first_two_sections_only`

Expected: FAIL because `IdeaController::show()` still passes only `linkedResearchUrl`.

- [ ] **Step 3: Implement the minimal controller support**

In `IdeaController.php`:

- replace `resolveEmailLinkedResearchUrl()` with a richer helper such as `resolveEmailLinkedResearchPreview()`
- reuse the same durable-link resolution rules as the current `resolveEmailLinkedResearchUrl()` path so metadata/imported/captured agreement behavior stays unchanged
- resolve the linked research root using the same user/research constraints as `showResearch()`
- convert the root markdown to HTML with `CommonMarkConverter`
- fetch ordered child sections with `$research->comments()->orderBy('created_at')->take(2)->get()`
- map child sections to `content_html`
- return a small array like:

```php
[
    'url' => route('idea.research.show', $research),
    'root_html' => $rootHtml,
    'sections' => $sectionsWithHtml,
]
```

- [ ] **Step 4: Pass the preview data into `idea.show`**

Update the `show()` view data:

```php
'researchPreview' => $researchPreview,
```

Keep the value `null` for non-email thoughts or unresolved/inaccessible links, and remove the old `linkedResearchUrl` view variable so there is a single research-entry API for the template.

- [ ] **Step 5: Run the targeted email detail tests**

Run: `php artisan test tests/Feature/ThoughtShowPageTest.php --filter=email_thought_detail_`

Expected: Some tests may still fail until the Blade templates render the preview, but the controller should no longer fail on missing data or wrong route targets.

- [ ] **Step 6: Commit the controller groundwork**

```bash
git add app/Http/Controllers/IdeaController.php tests/Feature/ThoughtShowPageTest.php
git commit -m "feat: build email research preview view model"
```

## Task 6: Render the Email Preview and Remove the Sidebar Link

**Files:**

- Modify: `resources/views/idea/show.blade.php`
- Modify: `resources/views/idea/partials/thought_detail_email_sidebar.blade.php`
- Test: `tests/Feature/ThoughtShowPageTest.php`

- [ ] **Step 1: Update the email layout to stack body and preview in the main column**

Change the email branch in `resources/views/idea/show.blade.php` from a single `<article>` in the grid to a main-column wrapper:

```blade
<div class="space-y-6">
    <article>...</article>

    @if (! empty($researchPreview))
        @include('idea.partials.research_content', [
            'label' => 'Research preview',
            'rootHtml' => $researchPreview['root_html'],
            'sections' => $researchPreview['sections'],
            'isPreview' => true,
            'footerUrl' => $researchPreview['url'],
            'footerLabel' => 'View full research',
        ])
    @endif
</div>
```

- [ ] **Step 2: Remove the redundant sidebar research link**

Delete:

```blade
@if (! empty($linkedResearchUrl))
    <a href="{{ $linkedResearchUrl }}" class="block text-[13px] font-medium text-memory-violet hover:underline pt-1">View research</a>
@endif
```

from `resources/views/idea/partials/thought_detail_email_sidebar.blade.php`.

- [ ] **Step 3: Remove the old `linkedResearchUrl` plumbing**

Delete the unused include argument from `resources/views/idea/show.blade.php` and any no-longer-needed references in the sidebar partial so the new preview path is the only rendering contract.

- [ ] **Step 4: Run the targeted email detail tests**

Run: `php artisan test tests/Feature/ThoughtShowPageTest.php --filter=email_thought_detail_`

Expected: PASS for the preview-related assertions added in Tasks 1-2.

- [ ] **Step 5: Run the full affected feature suite**

Run: `php artisan test tests/Feature/ThoughtShowPageTest.php tests/Feature/ResearchShowTest.php`

Expected: PASS with no regressions in the full research page or email detail page.

- [ ] **Step 6: Commit the UI wiring**

```bash
git add resources/views/idea/show.blade.php resources/views/idea/partials/thought_detail_email_sidebar.blade.php tests/Feature/ThoughtShowPageTest.php tests/Feature/ResearchShowTest.php
git commit -m "feat: show linked research preview on email detail"
```

## Task 7: Final Verification

**Files:**

- Modify: none
- Test: `tests/Feature/ThoughtShowPageTest.php`
- Test: `tests/Feature/ResearchShowTest.php`

- [ ] **Step 1: Run the focused verification commands**

Run: `php artisan test tests/Feature/ThoughtShowPageTest.php tests/Feature/ResearchShowTest.php`

Expected: PASS.

- [ ] **Step 2: Run a broad regression pass for the touched features**

Run: `php artisan test --filter=ResearchShowTest`

Expected: PASS, confirming the shared partial did not regress the full research route.

- [ ] **Step 3: Manually verify the UI in browser**

Check:

- an email with linked research shows the preview below `Email body`
- the preview shows root content plus at most two sections
- `View full research` opens the full research page
- the right sidebar no longer shows `View research`
- the research detail page still shows all sections and the related-email card

- [ ] **Step 4: Commit any final cleanup**

```bash
git add app/Http/Controllers/IdeaController.php resources/views/idea/show.blade.php resources/views/idea/research_show.blade.php resources/views/idea/partials/research_content.blade.php resources/views/idea/partials/thought_detail_email_sidebar.blade.php tests/Feature/ThoughtShowPageTest.php tests/Feature/ResearchShowTest.php
git commit -m "chore: finalize email research preview verification"
```
