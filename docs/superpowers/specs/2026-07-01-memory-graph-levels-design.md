# Memory graph — five levels of relationship visualization

**Status:** Approved  
**Date:** 2026-07-01  
**Owner:** Product + Engineering  

## Relationship to other specs

- Extends [`2026-04-13-projects-and-thought-links-design.md`](2026-04-13-projects-and-thought-links-design.md) (project graph v1, typed links, non-goals).
- Extends [`2026-04-13-thought-detail-actions-row-and-link-scoping-design.md`](2026-04-13-thought-detail-actions-row-and-link-scoping-design.md) (link UI on thought detail).
- Reuses semantic search patterns from `ThoughtSearchService`, `Thought::nearestWithin`, and learning-lesson “related thoughts”.
- Competitive context: Obsidian graph is wiki-link + tag driven; IdeaTub graph is **typed links + structure + tags + embeddings**, scoped to avoid hairballs.

## Problem

IdeaTub already stores rich relationships — explicit `thought_links`, parent/child chunking, tags, project membership, and vector embeddings — but only **project members + explicit links** are visualized today (`/projects/{project}/graph`). Users cannot:

- See local context around a thought while reading it (Obsidian “local graph”).
- Filter or layer relationship types on a project graph.
- Explore tag clusters visually.
- See semantic neighbors as a graph.
- Browse vault-wide structure with safe filters.

Search and Stream answer “find this thought”; a memory graph answers “how does this thought connect to everything else?”

## Goal

Ship a **shared graph stack** (service + JSON contract + vis-network UI) and five **progressive levels** of scope, each useful on its own:

| Level | Name | Scope | Primary edges |
|-------|------|-------|---------------|
| **1** | Local graph | One focal thought, 1–2 hops | `thought_links`, `parent_id` |
| **2** | Project graph (enhanced) | Project members + optional neighbors | `thought_links`, optional semantic, parent/child |
| **3** | Tag constellation | Thoughts sharing a tag (or tag set) | `shared_tag`, optional semantic |
| **4** | Semantic neighborhood | One focal thought, k-NN by embedding | `semantic` |
| **5** | Vault graph | Filtered subset of all thoughts | All layers, heavily capped |

Levels 1 and 4 can share a thought-detail surface (layer toggles). Level 2 upgrades the existing project graph. Levels 3 and 5 are new routes.

## Non-goals (all levels)

- Collaborative / multi-user graphs.
- Custom link types or user-defined ontologies (v1 enum unchanged).
- Editing links by dragging on the graph (read-only v1; link CRUD stays on thought detail).
- `[[wikilink]]` parsing from markdown content.
- Real-time graph updates (WebSockets); refresh on page load is fine.
- Graph as primary navigation replacing Stream or search.
- **Auto-creating** `thought_links` from background jobs (semantic similarity never mutates the curated link graph).
- **Pre-warming** or caching semantic edges for faster graph loads (deferred; on-demand k-NN in v1–v2).
- Storing computed semantic edges in `thought_links` or a graph cache table in v1.

## Resolved product decisions

| Topic | Decision |
|-------|----------|
| Client library | **vis-network** (already used on project graph); pin CDN version in follow-up |
| API shape | Shared JSON contract across all levels |
| Node identity | Thought UUID |
| Chunk visibility | **Default hide** `parent_id IS NOT NULL` unless `include_chunks=1` |
| Stream visibility | Respect `visibleInStream()` for levels 3–5; levels 1–2 use thought policy (owner can see own hidden) |
| Semantic edges (graph UI) | On-demand k-NN at read time; default `max_distance=0.45`, `k=8` per focal node |
| Background linking | **Suggestions-only** (v2): async neighbor discovery → promote to explicit link; never auto-write `thought_links` |
| Vault cap | Hard **200 nodes**, **500 edges**; response includes `truncated: true` |
| Feature flags | **Every level** behind its own toggle (see §Feature flags); all default **off** except project graph (already shipped) |
| Demo mode | Obfuscate node labels via existing `DemoObfuscator` patterns |

---

## Feature flags

