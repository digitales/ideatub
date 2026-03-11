# Laravel Cloud: "Please provide a valid cache path" – Customer Support Investigation

**Date**: 2026-03-11  
**Status**: Resolved  
**Priority**: High  
**Reported By**: Internal (Laravel Cloud deployment)

## Issue Description

Application fails on Laravel Cloud with:

```
ERROR: Please provide a valid cache path.
class: InvalidArgumentException
file: .../vendor/laravel/framework/src/Illuminate/View/Compilers/Compiler.php:75
```

Occurs when the first view is rendered (Blade compilation).

## Customer Impact

- Application is unusable on Laravel Cloud (production).
- Any request that renders a view triggers the exception.

## Investigation Steps

1. **Traced exception** to `Illuminate\View\Compilers\Compiler::__construct()` line 75: `if (! $cachePath)` → throws when `$cachePath` is empty/falsy.

2. **Traced cache path source** to `config('view.compiled')`, set in framework `config/view.php` and passed into the Blade compiler by `ViewServiceProvider`.

3. **Checked framework default** in `vendor/laravel/framework/config/view.php`:
   ```php
   'compiled' => env(
       'VIEW_COMPILED_PATH',
       realpath(storage_path('framework/views'))
   ),
   ```

4. **Root cause**: `realpath(storage_path('framework/views'))` returns **`false`** when the directory does not exist. On Laravel Cloud (and many PaaS/container deployments):
   - `storage/` is often not in the deployed artifact (e.g. gitignored).
   - So `storage/framework/views` does not exist at runtime.
   - `realpath()` on a non-existent path returns `false`.
   - `config('view.compiled')` is then `false` → Blade compiler receives an invalid cache path → `InvalidArgumentException`.

## Root Cause Analysis

The framework uses **`realpath(storage_path('framework/views'))`** as the default compiled view path. On read-only or minimal filesystem deployments (Laravel Cloud, containers, serverless), the `storage/framework/views` directory may not exist. `realpath()` returns `false` for non-existent paths, so the view compiler receives no valid path and throws.

## Resolution

**Option A – Application config (recommended for this repo)**  
Publish application-level view config so the default does not rely on `realpath()`:

- Add `config/view.php` that sets `'compiled' => env('VIEW_COMPILED_PATH', storage_path('framework/views'))` (no `realpath()`).
- This always provides a string path; the compiler (and Laravel’s view engine) will create the directory when needed, as long as the parent `storage` (or alternate path) is writable.

**Option B – Laravel Cloud / environment**  
If the app filesystem is read-only:

- Set **`VIEW_COMPILED_PATH`** in Laravel Cloud (or `.env`) to a writable directory that exists at runtime, e.g.:
  - `VIEW_COMPILED_PATH=/tmp/laravel-views`
- Ensure that path is writable by the web/queue processes.

**Option C – Deployment / build**  
Ensure `storage/framework/views` (and sibling dirs) exist and are writable before the app runs:

- In Docker/build: `mkdir -p storage/framework/{views,sessions,cache/data}` and appropriate permissions.
- Or commit the directory structure (e.g. with `.gitkeep`) so it is present in the deployed tree, if your deployment allows writing to `storage/`.

## Prevention & Follow-up

- [x] Add `config/view.php` so default compiled path does not use `realpath()`.
- [ ] If deploying to read-only filesystems, document `VIEW_COMPILED_PATH` in deployment docs (e.g. `CLAUDE.md` or runbook).

## References

- Framework: `vendor/laravel/framework/config/view.php`
- Exception: `Illuminate\View\Compilers\Compiler.php` (constructor)
- Stack Overflow: [“Please provide a valid cache path”](https://stackoverflow.com/questions/38483837/please-provide-a-valid-cache-path-error-in-laravel) (same root cause)
