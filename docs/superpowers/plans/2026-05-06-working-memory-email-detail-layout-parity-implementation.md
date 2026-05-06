# Working Memory Email-Detail Layout Parity Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Refactor working memory page composition to match email-detail-style cards and columns using a shared detail layout shell, while keeping backend behavior unchanged.

**Architecture:** Extract a reusable Blade layout shell for detail-style pages, then migrate both thought detail and working memory to consume it so parity is structural and not just styling. Keep domain-specific cards in feature-owned partials to preserve boundaries and reduce coupling.

**Tech Stack:** Laravel 12, Blade templates, Tailwind utility classes, Pest/PHPUnit feature tests.

---

## File structure and responsibilities

- **Create:** `resources/views/idea/partials/detail_layout_shell.blade.php`
  - Shared two-column detail-page shell with explicit header/main/sidebar slots.
- **Create:** `resources/views/memory/partials/details_card.blade.php`
  - Working memory details metadata card content.
- **Create:** `resources/views/memory/partials/recent_updates_card.blade.php`
  - Working memory recent update list card content.
- **Modify:** `resources/views/idea/show.blade.php`
  - Replace inline grid scaffold with shared shell usage (behavior-preserving).
- **Modify:** `resources/views/memory/show.blade.php`
  - Remove drawer UX; adopt shared shell and right sidebar cards.
- **Modify:** `tests/Feature/WorkingMemoryWebTest.php`
  - Add regression tests for sidebar cards and drawer removal.

---

### Task 1: Lock expected web behavior with failing tests

**Files:**
- Modify: `tests/Feature/WorkingMemoryWebTest.php`
- Test: `tests/Feature/WorkingMemoryWebTest.php`

- [ ] **Step 1: Add failing test for sidebar card rendering with deltas**

```php
public function test_working_memory_shows_details_and_recent_updates_cards_when_overlay_deltas_exist(): void
{
    config(['features.working_memory_ui' => true]);
    $user = User::factory()->create();

    \App\Models\WorkingMemory::query()->create([
        'user_id' => $user->id,
        'scope_type' => 'global',
        'scope_key' => 'global',
        'summary_markdown' => '## Summary',
        'overlay_deltas' => [
            ['label' => 'New decision', 'detail' => 'Chose sidebar parity', 'since' => '5 minutes ago'],
        ],
        'freshness_state' => 'fresh',
    ]);

    $response = $this->actingAs($user)->get(route('memory.show'));

    $response->assertOk();
    $response->assertSee('Details', false);
    $response->assertSee('Recent updates', false);
}
```

- [ ] **Step 2: Add failing test for no-deltas case**

```php
public function test_working_memory_hides_recent_updates_card_when_overlay_deltas_are_empty(): void
{
    config(['features.working_memory_ui' => true]);
    $user = User::factory()->create();

    \App\Models\WorkingMemory::query()->create([
        'user_id' => $user->id,
        'scope_type' => 'global',
        'scope_key' => 'global',
        'summary_markdown' => '## Summary',
        'overlay_deltas' => [],
        'freshness_state' => 'stale',
    ]);

    $response = $this->actingAs($user)->get(route('memory.show'));

    $response->assertOk();
    $response->assertSee('Details', false);
    $response->assertDontSee('Recent updates', false);
}
```

- [ ] **Step 3: Add failing test for drawer removal regression guard**

```php
public function test_working_memory_no_longer_renders_mobile_drawer_trigger(): void
{
    config(['features.working_memory_ui' => true]);
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('memory.show'));

    $response->assertOk();
    $response->assertDontSee('drawerOpen', false);
    $response->assertDontSee('Recent updates</button>', false);
}
```

- [ ] **Step 4: Run targeted tests to verify failure**

Run: `php artisan test tests/Feature/WorkingMemoryWebTest.php --filter=working_memory -v`  
Expected: FAIL for at least one newly added test before view refactor.

- [ ] **Step 5: Commit test additions**

```bash
git add tests/Feature/WorkingMemoryWebTest.php
git commit -m "test(memory): add layout parity regression coverage"
```

