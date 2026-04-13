# IdeaTub — Projects, typed thought links, and shared project views

**Date:** 2026-04-13  
**Status:** Implemented  
**Scope:** Named projects (many-to-many with thoughts), directed typed links between thoughts, project hub + graph (members-only in v1), and public sharing (token + optional password + optional expiry) aligned with existing research shares.

## Overview

- **Projects:** First-class containers. A thought may belong to **zero or more** projects. Membership is **explicit** (add/remove), with a per-project **`sort_order`** for hub and “Read all” ordering.
- **Typed links:** Directed edges between thoughts (`from` → `to`) with a **fixed enum** of `link_type` in v1 and optional **note** text. Shown on thought detail and in a **project graph** view.
- **Graph (v1):** **Members-only** — nodes are thoughts in the project; edges are `thought_links` where **both** endpoints are in that project’s member set. No “include linked neighbors outside the project” in v1.
- **Sharing:** **Live membership** — public hub / read-all / per-item URLs only show thoughts that are **currently** in the project. Access model matches **`ResearchShare`**: unguessable token, optional password, optional expiry, read-only.

---

## 1. Data model

### 1.1 `projects`

| Column        | Type        | Notes |
|---------------|-------------|--------|
| `id`          | uuid, PK    | |
| `user_id`     | FK users    | Owner |
| `title`       | string      | Required |
| `description` | text, nullable | **Markdown**; render consistently on hub and public share (same sanitizer/pipeline as safe thought excerpts where applicable) |
| `deleted_at`  | timestamp, nullable | **Soft delete**; hidden from lists and share resolution |
| `timestamps`  | | |

### 1.2 `project_thought` (pivot)

| Column        | Type        | Notes |
|---------------|-------------|--------|
| `project_id`  | FK projects | Part of composite primary key |
| `thought_id`  | FK thoughts | Part of composite primary key; must belong to **same `user_id`** as `project.user_id` (enforce in app + DB constraint if practical) |
| `sort_order`  | integer     | Unique per `project_id`; gap-friendly ordering (e.g. reorder by rewriting orders) |
| `timestamps`  | | |

**Primary key:** `(project_id, thought_id)`.

### 1.3 `thought_links`

| Column             | Type        | Notes |
|--------------------|-------------|--------|
| `id`               | uuid, PK    | |
| `user_id`          | FK users    | Owner (both endpoint thoughts must match this user) |
| `from_thought_id`  | FK thoughts | |
| `to_thought_id`    | FK thoughts | |
| `link_type`        | string/enum | One of fixed v1 types (§2) |
| `note`             | text, nullable | Short context |
| `timestamps`       | | |

**Uniqueness (v1):** At most **one** row per `(from_thought_id, to_thought_id, link_type)`. Attempting to create a duplicate returns a validation error.

**Self-loops:** **Disallowed** (`from` ≠ `to`).

### 1.4 `project_shares`

Mirror `research_shares` shape, bound to a project:

| Column          | Type        | Notes |
|-----------------|-------------|--------|
| `id`            | bigserial   | |
| `user_id`       | FK users    | Creator/owner |
| `project_id`    | FK projects | |
| `token`         | string, unique | URL-safe, unguessable length consistent with research shares |
| `password_hash` | string, nullable | Same hashing as `ResearchShare` |
| `expires_at`    | timestamp, nullable | |
| `timestamps`    | | |

**Invalidation:** Deleting a share row or rotating token **revokes** access. **Soft-deleted** projects: resolve share as **404** (no content).

---

## 2. Link types (v1, fixed enum)

Implement as PHP enum or validated string list; single source of truth.

| `link_type`    | Meaning (directed: from → to) |
|----------------|-------------------------------|
| `relates_to`   | General association |
| `spawned_from` | From was created/spawned from to (idea lineage) |
| `supports`     | From supports or backs up to |
| `contradicts`  | From conflicts with to |
| `supersedes`   | From replaces or supersedes to |

**Symmetric UX:** For `relates_to`, UI may offer “reverse direction” or show as undirected visually; storage remains directed.

