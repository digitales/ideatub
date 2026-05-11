# Research Note Detail Enhancements Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add editable title, inline body editing, tag editing, and read-only project display to the research note detail page.

**Architecture:** Title stored in `metadata['title']` on the root research thought. Reuse existing `editable_thought_content` and `thought_tag_row` partials. New `thoughtTitleEditor` Alpine component + PATCH endpoint. Backfill command extracts titles from existing content.

**Tech Stack:** Laravel 12 / PHP 8.2, Blade templates, Alpine.js, Pest tests.

---

## File Structure

| File | Responsibility |
|------|---------------|
| `routes/web.php` | Add `ideas.update-title` route |
| `app/Http/Controllers/IdeaController.php` | `updateTitle` method; update `showResearch` to pass projects + editable flag |
| `resources/views/idea/research_show.blade.php` | Integrate title, project, tag partials; wrap body in editable_thought_content |
| `resources/views/idea/partials/thought_detail_title.blade.php` | New partial — title display + click-to-edit |
| `resources/js/app.js` | New `thoughtTitleEditor` Alpine component |
| `app/Console/Commands/BackfillResearchTitles.php` | Artisan command to extract titles for existing research |
| `app/Services/ThoughtCaptureService.php` | Copy `section_title` → `metadata['title']` for research roots |
| `tests/Feature/ResearchShowTest.php` | Tests for new title, tag, project, and body edit features |
| `tests/Feature/UpdateTitleTest.php` | Dedicated tests for the title PATCH endpoint |
| `tests/Feature/BackfillResearchTitlesTest.php` | Tests for the backfill command |

---

### Task 1: Add `updateTitle` Endpoint

**Files:**
- Modify: `routes/web.php:201-202`
- Modify: `app/Http/Controllers/IdeaController.php:1485` (insert before `updateTags`)
- Create: `tests/Feature/UpdateTitleTest.php`

- [ ] **Step 1: Write the failing test for title update**

```php
<?php

namespace Tests\Feature;

use App\Models\Thought;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UpdateTitleTest extends TestCase
{
    use RefreshDatabase;

    public function test_update_title_stores_title_in_metadata(): void
    {
        $user = User::factory()->create();
        $thought = Thought::factory()->create([
            'user_id' => $user->id,
            'metadata' => ['type' => 'research', 'tags' => []],
        ]);

        $response = $this->actingAs($user)
            ->patchJson(route('ideas.update-title', $thought), [
                'title' => 'My Research Title',
            ]);

        $response->assertOk();
        $response->assertJson(['title' => 'My Research Title']);
        $this->assertSame('My Research Title', $thought->fresh()->metadata['title']);
    }

    public function test_update_title_rejects_string_over_255_chars(): void
    {
        $user = User::factory()->create();
        $thought = Thought::factory()->create([
            'user_id' => $user->id,
            'metadata' => ['type' => 'research', 'tags' => []],
        ]);

        $response = $this->actingAs($user)
            ->patchJson(route('ideas.update-title', $thought), [
                'title' => str_repeat('x', 256),
            ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('title');
    }

    public function test_update_title_allows_nullable_to_clear_title(): void
    {
        $user = User::factory()->create();
        $thought = Thought::factory()->create([
            'user_id' => $user->id,
            'metadata' => ['type' => 'research', 'tags' => [], 'title' => 'Old Title'],
        ]);

        $response = $this->actingAs($user)
            ->patchJson(route('ideas.update-title', $thought), [
                'title' => null,
            ]);

        $response->assertOk();
        $response->assertJson(['title' => null]);
        $this->assertNull($thought->fresh()->metadata['title']);
    }

    public function test_update_title_requires_authorization(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $thought = Thought::factory()->create([
            'user_id' => $owner->id,
            'metadata' => ['type' => 'research', 'tags' => []],
        ]);

        $response = $this->actingAs($other)
            ->patchJson(route('ideas.update-title', $thought), [
                'title' => 'Hacked',
            ]);

        $response->assertForbidden();
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test tests/Feature/UpdateTitleTest.php`
Expected: FAIL — route `ideas.update-title` not defined.

- [ ] **Step 3: Add route**

In `routes/web.php`, add after line 202 (the `ideas.update-content` route):

```php
    Route::patch('/ideas/{thought}/title', [IdeaController::class, 'updateTitle'])->name('ideas.update-title');
```

- [ ] **Step 4: Implement `updateTitle` method**

In `app/Http/Controllers/IdeaController.php`, add this method after `updateTags` (after line 1508):

