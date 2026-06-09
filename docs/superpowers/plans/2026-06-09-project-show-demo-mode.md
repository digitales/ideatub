# Project Show Demo Mode Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Extend per-session demo mode to `projects.show` so sensitive project and thought narrative text is obfuscated at render time, while sidebar mutating controls and the working memory block are hidden during demos.

**Architecture:** Add `ProjectShowPresenter` and `ProjectContextThoughtPresenter`; extend `ProjectMemberThoughtPresenter` with the existing `ObfuscatesDemoText` trait. Update three Blade templates to consume presenter output and gate UI when `DemoMode::enabled()`. No model or controller changes. Reuse `DemoMode`, `DemoObfuscator`, and fail-closed patterns from the March 2026 demo mode slice.

**Tech Stack:** Laravel 12, PHP 8.2+, Blade, Pest/PHPUnit via `php artisan test`, presenters under `app/View/Presenters/Projects/`.

**Spec:** `docs/superpowers/specs/2026-06-09-project-show-demo-mode-design.md`

**Execution notes:** Follow @superpowers:test-driven-development for each task and use @superpowers:verification-before-completion before claiming the implementation is done.

---

## File Structure

| File | Responsibility |
|------|----------------|
| `app/View/Presenters/Projects/ProjectShowPresenter.php` | Obfuscated project title and description for page title, H1, and markdown block |
| `app/View/Presenters/Projects/ProjectContextThoughtPresenter.php` | Obfuscated pinned context markdown or microsite label |
| `app/View/Presenters/Projects/ProjectMemberThoughtPresenter.php` | Extend with demo obfuscation on `title()` and `excerpt()` |
| `resources/views/projects/show.blade.php` | Use `ProjectShowPresenter`; gate WM, sidebar, context `editable` |
| `resources/views/projects/partials/context-thought.blade.php` | Use `ProjectContextThoughtPresenter` |
| `resources/views/projects/partials/member-thought-row.blade.php` | Hide pin/remove when demo mode on |
| `tests/Unit/View/Presenters/Projects/ProjectShowPresenterTest.php` | Unit tests for project show presenter |
| `tests/Unit/View/Presenters/Projects/ProjectContextThoughtPresenterTest.php` | Unit tests for context presenter |
| `tests/Unit/View/Presenters/Projects/ProjectMemberThoughtPresenterTest.php` | Extend with demo mode cases |
| `tests/Feature/ProjectShowDemoModeTest.php` | End-to-end project show safety |
| `dev/demo-mode-obfuscation-v1-boundary.md` | Document project show as covered; WM hidden |

---

### Task 1: `ProjectShowPresenter`

**Files:**
- Create: `app/View/Presenters/Projects/ProjectShowPresenter.php`
- Test: `tests/Unit/View/Presenters/Projects/ProjectShowPresenterTest.php`

- [ ] **Step 1: Write the failing unit tests**

Create `tests/Unit/View/Presenters/Projects/ProjectShowPresenterTest.php`:

