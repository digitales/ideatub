# Stream Multi-Column Layout Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a list/grid layout toggle to all stream views with session-persisted preference and fluid CSS Grid multi-column layout.

**Architecture:** New `StreamLayoutController` stores layout preference in session. A view composer shares `$streamLayout` to the idea layout. Alpine.js handles instant client-side toggle while a background POST persists the choice. CSS Grid `auto-fill` provides fluid columns in grid mode.

**Tech Stack:** Laravel (controller, route, view composer, session), Blade templates, Alpine.js, Tailwind CSS Grid.

**Spec:** `docs/superpowers/specs/2026-05-11-stream-multi-column-layout-design.md`

---

## File Structure

| File | Responsibility |
|------|---------------|
| `app/Http/Controllers/StreamLayoutController.php` | New. Validates and stores layout preference in session. |
| `routes/web.php` | Add POST route for layout persistence. |
| `app/Providers/AppServiceProvider.php` | Extend existing view composer to share `$streamLayout`. |
| `resources/views/idea/stream.blade.php` | Alpine toggle state, dynamic container width, grid classes, toggle button UI. |
| `resources/views/idea/stream_thoughts.blade.php` | Conditional card truncation in grid mode. |
| `tests/Feature/StreamLayoutTest.php` | New. Tests for the controller, session, and view rendering. |

---

### Task 1: StreamLayoutController and Route

**Files:**
- Create: `app/Http/Controllers/StreamLayoutController.php`
- Modify: `routes/web.php:157-162`
- Create: `tests/Feature/StreamLayoutTest.php`

- [ ] **Step 1: Write the failing test for storing layout preference**

Create `tests/Feature/StreamLayoutTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StreamLayoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_store_layout_sets_session_and_returns_204(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/stream/layout', [
            'layout' => 'grid',
        ]);

        $response->assertNoContent();
        $this->assertEquals('grid', session('stream_layout'));
    }

    public function test_store_layout_rejects_invalid_value(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/stream/layout', [
            'layout' => 'masonry',
        ]);

        $response->assertUnprocessable();
    }

    public function test_store_layout_requires_auth(): void
    {
        $response = $this->postJson('/stream/layout', [
            'layout' => 'grid',
        ]);

        $response->assertUnauthorized();
    }

    public function test_store_layout_accepts_list_value(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/stream/layout', ['layout' => 'grid']);
        $this->assertEquals('grid', session('stream_layout'));

        $response = $this->actingAs($user)->postJson('/stream/layout', [
            'layout' => 'list',
        ]);

        $response->assertNoContent();
        $this->assertEquals('list', session('stream_layout'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=StreamLayoutTest`
Expected: FAIL — route not defined / 404

- [ ] **Step 3: Create the controller**

Create `app/Http/Controllers/StreamLayoutController.php`:

```php
<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;

class StreamLayoutController extends Controller
{
    public function store(Request $request): Response
    {
        $validated = $request->validate([
            'layout' => 'required|in:list,grid',
        ]);

        $request->session()->put('stream_layout', $validated['layout']);

        return response()->noContent();
    }
}
```

- [ ] **Step 4: Add the route**

In `routes/web.php`, add the following inside the `Route::middleware('auth')->group(function () {` block (after the demo-mode routes around line 161):

```php
    Route::post('/stream/layout', [StreamLayoutController::class, 'store'])->name('stream.layout.store');
```

Also add the import at the top of the file:

