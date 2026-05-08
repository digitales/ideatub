# Working memory refresh loading indicator — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add visible pending feedback (spinner + “Refreshing…”, disabled submit) for every **Refresh working memory** form using one shared Blade partial and one `@once` script, without backend changes.

**Architecture:** Introduce `resources/views/components/working-memory-refresh-form.blade.php` that renders the POST form with `data-working-memory-refresh`, optional hidden fields, and configurable button/form classes. A single `DOMContentLoaded` handler attaches to all such forms: on first `submit`, mark the form as submitting, disable the button, set `aria-busy="true"`, replace button HTML with the same spinner pattern as `idea/partials/ideas_list.blade.php` plus “Refreshing…”; on a duplicate submit while pending, `preventDefault`. Replace inline `onsubmit` handlers in three templates with `@include` of this partial.

**Tech Stack:** Laravel 12, Blade, Tailwind CSS 4 (utility classes), PHPUnit/Pest feature tests, no new npm dependencies.

---

## File map

| File | Role |
|------|------|
| `resources/views/components/working-memory-refresh-form.blade.php` | **Create.** Form markup + `@once` `@push('scripts')` handler. |
| `resources/views/memory/show.blade.php` | **Modify.** Swap header `<form>` block for `@include` with correct `$action`, optional `$hiddenFields` for tag scope, `$buttonClass` matching current outline style. |
| `resources/views/idea/stream.blade.php` | **Modify.** Swap tag-context refresh `<form>` for `@include`; same signed action + hidden `tag`. |
| `resources/views/projects/partials/working-memory-module.blade.php` | **Modify.** Swap refresh `<form>` for `@include` with solid primary `$buttonClass` and `formClass` `mb-3`. |
| `tests/Feature/WorkingMemoryWebTest.php` | **Modify.** Assert `data-working-memory-refresh` appears on global + tag memory GET responses. |
| `tests/Feature/IdeaStreamTest.php` | **Modify.** Assert tag stream HTML includes `data-working-memory-refresh`. |
| `tests/Feature/ProjectMemoryModuleTest.php` | **Modify.** Assert `$formHtml` contains `data-working-memory-refresh`. |

**Spec:** `docs/superpowers/specs/2026-05-08-working-memory-refresh-loading-indicator-design.md`

---

### Task 1: Add shared partial with form + script

**Files:**
- Create: `resources/views/components/working-memory-refresh-form.blade.php`
- Modify: none yet

- [ ] **Step 1: Create the partial**

Create `resources/views/components/working-memory-refresh-form.blade.php` with the following contract:

**Expected Blade variables (passed from `@include`):**

- `$action` (string, required): form `action` URL.
- `$buttonClass` (string, required): classes for the submit button **excluding** layout flex utilities (the partial prepends `inline-flex items-center justify-center gap-2`).
- `$formClass` (string, optional): extra classes on `<form>` (e.g. `mb-3`). Omit or pass empty string if none.
- `$hiddenFields` (array, optional): associative `name => value` for `<input type="hidden">` (used for tag scope `tag` on signed refresh).

**Full file content:**

```blade
@php
    $formClass = $formClass ?? '';
    $hiddenFields = is_array($hiddenFields ?? null) ? $hiddenFields : [];
@endphp
<form
    method="POST"
    action="{{ $action }}"
    @if ($formClass !== '')
        class="{{ $formClass }}"
    @endif
    data-working-memory-refresh
>
    @csrf
    @foreach ($hiddenFields as $name => $value)
        <input type="hidden" name="{{ $name }}" value="{{ $value }}">
    @endforeach
    <button
        type="submit"
        class="inline-flex items-center justify-center gap-2 {{ $buttonClass }}"
    >
        Refresh working memory
    </button>
</form>
@once
    @push('scripts')
        <script>
            (function () {
                document.addEventListener('DOMContentLoaded', function () {
                    document.querySelectorAll('form[data-working-memory-refresh]').forEach(function (form) {
                        if (form.dataset.wmRefreshBound === '1') {
                            return;
                        }
                        form.dataset.wmRefreshBound = '1';
                        form.addEventListener('submit', function (e) {
                            if (form.dataset.wmSubmitting === '1') {
                                e.preventDefault();
                                return;
                            }
                            var button = form.querySelector('button[type="submit"]');
                            if (!button || button.disabled) {
                                e.preventDefault();
                                return;
                            }
                            form.dataset.wmSubmitting = '1';
                            button.disabled = true;
                            button.setAttribute('aria-busy', 'true');
                            var spinner =
                                '<span class="inline-block size-3.5 rounded-full border-2 border-neural-teal/50 border-t-neural-teal animate-spin" aria-hidden="true"></span>';
                            button.innerHTML =
                                spinner + '<span>Refreshing…</span>';
                        });
                    });
                });
            })();
        </script>
    @endpush
@endonce
```

