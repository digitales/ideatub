# Working memory: corpus sync (Phases 2 & 3)

Grow IdeaTub’s thought corpus so working memory stays useful between Elixirr `current.md` syncs. Client scopes with a fresh **external** upsert keep that baseline; other scopes can use **AI-authored** consolidation when enabled.

## Phase 2 — Ongoing capture (agents & automations)

### Meeting notes (required final step)

After `elixirr-meeting-writer` or `elixirr-meeting-notes` produces markdown locally, call **`capture_meeting`** (or `capture_plan` with `doc_type: meeting`):

| Parameter | Value |
|-----------|--------|
| `content` | Full meeting markdown |
| `plan_slug` | e.g. `2026-05-08-weekly-check-in` |
| `project` | Client slug (e.g. `dezeen`) |
| `section_title` | Meeting title |

IdeaTub then runs **meeting compaction** and an incremental working-memory refresh (delayed ~60s so compaction persists first).

### Automation outputs

After a daily bug scan (or similar) writes markdown under `outputs/automations/`, call **`capture_plan`**:

| Parameter | Value |
|-----------|--------|
| `content` | Scan markdown |
| `doc_type` | `plan` |
| `project` | Client slug |
| `plan_slug` | `{automation-id}-{date}` |
| `section_title` | Scan title |
| `tags` | `automation`, `bug-scan`, `client:{client}`, `repo:{repo}` |

### Working memory sync (Phase 1)

After captures, keep **`upsert_working_memory`** in `elixirr-sync` so canonical memory stays aligned with local `current.md`:

- `scope_type`: `project`
- `scope_key`: **IdeaTub project UUID** (not client slug)
- `source_label`: `elixirr-sync`

**Server-side dedupe:** Unchanged `current.md` content (ignoring `Last Updated` / `refreshed at` lines) does not create a duplicate Stream card or `external` version. Use `no_chunking: true` on WM `capture_plan` snapshots. Backfill duplicates: `php artisan working-memory:dedupe --dry-run` (scheduled nightly on the server).

## Phase 3 — Bulk import & AI consolidation

### Bulk import from disk (Slack / automations / meetings)

On a machine with markdown exports (e.g. Elixirr `outputs/slack/`), run on the IdeaTub app server:

```bash
php artisan working-memory:import-captures \
  --user=1 \
  --project=dezeen \
  --project-id=019e0705-5591-73e9-be2e-0fb9c86b269a \
  --path=/path/to/outputs/slack \
  --kind=slack \
  --rate=50 \
  --consolidate-after
```

| Option | Purpose |
|--------|---------|
| `--kind=slack` | Tags: `slack`, `channel:{name}`, `client:{project}` |
| `--kind=automation` | Tags: `automation`, `client:{project}` |
| `--kind=meeting` | `doc_type: meeting`, triggers meeting compaction |
| `--dry-run` | List files without importing |
| `--consolidate-after` | Queue one `ConsolidateWorkingMemory` for `--project-id` when done |

Imports use `Thought::withoutEvents()` to avoid hundreds of incremental jobs; run consolidation once after backfill.

### Enable AI authoring (non-external scopes)

In `.env` for environments **without** relying solely on external upsert:

```env
FEATURE_WORKING_MEMORY_AI_AUTHORED=true
WORKING_MEMORY_AUTHORING_ENABLED=true
```

Scopes with a fresh **external** version (default 14-day protection) are **not** replaced by scheduled or manual refresh unless you use **Rebuild in IdeaTub** (`force=1`) on the memory page.

Nightly consolidation skips protected scopes automatically:

```bash
php artisan working-memory:consolidate
```

Force rebuild for a single scope (bypasses external guard):

```bash
php artisan working-memory:consolidate --user=1 --scope_type=project --scope_key=019e0705-5591-73e9-be2e-0fb9c86b269a --force
```

Only queue scopes **without** fresh external memory:

```bash
php artisan working-memory:consolidate --user=1 --only-without-external
```

### Ongoing Slack sync

Add **`capture_plan`** as the final step in `elixirr-comms-normalizer` when writing a new processed Slack summary (same tags as bulk import).

Weekly digest compactions (`BuildWeeklyDigestsCommand`, scheduled) compress channel activity once enough Slack thoughts exist.

## See also

- [MCP integration guide](/help) — `capture_plan`, `capture_meeting`, `upsert_working_memory`, version history
- External-first hybrid design: `docs/superpowers/specs/2026-05-18-working-memory-hybrid-external-first-design.md`