```php
use App\Http\Controllers\StreamLayoutController;
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test --filter=StreamLayoutTest`
Expected: All 4 tests PASS

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/StreamLayoutController.php routes/web.php tests/Feature/StreamLayoutTest.php
git commit -m "feat: add StreamLayoutController with session persistence"
```

---

### Task 2: View Composer — Share $streamLayout

**Files:**
- Modify: `app/Providers/AppServiceProvider.php:121-133`
- Modify: `tests/Feature/StreamLayoutTest.php`

- [ ] **Step 1: Write failing tests for stream layout being shared to views**

Add to `tests/Feature/StreamLayoutTest.php`:

```php
    public function test_stream_page_defaults_to_list_layout(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('idea.stream'));

        $response->assertOk();
        $response->assertSee('data-stream-layout="list"', false);
    }

    public function test_stream_page_renders_grid_when_session_set(): void
    {
        $user = User::factory()->create();

        $response = $this->withSession(['stream_layout' => 'grid'])
            ->actingAs($user)
            ->get(route('idea.stream'));

        $response->assertOk();
        $response->assertSee('data-stream-layout="grid"', false);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=StreamLayoutTest`
Expected: FAIL — `data-stream-layout` not found in HTML

- [ ] **Step 3: Add $streamLayout to the existing view composer**

In `app/Providers/AppServiceProvider.php`, update the existing `View::composer('layouts.idea', ...)` block (around line 121–133). Add one line inside the closure:

```php
        View::composer('layouts.idea', function ($view): void {
            $count = 0;

            if (auth()->check()) {
                $count = InboxItem::query()
                    ->forUser(auth()->user())
                    ->actionable()
                    ->count();
            }

            $view->with('inboxActionableCount', $count);
            $view->with('demoModeEnabled', app(DemoMode::class)->enabled());
            $view->with('streamLayout', session('stream_layout', 'list'));
        });
```

- [ ] **Step 4: Add the data attribute to stream.blade.php for initial state**

In `resources/views/idea/stream.blade.php`, on line 13, update the outer div to include the data attribute. Change:

```blade
        <div class="max-w-[600px] mx-auto px-6 pt-16 pb-24">
```

to:

```blade
        <div data-stream-layout="{{ $streamLayout }}" class="mx-auto px-6 pt-16 pb-24 {{ $streamLayout === 'grid' ? 'max-w-[1400px]' : 'max-w-[600px]' }}">
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test --filter=StreamLayoutTest`
Expected: All 6 tests PASS

- [ ] **Step 6: Run existing stream tests to check no regressions**

Run: `php artisan test --filter=StreamPageTest`
Expected: All existing tests PASS

- [ ] **Step 7: Commit**

```bash
git add app/Providers/AppServiceProvider.php resources/views/idea/stream.blade.php tests/Feature/StreamLayoutTest.php
git commit -m "feat: share streamLayout via view composer, render initial state"
```

---

### Task 3: Toggle Button UI

**Files:**
- Modify: `resources/views/idea/stream.blade.php:67-78`
- Modify: `tests/Feature/StreamLayoutTest.php`

- [ ] **Step 1: Write failing test for toggle button presence**

Add to `tests/Feature/StreamLayoutTest.php`:

```php
    public function test_stream_page_shows_layout_toggle_buttons(): void
    {
        $user = User::factory()->create();
        \App\Models\Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Toggle test thought',
        ]);

        $response = $this->actingAs($user)->get(route('idea.stream'));

        $response->assertOk();
        $response->assertSee('data-testid="layout-toggle-list"', false);
        $response->assertSee('data-testid="layout-toggle-grid"', false);
    }

    public function test_stream_toggle_not_shown_when_empty(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('idea.stream'));

        $response->assertOk();
        $response->assertDontSee('data-testid="layout-toggle-list"', false);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=StreamLayoutTest`
Expected: FAIL — `data-testid="layout-toggle-list"` not found

- [ ] **Step 3: Add the Alpine.js toggle and button UI to stream.blade.php**

In `resources/views/idea/stream.blade.php`, replace the count line and thought list section. Change the block starting at line 67 (the `@else` branch for non-empty thoughts):

Replace:

```blade
            @else
                <p class="text-[11px] text-slate-brand/40 mb-2" id="stream-count-line">
                    Showing <span id="stream-showing-count">{{$thoughts->count()}}</span> of <span id="stream-total-count">{{$thoughts->total()}}</span> thoughts
                </p>
                <div id="stream-thoughts-list"
                    data-stream-refetch-url="{{ $__typedStreamRouteName ? route($__typedStreamRouteName) : ($tagSlug ? route('idea.stream', ['tag' => $tagSlug]) : route('idea.stream')) }}?page=1"
                    data-stream-since="{{ $streamSince }}">
                    @include('idea.stream_thoughts', ['cards' => $cards])
                </div>
                @if($thoughts->hasMorePages())
                    <div id="stream-load-more-sentinel" class="h-4 mt-4" data-stream-base-url="{{ $__typedStreamRouteName ? route($__typedStreamRouteName) : ($tagSlug ? route('idea.stream', ['tag' => $tagSlug]) : route('idea.stream')) }}" data-stream-total="{{$thoughts->total()}}"></div>
                @endif
            @endif