**Future:** Custom link types — out of scope for v1.

---

## 3. Owner UI (authenticated)

### 3.1 Projects index

- List non-deleted projects for current user: title, optional snippet from description, `updated_at`.
- Create project (title + optional description).
- Edit title/description; **soft delete** (archive) with confirm copy.

### 3.2 Project workspace (detail)

- **Members:** Search/picker to add thoughts (only own thoughts). List members in **`sort_order`**; remove member; **reorder** (drag-and-drop **or** explicit up/down — implementation choice; drag preferred if stack allows).
- **Links:** Do not require a separate “project links” editor for v1 — links are **global per user** between thoughts; they **appear** on the graph when both ends are in the project.
- **Graph:** Route e.g. `/projects/{project}/graph` or tab on detail. **Members-only** nodes and edges (§Overview). Read-only graph in v1 is acceptable; **creating links from the graph** is optional stretch.
- Navigation: from thought detail, show **project chips** (memberships) + **Add to project**; show **incoming / outgoing links** grouped by type with **Add link** (target picker + type + optional note).

### 3.3 Share management (owner)

- Same operational pattern as research document share: create link, optional password, optional expiry, copy URL, list active shares, revoke.
- Applies to the **project** as a whole (not per-thought share inside the project for this feature — existing per-thought `ResearchShare` remains independent).

---

## 4. Public routes (unauthenticated read-only)

Password and cookie behavior **mirror** `shared-research` (GET challenge, POST verifies, cookie name pattern analogous to `research_share_{token}`).

| Route | Purpose |
|-------|---------|
| `GET /shared/projects/{token}` | **Hub:** project title, description, ordered list of members (snippet/title); each item links to per-item URL |
| `GET /shared/projects/{token}/read` | **Read all:** single page, blocks in `sort_order`, each block title + rendered content |
| `GET /shared/projects/{token}/thoughts/{thoughtId}` | **Item:** readonly thought detail if `thoughtId` is in the project **now** |

**404:** Invalid token, expired share, soft-deleted project, or `thoughtId` not a member.

**Rate limiting:** Align with or reuse shared research limits.

---

## 5. Authorization and policies

- **Projects, pivot, thought_links:** Only the owning `user_id` may create/update/delete/query for mutation.
- **Thought membership:** Adding a thought to a project requires `thought.user_id === project.user_id`.
- **Thought links:** Both endpoints must belong to the same user as `thought_links.user_id`.
- **Public share:** No auth; scope strictly to data derivable from `project_shares.token` + membership at read time.

---

## 6. Testing (Pest)

- **Projects:** Create, update, soft delete; list excludes deleted.
- **Membership:** Add/remove/reorder; cannot add another user’s thought; unique pivot enforced.
- **Links:** Create with each type; duplicate `(from, to, type)` rejected; self-loop rejected; cross-user endpoints rejected.
- **Shares:** Hub and read-all and item return 200 with valid token; wrong password; expiry; revoked token 404; **removed member** no longer appears on hub and item URL 404s.
- **Share + delete project:** Soft-deleted project → shared URLs 404.

---

## 7. MCP

**Deferred v1.** Optional follow-up: `add_thought_to_project` (by project id or slug) if product needs capture-from-Cursor workflows.

---

## 8. Non-goals (v1)

- Collaborative / multi-user projects
- Custom link types or user-defined ontologies
- Graph including thoughts **outside** the project (no neighbor toggle)
- Versioned or frozen snapshots of shared content (membership is **live**)
- Replacing or merging with `ResearchShare` for single research thoughts

---

## 9. Implementation notes

- Reuse patterns from `ResearchShare`, `SharedResearchController` (or equivalent), and `ThoughtPolicy` for ownership checks.
- Consider index on `project_thought (project_id, sort_order)` and `thought_links (user_id, from_thought_id)` / `(user_id, to_thought_id)` for graph edge queries.
- Frontend graph: choose a small client library (e.g. force-directed) or minimal SVG; must degrade gracefully if JS fails (hub + lists still work).
