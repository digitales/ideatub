# Working memory refresh — loading indicator

## Problem

Manual **Refresh working memory** triggers a POST that queues `ConsolidateWorkingMemory` and redirects back with a flash message. Forms already disable the submit control and set `aria-busy="true"` on submit, but **there is no visible change**: the label stays “Refresh working memory”, so users cannot tell the request started (especially on slower networks).

## Goal

Provide clear **pending feedback** from click until navigation completes: **spinner + “Refreshing…”** label, button disabled, consistent across all surfaces that expose this action.

## Scope

**In scope**

- `resources/views/memory/show.blade.php` — global / project / tag working memory header form.
- `resources/views/idea/stream.blade.php` — tag stream contextual refresh form (when `$tag` is set).
- `resources/views/projects/partials/working-memory-module.blade.php` — project dashboard module.

**Out of scope**

- Settings **Build now (forced tags)** and other non–“Refresh working memory” submits (same pattern could be reused later).
- Showing queue/job completion in the UI (still asynchronous after redirect; flash message remains the success signal).

## Approaches

### A — Inline-only (per template)

Duplicate spinner markup and submit-handler logic in each of the three Blade files.

- **Pros:** No new abstractions; trivial to locate.
- **Cons:** Easy to drift (stream form already uses a slightly different `dataset.submitting` guard); three places to fix bugs.

### B — Shared Blade partial + one script (`@once`)

Introduce a small partial that renders the form + button (parameterized by action, optional hidden fields, button variant classes). Submit handling lives in a single `@once` `@push('scripts')` block that listens for `submit` on forms marked with a data attribute (e.g. `data-working-memory-refresh`).

- **Pros:** One behavior definition; matches existing Tailwind spinner usage elsewhere (`inline-block … animate-spin` pattern).
- **Cons:** Requires threading props for three slightly different layouts (outline vs solid button, signed tag URL + hidden `tag`).

### C — Full-page blocking overlay

Dim the page and show a centered spinner until `pageshow`/`beforeunload` — heavy-handed for a normal form POST.

- **Pros:** Very visible.
- **Cons:** Overkill; accessibility and focus management more complex; user chose button-level spinner.

## Recommendation

**Approach B.** Centralize markup and the submit-side effects so all three surfaces stay visually and behaviorally aligned. Reuse the existing **animate-spin** spinner styling already present in `resources/views/idea/partials/ideas_list.blade.php` for consistency.

## Design

### UX

- On **first** submit of the form: disable the submit button, set `aria-busy="true"`, replace button contents with:
  - A small decorative spinner (`aria-hidden="true"`).
  - Visible text **Refreshing…** (ellipsis matches common loading copy).
- Preserve **inline-flex**, **items-center**, and a small **gap** so spinner and text align on both outline and solid button styles.
- Keep **double-submit protection**: if the button is already disabled, do not run the handler again (align stream’s `dataset.submitting` behavior with the other forms via the shared handler).

### Accessibility

- Submit button: `aria-busy="true"` while pending; optional `aria-live="polite"` on the button is unnecessary if label changes visibly.
- Spinner purely decorative with `aria-hidden="true"`.

### Technical

- Add a dedicated partial under `resources/views/` (e.g. `components/working-memory-refresh-form.blade.php` or `memory/partials/refresh-form.blade.php`) accepting:
  - `action` URL
  - optional hidden fields (e.g. tag scope key for signed tag refresh)
  - button classes (memory page outline vs project module solid primary)
- Replace inline `onsubmit="..."` with `data-working-memory-refresh` on the form.
- Register one **DOMContentLoaded** (or delegated `submit`) handler via `@once` … `@push('scripts')` that:
  - Finds `button[type="submit"]`
  - Applies disabled + inner HTML swap + attributes
  - Returns without blocking default submit (POST proceeds).

### Edge cases

- **Fast response:** User may barely see the pending state; acceptable.
- **Validation / redirect errors:** Full page reload restores the default button; no extra cleanup.
- **JavaScript disabled:** Form still submits; progressive enhancement preserved.

### Testing

- Existing feature tests that `assertSee('Refresh working memory')` on **GET** responses remain valid (idle label unchanged).
- Manual QA: click refresh on global memory, tag memory header, tag stream, project module — confirm spinner + label before redirect.
- Optional follow-up: Laravel Dusk or HTTP-level assertion only if we add a test-only hook (not required for MVP).

## Implementation notes

Files expected to change:

- New: shared partial + script push (location as above).
- Modify: `memory/show.blade.php`, `idea/stream.blade.php`, `projects/partials/working-memory-module.blade.php` to use the partial or include shared markup.

No backend or route changes.