Every graph level is **independently feature-flagged**. Disabled levels return **404** on routes and **hide all UI entry points** (nav links, thought-detail panels, stream “view as graph”, project graph tab). No half-enabled states.

Follows the existing pattern: `config/features.php` ← `FEATURE_*` env vars, `Ensure*Enabled` middleware (see `EnsureAttentionPulseEnabled`).

### Config (`config/features.php`)

| Config key | Env var | Default | Level |
|------------|---------|---------|-------|
| `memory_graph_local` | `FEATURE_MEMORY_GRAPH_LOCAL` | `false` | 1 — local graph on thought detail |
| `memory_graph_project` | `FEATURE_MEMORY_GRAPH_PROJECT` | `true` | 2 — project graph (baseline + enhancements) |
| `memory_graph_tag` | `FEATURE_MEMORY_GRAPH_TAG` | `false` | 3 — tag constellation |
| `memory_graph_semantic` | `FEATURE_MEMORY_GRAPH_SEMANTIC` | `false` | 4 — semantic neighborhood layer |
| `memory_graph_vault` | `FEATURE_MEMORY_GRAPH_VAULT` | `false` | 5 — vault graph |
| `memory_graph_suggestions` | `FEATURE_MEMORY_GRAPH_SUGGESTIONS` | `false` | v2 — background suggestion job + thought-detail UI (not a graph level) |

**Project graph default `true`:** `/projects/{project}/graph` already ships unflagged; default preserves current behaviour. Set `FEATURE_MEMORY_GRAPH_PROJECT=false` to hide it. All **new** levels and suggestions default `false`.

**Semantic flag (L4)** gates:
- Dedicated `/thoughts/{thought}/semantic-graph` routes.
- The **“Show similar thoughts”** layer toggle on Level 1 and Level 2 canvases (semantic edges are not rendered when off, even if requested via query param).

### Middleware

Register in `bootstrap/app.php`:

```php
'memory.graph.local' => EnsureMemoryGraphLocalEnabled::class,
'memory.graph.project' => EnsureMemoryGraphProjectEnabled::class,
'memory.graph.tag' => EnsureMemoryGraphTagEnabled::class,
'memory.graph.semantic' => EnsureMemoryGraphSemanticEnabled::class,
'memory.graph.vault' => EnsureMemoryGraphVaultEnabled::class,
```

Each middleware reads `config('features.memory_graph_*')` and `abort(404)` when false.

**Route layering:**
- Level 1 routes: `memory.graph.local`
- Level 2 routes: `memory.graph.project`
- Level 3 routes: `memory.graph.tag`
- Level 4 routes: `memory.graph.semantic` (and L4 layer on L1/L2 checks the same config in service/controller)
- Level 5 routes: `memory.graph.vault`

`ThoughtGraphService` accepts an optional `layers` list; controllers **strip** `semantic` (and other gated layers) when the corresponding flag is off, even if the client passes query params. Defence in depth alongside middleware.

### UI gating

| Entry point | Flag required |
|-------------|---------------|
| Thought detail “Connection graph” panel | `memory_graph_local` |
| Thought detail “Show similar” toggle | `memory_graph_local` + `memory_graph_semantic` |
| Project workspace → Graph link/tab | `memory_graph_project` |
| Project graph filter toolbar (enhancements) | `memory_graph_project`; semantic toggle also needs `memory_graph_semantic` |
| Stream tag → “View as graph” | `memory_graph_tag` |
| Thought tag chip → constellation | `memory_graph_tag` |
| Nav “Graph” (vault) | `memory_graph_vault` |
| Thought detail “Suggested links” block | `memory_graph_suggestions` |
| `/help/memory-graph` (new help page) | Always available; documents all flags |

Blade: `config('features.memory_graph_local')` etc., same pattern as Pulse in `layouts/idea.blade.php`.

### `.env.example`

```dotenv
# Memory graph levels (see /help/memory-graph)
FEATURE_MEMORY_GRAPH_LOCAL=false
FEATURE_MEMORY_GRAPH_PROJECT=true
FEATURE_MEMORY_GRAPH_TAG=false
FEATURE_MEMORY_GRAPH_SEMANTIC=false
FEATURE_MEMORY_GRAPH_VAULT=false
FEATURE_MEMORY_GRAPH_SUGGESTIONS=false
```

