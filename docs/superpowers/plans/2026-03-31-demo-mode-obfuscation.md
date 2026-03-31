# Demo Mode Obfuscation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a per-session demo mode that keeps real records and live writes intact while obfuscating sensitive narrative text on covered HTML render paths and showing a persistent banner when demo mode is active.

**Architecture:** Introduce a small session-backed `DemoMode` service plus a deterministic `DemoObfuscator`, then thread those through the existing Blade presenter layer rather than mutating stored data. Start with the underlying session and obfuscation primitives, but keep the feature flag off until the first covered render slice is complete so the UI never claims safety before presenter-backed obfuscation is wired. Cover the highest-risk presenter-backed surfaces in order: thought detail, index/stream cards, and ideas/completed lists, while disabling inline edit leaks on covered pages when demo mode is on.

**Tech Stack:** Laravel 12, PHP 8.2+, Blade, Eloquent, session-backed services, existing view presenters under `app/View/Presenters`, Laravel feature/unit tests via `php artisan test` following the repo’s existing test style.

**Spec:** `docs/superpowers/specs/2026-03-31-demo-mode-obfuscation-design.md`

**Execution notes:** Follow @superpowers:test-driven-development for each task and use @superpowers:verification-before-completion before claiming the implementation is done.

---

## File Structure

| File | Responsibility |
|------|----------------|
| `config/services.php` | Add a `demo_mode` feature flag / environment gate for trusted deployments |
| `app/Services/DemoMode.php` | Own session keys, enable/disable logic, and current-request mode checks |
| `app/Services/DemoObfuscator.php` | Produce deterministic, fail-closed obfuscated strings scoped to one session and one field context |
| `app/View/Presenters/Concerns/ObfuscatesDemoText.php` | Shared presenter helper for applying demo obfuscation without duplicating service lookups |
| `app/Http/Controllers/DemoModeController.php` | Authenticated toggle endpoints for enabling/disabling demo mode |
| `routes/web.php` | Register auth-protected demo mode toggle routes |
| `app/Providers/AppServiceProvider.php` | Share demo mode state with `layouts.idea` so the banner/toggle can render consistently |
| `resources/views/layouts/idea.blade.php` | Add the persistent demo banner and a simple enable/disable control in the authenticated shell |
| `app/Http/Controllers/IdeaController.php` | Ensure detail-page markdown is obfuscated before HTML conversion and keep presenter-backed payloads consistent |
| `app/View/Presenters/Thoughts/ThoughtDetailPresenter.php` | Expose safe display text for thought detail content and email body fields |
| `app/View/Presenters/Email/EmailMetadataPresenter.php` | Obfuscate email subject and explicitly document the v1 status of `ImportedEmail.summary` while leaving non-narrative metadata untouched |
| `resources/views/idea/show.blade.php` | Consume presenter-backed obfuscated detail fields only |
| `resources/views/idea/partials/thought_detail_email_sidebar.blade.php` | Keep using presenter output only for the email sidebar |
| `resources/views/idea/partials/editable_thought_content.blade.php` | Stop embedding raw sensitive content in Alpine props on covered demo-mode pages; suppress inline editing there |
| `app/View/Presenters/Thoughts/IdeaIndexCardPresenter.php` | Expose obfuscated card body, parent preview, comment previews, and demo-safe editability state for the index feed |
| `app/View/Presenters/Thoughts/StreamThoughtCardPresenter.php` | Expose obfuscated card body and comment previews for the stream feed |
| `resources/views/idea/index_thought_cards.blade.php` | Render presenter-owned display strings for index cards and their comment previews |
| `resources/views/idea/stream_thoughts.blade.php` | Render presenter-owned display strings for stream cards and their comment previews |
| `app/View/Presenters/Ideas/IdeaListItemPresenter.php` | Expose obfuscated idea body and research preview snippets for the incomplete ideas list |
| `app/View/Presenters/Ideas/CompletedIdeaPresenter.php` | Expose obfuscated completed-idea excerpt while preserving logged/completed date labels |
| `resources/views/idea/partials/ideas_list.blade.php` | Render presenter-owned display text for ideas and research snippets |
| `resources/views/idea/partials/completed_ideas_list.blade.php` | Render presenter-owned display text for completed ideas |
| `tests/Feature/DemoModeToggleTest.php` | Cover toggle auth, feature gating, banner visibility, and session transitions |
| `tests/Unit/Services/DemoModeTest.php` | Cover direct `DemoMode` enable/disable/seed behavior without going through HTTP |
| `tests/Unit/Services/DemoObfuscatorTest.php` | Cover determinism, field-context namespacing, null passthrough, and fail-closed fallback |
| `tests/Unit/View/Presenters/Email/EmailMetadataPresenterTest.php` | Verify subject obfuscation in demo mode while leaving metadata fields alone |
| `tests/Feature/ThoughtShowPageTest.php` | Verify detail-page content and email subject/body are obfuscated in demo mode |
| `tests/Unit/View/Presenters/Thoughts/IdeaIndexCardPresenterTest.php` | Verify obfuscated card strings and demo-mode edit suppression on index cards |
| `tests/Unit/View/Presenters/Thoughts/StreamThoughtCardPresenterTest.php` | Verify obfuscated card strings and comment previews on stream cards |
| `tests/Feature/IdeaPageTest.php` | Verify recent/search HTML output hides raw thought text in demo mode |
| `tests/Feature/StreamPageTest.php` | Verify stream HTML output hides raw thought text in demo mode |
| `tests/Unit/View/Presenters/Ideas/IdeaListItemPresenterTest.php` | Verify obfuscated idea/research snippet output while preserving state labels |
| `tests/Unit/View/Presenters/Ideas/CompletedIdeaPresenterTest.php` | Verify obfuscated completed-idea excerpts while preserving formatted dates |
| `tests/Feature/IdeaIdeasTest.php` | Verify incomplete ideas page and AJAX HTML fragments are safe in demo mode |
| `tests/Feature/CompletedIdeasPageTest.php` | Verify completed ideas page hides raw excerpts in demo mode while preserving date text |