```php
<?php

namespace Tests\Unit\View\Presenters\Projects;

use App\Models\Project;
use App\Models\User;
use App\Services\DemoMode;
use App\Services\DemoObfuscator;
use App\View\Presenters\Projects\ProjectShowPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectShowPresenterTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_real_title_and_description_when_demo_mode_is_off(): void
    {
        config(['services.demo_mode.enabled' => true]);
        session()->forget([DemoMode::ENABLED_SESSION_KEY, DemoMode::SEED_SESSION_KEY]);

        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create([
            'title' => 'Acme redesign',
            'description' => 'Scope and milestones for Q3.',
        ]);

        $presenter = ProjectShowPresenter::fromProject($project);

        $this->assertSame('Acme redesign', $presenter->pageTitle());
        $this->assertSame('Scope and milestones for Q3.', $presenter->descriptionMarkdown());
    }

    public function test_obfuscates_title_and_description_in_demo_mode(): void
    {
        config(['services.demo_mode.enabled' => true]);
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create([
            'title' => 'PROJECT_SHOW_DEMO_TITLE_SECRET',
            'description' => 'PROJECT_SHOW_DEMO_DESC_SECRET',
        ]);

        session([
            DemoMode::ENABLED_SESSION_KEY => true,
            DemoMode::SEED_SESSION_KEY => 'unit-seed-project-show-presenter',
        ]);

        try {
            $presenter = ProjectShowPresenter::fromProject($project);

            $this->assertStringNotContainsString('PROJECT_SHOW_DEMO_TITLE_SECRET', $presenter->pageTitle());
            $this->assertStringNotContainsString('PROJECT_SHOW_DEMO_DESC_SECRET', $presenter->descriptionMarkdown());
            $this->assertSame(
                app(DemoObfuscator::class)->obfuscate('PROJECT_SHOW_DEMO_TITLE_SECRET', 'project_title'),
                $presenter->pageTitle(),
            );
            $this->assertSame(
                app(DemoObfuscator::class)->obfuscate('PROJECT_SHOW_DEMO_DESC_SECRET', 'project_description'),
                $presenter->descriptionMarkdown(),
            );
        } finally {
            session()->forget([DemoMode::ENABLED_SESSION_KEY, DemoMode::SEED_SESSION_KEY]);
        }
    }

    public function test_empty_description_returns_null(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create([
            'title' => 'Title only',
            'description' => null,
        ]);

        $presenter = ProjectShowPresenter::fromProject($project);

        $this->assertSame('Title only', $presenter->pageTitle());
        $this->assertNull($presenter->descriptionMarkdown());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Unit/View/Presenters/Projects/ProjectShowPresenterTest.php`

Expected: FAIL — class `ProjectShowPresenter` not found

- [ ] **Step 3: Write minimal implementation**

Create `app/View/Presenters/Projects/ProjectShowPresenter.php`:

```php
<?php

namespace App\View\Presenters\Projects;

use App\Models\Project;
use App\View\Presenters\Concerns\ObfuscatesDemoText;
use Illuminate\Support\Facades\Log;

final class ProjectShowPresenter
{
    use ObfuscatesDemoText;

    private function __construct(
        private readonly Project $project,
    ) {}

    public static function fromProject(Project $project): self
    {
        return new self($project);
    }

    public function project(): Project
    {
        return $this->project;
    }

    public function pageTitle(): string
    {
        $raw = trim((string) $this->project->title);

        return $this->obfuscatedOrRaw($raw, 'project_title', 'project_show_presenter.page_title');
    }

    public function descriptionMarkdown(): ?string
    {
        $raw = $this->project->description;
        if ($raw === null || trim((string) $raw) === '') {
            return null;
        }

        return $this->obfuscatedOrRaw((string) $raw, 'project_description', 'project_show_presenter.description_markdown');
    }

    private function obfuscatedOrRaw(string $value, string $context, string $boundary): string
    {
        try {
            return $this->demoText($value, $context) ?? '';
        } catch (\Throwable $e) {
            Log::warning('Demo obfuscation failed for project show presenter field.', [
                'boundary' => $boundary,
                'context' => $context,
                'project_id' => $this->project->id,
                'exception' => $e::class,
            ]);

            return 'Demo content hidden';
        }
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Unit/View/Presenters/Projects/ProjectShowPresenterTest.php`

Expected: PASS (3 tests)

- [ ] **Step 5: Commit**

```bash
git add app/View/Presenters/Projects/ProjectShowPresenter.php tests/Unit/View/Presenters/Projects/ProjectShowPresenterTest.php
git commit -m "feat(demo): add ProjectShowPresenter for obfuscated project title and description"
```

---

### Task 2: `ProjectContextThoughtPresenter`

**Files:**
- Create: `app/View/Presenters/Projects/ProjectContextThoughtPresenter.php`
- Test: `tests/Unit/View/Presenters/Projects/ProjectContextThoughtPresenterTest.php`

- [ ] **Step 1: Write the failing unit tests**

Create `tests/Unit/View/Presenters/Projects/ProjectContextThoughtPresenterTest.php`:

```php
<?php

namespace Tests\Unit\View\Presenters\Projects;

use App\Models\Thought;
use App\Models\User;
use App\Services\DemoMode;
use App\Services\DemoObfuscator;
use App\View\Presenters\Projects\ProjectContextThoughtPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectContextThoughtPresenterTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_real_markdown_when_demo_mode_is_off(): void
    {
        config(['services.demo_mode.enabled' => true]);
        session()->forget([DemoMode::ENABLED_SESSION_KEY, DemoMode::SEED_SESSION_KEY]);

        $user = User::factory()->create();
        $thought = Thought::factory()->for($user)->create([
            'content' => "# Briefing\n\nNorth star context body.",
        ]);

        $presenter = ProjectContextThoughtPresenter::fromThought($thought);

        $this->assertSame("# Briefing\n\nNorth star context body.", $presenter->markdown());
        $this->assertFalse($presenter->isMicrositeLayout());
    }

    public function test_obfuscates_markdown_in_demo_mode(): void
    {
        config(['services.demo_mode.enabled' => true]);
        $user = User::factory()->create();
        $thought = Thought::factory()->for($user)->create([
            'content' => 'PROJECT_CONTEXT_DEMO_SECRET_BODY',
        ]);

        session([
            DemoMode::ENABLED_SESSION_KEY => true,
            DemoMode::SEED_SESSION_KEY => 'unit-seed-project-context-presenter',
        ]);

        try {
            $presenter = ProjectContextThoughtPresenter::fromThought($thought);

            $this->assertStringNotContainsString('PROJECT_CONTEXT_DEMO_SECRET_BODY', $presenter->markdown());
            $this->assertSame(
                app(DemoObfuscator::class)->obfuscate('PROJECT_CONTEXT_DEMO_SECRET_BODY', 'thought_content'),
                $presenter->markdown(),
            );
        } finally {
            session()->forget([DemoMode::ENABLED_SESSION_KEY, DemoMode::SEED_SESSION_KEY]);
        }
    }

    public function test_obfuscates_microsite_display_label_in_demo_mode(): void
    {
        config(['services.demo_mode.enabled' => true]);
        $user = User::factory()->create();
        $thought = Thought::factory()->for($user)->create([
            'content' => '# Recommendations',
            'source_metadata' => ['document_layout' => 'microsite'],
        ]);

        session([
            DemoMode::ENABLED_SESSION_KEY => true,
            DemoMode::SEED_SESSION_KEY => 'unit-seed-project-context-microsite',
        ]);

        try {
            $presenter = ProjectContextThoughtPresenter::fromThought($thought);

            $this->assertTrue($presenter->isMicrositeLayout());
            $this->assertStringNotContainsString('Recommendations', $presenter->displayLabel());
        } finally {
            session()->forget([DemoMode::ENABLED_SESSION_KEY, DemoMode::SEED_SESSION_KEY]);
        }
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Unit/View/Presenters/Projects/ProjectContextThoughtPresenterTest.php`

Expected: FAIL — class not found

- [ ] **Step 3: Write minimal implementation**

Create `app/View/Presenters/Projects/ProjectContextThoughtPresenter.php`:

```php
<?php

namespace App\View\Presenters\Projects;

use App\Models\Thought;
use App\Support\Research\MicrositePageLabel;
use App\View\Presenters\Concerns\ObfuscatesDemoText;
use Illuminate\Support\Facades\Log;

final class ProjectContextThoughtPresenter
{
    use ObfuscatesDemoText;

    private function __construct(
        private readonly Thought $thought,
    ) {}

    public static function fromThought(Thought $thought): self
    {
        return new self($thought);
    }

    public function thought(): Thought
    {
        return $this->thought;
    }

    public function isMicrositeLayout(): bool
    {
        return $this->thought->isMicrositeDocumentLayout();
    }

    public function markdown(): string
    {
        $raw = (string) $this->thought->content;

        return $this->obfuscatedOrRaw($raw, 'thought_content', 'project_context_thought_presenter.markdown');
    }

    public function displayLabel(): string
    {
        $raw = MicrositePageLabel::forThought($this->thought);

        return $this->obfuscatedOrRaw($raw, 'thought_content', 'project_context_thought_presenter.display_label');
    }

    private function obfuscatedOrRaw(string $value, string $context, string $boundary): string
    {
        try {
            return $this->demoText($value, $context) ?? '';
        } catch (\Throwable $e) {
            Log::warning('Demo obfuscation failed for project context thought presenter field.', [
                'boundary' => $boundary,
                'context' => $context,
                'thought_id' => $this->thought->id,
                'exception' => $e::class,
            ]);

            return 'Demo content hidden';
        }
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Unit/View/Presenters/Projects/ProjectContextThoughtPresenterTest.php`

