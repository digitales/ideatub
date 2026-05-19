# Elixirr sync operator notes (IdeaTub client/project scopes)

See spec: `docs/superpowers/specs/2026-05-19-elixirr-client-project-scopes-design.md`

## One-time setup per client

1. In IdeaTub, create a **client root** project (e.g. title “Dezeen”). Set **Elixirr client slug** = `dezeen`; leave project slug empty.
2. For each Elixirr subfolder under `clients/dezeen/projects/<name>/`, create a **child** project with parent = Dezeen root and **Elixirr project slug** = folder name.
3. Call MCP `list_projects` with `elixirr_client_slug: dezeen` or use the project edit UI to copy UUIDs.
4. Write `~/Documents/elixirr/clients/dezeen/ideatub-scope.json` (see `elixirr-sync` skill).
5. Migrate legacy WM rows: `php artisan working-memory:migrate-project-scope-keys --dry-run` then without `--dry-run`.
6. Run Elixirr sync; verify `/memory/scopes` shows **Clients → Dezeen** with nested subprojects.

## MCP

- `list_projects` — resolve slugs to UUIDs
- `upsert_working_memory` — `scope_key` must be UUID when `source_label` is `elixirr-sync`
