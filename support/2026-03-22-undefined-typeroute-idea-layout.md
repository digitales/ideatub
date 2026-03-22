# Undefined `$typeRoute` in `layouts.idea` - Customer Support Investigation

**Date**: 2026-03-22
**Status**: Investigating
**Customer**: User ID 3
**Priority**: High
**Reported By**: Customer (support)

## Issue Description

Authenticated requests failed while rendering `resources/views/layouts/idea.blade.php` with:

- `Undefined variable $typeRoute`
- wrapped as an `Illuminate\View\ViewException`

The stack trace points at a compiled Blade view in `storage/framework/views/...php`, with the source view reported as `resources/views/layouts/idea.blade.php`.

## Customer Impact

- Affected user could not load pages that extend `layouts.idea`
- Impact is broad because this layout is shared across core authenticated pages
- User-facing result is a hard 500 error rather than degraded functionality

## Investigation Steps

1. Read the current `resources/views/layouts/idea.blade.php` in `master`.
2. Searched the current codebase for `$typeRoute` / `typeRoute`.
3. Reviewed `app/Providers/AppServiceProvider.php` for shared layout data.
4. Checked git history for `resources/views/layouts/idea.blade.php` to find when `$typeRoute` existed.
5. Compared the current layout to historical versions on `feature/focused-navigation`.

## Root Cause Analysis

The current `master` version of `resources/views/layouts/idea.blade.php` does **not** reference `$typeRoute` anywhere, and a repo-wide search on the current branch found no live usages.

Historical inspection showed that `$typeRoute` was introduced on `feature/focused-navigation`, where the layout rendered a `Types` dropdown and assigned the variable inside Blade loops:

- `@php $typeRoute = \App\Support\ThoughtTypeNavigation::routeName($typeKey); @endphp`
- then `href="{{ route($typeRoute) }}"`

That means the production exception is not consistent with the current `master` layout. The most likely explanations are:

- the server is running code from `feature/focused-navigation` (or another non-master deploy), or
- the server has a stale compiled Blade cache from that branch/version even though the source view has since changed

Because the exception references a compiled view line that expects `$typeRoute` while current `master` no longer contains that variable, this points to a deploy/cache mismatch rather than a current-source bug on `master`.

## Resolution

Recommended production remediation:

1. Confirm the deployed git SHA / branch on the affected environment.
2. Clear compiled Blade/views on the server:
   - `php artisan view:clear`
   - or `php artisan optimize:clear`
3. Redeploy the intended revision and verify the layout file on disk matches the deployed SHA.

If the environment is intentionally running `feature/focused-navigation`, harden that branch before redeploying:

- avoid relying on a temporary Blade variable for the route target
- guard route rendering so no link is emitted unless a route name exists
- add a regression test that renders the layout and opens the `Types` menu without throwing

## Customer Communication

- 2026-03-22: Investigated the exception and determined it does not match the current `master` source. Likely cause is a stale/mismatched deployed Blade or non-master branch deployment. Recommended clearing compiled views and verifying deployed SHA.

## Prevention & Follow-up

- [ ] Update deploy process to clear compiled Blade/views on release or rollback
- [ ] Record deployed SHA/branch in release logs for faster incident triage
- [ ] If `feature/focused-navigation` is revived, add a regression test around `Types` navigation rendering
- [ ] Consider avoiding temporary Blade variables in nav link generation when an inline helper call is sufficient

## Related Issues

- `support/2026-03-12-textarea-htmlobject-and-nav-support.md`

## Lessons Learned

When a Blade exception references variables that do not exist in the current branch, check historical branches and compiled view drift before treating it as a fresh code regression. For shared layouts, stale compiled views can create incidents that look like source bugs but are really deploy hygiene issues.

## References

- `resources/views/layouts/idea.blade.php`
- `app/Providers/AppServiceProvider.php`
- `support/2026-03-12-textarea-htmlobject-and-nav-support.md`