Expected: PASS (3 tests)

- [ ] **Step 5: Commit**

```bash
git add app/View/Presenters/Projects/ProjectContextThoughtPresenter.php tests/Unit/View/Presenters/Projects/ProjectContextThoughtPresenterTest.php
git commit -m "feat(demo): add ProjectContextThoughtPresenter for pinned context obfuscation"
```

---

### Task 3: Extend `ProjectMemberThoughtPresenter`

**Files:**
- Modify: `app/View/Presenters/Projects/ProjectMemberThoughtPresenter.php`
- Modify: `tests/Unit/View/Presenters/Projects/ProjectMemberThoughtPresenterTest.php`

- [ ] **Step 1: Write the failing demo mode unit tests**

Append to `tests/Unit/View/Presenters/Projects/ProjectMemberThoughtPresenterTest.php`:

```php
use App\Models\User;
use App\Services\DemoMode;
use App\Services\DemoObfuscator;
use Illuminate\Foundation\Testing\RefreshDatabase;

// Add RefreshDatabase trait to the class

public function test_returns_derived_title_and_excerpt_when_demo_mode_is_off(): void
{
    config(['services.demo_mode.enabled' => true]);
    session()->forget([DemoMode::ENABLED_SESSION_KEY, DemoMode::SEED_SESSION_KEY]);

    $thought = new Thought([
        'content' => "# QA and testing\n\nRun Pest before every deploy.",
        'metadata' => ['type' => 'plan'],
    ]);
    $thought->updated_at = now();

    $presenter = ProjectMemberThoughtPresenter::fromThought($thought);

    $this->assertSame('QA and testing', $presenter->title());
    $this->assertSame('Run Pest before every deploy.', $presenter->excerpt());
}

public function test_obfuscates_title_and_excerpt_in_demo_mode(): void
{
    config(['services.demo_mode.enabled' => true]);
    session([
        DemoMode::ENABLED_SESSION_KEY => true,
        DemoMode::SEED_SESSION_KEY => 'unit-seed-project-member-presenter',
    ]);

    try {
        $thought = new Thought([
            'content' => "# MEMBER_DEMO_TITLE_SECRET\n\nMEMBER_DEMO_EXCERPT_SECRET",
            'metadata' => ['type' => 'plan'],
        ]);
        $thought->updated_at = now();

        $presenter = ProjectMemberThoughtPresenter::fromThought($thought);

        $this->assertStringNotContainsString('MEMBER_DEMO_TITLE_SECRET', $presenter->title());
        $this->assertStringNotContainsString('MEMBER_DEMO_EXCERPT_SECRET', $presenter->excerpt() ?? '');
        $this->assertSame(
            app(DemoObfuscator::class)->obfuscate('MEMBER_DEMO_TITLE_SECRET', 'thought_content'),
            $presenter->title(),
        );
        $this->assertSame(
            app(DemoObfuscator::class)->obfuscate('MEMBER_DEMO_EXCERPT_SECRET', 'thought_content'),
            $presenter->excerpt(),
        );
    } finally {
        session()->forget([DemoMode::ENABLED_SESSION_KEY, DemoMode::SEED_SESSION_KEY]);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Unit/View/Presenters/Projects/ProjectMemberThoughtPresenterTest.php --filter=demo_mode`