### Enabling during rollout

Operators can turn levels on incrementally in `.env` or hosting config:

1. `FEATURE_MEMORY_GRAPH_PROJECT=true` — already on by default (existing graph).
2. `FEATURE_MEMORY_GRAPH_LOCAL=true` — thought-detail neighborhood.
3. `FEATURE_MEMORY_GRAPH_SEMANTIC=true` — similar-thought edges on L1/L2.
4. `FEATURE_MEMORY_GRAPH_TAG=true` — tag constellation.
5. `FEATURE_MEMORY_GRAPH_VAULT=true` — full vault explorer (last; highest load).
6. `FEATURE_MEMORY_GRAPH_SUGGESTIONS=true` — async “suggested links” on thought detail (independent of graph views).

No “master” flag — each capability stands alone. Optional future: `FEATURE_MEMORY_GRAPH=false` kill-switch that overrides all; **not in v1** unless ops asks for it.

### Tests

Each level: feature test with flag **on** (200 + data) and flag **off** (404). UI tests: entry points absent when flag off. Semantic: query `include_semantic=1` with flag off returns graph **without** semantic edges.

---

## Shared architecture

### `ThoughtGraphService`

Single service responsible for assembling `{ nodes, edges, meta }` for all modes. Controllers validate auth, parse query params, delegate.

```
ThoughtGraphController (or mode-specific thin controllers)
        │
        ▼
ThoughtGraphService
   ├── CuratedEdgeBuilder      (thought_links)
   ├── StructuralEdgeBuilder   (parent_id)
   ├── TagEdgeBuilder          (shared tags → edges)
   ├── SemanticEdgeBuilder     (pgvector k-NN)
   └── GraphAssembly           (dedupe, caps, truncation)
```

### JSON contract

**Nodes**

```json
{
  "id": "uuid",
  "label": "truncated content",
  "group": "focal | member | neighbor | chunk",
  "source_type": "research | email | …",
  "tags": ["decision:foo"],
  "url": "/thoughts/{id}"
}
```

**Edges**

```json
{
  "id": "stable string",
  "from": "uuid",
  "to": "uuid",
  "edge_type": "thought_link | parent_child | shared_tag | semantic",
  "label": "Supports | child of | #tag | similar",
  "directed": true,
  "dashed": false,
  "weight": 0.82
}
```

`weight` is cosine similarity for semantic edges; optional for layout tuning.

**Meta**

```json
{
  "mode": "local | project | tag | semantic | vault",
  "focal_id": "uuid | null",
  "filters": { },
  "node_count": 42,
  "edge_count": 67,
  "truncated": false,
  "caps": { "max_nodes": 200, "max_edges": 500 }
}
```

### Shared Blade partial

`resources/views/graph/partials/vis_network_canvas.blade.php`

- Accepts `dataUrl`, `emptyMessage`, `canvasId`, optional `controlsPartial`.
- Applies existing project-graph fixes: definite height, `network.fit()` on stabilization, resize debounce, double-click → thought URL.
- Styling: focal node highlighted (`group: focal`), neighbors muted, semantic edges dashed.

### Edge-type visual language

| `edge_type` | Arrow | Style | Color hint |
|-------------|-------|-------|------------|
| `thought_link` | Yes (from → to) | Solid | memory-violet |
| `parent_child` | Yes (parent → child) | Solid, thin | slate |
| `shared_tag` | No (undirected) | Dotted | neural-teal |
| `semantic` | No | Dashed | amber |

`contradicts` link type uses red-tinted edge when `edge_type=thought_link` and link_type is `contradicts`.

---

## Level 1 — Local graph (thought detail)

**Obsidian analogue:** Local graph panel.

### Purpose

While reading a thought, see its immediate neighborhood: what it links to, what links to it, parent document, child sections.

### Routes

Behind `memory.graph.local` middleware.

| Method | Path | Name |
|--------|------|------|
| GET | `/thoughts/{thought}/graph` | `thoughts.graph` |
| GET | `/thoughts/{thought}/graph/data` | `thoughts.graph.data` |

