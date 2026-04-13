# Thought detail — actions row with Share + Add to project, collapsed links, project-scoped targets

**Date:** 2026-04-13  
**Status:** Approved for implementation  
**Depends on:** `docs/superpowers/specs/2026-04-13-projects-and-thought-links-design.md`, `docs/superpowers/specs/2026-04-13-thought-detail-add-to-project-design.md`

## Goal

Refine the authenticated thought detail page so that:

1. **Share** and **Add to project** read as one horizontal **actions** band (same row, wrapping on small screens), instead of a full-width add control under the title and Share isolated at the bottom of the header card.
2. **Linked thoughts** is **collapsed by default**, while **Projects** stays visible.
3. When the thought belongs to at least one project, the **default** link-target picker lists thoughts that share **any** of those projects (union of co-members). When it belongs to no project, behavior matches today’s global list.

## UX

### Actions row (header card)

- **Placement:** After the **tags** row, before idea “Mark as incomplete” (if present).
- **Structure:** New partial (e.g. `thought_detail_actions_row.blade.php`) renders a top-bordered region consistent with current Share styling (`border-t`, spacing similar to `thought_detail_document_share`).
- **Layout:** One flex row with wrap: **Share** block (uppercase label + existing share links / “Create share link” / manage / copy) and **Add to project** (`<details>` from current `thought_detail_add_to_project` behavior) as **siblings** in the same row. The `<summary>` for Add to project is **not** full-width; it behaves like a compact text control next to Share. Opening the disclosure expands the **form below** the row, full width.
- **Visibility:**
  - Include **Add to project** when `$editable` is true (same as today’s header include).
  - Include **Share** when `$thoughtDetail->showDocumentShareBlock()` is true (unchanged rule: document-eligible and not demo mode).
  - Render the actions row when **at least one** of the above is true. If only one side is visible, show that side without requiring a placeholder for the other.
- **Removal:** Remove the standalone include of `thought_detail_document_share` from the bottom of `thought_detail_header` (content moves into the actions row). Remove the standalone placement of `thought_detail_add_to_project` from directly under the “Thought detail” title (include it only from the actions row).

### Linked thoughts (second card)

- **Projects** section: unchanged (always visible).
- **Linked thoughts** section: wrap heading + outgoing/incoming lists + new-link form in **`<details>` closed by default**.
- **Validation:** If the request returns validation errors for link fields (`to_thought_id`, `link_type`, `note`), open the disclosure on render (e.g. `old()` / `@error` presence) so the user sees errors without hunting.

### Accessibility

- Keep native `<details>` / `<summary>` semantics; ensure the Linked thoughts disclosure has a clear summary label (e.g. “Linked thoughts” matching the section heading pattern).

## Backend — link target list

**Current behavior (unchanged when no projects):** `Thought::query()` for current user, `parent_id` null, exclude current thought, order by `updated_at` desc, limit 100.

**When the thought is in one or more projects** (`$thoughtProjectsForDetail` non-empty):

- Let `P` = set of those project IDs.
- **Default targets:** distinct thoughts (same user) that appear in `project_thought` for any `project_id` in `P`, excluding the current thought, with `parent_id` null, ordered by `updated_at` desc, limit **100** (same cap as today unless profiling suggests otherwise).
- **Empty union:** If no other thoughts qualify (e.g. sole member of all linked projects), **fall back** to the same query as “no projects” and show **short helper copy** in the UI that there are no other thoughts in those projects yet, so the full list is shown for linking.

**Demo mode:** Apply the same `DemoObfuscator` mapping to link target options as today after the collection is built (no change to obfuscation rules).

**Authorization:** No change; link creation remains subject to existing policies and validation.

## Testing (Pest)

- **Controller / view data:** Thought with **no** projects → link options query matches prior shape (smoke: count/ids from a seeded set).
- Thought in **one** project with two other members → options include only co-members (plus fallback case if alone).
- Thought in **two** projects with different members → options include **union** (thought in either project), deduped.
- **Empty union** → fallback list non-empty when other thoughts exist globally.
- Optional: feature test that validation error on link form results in open disclosure (if asserted via HTML `open` attribute or presence of error message inside visible region).

## Non-goals

- Changing link types, graph behavior, or MCP.
- Client-side async search for link targets; still server-rendered `<select>`.
- Per-project sub-filter dropdown (user chose union-only behavior).

## Implementation notes

- `IdeaController` (thought show action): build `linkTargetThoughtOptions` using the union query when `$memberProjectIds` is non-empty, then apply empty-union fallback; reuse existing ordering/limit/obfuscation.
- Blade: refactor header partials; keep route names and form actions unchanged.