---

## Task 1: Add session-backed demo mode toggling and banner UX

**Files:**
- Modify: `config/services.php`
- Create: `app/Services/DemoMode.php`
- Create: `app/Http/Controllers/DemoModeController.php`
- Modify: `routes/web.php`
- Modify: `app/Providers/AppServiceProvider.php`
- Modify: `resources/views/layouts/idea.blade.php`
- Test: `tests/Feature/DemoModeToggleTest.php`
- Test: `tests/Unit/Services/DemoModeTest.php`

- [ ] **Step 1: Write the failing toggle and banner feature tests**

Create `tests/Feature/DemoModeToggleTest.php` with cases like:

```php
public function test_authenticated_user_can_enable_demo_mode_when_feature_is_enabled(): void
{
    config(['services.demo_mode.enabled' => true]);
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('demo-mode.enable'));

    $response->assertRedirect(route('idea.index'));
    $response->assertSessionHas('success');
    $response->assertSessionHas('demo_mode.enabled', true);
    $response->assertSessionHas('demo_mode.seed');
}
```

Add a second test proving guests are redirected and a third test proving the banner renders on `idea.index` after enabling:

```php
$page = $this->withSession(['demo_mode.enabled' => true, 'demo_mode.seed' => 'seed-123'])
    ->actingAs($user)
    ->get(route('idea.index'));

$page->assertSee('Demo mode enabled. Sensitive text is obfuscated.', false);
```

Also add a feature-flag test that expects `404` or `403` when `services.demo_mode.enabled` is false.

Create `tests/Unit/Services/DemoModeTest.php` with direct service coverage like:

```php
public function test_enable_sets_enabled_flag_and_seed_once(): void
{
    $mode = app(DemoMode::class);

    $mode->enable();
    $firstSeed = session('demo_mode.seed');

    $mode->enable();

    $this->assertTrue($mode->enabled());
    $this->assertSame($firstSeed, session('demo_mode.seed'));
}
```