---

### Task 2: Extract shared detail layout shell and migrate thought detail

**Files:**
- Create: `resources/views/idea/partials/detail_layout_shell.blade.php`
- Modify: `resources/views/idea/show.blade.php`
- Test: `tests/Feature/ThoughtShowPageTest.php`

- [ ] **Step 1: Create reusable shell partial**

```blade
<div class="max-w-6xl mx-auto px-6 md:px-8 pt-16 pb-24 space-y-6">
    @isset($header)
        {{ $header }}
    @endisset

    @if ($twoColumn ?? false)
        <div class="grid gap-6 lg:grid-cols-[minmax(0,2fr)_minmax(18rem,1fr)] lg:items-start">
            <div class="space-y-6 min-w-0" data-thought-detail-main>
                {{ $main }}
            </div>
            <div class="space-y-6">
                {{ $sidebar }}
            </div>
        </div>
    @else
        {{ $main }}
    @endif

    @isset($footer)
        {{ $footer }}
    @endisset
</div>
```

- [ ] **Step 2: Refactor thought detail page to consume shell with no behavioral changes**

```blade
@include('idea.partials.detail_layout_shell', [
    'twoColumn' => $useThoughtDetailTwoColumn,
    'header' => view('idea.partials.thought_detail_header', [...]),
    'main' => view('idea.partials.thought_detail_main_content', [...]),
    'sidebar' => $isEmailThought
        ? view('idea.partials.thought_detail_email_sidebar', [...])
        : view('idea.partials.thought_detail_video_sidebar', [...]),
    'footer' => view('comments._thread', [...]),
])
```

- [ ] **Step 3: Run thought show feature tests**

Run: `php artisan test tests/Feature/ThoughtShowPageTest.php -v`  
Expected: PASS.

- [ ] **Step 4: Commit shared shell extraction**

```bash
git add resources/views/idea/partials/detail_layout_shell.blade.php resources/views/idea/show.blade.php
git commit -m "refactor(detail): extract shared two-column layout shell"
```

---

### Task 3: Build working memory sidebar cards and adopt shared shell

**Files:**
- Create: `resources/views/memory/partials/details_card.blade.php`
- Create: `resources/views/memory/partials/recent_updates_card.blade.php`
- Modify: `resources/views/memory/show.blade.php`
- Test: `tests/Feature/WorkingMemoryWebTest.php`

- [ ] **Step 1: Create details sidebar card partial**

```blade
<aside class="rounded-2xl border border-memory-violet/20 bg-white/80 backdrop-blur p-5 shadow-[0_4px_24px_rgba(109,106,247,0.08)]">
    <p class="text-[11px] font-semibold tracking-[0.1em] uppercase text-memory-violet/80 mb-4">Details</p>
    <dl class="space-y-3 text-[13px] text-slate-brand">
        <div><dt class="text-memory-violet/70">Confidence</dt><dd class="text-deep-indigo font-medium">{{ number_format((float) ($confidence_score ?? 0), 2) }}</dd></div>
        <div><dt class="text-memory-violet/70">Last refreshed</dt><dd class="text-deep-indigo font-medium">{{ $last_refreshed_at ?? '—' }}</dd></div>
        <div><dt class="text-memory-violet/70">Consolidation window (days)</dt><dd class="text-deep-indigo font-medium">{{ $effective_consolidation_window_days ?? '—' }}</dd></div>
        <div><dt class="text-memory-violet/70">Input count</dt><dd class="text-deep-indigo font-medium">{{ $input_count ?? 0 }}</dd></div>
        <div><dt class="text-memory-violet/70">Baseline build</dt><dd class="text-deep-indigo font-medium">{{ $baseline_build_type ?? '—' }}</dd></div>
        <div><dt class="text-memory-violet/70">Recent updates (count)</dt><dd class="text-deep-indigo font-medium">{{ count($overlay_deltas ?? []) }}</dd></div>
    </dl>
</aside>
```

- [ ] **Step 2: Create recent updates card partial**