- [ ] **Step 2: Commit**

```bash
git add resources/views/components/working-memory-refresh-form.blade.php
git commit -m "feat(ui): add shared working memory refresh form partial"
```

---

### Task 2: Wire `memory/show.blade.php`

**Files:**
- Modify: `resources/views/memory/show.blade.php`

- [ ] **Step 1: Replace inline refresh `<form>`**

Locate the block (approximately lines 71–86) that starts with `<form` `method="POST"` `action="{{ $refreshAction }}"` and the inline `onsubmit="..."`, through the closing `</form>`.

Replace it with an `@include` that passes:

- `$action` => `$refreshAction` (already computed above in the template).
- `$buttonClass` => exactly the current button classes **without** changing visual design:

`text-xs font-medium text-memory-violet hover:text-memory-violet/80 px-3 py-1.5 rounded-lg border border-memory-violet/20 hover:bg-memory-violet/5 transition-colors`

- `$hiddenFields` => when `$isTag` is true, `['tag' => $tagRefreshScopeKey]`; otherwise `[]`.
- `$formClass` => empty string (no extra form classes).

Example:

```blade
@include('components.working-memory-refresh-form', [
    'action' => $refreshAction,
    'buttonClass' => 'text-xs font-medium text-memory-violet hover:text-memory-violet/80 px-3 py-1.5 rounded-lg border border-memory-violet/20 hover:bg-memory-violet/5 transition-colors',
    'hiddenFields' => $isTag ? ['tag' => $tagRefreshScopeKey] : [],
])
```

Remove the old `onsubmit` attribute entirely (behavior moves to the partial script).

- [ ] **Step 2: Manual smoke check**

With `php artisan serve`, open global working memory and tag working memory (flag on); confirm the page still shows “Refresh working memory”, form `action` matches the previous route assertions, and tag pages still emit hidden `tag` when `$isTag`.

- [ ] **Step 3: Commit**

```bash
git add resources/views/memory/show.blade.php
git commit -m "refactor(ui): use shared refresh form on memory show"
```

---

### Task 3: Wire `idea/stream.blade.php` (tag stream)

**Files:**
- Modify: `resources/views/idea/stream.blade.php`

- [ ] **Step 1: Replace the tag-only refresh form**

Inside `@if($tag)`, replace the `<form method="POST" action="{{ $refreshTagAction }}" onsubmit="...">` … `</form>` block (approximately lines 40–46) with:

```blade
@include('components.working-memory-refresh-form', [
    'action' => $refreshTagAction,
    'buttonClass' => 'rounded-full border border-memory-violet/40 px-3 py-1 text-[12px] font-medium text-memory-violet transition hover:bg-memory-violet/5',
    'hiddenFields' => ['tag' => $refreshTagScopeKey],
])
```

**Note:** The partial prepends `inline-flex items-center justify-center gap-2` to the button. Do not repeat `inline-flex` in `$buttonClass`. Keep `rounded-full border …` so the pill shape matches the current UI.

Remove the old inline `onsubmit` guard (replaced by `wmSubmitting` / `preventDefault` in the shared script).

- [ ] **Step 2: Commit**

```bash
git add resources/views/idea/stream.blade.php
git commit -m "refactor(ui): use shared refresh form on tag stream"
```