Expected: FAIL — raw secrets visible in demo mode

- [ ] **Step 3: Write minimal implementation**

In `app/View/Presenters/Projects/ProjectMemberThoughtPresenter.php`:

1. Add `use ObfuscatesDemoText;` and `use Illuminate\Support\Facades\Log;`
2. At end of `title()` before `return`, wrap the final string:

```php
return $this->obfuscatedOrRaw(
    Str::limit($plain !== '' ? $plain : $content, 120),
    'thought_content',
    'project_member_thought_presenter.title',
);
```

(Replace the existing `return Str::limit(...)` line.)

3. At end of `excerpt()` before `return`, wrap:

```php
return $this->obfuscatedOrRaw(
    Str::limit($plain, 160),
    'thought_content',
    'project_member_thought_presenter.excerpt',
);
```

(Only when `$plain !== ''`; keep `null` returns unchanged.)

4. Add private helper matching `CompletedIdeaPresenter`:

```php
private function obfuscatedOrRaw(string $value, string $context, string $boundary): string
{
    try {
        return $this->demoText($value, $context) ?? '';
    } catch (\Throwable $e) {
        Log::warning('Demo obfuscation failed for project member thought presenter field.', [
            'boundary' => $boundary,
            'context' => $context,
            'thought_id' => $this->thought->id,
            'exception' => $e::class,
        ]);

        return 'Demo content hidden';
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Unit/View/Presenters/Projects/ProjectMemberThoughtPresenterTest.php`

Expected: PASS (all tests)

- [ ] **Step 5: Commit**

```bash
git add app/View/Presenters/Projects/ProjectMemberThoughtPresenter.php tests/Unit/View/Presenters/Projects/ProjectMemberThoughtPresenterTest.php
git commit -m "feat(demo): obfuscate project member thought title and excerpt in demo mode"
```

---

### Task 4: Wire Blade views

**Files:**
- Modify: `resources/views/projects/show.blade.php`
- Modify: `resources/views/projects/partials/context-thought.blade.php`
- Modify: `resources/views/projects/partials/member-thought-row.blade.php`

- [ ] **Step 1: Update `projects/show.blade.php`**

At top of `@section('content')`, after existing `@php` block, add:

```blade
@php
    $projectShow = \App\View\Presenters\Projects\ProjectShowPresenter::fromProject($project);
    $demoModeOn = app(\App\Services\DemoMode::class)->enabled();
@endphp
```

Change title section:

```blade
@section('title', $projectShow->pageTitle().' — Project — IdeaTub')
```

Change H1:

```blade
<h1 class="text-3xl font-semibold tracking-tight text-deep-indigo">{{ $projectShow->pageTitle() }}</h1>
```

Change description:

```blade
@if ($projectShow->descriptionMarkdown())
    <div class="mt-3 prose prose-sm max-w-none text-slate-brand">
        <x-safe-markdown :markdown="$projectShow->descriptionMarkdown()" />
    </div>
@endif
```

Context include — pass editable flag:

```blade
@include('projects.partials.context-thought', [
    'project' => $project,
    'contextThought' => $contextThought,
    'editable' => ! $demoModeOn,
])
```

Working memory — add demo guard:

```blade
@includeWhen(config('features.working_memory_ui') && ! $demoModeOn, 'projects.partials.working-memory-inline', [
    'project' => $project,
    'workingMemoryPayload' => $workingMemoryPayload ?? null,
])
```

Wrap entire sidebar `<aside>` contents that mutate (add thought + import) in:

```blade
@if (! $demoModeOn)
    {{-- existing Add thought section --}}
    @if (config('features.file_upload'))
        {{-- existing import section --}}
    @endif
@endif
```

Leave header action links (Graph, Share, Edit, Archive) visible per spec — only sidebar mutating controls are hidden.

- [ ] **Step 2: Update `context-thought.blade.php`**

After the opening `@php` block, add:

```blade
@php
    $contextPresenter = \App\View\Presenters\Projects\ProjectContextThoughtPresenter::fromThought($contextThought);
@endphp
```

