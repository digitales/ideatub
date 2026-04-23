# Research microsite import and viewing — design

**Date:** 2026-04-23  
**Status:** Draft — for review before implementation plan

## Related

- [File and folder upload to thoughts (2026-04-22)](2026-04-22-file-folder-upload-design.md) — batch import, limits, batch tables, `ThoughtCaptureService`, and provenance. This spec **branches** that flow when a strict “numbered markdown site” is detected.
- [Shared long-form documents (2026-04-02)](2026-04-02-shared-longform-documents-design.md) — `research_shares`, `GET /r/{token}`. This spec **extends** public and signed-in UIs to support a **paged** layout with a single share token on the **root** thought.
- **Agent research (MCP):** `.cursor/rules/ideatub-sync-research.mdc` — save via `capture_plan` as chunked long-form. **Microsites** in this spec come from **folder import** only, unless a later plan unifies the two.

## 1. Summary

For folder or multi-file imports where **every** file in the selection is a `*.md` file with a **numeric-prefixed** name (see §2), IdeaTub creates a **research microsite**: **one `Thought` per file** (no heading-based chunking), a clear **parent/root** and **child page** relationship, and **in-app and public shared** views that share the **same** document chrome: a **left (or top-on-mobile) section list** and a **main reading pane** for the current page. All body HTML comes from the **same** server-side Markdown pipeline used elsewhere in the app (no VitePress or second renderer).

Internal markdown links that point to **other `*.md` files in the same import** are **rewritten at import** to point at IdeaTub’s canonical in-app and share URLs for the corresponding pages (see §5).

**Rendering philosophy:** Match Dark Factory’s “small doc site” **feel** (nav + one page) using IdeaTub’s default Markdown and prose styles, not by embedding VitePress.

## 2. When the microsite import branch runs

| Condition | Outcome |
| --- | --- |
| **Microsite (strict)** | The in-scope file set (after the same client/server extension and size rules as the 2026-04-22 import) contains **at least two** `*.md` files; **every** included file in that set matches the **page filename pattern** below, and the set is **exhaustive** (if the user includes any file that is not a matching `*.md`, the batch is **not** a microsite; use **classic** import for that selection). **Single** matching `NN-… .md` in quick path or batch: **not** a microsite — use the existing single-file or classic multi-file import. |
| **Not microsite** | Any other case: `.txt`, unnumbered or invalid `.md` names, mixed types, a single `*.md` only, or a batch that is not 100% matching `*.md` with valid names. Uses 2026-04-22 behaviour (chunking checkbox, per-file thoughts, `folder:<segment>` tags, etc.). **No** auto-upgrade to microsite. |

**Page filename pattern (strict, v1):** `basename` without extension:

1. A prefix of **one or more** ASCII digits.  
2. A single separator: `-`, `_`, or `.`  
3. A non-empty remainder (the `page` label), e.g. `summary` in `00-summary.md`.

Examples: `1-intro.md` ✓, `00-summary.md` ✓, `12_findings.md` ✓, `2-foo.md` ✓. Not valid: `00.md` (no title after separator) ✗; `9` without separator and remainder ✗; `narrative.md` (no numeric prefix) ✗. **Normalisation** (e.g. case of extension) must match 2026-04-22 allowlist.  
**Sort key** for ordering is the integer value of the **leading** digit run (e.g. `2-foo` → 2, `00-bar` → 0, which sorts before 2 if both exist).

**Pre-upload UI:** The batch confirm modal (2026-04-22) should show an explicit state when microsite is detected, e.g. *“This looks like a numbered research site: N pages, ordered by the leading numbers. Chunking and subfolder-to-tag folder rules for this batch will not apply; each file is one page.”* Copy should be one sentence, no jargon.

**Demo mode:** Inherits import disable rules from the 2026-04-22 spec; microsite is not a special case.

**Non-goals (v1) for the branch itself:** Unnumbered additional markdown, ZIP, nested subfolders as separate sites, or assets (see §6).

## 3. Page ordering and root

- **Order:** Parse the leading **integer** from each filename (e.g. `2-foo` → `2`, `10-bar` → `10`). Sort **ascending by that integer**; on tie, sort lexicographically by full basename.  
- **Root thought:** The page with the **minimum** sort key (lowest number) is the **root** `Thought` — `parent_id` is null. All other pages are **children** with `parent_id` set to the root.  
- **Rationale:** Matches user expectation of `00-summary` as the index when present, without hard-coding `00`.

## 4. Data model and metadata

- **Types:** The root and each child use the same **long-form / research** typing as the rest of the import pipeline (aligned with `metadata.type` and existing stream/share eligibility per 2026-04-02). The implementation plan should name the exact `metadata` keys set during microsite import.  
- **Layout discriminator:** `source_metadata.document_layout` (or a single key agreed with existing JSON patterns) = `microsite` for root and for every child page thought in that site, so the research view and shared view can select the **paged** layout and avoid conflating with **section-chunked** long-form (where children are “sections” of one scroll).  
- **Per page:** `source_metadata` must include at least: `page_path_segment` = basename of the file without `.md` (unique within the import; used in URLs and link rewriting), and `import_order` = integer from §3. Provenance: `import` with batch id / relative path as in 2026-04-22.  
- **Project:** The batch is associated with a `Project` as the 2026-04-22 flow already does (title from modal, etc.). All pages in the site link to that project.  
- **Deduplication / `content_sha256`:** The 2026-04-22 rules apply. If a duplicate is skipped, microsite **integrity** may be broken; **v1 behaviour:** if any file in a microsite batch is skipped as duplicate, the batch should **fail** or the implementation should document **fail-closed** vs partial site — **spec decision:** v1 **fail the microsite import** (or re-queue) if dedupe would drop any page in a strict set, and surface a clear error: *“Microsite import requires all files to be new; duplicate file: …”.* Adjust if product prefers partial sites (document in plan). **Decision for spec:** **Fail closed** for the whole microsite batch if any file would be skipped as duplicate, so the tree and link graph stay consistent.  
- **New tables (v1):** Prefer **no** new table; group identity is the root `thought` id. If a future index is needed, add a follow-up.