---

### Task 4: Wire `projects/partials/working-memory-module.blade.php`

**Files:**
- Modify: `resources/views/projects/partials/working-memory-module.blade.php`

- [ ] **Step 1: Replace the refresh `<form>`**

Replace the `<form method="POST" action="{{ route('working-memory.refresh.project', $project) }}" class="mb-3" onsubmit="...">` … `</form>` block with:

```blade
@include('components.working-memory-refresh-form', [
    'action' => route('working-memory.refresh.project', $project),
    'formClass' => 'mb-3',
    'buttonClass' => 'rounded-lg bg-memory-violet px-3 py-2 text-sm font-medium text-white hover:bg-memory-violet/90',
])
```

- [ ] **Step 2: Commit**

```bash
git add resources/views/projects/partials/working-memory-module.blade.php
git commit -m "refactor(ui): use shared refresh form on project module"
```

---

### Task 5: Feature tests — assert `data-working-memory-refresh`

**Files:**
- Modify: `tests/Feature/WorkingMemoryWebTest.php`
- Modify: `tests/Feature/IdeaStreamTest.php`
- Modify: `tests/Feature/ProjectMemoryModuleTest.php`

- [ ] **Step 1: Extend `WorkingMemoryWebTest`**

In `test_global_memory_page_shows_refresh_button_with_global_refresh_action`, after existing assertions, add:

```php
$response->assertSee('data-working-memory-refresh', false);
```

In `test_tag_memory_page_uses_signed_tag_refresh_and_tag_stream_link`, after existing assertions, add:

```php
$response->assertSee('data-working-memory-refresh', false);
```

- [ ] **Step 2: Extend `IdeaStreamTest`**

In `test_tag_stream_shows_refresh_button_and_form_action`, add:

```php
$response->assertSee('data-working-memory-refresh', false);
```

- [ ] **Step 3: Extend `ProjectMemoryModuleTest`**

After `$formHtml` is built, add:

```php
$this->assertStringContainsString('data-working-memory-refresh', $formHtml, 'Refresh form should be marked for shared pending-state script.');
```

- [ ] **Step 4: Run the affected tests**

Run:

```bash
php artisan test tests/Feature/WorkingMemoryWebTest.php tests/Feature/IdeaStreamTest.php tests/Feature/ProjectMemoryModuleTest.php
```

Expected: all tests **PASS**.

- [ ] **Step 5: Commit**

```bash
git add tests/Feature/WorkingMemoryWebTest.php tests/Feature/IdeaStreamTest.php tests/Feature/ProjectMemoryModuleTest.php
git commit -m "test: assert working memory refresh forms expose data attribute"
```

---

### Task 6: Final verification

- [ ] **Step 1: Run full test suite (or at least Feature)**

```bash
php artisan test tests/Feature
```

Expected: **PASS** (or fix any unrelated existing failures before merging).

- [ ] **Step 2: Manual QA (browser)**

1. Global memory: click Refresh — button shows teal spinner + “Refreshing…” before redirect; flash success after.
2. Tag memory page + tag stream: same.
3. Project show (working memory module): same on solid button.

---

## Self-review (plan vs spec)

| Spec requirement | Task |
|------------------|------|
| Spinner + “Refreshing…” | Partial script sets `innerHTML` with spinner + label |
| Disable + `aria-busy` | Partial script |
| All three surfaces | Tasks 2–4 |
| Double-submit protection | `wmSubmitting` + `preventDefault` on duplicate |
| Reuse `animate-spin` pattern from ideas list | Spinner classes match `ideas_list` teal ring |
| No backend changes | Not touched |
| Progressive enhancement (no JS) | Form still POSTs; script only enhances |

---

## Execution handoff

Plan complete and saved to `docs/superpowers/plans/2026-05-08-working-memory-refresh-loading-indicator.md`. Two execution options:

**1. Subagent-Driven (recommended)** — Dispatch a fresh subagent per task, review between tasks, fast iteration.

**2. Inline Execution** — Execute tasks in this session using executing-plans, batch execution with checkpoints.

Which approach?