Add a matching `disable()` test that asserts both session keys are cleared.

- [ ] **Step 2: Run the new demo-mode feature tests and verify they fail**

Run:

```bash
php artisan test tests/Feature/DemoModeToggleTest.php tests/Unit/Services/DemoModeTest.php -v
```

Expected: FAIL because the service, routes, controller, and banner do not exist yet.

- [ ] **Step 3: Implement the feature flag, session service, routes, controller, and layout banner**

In `config/services.php`, add:

```php
'demo_mode' => [
    'enabled' => filter_var(env('DEMO_MODE_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
],
```

Create `app/Services/DemoMode.php` with a small API:

```php
final class DemoMode
{
    public const ENABLED_SESSION_KEY = 'demo_mode.enabled';
    public const SEED_SESSION_KEY = 'demo_mode.seed';

    public function enabled(): bool {}
    public function enable(): void {}
    public function disable(): void {}
    public function seed(): ?string {}
}
```

Create `app/Http/Controllers/DemoModeController.php` with `enable()` and `disable()` actions that:

- require auth
- `abort_unless(config('services.demo_mode.enabled'), 404)`
- set or clear the session via `DemoMode`
- redirect back to `route('idea.index')` or `url()->previous()` with a flash message

Register auth-only POST routes in `routes/web.php`:

```php
Route::post('/demo-mode/enable', [DemoModeController::class, 'enable'])->name('demo-mode.enable');
Route::post('/demo-mode/disable', [DemoModeController::class, 'disable'])->name('demo-mode.disable');
```

Render the layout control as normal POST forms with `@csrf`.

Update `AppServiceProvider::boot()` to share a boolean like `demoModeEnabled` with `layouts.idea`, then update `resources/views/layouts/idea.blade.php` to:

- render a persistent banner when demo mode is active
- add a simple enable/disable form in the authenticated shell
- keep the control hidden when the feature flag is off
- keep `services.demo_mode.enabled` false in real deployments until Tasks 3 through 5 are merged, so there is no intermediate “banner on, raw text visible” state

- [ ] **Step 4: Re-run the toggle and banner feature tests and verify they pass**

Run:

```bash
php artisan test tests/Feature/DemoModeToggleTest.php tests/Unit/Services/DemoModeTest.php -v
```

Expected: PASS.

- [ ] **Step 5: Commit the session toggle foundation**

```bash
git add config/services.php app/Services/DemoMode.php app/Http/Controllers/DemoModeController.php routes/web.php app/Providers/AppServiceProvider.php resources/views/layouts/idea.blade.php tests/Feature/DemoModeToggleTest.php tests/Unit/Services/DemoModeTest.php
git commit -m "feat: add session-based demo mode toggle"
```

---

## Task 2: Add deterministic obfuscation primitives for presenters

**Files:**
- Create: `app/Services/DemoObfuscator.php`
- Create: `app/View/Presenters/Concerns/ObfuscatesDemoText.php`
- Test: `tests/Unit/Services/DemoObfuscatorTest.php`

- [ ] **Step 1: Write the failing unit tests for deterministic obfuscation**

Create `tests/Unit/Services/DemoObfuscatorTest.php` with cases like:

```php
public function test_same_input_and_context_map_to_same_output_within_one_session_seed(): void
{
    session()->put('demo_mode.seed', 'demo-seed-123');

    $obfuscator = app(DemoObfuscator::class);

    $first = $obfuscator->obfuscate('Sensitive thought body', 'thought_content');
    $second = $obfuscator->obfuscate('Sensitive thought body', 'thought_content');

    $this->assertSame($first, $second);
    $this->assertNotSame('Sensitive thought body', $first);
}
```

Add tests for:

- different field contexts produce different output for the same source string
- `null` and empty strings pass through unchanged
- if generation raises an exception internally, the returned value is `Demo content hidden`

- [ ] **Step 2: Run the obfuscator unit tests and verify they fail**

Run:

```bash
php artisan test tests/Unit/Services/DemoObfuscatorTest.php -v
```