```

with:

```blade
            @else
                <div x-data="streamLayout('{{ $streamLayout }}')" x-init="applyLayout()">
                    <div class="flex items-center justify-between mb-2">
                        <p class="text-[11px] text-slate-brand/40" id="stream-count-line">
                            Showing <span id="stream-showing-count">{{$thoughts->count()}}</span> of <span id="stream-total-count">{{$thoughts->total()}}</span> thoughts
                        </p>
                        <div class="flex items-center gap-1">
                            <button
                                type="button"
                                data-testid="layout-toggle-list"
                                @click="setLayout('list')"
                                :class="layout === 'list' ? 'text-memory-violet' : 'text-slate-brand/40 hover:text-slate-brand'"
                                class="p-1 rounded transition-colors"
                                aria-label="List layout"
                            >
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" d="M4 6h16M4 12h16M4 18h16" />
                                </svg>
                            </button>
                            <button
                                type="button"
                                data-testid="layout-toggle-grid"
                                @click="setLayout('grid')"
                                :class="layout === 'grid' ? 'text-memory-violet' : 'text-slate-brand/40 hover:text-slate-brand'"
                                class="p-1 rounded transition-colors"
                                aria-label="Grid layout"
                            >
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                    <rect x="3" y="3" width="7" height="7" rx="1" />
                                    <rect x="14" y="3" width="7" height="7" rx="1" />
                                    <rect x="3" y="14" width="7" height="7" rx="1" />
                                    <rect x="14" y="14" width="7" height="7" rx="1" />
                                </svg>
                            </button>
                        </div>
                    </div>
                    <div id="stream-thoughts-list"
                        :class="layout === 'grid' ? 'grid grid-cols-[repeat(auto-fill,minmax(300px,1fr))] gap-2' : ''"
                        data-stream-refetch-url="{{ $__typedStreamRouteName ? route($__typedStreamRouteName) : ($tagSlug ? route('idea.stream', ['tag' => $tagSlug]) : route('idea.stream')) }}?page=1"
                        data-stream-since="{{ $streamSince }}">
                        @include('idea.stream_thoughts', ['cards' => $cards])
                    </div>
                    @if($thoughts->hasMorePages())
                        <div id="stream-load-more-sentinel" class="h-4 mt-4" data-stream-base-url="{{ $__typedStreamRouteName ? route($__typedStreamRouteName) : ($tagSlug ? route('idea.stream', ['tag' => $tagSlug]) : route('idea.stream')) }}" data-stream-total="{{$thoughts->total()}}"></div>
                    @endif
                </div>
            @endif
```

- [ ] **Step 4: Add the Alpine component script**

In `resources/views/idea/stream.blade.php`, inside the `@push('scripts')` block (after the existing scripts, before `@endpush`), add:

```blade
                <script>
                function streamLayout(initial) {
                    return {
                        layout: initial || 'list',
                        setLayout(mode) {
                            this.layout = mode;
                            this.applyLayout();
                            fetch('{{ route("stream.layout.store") }}', {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json',
                                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                                    'X-Requested-With': 'XMLHttpRequest',
                                },
                                body: JSON.stringify({ layout: mode }),
                            });
                        },
                        applyLayout() {
                            var container = this.$el.closest('[data-stream-layout]');
                            if (!container) return;
                            container.setAttribute('data-stream-layout', this.layout);
                            if (this.layout === 'grid') {
                                container.classList.remove('max-w-[600px]');
                                container.classList.add('max-w-[1400px]');
                            } else {
                                container.classList.remove('max-w-[1400px]');
                                container.classList.add('max-w-[600px]');
                            }
                        },
                    };
                }
                </script>
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test --filter=StreamLayoutTest`
Expected: All 8 tests PASS

- [ ] **Step 6: Run existing stream tests to check no regressions**

Run: `php artisan test --filter=StreamPageTest`
Expected: All existing tests PASS

- [ ] **Step 7: Commit**

```bash
git add resources/views/idea/stream.blade.php tests/Feature/StreamLayoutTest.php
git commit -m "feat: add layout toggle button with Alpine.js and session persistence"
```

---

### Task 4: Card Truncation in Grid Mode

**Files:**
- Modify: `resources/views/idea/stream_thoughts.blade.php:1-6`
- Modify: `tests/Feature/StreamLayoutTest.php`

- [ ] **Step 1: Write failing test for card truncation classes in grid mode**

Add to `tests/Feature/StreamLayoutTest.php`:

```php
    public function test_stream_cards_include_grid_truncation_data_attribute(): void
    {
        $user = User::factory()->create();
        \App\Models\Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Truncation test thought',
        ]);

        $response = $this->actingAs($user)->get(route('idea.stream'));

        $response->assertOk();
        $response->assertSee('data-stream-card', false);
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=test_stream_cards_include_grid_truncation_data_attribute`
Expected: FAIL — `data-stream-card` not found

- [ ] **Step 3: Add truncation markup to stream_thoughts.blade.php**

In `resources/views/idea/stream_thoughts.blade.php`, update the outer card `<div>` (line 2) to include the data attribute and add truncation styles. Change:

```blade
    <div
        data-thought-id="{{ $card->thought()->id }}"
        @if ($card->isVideoThought()) data-thought-kind="video" @endif
        class="relative rounded-xl border border-memory-violet/15 bg-white/80 px-4 py-3.5 mb-2 hover:border-memory-violet/20 hover:shadow-[0_2px_12px_rgba(109,106,247,0.08)] transition-all @if ($card->isVideoThought()) border-l-[3px] border-l-rose-400/90 @endif"
    >