### Query params

| Param | Default | Notes |
|-------|---------|-------|
| `depth` | `1` | Link hops (1 or 2). BFS on `thought_links` both directions. |
| `include_parent_child` | `1` | Parent + direct children always at depth 0. |
| `include_chunks` | `0` | When 0, child section nodes excluded unless focal is a chunk. |

### Node set

1. Focal thought (`group: focal`).
2. All thoughts reachable within `depth` via `thought_links` (incoming + outgoing).
3. If `include_parent_child`: parent (if any) + children of focal.
4. At `depth=2`, optionally include parent/children of hop-1 nodes (cap 30 extra nodes).

### Edges

- All `thought_links` between nodes in the set.
- `parent_child` edges for included hierarchy.

### UI

- Rendered only when `config('features.memory_graph_local')` is true.
- **v1:** Collapsible `<details>` on thought detail, below “Linked thoughts”, closed by default. Summary: “Connection graph”.
- Canvas height `min(40vh, 480px)` — smaller than project graph.
- Link “Open full graph” → same URL in dedicated page (full viewport) if panel feels cramped.
- Empty state: “No links yet — add links above to see connections.”

### Authorization

`ThoughtPolicy::view` on focal thought; only traverse links where `user_id` matches owner.

---

## Level 2 — Enhanced project graph

**Obsidian analogue:** Filtered folder graph.

### Purpose

Upgrade existing `/projects/{project}/graph` with filters and optional computed layers. Addresses v1 non-goal “no neighbors outside project” as an **opt-in toggle**.

### Routes

Unchanged paths; behind `memory.graph.project` middleware. Extend `ProjectGraphController::data` query params.

**Migration:** Wrap existing `projects.graph` and `projects.graph.data` routes in the middleware group. Default `FEATURE_MEMORY_GRAPH_PROJECT=true` so production behaviour is unchanged until explicitly disabled.

### Query params (new)

| Param | Default | Notes |
|-------|---------|-------|
| `link_types[]` | all | Subset of `ThoughtLinkType` values |
| `include_neighbors` | `0` | Linked thoughts **outside** project membership (`group: neighbor`) |
| `include_semantic` | `0` | Semantic edges among member nodes; ignored unless `memory_graph_semantic` is on |
| `include_parent_child` | `0` | Section/chunk tree within members |
| `include_chunks` | `0` | Section thoughts as nodes |
| `semantic_k` | `3` | Max semantic edges per member node |

### Neighbor mode (`include_neighbors=1`)

- Add nodes: any thought linked to a member via `thought_links` (either direction) not in `project_thought`.
- Cap **50 neighbor nodes** (most recently updated first).
- Edges to neighbors included; neighbors styled muted, not in “member” count.

### Semantic mode (`include_semantic=1`)

For each member with non-null `embedding`, find up to `semantic_k` nearest **other members** within `max_distance` (default 0.45). Add undirected `semantic` edges; skip pairs already connected by `thought_link`.

### UI controls

Toolbar above canvas:

- Multi-select link type filter (checkboxes).
- Toggles: Neighbors, Sections.
- **Similar** toggle: only when `memory_graph_semantic` is enabled.
- “Reset filters” link.

Refactor `projects/graph.blade.php` to use shared vis partial + fetch with query string from toggles.

---

## Level 3 — Tag constellation

**Obsidian analogue:** Graph filtered by tag.

### Purpose

Explore how thoughts cluster around a tag — e.g. `decision:project-spec`, `meeting:weekly-sync`, `research:competitors`.

### Routes

Behind `memory.graph.tag` middleware.

| Method | Path | Name |
|--------|------|------|
| GET | `/graph/tags` | `graph.tags` |
| GET | `/graph/tags/data` | `graph.tags.data` |

Entry points (only when flag on):

- Stream tag filter → “View as graph” link.
- Thought detail tag chip → graph for that tag.

### Query params