Expected: FAIL because the obfuscator and presenter concern do not exist yet.

- [ ] **Step 3: Implement the obfuscator and presenter helper concern**

Create `app/Services/DemoObfuscator.php` with a deterministic API such as:

```php
final class DemoObfuscator
{
    public function obfuscate(?string $value, string $context): ?string
    {
        // normalize input, combine with session seed + context, and return
        // a deterministic fake string; on failure return 'Demo content hidden'
    }
}
```

Use one normalization path (trim + Unicode normalization if available) and namespace replacements by field context (`thought_content`, `email_subject`, `research_snippet`, etc.).

Create `app/View/Presenters/Concerns/ObfuscatesDemoText.php` with a helper like:

```php
trait ObfuscatesDemoText
{
    protected function demoText(?string $value, string $context): ?string
    {
        if (! app(DemoMode::class)->enabled()) {
            return $value;
        }

        return app(DemoObfuscator::class)->obfuscate($value, $context);
    }
}
```

Keep this helper presentation-only: it should never mutate models or write derived values back to the database.

- [ ] **Step 4: Re-run the obfuscator unit tests and verify they pass**

Run:

```bash
php artisan test tests/Unit/Services/DemoObfuscatorTest.php -v
```

Expected: PASS.

- [ ] **Step 5: Commit the obfuscation primitives**

```bash
git add app/Services/DemoObfuscator.php app/View/Presenters/Concerns/ObfuscatesDemoText.php tests/Unit/Services/DemoObfuscatorTest.php
git commit -m "feat: add deterministic demo obfuscation service"
```

---

## Task 3: Cover thought detail and email metadata surfaces

**Files:**
- Modify: `app/Http/Controllers/IdeaController.php`
- Modify: `app/View/Presenters/Thoughts/ThoughtDetailPresenter.php`
- Modify: `app/View/Presenters/Email/EmailMetadataPresenter.php`
- Modify: `resources/views/idea/show.blade.php`
- Modify: `resources/views/idea/partials/thought_detail_email_sidebar.blade.php`
- Modify: `tests/Unit/View/Presenters/Email/EmailMetadataPresenterTest.php`
- Modify: `tests/Feature/ThoughtShowPageTest.php`

- [ ] **Step 1: Add failing detail-page and email-subject coverage**

Extend `tests/Feature/ThoughtShowPageTest.php` with a demo-mode case:

```php
public function test_demo_mode_obfuscates_detail_page_content_without_mutating_the_record(): void
{
    config(['services.demo_mode.enabled' => true]);
    $owner = User::factory()->create();
    $thought = Thought::factory()->create([
        'user_id' => $owner->id,
        'source' => 'web',
        'content' => 'Highly sensitive strategy note 42',
    ]);

    $response = $this->withSession([
        'demo_mode.enabled' => true,
        'demo_mode.seed' => 'seed-123',
    ])->actingAs($owner)->get(route('thoughts.show', $thought));

    $response->assertOk();
    $response->assertDontSee('Highly sensitive strategy note 42', false);
    $response->assertSee('Demo mode enabled. Sensitive text is obfuscated.', false);

    $this->assertSame('Highly sensitive strategy note 42', $thought->fresh()->content);
}
```

Add a second test for an email thought with `ImportedEmail.subject` and `body_text`, and extend `tests/Unit/View/Presenters/Email/EmailMetadataPresenterTest.php` with a case proving `subject()` changes in demo mode while `direction()` and `provider()` stay real.

Add paired normal-mode assertions so the same marker strings are still visible when demo mode is off:

```php
$normal = $this->actingAs($owner)->get(route('thoughts.show', $thought));
$normal->assertSee('Highly sensitive strategy note 42', false);
```

Also add one failure-path feature test that binds a throwing `DemoObfuscator` into the container and asserts the page shows `Demo content hidden` instead of the raw source string.

- [ ] **Step 2: Run the focused detail-page tests and verify they fail**

Run:

```bash
php artisan test tests/Unit/View/Presenters/Email/EmailMetadataPresenterTest.php tests/Feature/ThoughtShowPageTest.php --filter=demo_mode -v
```