```

to:

```blade
    <div
        data-thought-id="{{ $card->thought()->id }}"
        data-stream-card
        @if ($card->isVideoThought()) data-thought-kind="video" @endif
        class="relative rounded-xl border border-memory-violet/15 bg-white/80 px-4 py-3.5 mb-2 hover:border-memory-violet/20 hover:shadow-[0_2px_12px_rgba(109,106,247,0.08)] transition-all @if ($card->isVideoThought()) border-l-[3px] border-l-rose-400/90 @endif"
    >
```

- [ ] **Step 4: Add CSS-based truncation via a `<style>` tag in stream.blade.php**

In `resources/views/idea/stream.blade.php`, add the following style block inside the `@push('scripts')` section (or a new `@push('styles')` — but since the layout uses inline styles already, add it right after the Alpine `streamLayout` script):

```blade
                <style>
                [data-stream-layout="grid"] [data-stream-card] {
                    max-height: 200px;
                    overflow: hidden;
                    position: relative;
                }
                [data-stream-layout="grid"] [data-stream-card]::after {
                    content: '';
                    position: absolute;
                    bottom: 0;
                    left: 0;
                    right: 0;
                    height: 2rem;
                    background: linear-gradient(to top, rgba(255,255,255,0.9), transparent);
                    pointer-events: none;
                }
                [data-stream-layout="grid"] [data-stream-card] .mb-2:last-child {
                    margin-bottom: 0;
                }
                </style>
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test --filter=StreamLayoutTest`
Expected: All 9 tests PASS

- [ ] **Step 6: Run full stream test suite for regressions**

Run: `php artisan test --filter=StreamPageTest && php artisan test --filter=IdeaStreamTest`
Expected: All PASS

- [ ] **Step 7: Commit**

```bash
git add resources/views/idea/stream_thoughts.blade.php resources/views/idea/stream.blade.php tests/Feature/StreamLayoutTest.php
git commit -m "feat: add card truncation with fade gradient in grid mode"
```

---

### Task 5: Tag and Collection Views — Verify Universal Behavior

**Files:**
- Modify: `tests/Feature/StreamLayoutTest.php`

- [ ] **Step 1: Write tests to verify grid mode works on tag-filtered and collection views**

Add to `tests/Feature/StreamLayoutTest.php`:

```php
    public function test_tag_stream_respects_grid_session(): void
    {
        $user = User::factory()->create();
        \App\Models\Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Tagged thought',
            'metadata' => ['tags' => ['work']],
        ]);

        $response = $this->withSession(['stream_layout' => 'grid'])
            ->actingAs($user)
            ->get(route('idea.stream', ['tag' => 'work']));

        $response->assertOk();
        $response->assertSee('data-stream-layout="grid"', false);
        $response->assertSee('data-testid="layout-toggle-grid"', false);
    }

    public function test_collection_stream_respects_grid_session(): void
    {
        $user = User::factory()->create();

        $response = $this->withSession(['stream_layout' => 'grid'])
            ->actingAs($user)
            ->get(route('idea.stream.meetings'));

        $response->assertOk();
        $response->assertSee('data-stream-layout="grid"', false);
    }
```

- [ ] **Step 2: Run all tests**

Run: `php artisan test --filter=StreamLayoutTest`
Expected: All 11 tests PASS

- [ ] **Step 3: Run the complete stream-related test suite**

Run: `php artisan test --filter=Stream`
Expected: All PASS — no regressions

- [ ] **Step 4: Commit**

```bash
git add tests/Feature/StreamLayoutTest.php
git commit -m "test: verify grid layout on tag and collection stream views"
```

---

### Task 6: Final Smoke Test and Cleanup

- [ ] **Step 1: Run the full test suite**

Run: `php artisan test`
Expected: All tests PASS

- [ ] **Step 2: Check for lint issues**

Run: `./vendor/bin/pint --test`
Expected: No formatting issues (or run `./vendor/bin/pint` to auto-fix)

- [ ] **Step 3: Manual verification checklist**

Verify in browser:
1. Stream page loads in list mode by default
2. Toggle button is visible next to "Showing X of Y"
3. Clicking grid icon switches to multi-column layout instantly
4. Clicking list icon switches back to single column
5. Refreshing the page preserves the chosen layout
6. Grid mode shows 1-4 columns depending on viewport width
7. Tall cards are truncated with fade gradient in grid mode
8. Tag-filtered page respects layout preference
9. Collection pages (Meetings, Plans, etc.) respect layout preference
10. Empty state displays correctly in both modes
11. Infinite scroll loads more cards correctly in grid mode

- [ ] **Step 4: Commit any cleanup**

```bash
git add -A
git commit -m "chore: final cleanup for stream multi-column layout"
```
