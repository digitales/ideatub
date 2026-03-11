# Laravel Cloud: bootstrap/cache Must Be Present and Writable - Support Investigation

**Date**: 2026-03-10
**Status**: Resolved
**Customer**: Internal (Laravel Cloud deployment)
**Priority**: High
**Reported By**: Internal

## Issue Description

Deployment on Laravel Cloud fails during the build with:

```
The /var/www/html/bootstrap/cache directory must be present and writable.
```

Error occurs when the Composer post-autoload-dump script runs `php artisan package:discover --ansi`. Full trace points to `Illuminate\Foundation\PackageManifest.php:179`.

Additional build output observed:
- "The lock file is not up to date with the latest changes in composer.json" (separate recommendation: run `composer update` when appropriate)
- npm notices (audit, minor version) — informational only

## Customer Impact

- **Deployments fail**; application cannot be built or go live on Laravel Cloud
- **Severity**: Critical for deployment pipeline

## Investigation Steps

1. Confirmed `bootstrap/cache` is listed in `.gitignore` (`/bootstrap/cache`), so the directory is **not** in the repository
2. Laravel Cloud build: clone → install deps → runs Composer scripts; `post-autoload-dump` runs `artisan package:discover`, which requires `bootstrap/cache` to exist and be writable
3. On a fresh clone, `bootstrap/cache` does not exist, so the first time Laravel tries to write the package manifest cache it fails
4. Project Dockerfile runs `chmod -R 755 .../bootstrap/cache` **after** `composer install`, so it never creates the directory before Composer runs (and in Cloud, the Dockerfile may not be used for the same build path)

## Root Cause Analysis

- **Primary**: The `bootstrap/cache` directory is gitignored and not committed. Laravel Cloud’s build runs `composer install` on a clean clone where that directory does not exist. The Composer hook `package:discover` runs before any step creates the directory, so Laravel throws when trying to write the manifest cache.
- **Secondary**: Even when using the project Dockerfile, the directory is not created before `composer install`, so the same failure can occur in Docker-based builds if the build context doesn’t include the directory.

## Resolution

**Option A – Recommended (repo change): ensure the directory exists in the repo**

1. **.gitignore**: Stop ignoring the directory itself; ignore only its contents and keep a placeholder file so the directory is tracked:
   - Replace `/bootstrap/cache` with:
     - `/bootstrap/cache/*`
     - `!/bootstrap/cache/.gitkeep`
2. **Add** `bootstrap/cache/.gitkeep` (empty file) and commit it. The directory then exists on every clone, including Laravel Cloud’s build environment.

**Option B – Laravel Cloud build commands only (no repo change)**

In Laravel Cloud → Environment → Deployments → **Build commands**, ensure the directory is created **before** `composer install`, e.g.:

```bash
mkdir -p bootstrap/cache && chmod -R 775 bootstrap/cache
composer install --no-dev
# ... rest of your build (npm, etc.)
```

Option A is preferred so the fix applies to all environments (Cloud, Docker, new clones) without per-environment build tweaks.

## Prevention & Follow-up

- [x] Add `bootstrap/cache/.gitkeep` and adjust `.gitignore` so the directory is in the repo
- [x] Update Dockerfile to create `bootstrap/cache` before `composer install` for Docker-based builds
- [ ] If you see "lock file is not up to date with composer.json", run `composer update` (or update specific packages) locally and commit the updated `composer.lock`

## Related Issues

- [2025-03-10-artisan-command-not-found-local.md](2025-03-10-artisan-command-not-found-local.md) — same "bootstrap/cache must be present" noted for local; this investigation addresses Laravel Cloud specifically

## References

- Laravel Cloud: [Environments – Build and deploy commands](https://cloud.laravel.com/docs/environments)
- Laravel deployment: bootstrap/cache and storage must be writable
- Project: `Dockerfile` (permissions run after composer install; order fixed in this resolution)