Expected: FAIL because the detail presenter/controller path still returns raw content.

- [ ] **Step 3: Implement detail-page obfuscation in the controller and presenters**

Update `IdeaController::show()` so non-email markdown is obfuscated before `CommonMarkConverter` runs:

```php
$displayMarkdown = app(DemoMode::class)->enabled()
    ? app(DemoObfuscator::class)->obfuscate($thought->content, 'thought_content')
    : $thought->content;

$contentHtml = (new CommonMarkConverter)->convert($displayMarkdown)->getContent();
```

Then update `ThoughtDetailPresenter` to use `ObfuscatesDemoText` and expose a safe `emailBodyText()`:

```php
public function emailBodyText(): string
{
    $body = /* imported email body or fallback thought content */;

    return $this->demoText($body, 'email_body_text') ?? 'Demo content hidden';
}
```

Update `EmailMetadataPresenter::subject()` to route through `demoText(..., 'email_subject')`. Also make the summary decision explicit in code and tests: because no currently covered v1 page renders `ImportedEmail.summary`, document that it is intentionally deferred from this implementation slice; if implementation discovers a covered detail or list surface already rendering summary, wire that field through `demoText(..., 'email_summary')` in the same task before merging. Leave non-narrative methods like `direction()`, `provider()`, `mailboxName()`, `fromLine()`, `toLine()`, and `ccLine()` unchanged for v1.

Keep `resources/views/idea/show.blade.php` and `resources/views/idea/partials/thought_detail_email_sidebar.blade.php` on presenter methods only; do not reintroduce raw model reads in Blade.

While touching the covered detail route, audit for any `<title>`, heading text, or text-bearing HTML attributes derived from classified narrative fields. If any are found, source them from the same obfuscated presenter values before closing this task.

- [ ] **Step 4: Re-run the detail-page tests and verify they pass**

Run:

```bash
php artisan test tests/Unit/View/Presenters/Email/EmailMetadataPresenterTest.php tests/Feature/ThoughtShowPageTest.php --filter=demo_mode -v
```

Expected: PASS.

- [ ] **Step 5: Commit thought-detail coverage**

```bash
git add app/Http/Controllers/IdeaController.php app/View/Presenters/Thoughts/ThoughtDetailPresenter.php app/View/Presenters/Email/EmailMetadataPresenter.php resources/views/idea/show.blade.php resources/views/idea/partials/thought_detail_email_sidebar.blade.php tests/Unit/View/Presenters/Email/EmailMetadataPresenterTest.php tests/Feature/ThoughtShowPageTest.php
git commit -m "feat: obfuscate thought detail demo content"
```

---

## Task 4: Cover index and stream cards, including inline-edit leak prevention

**Files:**
- Modify: `app/View/Presenters/Thoughts/IdeaIndexCardPresenter.php`
- Modify: `app/View/Presenters/Thoughts/StreamThoughtCardPresenter.php`
- Modify: `resources/views/idea/index_thought_cards.blade.php`
- Modify: `resources/views/idea/stream_thoughts.blade.php`
- Modify: `resources/views/idea/partials/editable_thought_content.blade.php`
- Modify: `tests/Unit/View/Presenters/Thoughts/IdeaIndexCardPresenterTest.php`
- Modify: `tests/Unit/View/Presenters/Thoughts/StreamThoughtCardPresenterTest.php`
- Modify: `tests/Feature/IdeaPageTest.php`
- Modify: `tests/Feature/StreamPageTest.php`

- [ ] **Step 1: Add failing presenter and feature coverage for index/stream obfuscation**

Extend `IdeaIndexCardPresenterTest` with a case like:

```php
public function it_obfuscates_card_and_parent_preview_text_in_demo_mode(): void
{
    config(['services.demo_mode.enabled' => true]);
    session()->put('demo_mode.enabled', true);
    session()->put('demo_mode.seed', 'seed-123');

    $user = User::factory()->create();
    $this->actingAs($user);

    $parent = Thought::factory()->create(['user_id' => $user->id, 'content' => 'Parent secret']);
    $child = Thought::factory()->create(['user_id' => $user->id, 'parent_id' => $parent->id, 'content' => 'Child secret']);
    $child->load('parent');
    $child->setRelation('comments', collect());

    $card = IdeaIndexCardPresenter::fromThought($child, -1);

    $this->assertNotSame('Parent secret', $card->parentPreviewExcerpt());
    $this->assertFalse($card->editable());
}
```

Extend `tests/Feature/IdeaPageTest.php` and `tests/Feature/StreamPageTest.php` with demo-mode assertions that:

- the response does not contain the original thought text
- the banner is visible
- inline edit affordances are hidden or disabled
- comment preview text is also obfuscated

Include one AJAX fragment case for `idea.index?q=...` or `idea.ideas`-style HTML fragment output if the page under test returns server-rendered card HTML through JSON.

Add paired normal-mode assertions so the raw marker text still appears when demo mode is off.

- [ ] **Step 2: Run the focused index/stream tests and verify they fail**

Run:

```bash
php artisan test tests/Unit/View/Presenters/Thoughts/IdeaIndexCardPresenterTest.php tests/Unit/View/Presenters/Thoughts/StreamThoughtCardPresenterTest.php tests/Feature/IdeaPageTest.php tests/Feature/StreamPageTest.php --filter=demo_mode -v
```

Expected: FAIL because the cards and Alpine partial still embed raw content.

- [ ] **Step 3: Implement presenter-owned display strings and suppress editing in demo mode**

Update `IdeaIndexCardPresenter` and `StreamThoughtCardPresenter` to expose explicit display fields:

```php
public function displayContent(): string {}
public function commentPreviewRows(): array {}
public function editable(): bool
{
    return $this->editable && ! app(DemoMode::class)->enabled();
}
```

Use `demoText()` for:

- main card body (`thought_content`)
- parent preview excerpt (`thought_parent_preview`)
- comment previews (`thought_comment_preview`)

Then update `resources/views/idea/index_thought_cards.blade.php` and `resources/views/idea/stream_thoughts.blade.php` to render presenter-owned strings instead of `thought()->content` / `comment->content`.

Refactor `resources/views/idea/partials/editable_thought_content.blade.php` so it accepts explicit display data and does not embed raw sensitive text into Alpine state on covered demo-mode pages. A safe shape is:

```php
@include('idea.partials.editable_thought_content', [
    'thought' => $card->thought(),
    'editable' => $card->editable(),
    'displayContent' => $card->displayContent(),
    'rawEditorContent' => $card->editable() ? $card->thought()->content : null,
])
```

When demo mode is enabled for a covered page, keep `editable` false so no raw editor payload is emitted into HTML.

While touching these card templates, audit for any `title`, `aria-label`, or similar text-bearing attributes derived from classified narrative strings and route them through the same presenter display values if they exist.

- [ ] **Step 4: Re-run the index/stream tests and verify they pass**

Run:

```bash
php artisan test tests/Unit/View/Presenters/Thoughts/IdeaIndexCardPresenterTest.php tests/Unit/View/Presenters/Thoughts/StreamThoughtCardPresenterTest.php tests/Feature/IdeaPageTest.php tests/Feature/StreamPageTest.php --filter=demo_mode -v
```

Expected: PASS.

- [ ] **Step 5: Commit index/stream coverage**

```bash
git add app/View/Presenters/Thoughts/IdeaIndexCardPresenter.php app/View/Presenters/Thoughts/StreamThoughtCardPresenter.php resources/views/idea/index_thought_cards.blade.php resources/views/idea/stream_thoughts.blade.php resources/views/idea/partials/editable_thought_content.blade.php tests/Unit/View/Presenters/Thoughts/IdeaIndexCardPresenterTest.php tests/Unit/View/Presenters/Thoughts/StreamThoughtCardPresenterTest.php tests/Feature/IdeaPageTest.php tests/Feature/StreamPageTest.php
git commit -m "feat: obfuscate demo mode feed cards"
```