## 5. Link rewriting (v1)

**In scope:** Markdown links in `text` (and optionally reference-style) where the target is another `*.md` that corresponds to a **fellow page in the same batch** (by relative path: `./02-findings.md`, `../peer/01-x.md` only if in selection — **v1:** only **same-import** file resolution, normalised after path canonicalisation; reject or leave unchanged cross-folder paths that escape the set).

**Mechanism:** During import, before persisting `content` on each `Thought`, run a pass that:  
1) Resolves each relative `.md` target to a path segment / page `thought` in the set;  
2) Replaces the URL with:  
- **In-app** canonical: pattern under `idea.research` (e.g. `/research/{root}?page={path_segment}` or `.../p/{path_segment}` — one scheme only);  
- For **public share**, the stored markdown can either store **one** form that works for both, or the share renderer rewrites a neutral internal `ideatub:` reference — **v1 decision:** **store rewritten absolute paths for in-app** and on share view **additionally** rewrite known internal prefixes to `/r/{token}/...` **or** store a **portable** relative fragment like `?page=00-summary` and resolve in both contexts in the view layer. The implementation plan must pick one approach; **recommendation:** keep **one** `page_path_segment` in the URL in both **relative** to root resource: `GET` root without segment = first page, `GET` with `page=path_segment` or subpath. Public: `/r/{token}` and `/r/{token}/p/{page_path_segment}` (new routes), same controller family as `SharedResearchViewController` today.

**Out of scope (v1):** Local images (`![](./x.png)`), other assets, and links to `*.md` **not** in the import set — leave as-is; post-import **warning** in batch summary: *“N local asset references; not included in v1.”*

## 6. In-app and shared UI (same experience)

- **Layout:** A **section navigation** lists all pages (flat list) in import order, labels from **first heading** in the page’s markdown, falling back to a **humanised** `page_path_segment`. **Current** page is highlighted.  
- **In-app** route: Extend `IdeaController::showResearch` (or dedicated action) to accept an optional page selector; when `document_layout` is `microsite`, render the **paged** template, not the stacked single-scroll of root + all sections.  
- **Public** route: `GET /r/{token}` shows the root’s default page (first/only landing); `GET /r/{token}/p/{page_path_segment}` shows a specific child page, same layout. Re-use password gate from existing shared flow; **one** cookie for the site. **Route names** may stay under `shared-research.*` with a second named route for page, or a single route with optional segment — plan decides.  
- **Prose** classes: Reuse the same `prose` + heading/link styles as `resources/views/idea/partials/research_content.blade.php` and `shared_research/readonly.blade.php` so the microsite is visually consistent.  
- **Comments:** **Per-page** where comments attach to a `thought` (each page is a thought) — the existing model maps naturally. Root-level comments = comments on the index `Thought`. **No** separate “one thread for whole site” in v1.

## 7. Relationship to 2026-04-22 import batch flow

- **Chunking** checkbox: **N/A** for microsite; hidden or disabled when the branch is active.  
- **AI metadata / tags** options: Inherit or simplify per product; default: same as batch, but do not split on headings.  
- **Batch progress / Import page** (`/imports/{batch}`): Per-file status remains valid (one file → one page thought). **Final destination link** in UI: open the **microsite root** research URL (or first failed file).  
- **Notifications** copy: Mention “research site of N pages” when microsite, not just “N thoughts”.

## 8. Error handling and edge cases

- **One file** matching the pattern: **not** a microsite (classic import).  
- **Gaps in numbers** (`00-`, `03-` but no `01-`): still valid; order by numeric prefix; links to missing `02-…` are unresolved and stay raw or are logged in import notes.  
- **Duplicate title/slug in filenames:** The `page_path_segment` is the full basename; users must not duplicate basenames. If collision: **server rejects** batch at validation.  
- **Max files / size:** Inherit 2026-04-22 limits; microsite is still a batch.

## 9. Security

- Inherits sanitisation, MIME checks, and markdown safety from 2026-04-22 and the existing `tests/Feature/Rendering/MarkdownSafetyTest.php` (or equivalent) policy.  
- Link rewriter must not emit `javascript:` or inject raw HTML. Only rewrite to **application-controlled** path patterns.

## 10. Testing (acceptance)

- **Branching:** All-strict `NN-… .md` (≥2 files) → microsite; add one `.txt` or unnumbered `notes.md` → **classic** path.  
- **Order + root:** Numeric sort and `parent_id` to minimum-number page.  
- **Rewritten links:** `[]()` between two pages opens the correct in-app and shared page.  
- **Share password:** Unlocks all `/r/{token}/p/...` for that token.  
- **Layout:** In-app and shared use the same structure (nav + content); snapshot or integration tests for key fragments.

## 11. Open items for the implementation plan only (not TBD in product)

- Exact Laravel route list and `route()` names.  
- Whether dedupe “fail closed” is relaxed in a follow-up.  
- Analytics / logging for microsite imports (optional).

---

*Self-review: scope is a single import branch + paged read surfaces; public URL shape uses `/r/{token}/p/{page_path_segment}`; one duplicate-basename rule; v1 defers assets; fail-closed dedupe for microsite keeps page trees and links consistent; single `NN-… .md` uses classic import; filename pattern is one or more leading digits, separator, non-empty remainder.*
