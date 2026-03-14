# Laravel Cloud: application logs and migrations

**Date**: 2026-03-13
**Status**: Open
**Context**: Seeing only queue/process logs; MCP and app logs not visible. Jobs table missing on Cloud.

## What you’re seeing

- **Application logs** in the Laravel Cloud dashboard show:
  - **ERROR**: `relation "jobs" does not exist` (from the queue worker every ~11s)
  - **INFO/NOTICE**: Process lifecycle (deploy, PHP-FPM, scheduler, queue worker start/stop)
- No `MCP tools/call` or `MCP search_thoughts` lines even after adding diagnostic logging.

## 1. Fix the jobs table (stop queue errors)

The queue worker runs `php artisan queue:work database` and expects a `jobs` table. The migration exists in the repo (`database/migrations/2026_03_13_200342_create_jobs_table.php`).

- Ensure **migrations run on deploy** for the Cloud database. In Laravel Cloud: check your deploy configuration (e.g. build/deploy steps) and confirm `php artisan migrate --force` runs against the Cloud DB.
- If you run migrations manually: from a context that can reach the Cloud DB (e.g. Cloud shell or a one-off command), run:
  ```bash
  php artisan migrate --force
  ```
- After the `jobs` (and `cache` if used) tables exist, the queue worker will stop throwing every few seconds and your application logs will be easier to read.

## 2. Set and see log level on Laravel Cloud

**Where it’s set**

- Laravel uses **`LOG_LEVEL`** from the environment. In Laravel Cloud: open your **project** → select the **environment** (e.g. production) → **Environment variables** (or “Variables”).
- Add or edit:
  - **Key**: `LOG_LEVEL`
  - **Value**: `debug` (to see all app logs including `Log::info` / `Log::warning`) or `warning` (only warning and above).
- **Redeploy** after changing variables so the new value is used at runtime.

**How to “see” the current level**

- The framework doesn’t print the log level anywhere by default. You can:
  - **Inspect env in Cloud**: if your dashboard shows resolved env vars, check whether `LOG_LEVEL` is set and to what.
  - **Temporarily log it**: e.g. in a route or the MCP controller, `Log::info('LOG_LEVEL=' . config('logging.channels.' . config('logging.default') . '.level', env('LOG_LEVEL', 'not set')));` — then trigger that code and look in Application logs. (Optional; only for debugging.)

**Monolog levels** (lowest to highest): `debug`, `info`, `notice`, `warning`, `error`, `critical`, `alert`, `emergency`. Setting `LOG_LEVEL=warning` shows warning + error + critical etc.; `LOG_LEVEL=debug` shows everything.

## 3. Why you might still not see MCP logs

- **Web vs queue**: The `relation "jobs" does not exist` errors come from the **queue worker** process. MCP requests are handled by the **web** process (PHP-FPM). On Cloud, web and queue may have separate log streams. Check that you’re looking at the stream that includes **web** application output (often the main “Application logs” for the app).
- **No MCP traffic**: Our MCP logs run only when a request hits `POST /api/mcp` (e.g. a `tools/call` for `search_thoughts`). Trigger a search from the client, then refresh Application logs; look for `MCP tools/call` or `MCP search_thoughts start`.
- **LOG_CHANNEL**: Laravel Cloud often sets `LOG_CHANNEL=stderr` so logs go to stderr and Cloud aggregates them. Our code uses `Log::warning()` / `Log::error()`, which use the default channel. As long as you didn’t override `LOG_CHANNEL` to something that discards logs, they should appear when the web process handles a request.

## Checklist

- [ ] Run migrations on the Cloud database so `jobs` (and `cache` if needed) exist.
- [ ] Set `LOG_LEVEL=debug` in the environment variables for the Cloud environment and redeploy.
- [ ] Trigger an MCP search (or any MCP tool call) from your client.
- [ ] Open Application logs, refresh, and search for `MCP` or the exact error message.

## References

- Laravel Cloud: [Environments](https://cloud.laravel.com/docs/environments), [Logs](https://cloud.laravel.com/docs/logs)
- `config/logging.php` — `LOG_LEVEL` and channel `level`
- `support/2026-03-13-mcp-search-cache-table-missing.md` — cache table and MCP diagnostic log lines