---

## Task 5: Cover ideas and completed lists, including research snippets and AJAX HTML fragments

**Files:**
- Modify: `app/View/Presenters/Ideas/IdeaListItemPresenter.php`
- Modify: `app/View/Presenters/Ideas/CompletedIdeaPresenter.php`
- Modify: `resources/views/idea/partials/ideas_list.blade.php`
- Modify: `resources/views/idea/partials/completed_ideas_list.blade.php`
- Modify: `tests/Unit/View/Presenters/Ideas/IdeaListItemPresenterTest.php`
- Modify: `tests/Unit/View/Presenters/Ideas/CompletedIdeaPresenterTest.php`
- Modify: `tests/Feature/IdeaIdeasTest.php`
- Modify: `tests/Feature/CompletedIdeasPageTest.php`

- [ ] **Step 1: Add failing list-page coverage for obfuscated excerpts**

Extend `IdeaListItemPresenterTest` with a case that proves:

```php
$row = IdeaListItemPresenter::from($idea, collect([$research]));

$this->assertNotSame('Secret idea body', $row->displayContent());
$this->assertNotSame('Secret research body', $row->researchPreviewRows()[0]['excerpt']);
$this->assertSame('2025-06-15', $row->loggedDateYmd());
```

Extend `CompletedIdeaPresenterTest` with a case proving the completed-idea excerpt is obfuscated while `loggedFormatted()` and `completedFormatted()` stay unchanged.

Then extend `tests/Feature/IdeaIdeasTest.php` and `tests/Feature/CompletedIdeasPageTest.php` with demo-mode cases that assert:

- raw idea / research snippet text is absent
- dates and status labels still render
- AJAX HTML fragments for the ideas page are safe when the `html` payload is returned

Add paired normal-mode assertions so the same idea/research marker text still appears when demo mode is off.

- [ ] **Step 2: Run the ideas/completed tests and verify they fail**

Run:

```bash
php artisan test tests/Unit/View/Presenters/Ideas/IdeaListItemPresenterTest.php tests/Unit/View/Presenters/Ideas/CompletedIdeaPresenterTest.php tests/Feature/IdeaIdeasTest.php tests/Feature/CompletedIdeasPageTest.php --filter=demo_mode -v
```

Expected: FAIL because the views still read raw `Thought` content and research snippets directly.

- [ ] **Step 3: Implement presenter-owned excerpts for ideas, research snippets, and completed cards**

Update `IdeaListItemPresenter` to expose methods like:

```php
public function displayContent(): string {}
public function researchPreviewRows(): array {}
```

Each research preview row should carry only safe display data needed by Blade:

```php
[
    'thought_id' => (string) $research->id,
    'excerpt' => $this->demoText(Str::limit($research->content, 120), 'research_snippet') ?? 'Demo content hidden',
    'url' => route('idea.research.show', $research).'?from=ideas',
]
```

Update `CompletedIdeaPresenter` to expose an obfuscated `displayExcerpt()` for the list card while leaving `loggedFormatted()` and `completedFormatted()` intact.

Then update `resources/views/idea/partials/ideas_list.blade.php` and `resources/views/idea/partials/completed_ideas_list.blade.php` to render only presenter-owned strings, never `thought->content` or `research->content` directly.

- [ ] **Step 4: Re-run the ideas/completed tests and verify they pass**

Run:

```bash
php artisan test tests/Unit/View/Presenters/Ideas/IdeaListItemPresenterTest.php tests/Unit/View/Presenters/Ideas/CompletedIdeaPresenterTest.php tests/Feature/IdeaIdeasTest.php tests/Feature/CompletedIdeasPageTest.php --filter=demo_mode -v
```

Expected: PASS.

- [ ] **Step 5: Commit list-page coverage**