Replace the microsite / markdown branch:

```blade
@if ($contextPresenter->isMicrositeLayout())
    <p class="text-sm font-medium text-deep-indigo group-hover:text-neural-teal">
        {{ $contextPresenter->displayLabel() }}
    </p>
@else
    <div class="prose prose-sm max-w-none text-deep-indigo line-clamp-6">
        <x-safe-markdown :markdown="$contextPresenter->markdown()" />
    </div>
@endif
```

- [ ] **Step 3: Update `member-thought-row.blade.php`**

Wrap the pin/remove `<div>`:

```blade
@if (! app(\App\Services\DemoMode::class)->enabled())
    <div class="flex shrink-0 items-center gap-1 pt-1 opacity-100 sm:opacity-0 sm:group-hover:opacity-100 sm:group-focus-within:opacity-100 transition-opacity">
        {{-- existing forms unchanged --}}
    </div>
@endif
```

- [ ] **Step 4: Smoke test manually or defer to Task 5 feature tests**

Run: `php artisan test tests/Feature/ProjectCrudTest.php`

Expected: PASS — existing project show tests unaffected (no demo session)

- [ ] **Step 5: Commit**

```bash
git add resources/views/projects/show.blade.php resources/views/projects/partials/context-thought.blade.php resources/views/projects/partials/member-thought-row.blade.php
git commit -m "feat(demo): wire project show views to demo presenters and gate sidebar UI"
```

---

### Task 5: Feature test `ProjectShowDemoModeTest`

**Files:**
- Create: `tests/Feature/ProjectShowDemoModeTest.php`

- [ ] **Step 1: Write the failing feature test**

Create `tests/Feature/ProjectShowDemoModeTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Thought;
use App\Models\User;
use App\Models\WorkingMemoryVersion;
use App\Services\DemoMode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectShowDemoModeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_demo_mode_obfuscates_project_show_without_mutating_records(): void
    {
        config([
            'services.demo_mode.enabled' => true,
            'features.working_memory_ui' => true,
            'features.file_upload' => true,
        ]);

        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create([
            'title' => 'PROJECT_SHOW_DEMO_TITLE_SECRET',
            'description' => 'PROJECT_SHOW_DEMO_DESC_SECRET',
        ]);
        $context = Thought::factory()->for($user)->create([
            'content' => 'PROJECT_SHOW_CONTEXT_SECRET',
        ]);
        $member = Thought::factory()->for($user)->create([
            'content' => "# MEMBER_ROW_TITLE_SECRET\n\nMEMBER_ROW_EXCERPT_SECRET",
        ]);
        $project->update(['context_thought_id' => $context->id]);
        $project->thoughts()->attach($member->id, ['sort_order' => 0]);

        WorkingMemoryVersion::factory()->create([
            'user_id' => $user->id,
            'scope_type' => 'project',
            'scope_key' => strtolower((string) $project->id),
            'structured_sections' => [
                ['key' => 'focus', 'title' => 'Current Focus', 'text' => 'WM_INLINE_SECRET_FOCUS'],
            ],
            'authoring_status' => 'validated',
        ]);

        $response = $this->withSession([
            DemoMode::ENABLED_SESSION_KEY => true,
            DemoMode::SEED_SESSION_KEY => 'feat-seed-project-show-demo',
        ])->actingAs($user)->get(route('projects.show', $project));

        $response->assertOk();
        $response->assertSee('Demo mode enabled. Sensitive text is obfuscated.', false);
        $html = $response->getContent();
        $this->assertStringNotContainsString('PROJECT_SHOW_DEMO_TITLE_SECRET', $html);
        $this->assertStringNotContainsString('PROJECT_SHOW_DEMO_DESC_SECRET', $html);
        $this->assertStringNotContainsString('PROJECT_SHOW_CONTEXT_SECRET', $html);
        $this->assertStringNotContainsString('MEMBER_ROW_TITLE_SECRET', $html);
        $this->assertStringNotContainsString('MEMBER_ROW_EXCERPT_SECRET', $html);
        $this->assertStringNotContainsString('WM_INLINE_SECRET_FOCUS', $html);

        $response->assertSee('Contents', false);
        $response->assertSee('1 idea', false);
        $response->assertDontSee('Add thought', false);
        $response->assertDontSee('Import markdown', false);
        $response->assertDontSee('Pin as context', false);
        $response->assertDontSee('Remove', false);
        $response->assertDontSee('Unpin', false);
        $response->assertDontSee('Refresh working memory', false);

        $this->assertSame('PROJECT_SHOW_DEMO_TITLE_SECRET', $project->fresh()->title);
        $this->assertSame('PROJECT_SHOW_CONTEXT_SECRET', $context->fresh()->content);

        session()->forget([DemoMode::ENABLED_SESSION_KEY, DemoMode::SEED_SESSION_KEY]);
        $normal = $this->actingAs($user)->get(route('projects.show', $project));
        $normal->assertSee('PROJECT_SHOW_DEMO_TITLE_SECRET', false);
        $normal->assertSee('PROJECT_SHOW_CONTEXT_SECRET', false);
    }
}
```

