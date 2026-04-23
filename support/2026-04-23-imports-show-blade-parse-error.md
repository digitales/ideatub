# Imports show view ParseError — support investigation

**Date**: 2026-04-23  
**Status**: Resolved (code fix)  
**Customer**: userId 3 (from error payload)  
**Priority**: High (page 500)  
**Reported By**: Monitoring / exception

## Issue description

`Illuminate\View\ViewException` with underlying `ParseError: Unclosed '[' on line 56 does not match ')'` when rendering `resources/views/imports/show.blade.php` (compiled view pointed at a nearby line; root cause in source Blade).

## Customer impact

User(s) on the import batch show page see a 500 and cannot see import progress or cancel the batch.

## Root cause

On the `x-data` line, the URL was emitted as `{{ route('imports.status', $batch) }}` **inside** a single-quoted HTML/JS string (`'...'`). The nested single quotes around the route name interact badly with the Blade echo boundary and can produce **invalid compiled PHP** (a misleading parse error often surfaces against the `@json(...)` / array section later in the file).

**Fix:** use **double-quoted** route name in the PHP call: `{{ route("imports.status", $batch) }}` (same on every similar pattern).

**Alternative (also safe):** pass the URL with `@js(route('imports.status', $batch))` in `x-data` to avoid hand-nested quotes in HTML.

## Resolution

- Updated `resources/views/imports/show.blade.php` to use `route("imports.status", $batch)`.
- Verified: `php artisan view:cache` completes successfully.

After deploy, clear compiled views on the server if needed: `php artisan view:clear` (or delete `storage/framework/views`).

## Prevention

- In Blade, avoid `route('name', $x)` inside a surrounding single-quoted fragment when the echo itself is already inside `'...'`. Prefer `route("name", $x)` or pass the value via `@js()`.

## References

- `origin/master:resources/views/imports/show.blade.php` (line with `x-data=... importBatch`).