```php
    /**
     * Update research thought title stored in metadata.
     */
    public function updateTitle(
        Request $request,
        Thought $thought
    ): RedirectResponse|JsonResponse {
        $this->authorize('update', $thought);

        $validated = $request->validate([
            'title' => ['nullable', 'string', 'max:255'],
        ]);

        $title = $validated['title'] !== null ? trim($validated['title']) : null;
        if ($title === '') {
            $title = null;
        }

        $metadata = $thought->metadata ?? [];
        $metadata['title'] = $title;
        $thought->update(['metadata' => $metadata]);

        if ($request->expectsJson()) {
            return response()->json(['title' => $title]);
        }

        return redirect()->back()->with('success', 'Title updated.');
    }
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test tests/Feature/UpdateTitleTest.php`
Expected: All 4 tests PASS.

- [ ] **Step 6: Commit**

```bash
git add routes/web.php app/Http/Controllers/IdeaController.php tests/Feature/UpdateTitleTest.php
git commit -m "feat: add PATCH endpoint for research thought title"
```

---

### Task 2: `thoughtTitleEditor` Alpine Component

**Files:**
- Modify: `resources/js/app.js` (insert after the `thoughtContentEditor` component, ~line 930)

- [ ] **Step 1: Add the Alpine component**

Insert after the closing of `thoughtContentEditor` in `resources/js/app.js`:

```javascript
Alpine.data('thoughtTitleEditor', (initialTitle, updateUrl, editable = false) => ({
  title: initialTitle || '',
  updateUrl: updateUrl || '',
  editable: !!editable,
  editing: false,
  draft: initialTitle || '',
  saving: false,
  error: '',

  startEdit() {
    if (!this.editable) return;
    this.editing = true;
    this.draft = this.title;
    this.error = '';
    this.$nextTick(() => this.$refs.titleInput?.focus());
  },

  cancelEdit() {
    this.editing = false;
    this.draft = this.title;
    this.error = '';
  },

  async saveEdit() {
    if (this.saving) return;
    const trimmed = this.draft.trim();
    if (trimmed === this.title) {
      this.editing = false;
      return;
    }

    this.saving = true;
    this.error = '';

    try {
      const res = await fetch(this.updateUrl, {
        method: 'PATCH',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
          'X-CSRF-TOKEN':
            document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
        },
        body: JSON.stringify({ title: trimmed || null }),
      });

      const data = await res.json().catch(() => ({}));

      if (!res.ok) {
        if (res.status === 422 && data.errors?.title?.[0]) {
          this.error = data.errors.title[0];
        } else {
          this.error = 'Failed to save title.';
        }
        return;
      }

      this.title = data.title || '';
      this.editing = false;
    } catch {
      this.error = 'Network error. Try again.';
    } finally {
      this.saving = false;
    }
  },
}));
```

- [ ] **Step 2: Verify JS builds without errors**

Run: `npm run build`
Expected: Build completes without errors.

- [ ] **Step 3: Commit**

```bash
git add resources/js/app.js
git commit -m "feat: add thoughtTitleEditor Alpine component"
```

---

### Task 3: Title Partial

**Files:**
- Create: `resources/views/idea/partials/thought_detail_title.blade.php`

- [ ] **Step 1: Create the partial**

```blade
@php
    $editable = $editable ?? false;
    $title = data_get($thought->metadata, 'title', '');
@endphp

<div
    x-data="thoughtTitleEditor(@js($title), @js(route('ideas.update-title', $thought)), @js($editable))"
    class="mb-3"
>
    <div x-show="!editing" class="flex items-baseline gap-2">
        <h1
            class="text-[22px] font-semibold leading-tight"
            :class="title ? 'text-deep-indigo' : 'text-slate-brand/50'"
            x-text="title || 'Untitled research'"
        ></h1>
        @if ($editable)
            <button
                type="button"
                @click="startEdit()"
                class="text-[12px] font-medium text-slate-brand/60 hover:text-memory-violet transition-colors shrink-0"
                aria-label="Edit title"
            >Edit</button>
        @endif
    </div>
    <div x-show="editing" x-cloak x-on:keydown.escape.stop.prevent="cancelEdit()">
        <input
            type="text"
            x-ref="titleInput"
            x-model="draft"
            maxlength="255"
            placeholder="Research title…"
            @keydown.enter.prevent="saveEdit()"
            @blur="saveEdit()"
            class="w-full text-[22px] font-semibold text-deep-indigo leading-tight rounded-lg border border-memory-violet/20 px-2 py-1 focus:border-memory-violet focus:ring-memory-violet/20"
        >
        <p x-show="error" x-text="error" class="text-[11px] text-red-600 mt-1"></p>
    </div>
</div>
```

