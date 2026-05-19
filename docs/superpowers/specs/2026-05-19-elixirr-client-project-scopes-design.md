# Elixirr Client ↔ IdeaTub Project Scopes

**Date:** 2026-05-19  
**Status:** Approved (brainstorming 2026-05-19)  
**Scope:** IdeaTub projects, working memory, Stream grouping, MCP/REST discovery; Elixirr `elixirr-sync` skill and sync script  
**Related:** [2026-05-18-working-memory-hybrid-external-first-design.md](./2026-05-18-working-memory-hybrid-external-first-design.md), [2026-05-12-working-memory-parity-design.md](./2026-05-12-working-memory-parity-design.md), [2026-05-08-working-memory-index-design.md](./2026-05-08-working-memory-index-design.md), [2026-05-05-working-memory-design.md](./2026-05-05-working-memory-design.md)

## Problem

Elixirr sync publishes client-level and project-level `working-memory/current.md` into IdeaTub. Today:

1. **Memory index** lists every `scope_type=project` row flat under **Projects**. Client-level memory (e.g. Dezeen) and subproject memory (e.g. Dezeen / Foo) look like unrelated peers; there is no **Clients** grouping.
2. **Stream / corpus roll-up** splits across mechanisms:
   - `client:dezeen` is a **tag** (Stream filter, optional `tag` working-memory scope).
   - Client canonical memory uses **`project` scope** with `scope_key` = slug `dezeen` or IdeaTub project UUID.
   - Subproject captures use `source_metadata.project` = `dezeen/foo` (composite slug).
   - Project-scope evidence matching is **exact** on `scope_key`; subproject thoughts do not contribute to client scope; tag-only thoughts do not contribute to project scope `dezeen`.
3. **`client:*` is not a working-memory scope type** in IdeaTub (`global`, `project`, `insights`, `tag` only). Operators conflate the tag `client:dezeen` with a client scope.

**User goals (confirmed):**

- **(A)** Visible **Clients** grouping in the memory index (client → nested subprojects).
- **(C)** Captures tagged `client:<slug>` and scoped to subprojects **roll up** sensibly to client-level working memory and Stream navigation.
- **(2B)** Each Elixirr subproject maps to a **separate IdeaTub Project** (not composite slug `dezeen/foo` as `scope_key`).

## Non-Goals

- Adding `scope_type=client` to the working-memory normalizer (client identity is represented by the **client root IdeaTub Project**).
- Mirroring the client name as a duplicate IdeaTub project solely for display.
- Auto-creating IdeaTub projects from Elixirr folder names without explicit linking (v1).
- Replacing `elixirr-memory-refresh` or making IdeaTub the sole author of client memory.
- Full Slack backfill (see parity spec phase 3).

## Decisions

| Topic | Decision |
|-------|----------|
| Elixirr client | One **client root** IdeaTub `Project` per `elixirr_client_slug` |
| Elixirr subproject | One **child** IdeaTub `Project` per `elixirr_project_slug`, `parent_project_id` → client root |
| WM `scope_type` | Remains `project`; `scope_key` = **project UUID** (lowercased) for upsert and canonical read |
| Composite slug keys | **Deprecated** for new sync (`dezeen/foo` as `scope_key`); migrate existing rows if present |
| `client:<slug>` tag | Kept on captures for Stream; contributes to **client root** project-scope roll-up; not a separate WM bucket |
| UUID discovery | **`list_projects`** MCP + REST; Elixirr **`ideatub-scope.json`** cache written by sync |
| Memory index | **Clients** section with nested subproject rows; non-Elixirr projects under **Other projects** |

## Identity Model (2B)

### Elixirr → IdeaTub mapping

| Elixirr path | IdeaTub entity | WM `upsert` `scope_key` |
|--------------|----------------|-------------------------|
| `clients/<client>/working-memory/current.md` | Client root `Project` | Root project UUID |
| `clients/<client>/projects/<project>/working-memory/current.md` | Child `Project` | Child project UUID |

### Project schema additions

Add to `projects`:

| Column | Type | Rules |
|--------|------|--------|
| `parent_project_id` | UUID, nullable FK → `projects.id` | `null` on client root; set on subprojects |
| `elixirr_client_slug` | string, nullable, indexed | e.g. `dezeen`; required for Elixirr-linked projects |
| `elixirr_project_slug` | string, nullable | `null` on client root; e.g. `foo` on subprojects |

**Invariants:**