| Param | Default | Notes |
|-------|---------|-------|
| `tag` | required | Tag string or slug (normalize via `TagSlug`) |
| `match` | `any` | `any` = has tag; future: `all` for multi-tag |
| `include_semantic` | `0` | Semantic edges among tag members; ignored unless `memory_graph_semantic` is on |
| `limit` | `80` | Max nodes (hard cap 100) |
| `since` | null | Optional ISO date — only thoughts after |

### Node set

Thoughts for current user where `metadata->tags` contains `tag`, `visibleInStream()`, ordered by `updated_at desc`, limited.

Node `group` by `source` or primary type badge for color.

### Edges

1. **shared_tag:** between each pair sharing ≥2 tags (expensive O(n²); only when n ≤ 40, else skip or sample).
2. **Simpler v1:** star edges from a synthetic **tag hub node** (id `tag:{slug}`, shape `ellipse`, not clickable) to each thought — readable for large sets.
3. **semantic** (optional): same k-NN as level 2 among tag members.

**v1 recommendation:** Tag hub + optional semantic edges. Shared-tag pairwise only when `node_count ≤ 40`.

### UI

- Page title: `#{tag} — constellation`
- Tag picker / recent tags sidebar (reuse stream tag list patterns).
- Legend for source colors.

---

## Level 4 — Semantic neighborhood

**Obsidian analogue:** None native; similar to “Smart Connections” plugins.

### Purpose

Answer: “What else in my memory is *about the same thing* as this thought?” — complementary to explicit links.

### Routes

Behind `memory.graph.semantic` middleware.

| Method | Path | Name |
|--------|------|------|
| GET | `/thoughts/{thought}/semantic-graph` | `thoughts.semantic_graph` |
| GET | `/thoughts/{thought}/semantic-graph/data` | `thoughts.semantic_graph.data` |

### Query params

| Param | Default | Notes |
|-------|---------|-------|
| `k` | `12` | Nearest neighbors |
| `max_distance` | `0.45` | Cosine distance cap |
| `include_links` | `1` | Also show explicit links among returned nodes |

### Node set

- Focal thought.
- `Thought::nearestWithin($focal->embedding, $maxDistance)->take($k)` excluding focal, same user, `visibleInStream()`.
- If focal has no embedding, return focal only + `meta.error: no_embedding`.

### Edges

- `semantic` edges: focal ↔ each neighbor (undirected, weight = 1 - distance).
- Optional `thought_link` edges among nodes in set.

### UI integration

**Option A (recommended):** Merge into Level 1 as a layer toggle “Show similar thoughts” on the same canvas — one graph surface, two edge sources.

**Option B:** Separate tab “Similar” with list (today’s learning-lesson pattern) + “View as graph”.

v1 ships **Option A** on thought detail graph when **both** `memory_graph_local` and `memory_graph_semantic` are on; dedicated route for full-page semantic view and API clarity.

### Cost note

One embedding comparison query per request (pgvector index). Acceptable for interactive use. No N² all-pairs.

---

## Level 5 — Vault graph

**Obsidian analogue:** Full vault graph.

### Purpose

Power-user exploration of entire memory with **mandatory filters** to prevent unusable hairballs.

### Routes

| Method | Path | Name |
|--------|------|------|
| GET | `/graph` | `graph.vault` |
| GET | `/graph/data` | `graph.vault.data` |

Behind `memory.graph.vault` middleware. Nav link “Graph” when `config('features.memory_graph_vault')` is true.

### Query params

| Param | Default | Notes |
|-------|---------|-------|
| `layers[]` | `thought_link` | Subset: `thought_link`, `parent_child`, `shared_tag`, `semantic` |
| `project_id` | null | Restrict nodes to project members |
| `tag` | null | Restrict to tag |
| `source` | null | Stream source filter |
| `since` / `until` | null | Date range on `created_at` |
| `include_chunks` | `0` | |
| `include_neighbors` | `0` | For thought_link layer only |
| `semantic_k` | `2` | Lower default than project graph (scale) |
| `limit` | `200` | Node cap |

### Assembly algorithm

1. **Seed nodes** from filtered thought query (cap `limit`).
2. **Layer `thought_link`:** edges among seed nodes; if `include_neighbors`, add linked outsiders up to cap.
3. **Layer `parent_child`:** only when seed includes parents or `include_chunks=1`.
4. **Layer `shared_tag`:** only when node count ≤ 60; else omit with `meta.warnings`.
5. **Layer `semantic`:** run k-NN per node only when node count ≤ 30; else sample 30 focal nodes (most recent).