- [ ] **Step 2: Commit**

```bash
git add resources/views/idea/partials/thought_detail_title.blade.php
git commit -m "feat: add thought_detail_title Blade partial"
```

---

### Task 4: Update `research_show.blade.php` Layout

**Files:**
- Modify: `resources/views/idea/research_show.blade.php`
- Modify: `app/Http/Controllers/IdeaController.php` (the `showResearch` method, ~line 1809)

- [ ] **Step 1: Write test for project display on research show**

Add to `tests/Feature/ResearchShowTest.php`:

```php
    public function test_research_show_displays_project_when_associated(): void
    {
        $user = User::factory()->create();
        $thought = Thought::factory()->create([
            'user_id' => $user->id,
            'parent_id' => null,
            'content' => '# With project',
            'metadata' => ['type' => 'research', 'tags' => []],
        ]);
        $project = \App\Models\Project::factory()->create([
            'user_id' => $user->id,
            'title' => 'Test Project',
        ]);
        $thought->projects()->attach($project, ['sort_order' => 0]);

        $response = $this->actingAs($user)->get(route('idea.research.show', $thought));

        $response->assertStatus(200);
        $response->assertSee('Test Project', false);
    }

    public function test_research_show_displays_tags(): void
    {
        $user = User::factory()->create();
        $thought = Thought::factory()->create([
            'user_id' => $user->id,
            'parent_id' => null,
            'content' => '# Tagged research',
            'metadata' => ['type' => 'research', 'tags' => ['ai', 'ml']],
        ]);

        $response = $this->actingAs($user)->get(route('idea.research.show', $thought));

        $response->assertStatus(200);
        $response->assertSee('ai', false);
        $response->assertSee('ml', false);
    }

    public function test_research_show_displays_title_from_metadata(): void
    {
        $user = User::factory()->create();
        $thought = Thought::factory()->create([
            'user_id' => $user->id,
            'parent_id' => null,
            'content' => 'Body content here.',
            'metadata' => ['type' => 'research', 'tags' => [], 'title' => 'My Custom Title'],
        ]);

        $response = $this->actingAs($user)->get(route('idea.research.show', $thought));

        $response->assertStatus(200);
        $response->assertSee('My Custom Title', false);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test tests/Feature/ResearchShowTest.php --filter="test_research_show_displays_project_when_associated|test_research_show_displays_tags|test_research_show_displays_title_from_metadata"`
Expected: FAIL — views don't render these elements yet.

- [ ] **Step 3: Update `showResearch` controller method**

In `app/Http/Controllers/IdeaController.php`, update the return statement at ~line 1809. Replace:

```php
        return view('idea.research_show', [
            'root' => $thought,
            'isMicrosite' => false,
            'pageTitle' => $pageTitle,
            'root_html' => $rootHtml,
            'sections' => $sectionsWithHtml,
            'sectionThoughts' => $sections,
            'relatedEmail' => $relatedEmail,
            'linkedVideo' => $linkedVideo,
            'editorialLinkSummaries' => $editorialLinkSummaries,
            'newsletterAnalysis' => $newsletterAnalysis,
            'commentsPresenter' => $commentsPresenter,
            'researchContentComments' => ResearchContentCommentsViewData::none(),
            'researchUnreadBannerCount' => $researchUnreadBannerCount,
        ]);
```

With:

```php
        $editable = ! app(\App\Services\DemoMode::class)->enabled();
        $thoughtProjectsForDetail = $thought->projects;

        return view('idea.research_show', [
            'root' => $thought,
            'isMicrosite' => false,
            'pageTitle' => data_get($thought->metadata, 'title') ?: $pageTitle,
            'root_html' => $rootHtml,
            'sections' => $sectionsWithHtml,
            'sectionThoughts' => $sections,
            'relatedEmail' => $relatedEmail,
            'linkedVideo' => $linkedVideo,
            'editorialLinkSummaries' => $editorialLinkSummaries,
            'newsletterAnalysis' => $newsletterAnalysis,
            'commentsPresenter' => $commentsPresenter,
            'researchContentComments' => ResearchContentCommentsViewData::none(),
            'researchUnreadBannerCount' => $researchUnreadBannerCount,
            'editable' => $editable,
            'thoughtProjectsForDetail' => $thoughtProjectsForDetail,
        ]);
```

