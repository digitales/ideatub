# MCP search_thoughts error: relation "cache" does not exist

**Date**: 2026-03-13
**Status**: Resolved (mitigation documented)
**Context**: Customer support / deployment
**Priority**: High (blocks MCP search)

## Issue Description

When calling MCP `search_thoughts` (e.g. query "Dezeen" from Cursor, ChatGPT, or another MCP client), the request fails with:

- **Client**: `{"error": "Error occurred during tool execution", "request_id": "…"}`
- **Server logs**:  
  `SQLSTATE[42P01]: Undefined table: 7 ERROR: relation "cache" does not exist`  
  with query: `select * from "cache" where "key" in ($1)`

## Customer Impact

- **Users affected**: Any deployment where `CACHE_STORE=database` (or `CACHE_DRIVER=database`) is set and the `cache` table has not been created
- **Severity**: High — MCP tools (search_thoughts, browse_recent, capture_thought, etc.) all go through the same API route and fail
- **Business impact**: MCP integration is unusable until fixed

## Investigation Steps

1. Confirmed the failing query is from Laravel’s cache layer (`select * from "cache" where "key" in ($1)`).
2. API routes use Laravel’s default `api` middleware group, which includes **rate limiting** (throttle). The rate limiter uses the default cache store to track request counts.
3. If `CACHE_STORE=database`, the cache driver uses a database store that expects a `cache` table. This project did not previously ship a migration for that table.
4. No application code in IdeaTub explicitly uses `Cache::`; the cache is used by the framework (rate limiting) on every API request.

## Root Cause

- **Config**: Environment has `CACHE_STORE=database` (or equivalent).
- **Missing schema**: The `cache` table (and optionally `cache_locks`) was never created (no migration in the project).
- **Trigger**: Any request to `POST /api/mcp` (and other API routes) hits the throttle middleware → cache read → failure.

## Resolution Options

### Option A: Create the cache table (keep database cache)

If you want to keep using the database as the cache store:

1. Run the cache table migration (after it is added to the project):
   ```bash
   php artisan migrate
   ```
   This runs the migration that creates the `cache` (and optionally `cache_locks`) table.

2. If you are on an older commit without that migration, you can create it manually:
   ```bash
   php artisan make:cache-table
   php artisan migrate
   ```
   (Use `cache:table` or `make:cache-table` depending on your Laravel version.)

### Option B: Use a cache store that doesn’t need the DB table

If you don’t need database-backed cache:

1. In `.env`, set:
   ```env
   CACHE_STORE=file
   ```
   or, for no persistence (e.g. single process):
   ```env
   CACHE_STORE=array
   ```
2. Restart the app / workers so the new config is loaded.

Rate limiting will then use file or array storage instead of the database, and the "cache" table is no longer required.

## Prevention / Follow-up

- **Codebase**: A migration that creates the `cache` (and `cache_locks`) table has been added so that deployments using `CACHE_STORE=database` work after `php artisan migrate`.
- **Docs**: Consider documenting in deployment/README that:
  - `CACHE_STORE=database` requires running migrations (so the cache table exists), or
  - Use `CACHE_STORE=file` if the cache table is not desired.

## Diagnosing MCP search / tool failures (logging)

Search/tool failures are logged so you can see where they break. Log lines use **warning** or **error** level so they appear in production (e.g. when `LOG_LEVEL=warning`).

**Where to look:**

- **Laravel Cloud**: Dashboard → **Logs** tab → filter by **Application logs** (not Deploy/Build or process output). Cloud uses the `stderr` channel; application `Log::warning` / `Log::error` appear there.
- **Self-hosted**: `storage/logs/laravel.log` (or your configured log channel).

**What to look for:**

- **`MCP search_thoughts start`** — request reached search with query/limit. If you never see this, failure is earlier (auth, routing, or a different tool).
- **`MCP search_thoughts embed ok`** — OpenRouter embedding succeeded. If you see "start" but not "embed ok", the failure is in `OpenRouterService::embed()` (e.g. missing `OPENROUTER_API_KEY`, timeout, or OpenRouter API error).
- **`MCP search_thoughts query ok`** — vector search succeeded. If you see "embed ok" but not "query ok", the failure is in the DB / pgvector query (e.g. extension, column type, or connection).
- **`MCP tools/call failed`** or **`MCP dispatch failed`** — includes `tool`/`method`, `exception` class, and `message` so you can see the exact error (e.g. `RuntimeException`, `OPENROUTER_API_KEY is not set` or a DB error message).

## References

- `config/cache.php` — default store `env('CACHE_STORE', 'array')`; only `array` and `file` defined by default in this project
- `routes/api.php` — `POST /api/mcp` uses default API middleware (includes throttle)
- Laravel rate limiting uses the default cache store: [Laravel Rate Limiting](https://laravel.com/docs/rate-limiting)