Return `truncated: true` when seed query hits cap.

### UI

- Filter panel (sticky): project, tag, source, date range, layer checkboxes.
- Warning banner when truncated or layers skipped.
- “Load explicit links only” preset (fast default).
- Performance target: p95 < 2s for 200 nodes, links-only layer.

### Why last + flagged

Full vault + semantic can stress pgvector and produce overwhelming UI. Ship after levels 1–2 prove the shared stack. Flag defaults off; enable explicitly when ready.

---

## Cross-level UX map

```
FEATURE_MEMORY_GRAPH_LOCAL
  Thought detail → Connection graph (L1)
    └── FEATURE_MEMORY_GRAPH_SEMANTIC → “Show similar” toggle (L4)

FEATURE_MEMORY_GRAPH_PROJECT
  Project workspace → Graph (L2)
    └── FEATURE_MEMORY_GRAPH_SEMANTIC → Similar layer on project graph

FEATURE_MEMORY_GRAPH_TAG
  Stream / tag chip → View constellation (L3)

FEATURE_MEMORY_GRAPH_VAULT
  Nav → Graph (L5 vault)
```

Entry points are omitted entirely when the relevant flag is off.

---

## Link computation strategy

How relationships are created vs discovered vs displayed. **Product decision:** background work is **suggestions-only** — it never auto-writes `thought_links` and never pre-warms graph caches in v1–v2.

### By edge type

| Edge type | Written when | Graph render | Background job |
|-----------|--------------|--------------|----------------|
| `thought_link` | User / MCP explicit action | Read from DB | **No** |
| `parent_child` | Capture / chunking (`parent_id`) | Read from DB | **No** |
| `shared_tag` | Tags on capture / edit | Derived at read time | **No** |
| `semantic` (graph layer) | Not stored | On-demand k-NN when user toggles layer | **No** (v1) |
| `semantic` (suggestion) | Not stored until promoted | Suggestion list on thought detail (v2) | **Yes** (v2) |

Embeddings are already computed **at capture** (sync in `ThoughtCaptureService`); background jobs do not re-embed for graph purposes.

### v1 — On-demand graph, no background linking

- Graph endpoints compute semantic edges live via pgvector when the user enables the semantic layer (`memory_graph_semantic`).
- Explicit, structural, and tag edges are cheap DB reads.
- No `thought_suggested_links` table in v1.
- Rationale: small scopes (local/project) stay fast; graph always reflects current embeddings after content edits.

### v2 — Background suggestions only (optional follow-up)

**Goal:** Surface “you might want to link these” without polluting the curated graph.

**Trigger:** `ThoughtObserver` on create/update when `content` changes (same fields as working-memory refresh), gated by `FEATURE_MEMORY_GRAPH_SUGGESTIONS` (new flag, default `false`).

**Job:** `ComputeSemanticLinkSuggestionsJob`

1. Skip if focal has no embedding.
2. `nearestWithin(embedding, max_distance)` → top **5** neighbors (`visibleInStream()`, same user).
3. Exclude pairs that already have any `thought_link` (either direction).
4. Upsert rows in `thought_suggested_links` (new table):

| Column | Type | Notes |
|--------|------|-------|
| `id` | uuid | PK |
| `user_id` | FK | Owner |
| `from_thought_id` | FK | Focal thought |
| `to_thought_id` | FK | Suggested target |
| `distance` | float | Cosine distance |
| `dismissed_at` | timestamp, nullable | User dismissed |
| `promoted_at` | timestamp, nullable | User created link |
| `computed_at` | timestamp | Last job run |

Unique `(from_thought_id, to_thought_id)`.

**UI (thought detail):** “Suggested links” block below Linked thoughts (when flag on). Each row: excerpt, similarity hint, **Link** (opens add-link form pre-filled with `relates_to`) + **Dismiss**. Promoting creates a normal `thought_link` via existing flow and sets `promoted_at`.