- [ ] **Step 4: Update `research_show.blade.php` template**

Replace the current content inside the card div (lines 13–53) with:

```blade
        <p class="text-[11px] font-semibold tracking-[0.1em] uppercase text-memory-violet/80 mb-4">Research</p>

        @include('idea.partials.thought_detail_title', [
            'thought' => $root,
            'editable' => $editable ?? false,
        ])

        @if (($thoughtProjectsForDetail ?? collect())->isNotEmpty())
            @include('idea.partials.thought_detail_projects_and_links', [
                'thought' => $root,
                'thoughtProjectsForDetail' => $thoughtProjectsForDetail,
                'editable' => false,
            ])
        @endif

        <div class="mt-3 mb-4">
            @include('idea.partials.thought_tag_row', [
                'thought' => $root,
                'editable' => $editable ?? false,
            ])
        </div>

        @if (empty($isMicrosite))
            <p class="text-[11px] text-slate-brand/50 mb-6">{{ $root->created_at->diffForHumans() }}</p>
        @endif

        @if (empty($isMicrosite) || ! empty($onMicrositeRootIndex))
        @if (! empty($linkedVideo ?? null))
            <div class="mb-6 rounded-xl border border-rose-400/25 bg-rose-500/[0.06] p-4 md:p-5">
                <p class="text-[11px] font-semibold tracking-[0.1em] uppercase text-rose-600/90 mb-3">Related video</p>
                <p class="text-[11px] font-semibold tracking-[0.1em] uppercase text-memory-violet/80 mb-2">Video metadata</p>
                @include('idea.partials.video_metadata_labeled_rows', ['rows' => $linkedVideo['metadata_rows'] ?? []])
                <p class="mt-3">
                    <a href="{{ $linkedVideo['detail_url'] }}" class="text-[13px] font-medium text-memory-violet hover:underline">Open video thought</a>
                </p>
            </div>
        @endif
        @if (! empty($relatedEmail))
            <div class="mb-6 rounded-xl border border-memory-violet/25 bg-memory-violet/5 p-4 md:p-5">
                <p class="text-[11px] font-semibold tracking-[0.1em] uppercase text-memory-violet/80 mb-3">Related email</p>
                <p class="text-[14px] md:text-[15px] font-semibold text-deep-indigo">{{ $relatedEmail['subject'] }}</p>
                <p class="text-[13px] text-slate-brand mt-1">{{ $relatedEmail['sender'] }}</p>
                <p class="mt-3">
                    <a href="{{ $relatedEmail['url'] }}" class="text-[13px] font-medium text-memory-violet hover:underline">View email</a>
                </p>
            </div>
        @endif
        @include('idea.partials.research_newsletter_analysis', ['newsletterAnalysis' => $newsletterAnalysis ?? null])
        @include('idea.partials.research_editorial_link_summaries', ['editorialLinkSummaries' => $editorialLinkSummaries])
        @endif

        @if (empty($isMicrosite))
        @include('idea.partials.research_content', [
            'root_html' => $root_html,
            'sections' => $sections,
            'researchContentComments' => $researchContentComments,
        ])
        @else
        @include('idea.partials.microsite_reader', [
            'root_html' => $root_html,
            'micrositeNav' => $micrositeNav,
            'activeMicrositePage' => $activeMicrositePage,
        ])
        @endif
```

Also update the `@section('title')` at line 3 from:

```blade
@section('title', ($pageTitle ?? Str::limit($root->content, 50)) . ' — IdeaTub')
```

To:

```blade
@section('title', ($pageTitle ?? Str::limit($root->content, 50)) . ' — IdeaTub')
```