```blade
@if (($overlay_deltas ?? []) !== [])
    <aside class="rounded-2xl border border-memory-violet/20 bg-white/80 backdrop-blur p-5 shadow-[0_4px_24px_rgba(109,106,247,0.08)]">
        <p class="text-[11px] font-semibold tracking-[0.1em] uppercase text-memory-violet/80 mb-4">Recent updates</p>
        <ul class="space-y-4 text-sm">
            @foreach ($overlay_deltas as $delta)
                <li class="border-b border-memory-violet/10 pb-4 last:border-0 last:pb-0">
                    <p class="font-medium text-deep-indigo">{{ $delta['label'] ?? '' }}</p>
                    @if (! empty($delta['detail'] ?? ''))
                        <p class="text-slate-brand mt-1 text-[13px] leading-relaxed">{{ $delta['detail'] }}</p>
                    @endif
                    @if (! empty($delta['since'] ?? ''))
                        <p class="text-[11px] text-slate-brand/50 mt-1">{{ $delta['since'] }}</p>
                    @endif
                </li>
            @endforeach
        </ul>
    </aside>
@endif
```

- [ ] **Step 3: Refactor memory page to shared shell and remove drawer markup**

```blade
@include('idea.partials.detail_layout_shell', [
    'twoColumn' => true,
    'header' => view('memory.partials.header', [...]),
    'main' => view('memory.partials.summary_card', [...]),
    'sidebar' => view('memory.partials.sidebar_stack', [
        'details' => view('memory.partials.details_card', get_defined_vars()),
        'recentUpdates' => view('memory.partials.recent_updates_card', get_defined_vars()),
    ]),
])
```

- [ ] **Step 4: Run working memory web tests**

Run: `php artisan test tests/Feature/WorkingMemoryWebTest.php -v`  
Expected: PASS.

- [ ] **Step 5: Commit working memory layout refactor**

```bash
git add resources/views/memory/show.blade.php resources/views/memory/partials/details_card.blade.php resources/views/memory/partials/recent_updates_card.blade.php
git commit -m "feat(memory): align working memory layout with detail sidebar cards"
```

---

### Task 4: Verify full affected surface and polish

**Files:**
- Modify: `resources/views/memory/show.blade.php` (only if test-discovered fix needed)
- Modify: `resources/views/idea/show.blade.php` (only if test-discovered fix needed)
- Test: `tests/Feature/WorkingMemoryWebTest.php`
- Test: `tests/Feature/ThoughtShowPageTest.php`

- [ ] **Step 1: Run combined feature verification**

Run: `php artisan test tests/Feature/WorkingMemoryWebTest.php tests/Feature/ThoughtShowPageTest.php -v`  
Expected: PASS.

- [ ] **Step 2: Run formatter for touched PHP/Blade files**

Run: `./vendor/bin/pint --dirty`  
Expected: no changes or small formatting-only updates.

- [ ] **Step 3: Re-run focused tests if formatter changed code**

Run: `php artisan test tests/Feature/WorkingMemoryWebTest.php --filter=working_memory -v`  
Expected: PASS.

- [ ] **Step 4: Commit final polish**

```bash
git add -A
git commit -m "test(memory): verify detail shell parity and remove drawer UX"
```

---

## Self-review (plan vs spec)

| Spec requirement | Task coverage |
|---|---|
| Shared detail layout primitives (approach C) | Task 2 |
| Working memory uses card + column structure | Task 3 |
| Details card in right sidebar first | Task 3 |
| Recent updates card in right sidebar second | Task 3 |
| Remove mobile drawer and stack on narrow screens | Task 3 + Task 1 regression test |
| Keep backend/data contracts unchanged | Task 3 (view-only changes) |
| Working memory web verification | Task 1, Task 3, Task 4 |

Placeholder scan: no TODO/TBD placeholders.  
Type consistency check: uses existing payload keys (`overlay_deltas`, `confidence_score`, `last_refreshed_at`, `effective_consolidation_window_days`, `input_count`, `baseline_build_type`) consistently across tasks.