**What v2 does *not* do:**

- Auto-create `thought_links`.
- Add suggestion nodes/edges to graph views by default.
- Pre-compute or cache graph payloads for faster vault loads.
- Run N² all-pairs across the vault.

**Stale suggestions:** Recompute on content change; clear or refresh rows where focal embedding changed. Dismissed rows are not recreated unless focal content changes significantly (recompute replaces non-dismissed rows for that focal).

### v3 — Performance cache (deferred, not in scope)

Neighbor **cache tables** or pre-warmed graph JSON for vault scale are **out of scope** unless profiling proves on-demand k-NN fails SLOs. If ever needed, that would be a separate spec — still distinct from suggestions and still not auto-linking.

---

## MCP (deferred)

- **v1:** `get_thought_graph` with `mode` + params for agents (optional).
- **v2:** `list_link_suggestions` for agents. Agents promote via existing link-creation tools, not by writing suggestions directly to `thought_links`.

---

## Testing strategy

| Area | Tests |
|------|-------|
| `ThoughtGraphService` | Unit: BFS depth, caps, dedupe, empty focal, no embedding |
| Level 1 | Feature: linked thoughts appear; parent/child; depth=2 |
| Level 2 | Feature: link_type filter; neighbor toggle adds outsider node |
| Level 3 | Feature: tag filter returns only tagged thoughts |
| Level 4 | Feature: mock embedding, k neighbors; no embedding graceful |
| Level 5 | Feature: cap truncation; 404 when `memory_graph_vault` off |
| Feature flags | Each level: 404 when off; UI entry points hidden; semantic query param stripped when semantic flag off |
| Suggestions (v2) | Job writes `thought_suggested_links` only; promote creates `thought_link`; dismiss persists |
| Auth | Cannot graph another user’s thoughts |

---

## Phased delivery

| Phase | Deliverable | Depends on |
|-------|-------------|------------|
| **0** | Feature flags + middleware + `ThoughtGraphService` + JSON contract + shared vis partial | — |
| **1** | Level 1 local graph (`FEATURE_MEMORY_GRAPH_LOCAL`) | Phase 0 |
| **2** | Level 2 project enhancements; gate existing graph (`FEATURE_MEMORY_GRAPH_PROJECT`, default true) | Phase 0 |
| **3** | Level 4 semantic layer (`FEATURE_MEMORY_GRAPH_SEMANTIC`) | Phase 0 |
| **4** | Level 3 tag constellation (`FEATURE_MEMORY_GRAPH_TAG`) | Phase 0 |
| **5** | Level 5 vault graph (`FEATURE_MEMORY_GRAPH_VAULT`) | Phases 1–3 validated |
| **6** (optional) | Link suggestions job + UI (`FEATURE_MEMORY_GRAPH_SUGGESTIONS`) | Phase 1 + semantic flag validated |

Phases 1–2 are highest value / lowest risk. Phase 5 last. Phase 6 is suggestions-only background work — no graph cache, no auto-linking. Enable per environment as you validate.

---

## Open questions for review

1. **Level 1 placement:** Collapsed panel on detail vs always-visible sidebar — spec assumes collapsed `<details>`; confirm.
2. **Tag hub vs pairwise edges:** Spec recommends tag hub for v1; prefer pairwise for small sets?
3. **Nav entry for L3:** Standalone `/graph/tags` only, or also embed mini-constellation on stream tag pages?
4. **Chunk default:** Hiding chunks by default — confirm this matches how you think about “memories” vs “document sections”.
5. **Project graph default:** Spec keeps `FEATURE_MEMORY_GRAPH_PROJECT=true` for backward compat — prefer all flags default false (breaking change for existing graph users)?

---

## Success criteria

- User can open a thought and see its link neighborhood without leaving the page.
- Project graph supports filtering by link type and optional semantic/neighbor layers.
- Tag exploration has a visual mode beyond Stream list.
- Semantic similarity is visible as graph edges, not only search results.
- Vault graph is usable with filters and does not time out at 200 nodes (links-only preset).
- Background jobs never auto-create `thought_links`; suggestions require explicit promote (v2).
