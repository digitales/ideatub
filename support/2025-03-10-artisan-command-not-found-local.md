# Artisan Command Not Found on Local - Customer Support Investigation

**Date**: 2025-03-10
**Status**: Resolved
**Customer**: Local developer (ideatub)
**Priority**: Medium
**Reported By**: Internal

## Issue Description

User reported: "the artiscan command is not found on local."

Interpreted as: **artisan** (Laravel’s CLI) not found when running Artisan commands locally. The project uses Laravel 12.

## Customer Impact

- Developer could not run `php artisan` (e.g. `migrate`, `ideatub:create-mcp-keys`, `serve`) from the project root.
- "Command not found" typically means either the executable is missing or the shell cannot find it; here the **artisan** file was missing from the repository.

## Investigation Steps

1. Searched codebase for "artiscan" → no matches (confirmed user meant **artisan**).
2. Searched for Laravel `artisan` usage in docs/README → all docs expect `php artisan` from project root.
3. Checked for `artisan` file in:
   - Main repo root (`/Users/rosstweedie/Sites/ideatub/`) → **missing**
   - IdeaTub worktree (`.worktrees/ideatub-implementation/`) → **missing**
4. Confirmed `composer.json` and `bootstrap/app.php` are present and reference Artisan (e.g. `@php artisan package:discover`); only the root `artisan` entry-point file was missing.
5. Added the standard Laravel 12 `artisan` file to both roots and made it executable.

## Root Cause Analysis

The **artisan** file was never present in the repo (or was removed and not re-added). In Laravel, `artisan` is the console entry point and lives in the project root; it is normally part of the Laravel application skeleton. This project may have been created or refactored in a way that omitted it, or it was not committed.

## Resolution

- **Added** the standard Laravel 12 `artisan` script to:
  - Main repo: `ideatub/artisan`
  - IdeaTub worktree: `ideatub/.worktrees/ideatub-implementation/artisan`
- **Set** executable bit: `chmod +x artisan` in both locations.

Content of `artisan`:

```php
#!/usr/bin/env php
<?php

define('LARAVEL_START', microtime(true));

require __DIR__.'/vendor/autoload.php';

$status = (require_once __DIR__.'/bootstrap/app.php')
    ->handleCommand(new Symfony\Component\Console\Input\ArgvInput);

exit($status);
```

**How to use:**

- Always run: **`php artisan`** (not just `artisan`) from the **project root** where `artisan` and `composer.json` live.
- From main repo: `cd /path/to/ideatub && php artisan --version`
- From IdeaTub worktree: `cd .worktrees/ideatub-implementation && php artisan --version`

## Additional Notes (separate from “command not found”)

- **Main repo**: If you see "bootstrap/cache directory must be present and writable", create it: `mkdir -p bootstrap/cache && chmod 775 bootstrap/cache`.
- **Worktree**: If you see "Class App\Http\Controllers\Controller not found", add or restore the base `App\Http\Controllers\Controller` class so `McpController` and other controllers can extend it.

## Prevention & Follow-up

- [ ] Consider adding `artisan` to the repo (or a note in README/setup docs) so new clones/worktrees have it.
- [ ] Ensure `bootstrap/cache` exists and is writable in setup instructions.

## References

- Laravel 12 Artisan docs: https://laravel.com/docs/12.x/artisan
- Project READMEs: `README.md`, `.worktrees/ideatub-implementation/README.md`
