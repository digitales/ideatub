# IdeaTub — Shared long-form documents (all capture types)

**Date:** 2026-04-02  
**Status:** Approved for planning  
**Scope:** Extend the existing readonly share mechanism so any **long-form capture document** (same doc types as MCP `capture_plan`) can be shared like research today, with neutral product naming. Add a **Share** affordance on the **thought detail** page. Keep the existing `research_shares` table and `/r/{token}` public URL unless a later migration is justified.

**Related:** `docs/superpowers/specs/2026-03-17-shareable-research-design.md` (original research-only framing; this spec supersedes product scope for eligibility and naming while retaining technical behavior).

## Overview

- **Goal:** Owners can create a readonly link for the **root** of a captured long-form document (root markdown + section children), with optional per-share password and expiry, for **plan**, **decision**, **dev**, **support**, **spec**, **research**, and **meeting** types — aligned with MCP `capture_plan` / meeting aliases.
- **Non-goal (v1):** Sharing arbitrary top-level thoughts (e.g. `idea`, untyped notes, **video**, **email**, **jira**). Sharing **section** rows (child thoughts) as link targets remains invalid.
- **Detail page:** When the viewed thought is an eligible document root, show a compact **Share** block (create / copy / open / manage) consistent with stream card behavior.
- **Public view:** Same server-rendered readonly page as today (`SharedResearchViewController`); adjust headings/copy to generic **shared document** wording where user-visible.

## 1. Eligibility (single source of truth)

A thought may be **shared as a document** when all hold:

1. **Owner** — Current user owns the thought (existing policy).
2. **Root only** — `parent_id` is null.
3. **Stream visibility** — `visibleInStream()` is true (same as today).
4. **Document type** — `metadata.type` (string, case-insensitive) is one of:

   `plan`, `decision`, `dev`, `support`, `spec`, `research`, `meeting`

   **Normalization:** Implementation MUST reuse the same rules the app already uses for stream/MCP type matching (e.g. `ThoughtTypeNavigation::storedValuesForCollection` / `normalizeTypeKey` where applicable) so `plans` / `meetings` aliases behave like `plan` / `meeting`. The implementation plan should name the exact helper(s) called from the eligibility method.

5. **Excluded sources/types** — Do not offer share for `source` email or jira, or for `metadata.type` **video** (and other non-listed types).

Implement eligibility in one place (e.g. `Thought` method or small dedicated class) and reuse from:

- `SharedResearchController::store` (reject ineligible with clear validation/error message).
- `IdeaController::show` (load optional `ResearchShare` for that `thought_id` + pass to presenter/view).
- Stream card actions / presenter (show Share menu only when eligible).

**Edge case:** Thoughts with `decision` / `dev` / `support` / `spec` may appear in the **main** stream only (no dedicated nav tab); they remain eligible if `metadata.type` matches.

## 2. Data model and policies

- **No schema change in v1.** Continue using `research_shares` (`user_id`, `thought_id`, `token`, `password_hash`, `expires_at`).
- **One active share per thought** — unchanged; create redirects or errors if a share already exists.
- **Policies** — Unchanged: only share owner may update/delete.

## 3. Routes and public behavior

- **GET/POST `/r/{token}`** — Unchanged (password cookie naming may stay `research_share_{token}` for v1 to avoid migration).
- **Authenticated owner routes** — Keep route names `shared-research.*` in v1; update **user-visible** labels only (menu, page titles, help, flash messages).

### 3.1 Readonly page presentation

- User-visible title/heading: generic (e.g. **Shared document**), optionally supplemented by a soft label derived from `metadata.type` (e.g. “Meeting”) when trivial.
- Footer: keep minimal “Shared via IdeaTub” (or existing equivalent).
- **Known divergence (document, do not fix in v1 unless scoped):** Detail page sections use `isStructuredDocumentSection` + `section_index` ordering; public share view currently loads all comments by `created_at`. Call out in implementation plan as follow-up if mixed replies and sections appear.

## 4. Owner UX

### 4.1 Thought detail page (`idea.show`)

- For **eligible** roots, when editing is not demo-blocked (same as other actions), render a **Share** partial:
  - **No share:** Primary action to create (link to `shared-research.index?create={id}` or POST form with CSRF — match existing stream pattern).
  - **Share exists:** **Copy link**, **Open** (new tab), **Manage** (existing index with `?share=` focus).
- For **ineligible** thoughts: do not show the block.

### 4.2 Stream cards

- Keep existing Share entry points but **gate** them with the same eligibility helper so stream and detail stay consistent.

### 4.3 Management index (`/shared-research`)

- Rename user-facing strings from **“Shared research”** to a neutral label (**“Shared links”** or **“Shared documents”**). **Pick one string once** at implementation start and use it for index title, nav menu, help, and flash messages so copy does not drift mid-build.
- List rows remain per share; preview text can remain first lines of root content.

### 4.4 Help and navigation

- Update `resources/views/help.blade.php` and layout menu labels to describe **long-form documents** (plans, specs, meetings, research, etc.), not research only.

## 5. Validation and errors

- `SharedResearchController::store`: after loading `thought`, if not eligible, redirect back with error (same pattern as invisible thought today), with a message that states **document type** restriction, not “research only.”
- Do not broaden create API to jira/email/video implicitly.

## 6. Testing

- Feature tests:
  - **Store:** eligible `meeting` / `plan` / `research` root succeeds; **video** or wrong parent fails or redirects with errors as specified.
  - **Store / exclusions:** at least one case by **`source`** (e.g. email or jira top-level thought) is rejected even if metadata were ambiguous, matching §1.5.
  - **Detail page:** eligible root shows Share block; ineligible root does not.
  - **Stream:** Share action present only when eligible (if covered by existing presenter tests, extend).
- Adjust any tests that assert literal **“Shared research”** strings if copy changes.

## 7. Implementation notes

- Prefer **named arguments** when extending `ThoughtDetailPresenter::forShow` to avoid parameter order mistakes.
- Demo mode: follow existing rules for obfuscation on public pages if applicable (see demo-mode docs); no change required unless current shared page already special-cased.

## 8. Out of scope (v1)

- Renaming PHP classes (`ResearchShare`) or database table.
- Changing cookie name prefix (optional v2).
- Aligning public section ordering with detail `section_index` filter (tracked as follow-up).