(No change needed here — `$pageTitle` is already updated in the controller to prefer `metadata['title']`.)

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test tests/Feature/ResearchShowTest.php --filter="test_research_show_displays_project_when_associated|test_research_show_displays_tags|test_research_show_displays_title_from_metadata"`
Expected: All 3 tests PASS.

- [ ] **Step 6: Run the full ResearchShowTest suite to check for regressions**

Run: `php artisan test tests/Feature/ResearchShowTest.php`
Expected: All existing tests still PASS.

- [ ] **Step 7: Commit**

```bash
git add resources/views/idea/research_show.blade.php app/Http/Controllers/IdeaController.php tests/Feature/ResearchShowTest.php
git commit -m "feat: add title, tags, and project display to research show page"
```

---

### Task 5: Inline Body Editing on Research Show

**Files:**
- Modify: `resources/views/idea/partials/research_content.blade.php`

- [ ] **Step 1: Write test for body editing on research show**

Add to `tests/Feature/ResearchShowTest.php`:

```php
    public function test_research_show_includes_edit_button_for_content(): void
    {
        $user = User::factory()->create();
        $thought = Thought::factory()->create([
            'user_id' => $user->id,
            'parent_id' => null,
            'content' => '# Editable research',
            'metadata' => ['type' => 'research', 'tags' => []],
        ]);

        $response = $this->actingAs($user)->get(route('idea.research.show', $thought));

        $response->assertStatus(200);
        $response->assertSee('ideatub-thought-content-update', false);
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/ResearchShowTest.php --filter="test_research_show_includes_edit_button_for_content"`
Expected: FAIL — the `editable_thought_content` partial is not yet included.

- [ ] **Step 3: Update `research_content.blade.php` to use editable content**

Replace the root HTML rendering (line 9-11) from:

```blade
        <div class="prose prose-sm prose-slate max-w-none prose-headings:text-deep-indigo prose-headings:font-semibold prose-headings:tracking-tight prose-p:text-deep-indigo prose-p:leading-relaxed prose-li:text-slate-brand prose-strong:text-deep-indigo prose-pre:bg-slate-100/90 prose-pre:border prose-pre:border-memory-violet/10 prose-pre:rounded-lg prose-pre:py-3 prose-pre:px-4 prose-code:text-deep-indigo prose-code:bg-slate-100/90 prose-code:px-1.5 prose-code:py-0.5 prose-code:rounded prose-code:text-[12px] prose-a:text-memory-violet prose-a:no-underline hover:prose-a:underline prose-blockquote:border-memory-violet/30 prose-blockquote:bg-memory-violet/5 prose-blockquote:py-1 prose-blockquote:px-4 prose-blockquote:rounded-r-lg text-[14px] md:text-[15px]">
            {!! $root_html !!}
        </div>
```

With:

```blade
        @if (($editable ?? false) && isset($rootThought))
            @include('idea.partials.editable_thought_content', [
                'thought' => $rootThought,
                'editable' => true,
                'displayContent' => '',
                'rawEditorContent' => $rootThought->content,
                'detailMarkdownRead' => true,
                'contentHtml' => $root_html,
                'displayClass' => 'text-[14px] md:text-[15px] text-deep-indigo leading-relaxed mb-2 whitespace-pre-line break-words [overflow-wrap:anywhere]',
                'editorClass' => 'w-full text-[14px] md:text-[15px] text-deep-indigo leading-relaxed rounded-lg border border-memory-violet/20 focus:border-memory-violet focus:ring-memory-violet/20',
            ])
        @else
            <div class="prose prose-sm prose-slate max-w-none prose-headings:text-deep-indigo prose-headings:font-semibold prose-headings:tracking-tight prose-p:text-deep-indigo prose-p:leading-relaxed prose-li:text-slate-brand prose-strong:text-deep-indigo prose-pre:bg-slate-100/90 prose-pre:border prose-pre:border-memory-violet/10 prose-pre:rounded-lg prose-pre:py-3 prose-pre:px-4 prose-code:text-deep-indigo prose-code:bg-slate-100/90 prose-code:px-1.5 prose-code:py-0.5 prose-code:rounded prose-code:text-[12px] prose-a:text-memory-violet prose-a:no-underline hover:prose-a:underline prose-blockquote:border-memory-violet/30 prose-blockquote:bg-memory-violet/5 prose-blockquote:py-1 prose-blockquote:px-4 prose-blockquote:rounded-r-lg text-[14px] md:text-[15px]">
                {!! $root_html !!}
            </div>
        @endif
```

For sections, update the non-comments loop (lines 34-39). Replace:

```blade
                    @foreach($sections as $section)
                        <li @if(isset($section->id)) id="section-{{ $section->id }}" @endif>
                            <div class="prose prose-sm prose-slate max-w-none prose-headings:text-deep-indigo prose-headings:font-semibold prose-headings:tracking-tight prose-p:text-slate-brand prose-p:leading-relaxed prose-li:text-slate-brand prose-strong:text-deep-indigo prose-pre:bg-slate-100/90 prose-pre:border prose-pre:border-memory-violet/10 prose-pre:rounded-lg prose-pre:py-3 prose-pre:px-4 prose-code:text-deep-indigo prose-code:bg-slate-100/90 prose-code:px-1.5 prose-code:py-0.5 prose-code:rounded prose-code:text-[12px] prose-a:text-memory-violet prose-a:no-underline hover:prose-a:underline prose-blockquote:border-memory-violet/30 prose-blockquote:bg-memory-violet/5 prose-blockquote:py-1 prose-blockquote:px-4 prose-blockquote:rounded-r-lg text-[13px] md:text-[14px]">
                                {!! $section->content_html !!}
                            </div>
                        </li>
                    @endforeach
```

With:

```blade
                    @foreach($sections as $section)
                        <li @if(isset($section->id)) id="section-{{ $section->id }}" @endif>
                            @if (($editable ?? false) && isset($section->thought))
                                @include('idea.partials.editable_thought_content', [
                                    'thought' => $section->thought,
                                    'editable' => true,
                                    'displayContent' => '',
                                    'rawEditorContent' => $section->thought->content,
                                    'detailMarkdownRead' => true,
                                    'contentHtml' => $section->content_html,
                                    'displayClass' => 'text-[13px] md:text-[14px] text-deep-indigo leading-relaxed mb-2 whitespace-pre-line break-words [overflow-wrap:anywhere]',
                                    'editorClass' => 'w-full text-[13px] md:text-[14px] text-deep-indigo leading-relaxed rounded-lg border border-memory-violet/20 focus:border-memory-violet focus:ring-memory-violet/20',
                                ])
                            @else
                                <div class="prose prose-sm prose-slate max-w-none prose-headings:text-deep-indigo prose-headings:font-semibold prose-headings:tracking-tight prose-p:text-slate-brand prose-p:leading-relaxed prose-li:text-slate-brand prose-strong:text-deep-indigo prose-pre:bg-slate-100/90 prose-pre:border prose-pre:border-memory-violet/10 prose-pre:rounded-lg prose-pre:py-3 prose-pre:px-4 prose-code:text-deep-indigo prose-code:bg-slate-100/90 prose-code:px-1.5 prose-code:py-0.5 prose-code:rounded prose-code:text-[12px] prose-a:text-memory-violet prose-a:no-underline hover:prose-a:underline prose-blockquote:border-memory-violet/30 prose-blockquote:bg-memory-violet/5 prose-blockquote:py-1 prose-blockquote:px-4 prose-blockquote:rounded-r-lg text-[13px] md:text-[14px]">
                                    {!! $section->content_html !!}
                                </div>
                            @endif
                        </li>
                    @endforeach
```

- [ ] **Step 4: Pass `rootThought` and `editable` to the `research_content` include**

In `research_show.blade.php`, update the `research_content` include to pass the additional variables:

```blade
        @include('idea.partials.research_content', [
            'root_html' => $root_html,
            'sections' => $sections,
            'researchContentComments' => $researchContentComments,
            'editable' => $editable ?? false,
            'rootThought' => $root,
        ])
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test tests/Feature/ResearchShowTest.php --filter="test_research_show_includes_edit_button_for_content"`
Expected: PASS.

- [ ] **Step 6: Run full test suite to check for regressions**

Run: `php artisan test tests/Feature/ResearchShowTest.php`
Expected: All tests PASS.

- [ ] **Step 7: Commit**

```bash
git add resources/views/idea/partials/research_content.blade.php resources/views/idea/research_show.blade.php
git commit -m "feat: add inline body editing to research content sections"
```

---

### Task 6: Backfill Research Titles Command

**Files:**
- Create: `app/Console/Commands/BackfillResearchTitles.php`
- Create: `tests/Feature/BackfillResearchTitlesTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\Thought;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BackfillResearchTitlesTest extends TestCase
{
    use RefreshDatabase;

    public function test_backfill_extracts_title_from_markdown_heading(): void
    {
        $user = User::factory()->create();
        $thought = Thought::factory()->create([
            'user_id' => $user->id,
            'parent_id' => null,
            'content' => "# My Research Title\n\nBody content here.",
            'metadata' => ['type' => 'research', 'tags' => []],
        ]);

        $this->artisan('research:backfill-titles')
            ->assertExitCode(0);

        $this->assertSame('My Research Title', $thought->fresh()->metadata['title']);
    }

    public function test_backfill_extracts_title_from_plain_text_when_no_heading(): void
    {
        $user = User::factory()->create();
        $thought = Thought::factory()->create([
            'user_id' => $user->id,
            'parent_id' => null,
            'content' => 'This is a research note without any markdown headings but with plenty of text content that goes on.',
            'metadata' => ['type' => 'research', 'tags' => []],
        ]);

        $this->artisan('research:backfill-titles')
            ->assertExitCode(0);

        $title = $thought->fresh()->metadata['title'];
        $this->assertNotNull($title);
        $this->assertLessThanOrEqual(80, mb_strlen($title));
    }

    public function test_backfill_skips_thoughts_with_existing_title(): void
    {
        $user = User::factory()->create();
        $thought = Thought::factory()->create([
            'user_id' => $user->id,
            'parent_id' => null,
            'content' => "# Different Heading\n\nBody.",
            'metadata' => ['type' => 'research', 'tags' => [], 'title' => 'Keep This'],
        ]);

        $this->artisan('research:backfill-titles')
            ->assertExitCode(0);

        $this->assertSame('Keep This', $thought->fresh()->metadata['title']);
    }

    public function test_backfill_dry_run_does_not_modify(): void
    {
        $user = User::factory()->create();
        $thought = Thought::factory()->create([
            'user_id' => $user->id,
            'parent_id' => null,
            'content' => "# Would Be Extracted\n\nBody.",
            'metadata' => ['type' => 'research', 'tags' => []],
        ]);

        $this->artisan('research:backfill-titles', ['--dry-run' => true])
            ->assertExitCode(0);

        $this->assertNull($thought->fresh()->metadata['title'] ?? null);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test tests/Feature/BackfillResearchTitlesTest.php`
Expected: FAIL — command not found.

- [ ] **Step 3: Create the command**

```php
<?php

namespace App\Console\Commands;

use App\Models\Thought;
use Illuminate\Console\Command;
use League\CommonMark\CommonMarkConverter;

class BackfillResearchTitles extends Command
{
    protected $signature = 'research:backfill-titles
                            {--user= : Scope to a specific user ID}
                            {--dry-run : Preview without saving}';

    protected $description = 'Extract and set titles for existing research thoughts that lack one';

    public function handle(): int
    {
        $query = Thought::query()
            ->whereNull('parent_id')
            ->whereJsonContains('metadata->type', 'research')
            ->whereNull('metadata->title');

        if ($userId = $this->option('user')) {
            $query->where('user_id', (int) $userId);
        }

        $dryRun = (bool) $this->option('dry-run');
        $updated = 0;
        $skipped = 0;

        $query->chunkById(100, function ($thoughts) use ($dryRun, &$updated, &$skipped) {
            foreach ($thoughts as $thought) {
                $title = $this->extractTitle($thought->content);

                if ($title === null) {
                    $skipped++;
                    continue;
                }

                if ($dryRun) {
                    $this->line("  [dry-run] {$thought->id}: \"{$title}\"");
                    $updated++;
                    continue;
                }

                $metadata = $thought->metadata ?? [];
                $metadata['title'] = $title;
                $thought->update(['metadata' => $metadata]);
                $updated++;
            }
        });

        $prefix = $dryRun ? '[DRY RUN] ' : '';
        $this->info("{$prefix}Updated: {$updated}, Skipped: {$skipped}");

        return self::SUCCESS;
    }

    private function extractTitle(string $content): ?string
    {
        if (preg_match('/^#{1,3}\s+(.+)$/m', $content, $matches)) {
            $title = trim($matches[1]);
            return mb_substr($title, 0, 255);
        }

        $plain = strip_tags((new CommonMarkConverter())->convert($content)->getContent());
        $plain = preg_replace('/\s+/', ' ', trim($plain));

        if ($plain === '') {
            return null;
        }

        if (mb_strlen($plain) <= 80) {
            return $plain;
        }

        $truncated = mb_substr($plain, 0, 80);
        $lastSpace = mb_strrpos($truncated, ' ');
        if ($lastSpace !== false && $lastSpace > 40) {
            $truncated = mb_substr($truncated, 0, $lastSpace);
        }

        return $truncated;
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test tests/Feature/BackfillResearchTitlesTest.php`
Expected: All 4 tests PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Console/Commands/BackfillResearchTitles.php tests/Feature/BackfillResearchTitlesTest.php
git commit -m "feat: add research:backfill-titles artisan command"
```

---

### Task 7: MCP Integration — Copy `section_title` to `metadata['title']`

**Files:**
- Modify: `app/Services/ThoughtCaptureService.php:209-217`

- [ ] **Step 1: Write the failing test**

Add a test file `tests/Feature/ThoughtCaptureResearchTitleTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Services\ThoughtCaptureService;
use App\Models\Thought;
use App\Models\User;
use App\Services\OpenRouterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class ThoughtCaptureResearchTitleTest extends TestCase
{
    use RefreshDatabase;

    public function test_capture_sets_metadata_title_from_section_title_for_research(): void
    {
        $user = User::factory()->create();

        $openRouter = Mockery::mock(OpenRouterService::class);
        $openRouter->shouldReceive('embed')->andReturn(array_fill(0, 1536, 0.0));
        $openRouter->shouldReceive('extractMetadata')->andReturn([
            'type' => 'research',
            'tags' => ['test'],
        ]);

        $service = app(ThoughtCaptureService::class);
        // Swap the OpenRouter dependency
        $reflection = new \ReflectionClass($service);
        $prop = $reflection->getProperty('openRouter');
        $prop->setAccessible(true);
        $prop->setValue($service, $openRouter);

        $result = $service->create([
            'content' => "# Section Title\n\nResearch body with enough words to trigger chunking. " . str_repeat('word ', 100),
            'user_id' => $user->id,
            'source' => 'mcp',
            'doc_type' => 'research',
            'plan_slug' => 'test-research',
            'source_metadata' => ['section_title' => 'My Research Paper'],
        ]);

        $root = $result['root'] ?? $result['thought'] ?? null;
        $this->assertNotNull($root);
        $this->assertSame('My Research Paper', $root->fresh()->metadata['title']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/ThoughtCaptureResearchTitleTest.php`
Expected: FAIL — metadata title is not set from section_title.

- [ ] **Step 3: Update `ThoughtCaptureService` to copy section_title to metadata['title']**

In `app/Services/ThoughtCaptureService.php`, after the root thought is created (after line 217: `$sectionIds = [$root->id];`), add:

```php
        // For research roots, copy section_title into metadata['title'] if not already set.
        $rootSectionTitle = $sections[0]['title'] ?? null;
        if (
            $rootSectionTitle !== null
            && $rootSectionTitle !== ''
            && ($metadata['type'] ?? null) === 'research'
            && ! isset($metadata['title'])
        ) {
            $metadata['title'] = mb_substr(trim($rootSectionTitle), 0, 255);
            $root->update(['metadata' => $metadata]);
        }
```

Also handle the single-thought (non-chunked) path. Find where single thoughts are created with `doc_type = 'research'` (~line 141-148 in the non-chunked path). After the thought is created, add the same logic. Locate the line:

```php
        $thought = Thought::create([
            'content' => $content,
            'embedding' => $embedding,
            'metadata' => $metadata,
            'user_id' => $userId,
            'source' => $source,
            'source_metadata' => $sourceMetadata,
        ]);
```

After this create, add:

```php
        // For research single-thought captures, copy section_title from source_metadata into metadata title.
        $singleSectionTitle = data_get($sourceMetadata, 'section_title');
        if (
            $singleSectionTitle !== null
            && $singleSectionTitle !== ''
            && ($metadata['type'] ?? null) === 'research'
            && ! isset($metadata['title'])
        ) {
            $metadata['title'] = mb_substr(trim((string) $singleSectionTitle), 0, 255);
            $thought->update(['metadata' => $metadata]);
        }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/ThoughtCaptureResearchTitleTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/ThoughtCaptureService.php tests/Feature/ThoughtCaptureResearchTitleTest.php
git commit -m "feat: copy section_title to metadata title for research captures"
```

---

### Task 8: Final Integration Test & Cleanup

**Files:**
- All previously modified files

- [ ] **Step 1: Run the full test suite**

Run: `php artisan test`
Expected: All tests PASS (no regressions).

- [ ] **Step 2: Run the backfill command in dry-run mode**

Run: `php artisan research:backfill-titles --dry-run`
Expected: Outputs count of research thoughts that would be updated. No errors.

- [ ] **Step 3: Build frontend assets**

Run: `npm run build`
Expected: Completes without errors.

- [ ] **Step 4: Commit any remaining fixes**

If any test failures or lint issues were fixed:

```bash
git add -A
git commit -m "fix: address test/lint issues from research detail enhancements"
```

- [ ] **Step 5: Final commit summary**

Run: `git log --oneline -8`
Expected output shows commits in this order (newest first):
1. fix: address test/lint issues (if applicable)
2. feat: copy section_title to metadata title for research captures
3. feat: add research:backfill-titles artisan command
4. feat: add inline body editing to research content sections
5. feat: add title, tags, and project display to research show page
6. feat: add thought_detail_title Blade partial
7. feat: add thoughtTitleEditor Alpine component
8. feat: add PATCH endpoint for research thought title