- At most one client root per `(user_id, elixirr_client_slug)` where `parent_project_id` is null and `elixirr_project_slug` is null.
- Subprojects: `parent_project_id` = client root id; `elixirr_client_slug` matches parent; `elixirr_project_slug` non-null.
- A child must not be parent of the client root (no cycles; max depth 2 for v1).

### Capture metadata (unchanged human slugs)

`capture_plan` / `capture_meeting` continue to use readable `project` metadata:

- Client-wide captures: `project: <elixirr_client_slug>` (e.g. `dezeen`).
- Subproject captures: `project: <elixirr_client_slug>/<elixirr_project_slug>` (e.g. `dezeen/foo`).

Tags (required on Elixirr sync):

- Always: `working-memory`, `working-memory:<date>`, `client:<client>`, `scope:client|project`.
- Subprojects: add `project:<elixirr_project_slug>`.

**Linking:** After capture, attach thoughts to the resolved IdeaTub project via `project_thought` when sync or import knows the UUID mapping (see Sync pipeline).

### Working memory scope keys

| Operation | `scope_type` | `scope_key` |
|-----------|--------------|-------------|
| `upsert_working_memory` | `project` | IdeaTub project UUID |
| `get_working_memory` | `project` | Same UUID |
| Legacy slug scopes | `project` | Existing `dezeen` / `dezeen/foo` rows may remain until migrated |

**Strict validation (optional config):** Reject `upsert_working_memory` with `source_label=elixirr-sync` when `scope_type=project` and `scope_key` is not UUID-shaped.

## Client-Scope Evidence Roll-Up

Applies when building evidence packs, incremental overlays, and consolidated rebuilds for the **client root** project scope (not for external canonical markdown, which comes from `upsert`).

Include a thought in client root scope if **any** of:

1. Linked to the client root project (`project_thought`).
2. Linked to any child project whose `parent_project_id` is that root.
3. Tag `client:<elixirr_client_slug>` in `metadata.tags` (normalized lowercase).
4. `source_metadata.project` equals `<elixirr_client_slug>` exactly (legacy client-wide captures).

**Subproject scope** (child project UUID): include only thoughts linked to that child project, or `source_metadata.project` equal to `<client>/<project>` exactly.

Do **not** register `client:<slug>` as a standalone `tag` working-memory scope for consolidation unless the user has explicitly forced that tag for topic memory.

## Project Discovery API

### Why

Sync and agents need stable UUIDs for `upsert_working_memory`. There is no MCP method to list projects today. Manual UUID copy drifts when projects are created in the IdeaTub UI.

### `list_projects`

**MCP method:** `list_projects`  
**REST:** `GET /api/projects` (OAuth / MCP auth, same user scope as other thought APIs)

**Query parameters (optional):**

| Param | Description |
|-------|-------------|
| `elixirr_client_slug` | Filter to one client tree |
| `parent_project_id` | Filter children of a root |

**Response item:**

```json
{
  "id": "019e0705-5591-73e9-be2e-0fb9c86b269a",
  "title": "Dezeen",
  "elixirr_client_slug": "dezeen",
  "elixirr_project_slug": null,
  "parent_project_id": null
}
```

Child example: `elixirr_project_slug: "foo"`, `parent_project_id: "<root-uuid>"`.

### Elixirr cache file

Path: `clients/<client>/ideatub-scope.json` (written by sync script, gitignored or committed per operator preference).

```json
{
  "client_project_id": "019e0705-5591-73e9-be2e-0fb9c86b269a",
  "projects": {
    "foo": "019e0705-xxxx-xxxx-xxxx-xxxxxxxxxxxx"
  },
  "resolved_at": "2026-05-19T12:00:00Z"
}
```

**Sync resolution order:**

1. Read `ideatub-scope.json` if present and complete for the target paths.
2. Else call `list_projects` (or REST), match slugs, write cache.
3. If slug missing: fail with actionable error (link or create project in IdeaTub and set `elixirr_*_slug` fields).

Routine sync should use the cache; discovery runs on first sync, new subproject folder, or `--refresh-mapping`.

## Memory Index UI (A)

Replace flat **Projects** section in `WorkingMemoryScopesIndexBuilder` / `memory/scopes/index` with:

### Clients

- One group per distinct `elixirr_client_slug` found among project-scoped memories and/or linked `Project` records.
- Rows:
  - **Client root** — title from linked Project or readable client slug; href `projects.memory.show` for root UUID.
  - **Indented subprojects** — child projects with matching parent; same freshness/badge fields as today.