Adjust `WorkingMemoryVersion::factory()` fields if the factory signature differs — check `database/factories/WorkingMemoryVersionFactory.php` and mirror `ProjectMemoryModuleTest::project_show_renders_structured_sections_when_memory_is_built`.

- [ ] **Step 2: Run test to verify it fails before Blade wiring (or passes after Task 4)**

Run: `php artisan test tests/Feature/ProjectShowDemoModeTest.php`

Expected after Tasks 1–4 complete: PASS

- [ ] **Step 3: Fix any factory or assertion mismatches**

If WM block uses different copy than `Refresh working memory`, assert on strings from `projects/partials/working-memory-inline.blade.php` that are unique to that partial.

- [ ] **Step 4: Run full related test suite**

Run: `php artisan test tests/Feature/ProjectShowDemoModeTest.php tests/Feature/ProjectCrudTest.php tests/Feature/ProjectContextTest.php tests/Feature/ProjectMemoryModuleTest.php tests/Unit/View/Presenters/Projects/`

Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add tests/Feature/ProjectShowDemoModeTest.php
git commit -m "test(demo): cover project show obfuscation and UI gating in demo mode"
```

---

### Task 6: Update boundary checklist

**Files:**
- Modify: `dev/demo-mode-obfuscation-v1-boundary.md`

- [ ] **Step 1: Add project show to covered list**

Under **Covered**, add:

```markdown
- Project show (`projects.show`): project title/description, pinned context body, member row title/excerpt; sidebar add/import and pin/remove hidden; working memory inline block hidden (not obfuscated).
```

Under **Intentional v1 exclusions / gaps**, add:

```markdown
- **Project index, graph, edit**: titles and descriptions still raw until follow-up slices.
```

- [ ] **Step 2: Commit**

```bash
git add dev/demo-mode-obfuscation-v1-boundary.md
git commit -m "docs(demo): document project show in v1 obfuscation boundary"
```

---

## Verification

Run before claiming done:

```bash
php artisan test tests/Unit/View/Presenters/Projects/ tests/Feature/ProjectShowDemoModeTest.php tests/Feature/ProjectCrudTest.php tests/Feature/ProjectContextTest.php
```

Manual spot-check (optional): enable demo mode from profile/settings, open a project with real client names — banner visible, titles obfuscated, WM block absent, sidebar add/import absent.

## Spec Coverage Checklist

| Spec requirement | Task |
|------------------|------|
| Obfuscate project title/description | Task 1, 4 |
| Obfuscate pinned context | Task 2, 4 |
| Obfuscate member title/excerpt | Task 3, 4 |
| Hide WM block | Task 4, 5 |
| Hide add/import/pin/remove | Task 4, 5 |
| Fail closed | Tasks 1–3 (`obfuscatedOrRaw`) |
| No model changes | All tasks |
| Feature + unit tests | Tasks 1–3, 5 |
| Boundary doc update | Task 6 |