```bash
git add app/View/Presenters/Ideas/IdeaListItemPresenter.php app/View/Presenters/Ideas/CompletedIdeaPresenter.php resources/views/idea/partials/ideas_list.blade.php resources/views/idea/partials/completed_ideas_list.blade.php tests/Unit/View/Presenters/Ideas/IdeaListItemPresenterTest.php tests/Unit/View/Presenters/Ideas/CompletedIdeaPresenterTest.php tests/Feature/IdeaIdeasTest.php tests/Feature/CompletedIdeasPageTest.php
git commit -m "feat: obfuscate demo mode idea lists"
```

---

## Task 6: Run the covered-surface regression suite and document remaining v1 gaps

**Files:**
- Modify: `tests/Feature/DemoModeToggleTest.php` (if one final integration assertion is missing)
- Modify: `tests/Feature/ThoughtShowPageTest.php`
- Modify: `tests/Feature/IdeaPageTest.php`
- Modify: `tests/Feature/StreamPageTest.php`
- Modify: `tests/Feature/IdeaIdeasTest.php`
- Modify: `tests/Feature/CompletedIdeasPageTest.php`

- [ ] **Step 1: Add one final leak-focused regression assertion if needed**

If the previous tasks do not already prove it, add one assertion per representative covered page that the raw classified source string is absent anywhere in the rendered response body:

```php
$response->assertDontSee('Highly sensitive strategy note 42', false);
```

Use unique marker strings so the test cannot pass accidentally.

- [ ] **Step 2: Run the full covered-surface regression suite**

Run:

```bash
php artisan test tests/Feature/DemoModeToggleTest.php tests/Feature/ThoughtShowPageTest.php tests/Feature/IdeaPageTest.php tests/Feature/StreamPageTest.php tests/Feature/IdeaIdeasTest.php tests/Feature/CompletedIdeasPageTest.php tests/Unit/Services/DemoModeTest.php tests/Unit/Services/DemoObfuscatorTest.php tests/Unit/View/Presenters/Email/EmailMetadataPresenterTest.php tests/Unit/View/Presenters/Thoughts/IdeaIndexCardPresenterTest.php tests/Unit/View/Presenters/Thoughts/StreamThoughtCardPresenterTest.php tests/Unit/View/Presenters/Ideas/IdeaListItemPresenterTest.php tests/Unit/View/Presenters/Ideas/CompletedIdeaPresenterTest.php -v
```

Expected: PASS.

- [ ] **Step 3: Manually verify the toggle and banner on two real pages**

Check in the browser:

1. enable demo mode from the authenticated shell
2. visit `idea.index`
3. visit `thoughts.show` for a record with sensitive-looking content
4. confirm the banner appears, the text changes, and inline editing is unavailable on covered pages
5. disable demo mode and confirm the original text returns

- [ ] **Step 4: Note the remaining intentional v1 exclusions**

Before handoff, capture in the PR description or implementation notes that v1 intentionally excludes:

- JSON/API payload obfuscation beyond server-rendered HTML fragments
- shared research pages
- export/email/PDF surfaces
- any Blade route still reading raw narrative text outside the presenter-backed covered pages

Also keep or update a small checklist of covered versus excluded sensitive HTML surfaces so later work does not have to rediscover the boundary by reading the implementation.

- [ ] **Step 5: Commit the final regression pass**

```bash
git add tests/Feature/DemoModeToggleTest.php tests/Feature/ThoughtShowPageTest.php tests/Feature/IdeaPageTest.php tests/Feature/StreamPageTest.php tests/Feature/IdeaIdeasTest.php tests/Feature/CompletedIdeasPageTest.php tests/Unit/Services/DemoModeTest.php tests/Unit/Services/DemoObfuscatorTest.php tests/Unit/View/Presenters/Email/EmailMetadataPresenterTest.php tests/Unit/View/Presenters/Thoughts/IdeaIndexCardPresenterTest.php tests/Unit/View/Presenters/Thoughts/StreamThoughtCardPresenterTest.php tests/Unit/View/Presenters/Ideas/IdeaListItemPresenterTest.php tests/Unit/View/Presenters/Ideas/CompletedIdeaPresenterTest.php
git commit -m "test: cover demo mode obfuscation surfaces"
```