### Other projects

- Project-scoped memories whose `scope_key` is a UUID with no `elixirr_client_slug` on the linked Project, or slug-only legacy keys not yet migrated.

Global, Insights, and Tags sections unchanged.

## Stream (C)

- **Client stream:** `/stream?tag=client-dezeen` (canonical tag slug rules apply).
- Memory index client row may link to Stream with that tag (secondary nav).
- Subproject-specific stream: optional `project:<slug>` tag filter in addition to client tag.

Roll-up for WM is defined above (project graph + `client:` tag); Stream filtering remains tag/metadata based.

## Sync Pipeline (`elixirr-sync`)

Update `scripts/sync_project_working_memory_to_ideatub.py` and skill docs:

1. Resolve UUIDs via cache + `list_projects`.
2. `capture_plan` — unchanged slug `project` field and tags.
3. `upsert_working_memory` — `scope_key` = resolved UUID; `source_label` = `elixirr-sync`.
4. Optional post-capture: attach recent snapshots to projects when UUID known (batch or per-upload agent step).

Remove emission of composite `scope_key` in new upserts (`idea_project_key` slug form for upsert only; capture metadata may keep `dezeen/foo`).

## Migration

1. **Data:** For each existing `working_memories` row with `scope_type=project` and non-UUID `scope_key` matching `dezeen` or `dezeen/foo`, map to root/child project UUID via new Project slug columns or manual registry; update `scope_key` or create new WM row and retire old.
2. **Projects:** Create/link Dezeen root + subprojects in IdeaTub UI; set `elixirr_client_slug` / `elixirr_project_slug`.
3. **One-time upsert:** Run client `current.md` upsert to root UUID per hybrid spec.

## Implementation Touchpoints (IdeaTub)

| Area | Change |
|------|--------|
| Migration | `parent_project_id`, `elixirr_client_slug`, `elixirr_project_slug` on `projects` |
| `Project` model | Relationships, fillable, validation |
| `McpController` | `list_projects` dispatch + tool schema |
| `routes/api.php` | `GET /api/projects` |
| `WorkingMemoryBuilderService` | Hierarchical roll-up for client root UUID scopes |
| `WorkingMemoryScopesIndexBuilder` | Clients / Other projects sections |
| `resources/views/memory/scopes/index.blade.php` | Nested subproject layout if not driven entirely by builder |
| Tests | Feature tests for list API, roll-up filtering, index grouping |

## Implementation Touchpoints (Elixirr, out of repo)

| Area | Change |
|------|--------|
| `elixirr-sync` SKILL.md | UUID resolution, cache file, `list_projects` step |
| `sync_project_working_memory_to_ideatub.py` | Mapping resolution; upsert uses UUID |
| Client onboarding | Create/link IdeaTub projects + slugs; initial `ideatub-scope.json` |

## Phases

| Phase | Deliverable |
|-------|-------------|
| **1** | Project schema + `list_projects` MCP/REST + manual slug assignment in UI |
| **2** | WM roll-up rules + memory index Clients section |
| **3** | Sync script + skill + cache; migrate Dezeen scopes; deprecate composite `scope_key` |

## Acceptance Criteria

1. Memory index shows **Dezeen** as a client with nested subprojects, not a flat list of unrelated project titles.
2. `get_working_memory` for Dezeen root UUID returns external canonical from `upsert`; overlay/evidence includes meetings/automations tagged `client:dezeen` and thoughts linked to child projects.
3. Subproject WM at child UUID does not include sibling subproject thoughts.
4. Sync script completes using `ideatub-scope.json` or `list_projects` without hand-pasting UUIDs on every run.
5. `upsert_working_memory` from Elixirr uses UUID `scope_key` only.

## Risks

| Risk | Mitigation |
|------|------------|
| UUID mapping drift | Cache refresh flag; `list_projects` filter by `elixirr_client_slug` |
| Legacy slug WM rows | One-time migration command or admin script |
| Duplicate client roots | DB unique partial index on `(user_id, elixirr_client_slug)` where `parent_project_id` is null |
| Thoughts only tagged, not linked | Roll-up rule (3) includes `client:` tag for client root only |

## Open Questions (deferred)

- Auto-create IdeaTub child project when Elixirr folder appears (v2).
- `create_project` MCP for headless bootstrap (v2).
- Depth > 2 in project tree (out of scope v1).
