# IdeaTub — Research unread comment count (denormalized + reconcile)

**Date:** 2026-04-21  
**Status:** Implemented  
**Scope:** Replace per-request SQL recomputation of research document unread comment counts with a stored **`unread_count`** on **`thought_comment_reads`**, maintained on comment create/delete with parity to current rules, plus a **scheduled reconcile** job to correct drift.

## Overview

Today **`ResearchCommentsPresenter::unreadCount()`** counts qualifying **`comments`** on the research root and its section thoughts after **`last_read_at`**, excluding the owner’s own comments. That query runs on every page load that needs the count.

This spec adds **`unread_count`** (unsigned integer) to **`thought_comment_reads`** for rows where **`thought_id`** is the **research root**, updates it in **listeners** when comments are created or deleted (so behavior matches **A**), resets it in **`markRead()`**, reads it in the presenter, and runs a **nightly (or similar) reconcile** job (**C**) that recomputes from SQL and fixes mismatches.

## Goals

- **Read path:** Unread count for the research detail page comes from a **single column read** (no aggregate query on normal load).
- **Write path:** Create/delete handlers keep **`unread_count`** aligned with the **same semantics** as the current `unreadCount()` implementation.
- **Repair path:** A **scheduled** job recomputes counts from SQL and updates rows where values differ (drift, bugs, rare races).

## Non-goals

- Changing **who** sees unread (v1 remains **document owner** as the consumer of this counter for the research banner; **`user_id`** on **`thought_comment_reads`** still supports future readers).
- Redis-only counters or replacing **`last_read_at`** (both remain; **`markRead`** continues to set **`last_read_at`** and zero **`unread_count`**).
- Optimizing section-level comment **lists** (separate from this unread indicator).

## Current semantics (must be preserved)

For viewer **`V`** and research root **`R`** (owner **`O = R.user_id`**):

- **Comment set:** `commentable_id` in **`{ R.id } ∪ { child thought ids with parent_id = R.id }`**, `commentable_type = thought`.
- **Author filter:** Count only where **`author_user_id` is null OR `author_user_id <> V.id`** (for the owner viewing their page, **`V = O`**, so owner’s own comments are excluded).
- **Time filter:** If a **`thought_comment_reads`** row exists with **`last_read_at`**, only comments with **`created_at > last_read_at`**. If **`last_read_at`** is null (no row or column not yet set), count **all** qualifying comments (matches current query when **`last_read_at`** is missing from the subquery result).

**Note:** Implementers should re-read **`ResearchCommentsPresenter::unreadCount()`** and tests (**`UnreadCommentIndicatorTest`**, **`ResearchCommentsPresenterTest`**) when coding; this spec is the product intent, not a line-for-line duplicate of SQL.

## Data model

### `thought_comment_reads`

Add:

| Column         | Type            | Notes |
|----------------|-----------------|--------|
| `unread_count` | unsigned int    | Default **0**. Number of comments that currently qualify as “unread” for **`(user_id, thought_id)`** per semantics above. |

**Primary key** remains **`(user_id, thought_id)`**.

**Invariant (target):** For research roots **`R`** and owner **`O`**, the row **`(user_id = O, thought_id = R)`** should satisfy: **`unread_count`** equals the result of the **canonical recompute query** (same as today’s presenter logic).

## Behavior

### `ThoughtCommentRead::markRead($userId, $thoughtId)`

Extend upsert to also set **`unread_count = 0`** whenever **`last_read_at`** is updated (research page load and any other caller of **`markRead`** for that pair).

### Comment created (listener)

1. Load **`Thought`** for **`commentable`**; ensure morph type is **`thought`**.
2. Resolve **research root** **`R`** for that thought (walk **`parent_id`** until a root; root must be a **research** document per existing app rules—centralize in e.g. **`ResearchRootResolver::forThought(Thought): ?Thought`** or equivalent).
3. If not under a tracked research root, **return**.
4. Let **`O = R.user_id`**. If **`comment.author_user_id === O`**, **return** (owner’s own comment does not increase unread for self).
5. Determine whether this comment **would** be counted for **`O`**: **`last_read_at`** for **`(O, R)`** is null **OR** **`comment.created_at > last_read_at`** (use the row’s **`last_read_at`** after load; if **no row**, treat as null for this decision).
6. If it would count, **atomically increment** **`unread_count`** on **`(O, R)`** (create row if missing: **`last_read_at`** may remain null until **`markRead`**, or follow one explicit rule in implementation notes below).

### Comment deleted (listener)

1. Same root resolution and owner self-comment check **on the deleted comment snapshot** (listener receives model before delete or uses stored attributes).
2. If the comment **would have** been counted (**same time/author rules** as create), **decrement** **`unread_count`** with floor at **0** (atomic **`GREATEST(0, unread_count - 1)`** or equivalent).

### Presenter

**`ResearchCommentsPresenter::unreadCount()`** should return **`unread_count`** from **`thought_comment_reads`** for **`(viewer->id, root->id)`** when **`viewer`** is non-null.

**Rollout:** Optional short-lived **fallback**: if row missing, run **legacy SQL once** and optionally **upsert**—only if needed for zero-downtime; prefer **backfill migration** so row always exists for active research roots.

### Scheduled reconcile (C)

- **Command** (e.g. **`php artisan research:reconcile-comment-unread-counts`**) recomputes the canonical count per **`(user_id, thought_id)`** for research roots in scope (e.g. all roots with **`metadata`** type research, or all rows in **`thought_comment_reads`** tied to research roots—pick one efficient scope in implementation).
- **`UPDATE`** **`unread_count`** where **computed ≠ stored**; optional **log** when **`diff != 0`**.
- **Schedule:** nightly by default; configurable.

## Backfill

- **Migration or artisan command** after deploy: set **`unread_count`** using the **same SQL** as the canonical recompute for each existing **`thought_comment_reads`** row that applies to research roots; **insert** missing **`(O, R)`** rows only if required by product (otherwise **0** until first comment event).

## Testing

- **Unit tests** for increment/decrement rules (owner vs guest, before/after **`last_read_at`**, delete of counted vs uncounted comment).
- **Feature tests:** extend **`UnreadCommentIndicatorTest`** / **`ResearchCommentsPresenterTest`** so behavior remains unchanged from a user perspective.
- **Reconcile command test:** inject a deliberate wrong **`unread_count`** and assert fix.

## Risks and mitigations

| Risk | Mitigation |
|------|------------|
| Wrong research root resolution | Single shared resolver + unit tests |
| Race on concurrent comments | Atomic **`increment`** / conditional decrement |
| Drift | **C** reconcile job |
| `markRead` callers | Audit **`markRead`** usages; all must zero **`unread_count`** |

## Implementation follow-up

- After this spec is approved: use **writing-plans** for task breakdown (migrations, observers, presenter, command, schedule, tests).
- Optional later: extend counter to **non-owner** readers using the same table (**`user_id`** = reader).
