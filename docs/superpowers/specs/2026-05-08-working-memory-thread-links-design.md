# Design: Working memory section rows with thought links (A + C)

**Status:** Approved — implemented  
**Date:** 2026-05-08  
**Depends on:** [`2026-05-08-working-memory-index-design.md`](2026-05-08-working-memory-index-design.md) (orthogonal; index unchanged)

## Problem

`active_threads_json` (and related legacy lists) store **display strings only** (`title` / `question` / `action`). The Memory UI cannot render links to source thoughts. Composer-authored content already requires **citations per bullet**, but `payloadFromStructuredSections()` collapses bullets to **text only**, dropping citation identity.

## Goal

1. **(C)** When working memory is built from **composer structured sections**, derive **optional link targets** for each legacy list row from that bullet’s **citations** (same evidence contract as authoring validation).
2. **(A)** When built from **heuristic assembly** (no structured sections) or **insights synthesis**, attach **`thought_id`** when the row clearly corresponds to a single `Thought`.

Rows **without** a resolvable target remain **plain text** (backward compatible).

## Non-goals

- Replacing legacy JSON columns with full structured-bullet mirrors for every section.
- Guaranteeing a link on every row (synthetic placeholders and multi-source bullets may stay unlinked).
- Changing composer citation validation rules beyond what is needed to expose already-valid citations on legacy lists.

## Data shape (persisted JSON)

Extend items in `active_threads_json`, `open_questions_json`, and `next_actions_json`:

| Field | Required | Description |
| --- | --- | --- |
| Existing string field (`title`, `question`, `action`) | Yes | Unchanged semantics. |
| `thought_id` | No | UUID string when the primary source is a thought. |
| `url` | No | App path or absolute URL for the detail link; may be derived server-side from `thought_id` via `route('thoughts.show', …)` so clients need not construct routes. |

**Precedence for consumers:** If `url` is present, use it; else if `thought_id` is present, build or request link server-side; else render plain text.

**Compatibility:** Older rows stay valid (only string keys). New builds may add optional keys.

## (C) Composer / structured-section path

Source: `structured_sections_json` after composer output is normalized (bullets with `text`, `citations[]`).

For each legacy list, map from the matching section:

| Legacy list | Structured section |
| --- | --- |
| `active_threads` | `Recent Changes` |
| `open_questions` | `Open Questions` |
| `next_actions` | `Next Actions` |

**Primary citation resolution** (per bullet, first match wins):

1. Citation with `type === 'thought'` and a usable `url` or `thought_id`.
2. Any citation whose `url` path matches app thought detail (`/thoughts/{uuid}`) — extract UUID.
3. Otherwise no link for that row (bullet may cite only compactions or external URLs).

**Title/text:** Continue using the bullet’s `text` (truncation rules unchanged).

**Duplicates:** Same section-level dedupe as today (by text); if two bullets collapse to one title, first wins for link attachment.

## (A) Heuristic assembler path

`WorkingMemoryAssembler::assemblePayload`: build thread/question/action rows from **`Thought` models**, not from `pluck('content')` alone:

- **Active threads:** Prefer one row per selected thought with `title` = existing truncation of content and `thought_id` = `$thought->id` (string UUID). Preserve limits (`take(5)` / uniqueness) on **thought ids** or stable titles as today.
- **Open questions:** Restrict to thoughts whose content contains `?`; attach `thought_id`.
- **Next actions:** Either exclude thoughts already classified as questions, or use the same pool but dedupe by `thought_id` across sections so one thought does not dominate every list (product choice: **prefer partitioning**: question → open_questions only; else eligible for threads/actions — specify in implementation).

**Insights** (`MemoryInsightsService::synthesizePersistable`): `active_threads` titles are already derived from ordered thoughts — persist **`thought_id`** alongside each title from the same thought instance.

## API and UI

- **REST / Inertia payload:** Pass through optional `thought_id` and `url` on each list item wherever working memory is serialized for the Memory UI.
- **Rendering:** Active threads (and optionally questions/actions) render as **links** when `url` or `thought_id` is present; accessibility: link text = title/question/action string.

## Testing

- **Feature/unit:** Composer-shaped structured sections with a thought citation produce `active_threads` rows including `thought_id` or `url`.
- **Feature/unit:** Assembler path produces linked rows for synthetic thought fixtures.
- **Regression:** Rows without optional keys still serialize and render as plain text.

## Future

- Extend the same resolution to **key concepts** if those bullets gain citations.
- Stream deep-links for compaction-only citations (`type: compaction`) using existing compaction URLs from evidence.
