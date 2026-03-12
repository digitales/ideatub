# IdeaController::authorize BadMethodCallException – Customer Support Investigation

**Date**: 2026-03-12  
**Status**: Resolved  
**Priority**: High  
**Reported By**: Internal (500 on POST /thoughts)

## Issue Description

`BadMethodCallException`: `Method App\Http\Controllers\IdeaController::authorize does not exist.`

- **Where**: `app/Http/Controllers/IdeaController.php:98` — `$this->authorize('comment', $parent);`
- **When**: POST `https://ideatub.test/thoughts` (storing a thought with a parent / comment flow).

## Customer Impact

- Creating a thought as a reply (with `parent_id`) returns 500.
- Comment-on-thought flow blocked until fixed.

## Investigation Steps

1. **Confirmed** `IdeaController` extends `App\Http\Controllers\Controller` and calls `$this->authorize('comment', $parent)` when `parent_id` is present.

2. **Checked base Controller** — `app/Http/Controllers/Controller.php` only extended `Illuminate\Routing\Controller` with no traits.

3. **Laravel behaviour** — `$this->authorize()` is provided by `Illuminate\Foundation\Auth\Access\AuthorizesRequests`. Without that trait, `__call` on the routing controller does not define `authorize`, hence `BadMethodCallException`.

4. **Project grep** — No other controllers used `authorize` yet; this controller was the first to need it after the minimal base Controller was introduced (e.g. Laravel 11+ skeleton without the trait).

## Root Cause Analysis

The application base `Controller` class did not use the `AuthorizesRequests` trait. Calling `$this->authorize()` therefore attempted magic `__call` on `Illuminate\Routing\Controller`, which does not implement `authorize`.

## Resolution

Add the `AuthorizesRequests` trait to the base `Controller` so any controller extending it can use `$this->authorize($ability, $arguments)`.

**File**: `app/Http/Controllers/Controller.php`

- `use Illuminate\Foundation\Auth\Access\AuthorizesRequests;`
- `use AuthorizesRequests;` on the abstract class.

After this change, `IdeaController::store` can authorize the `comment` policy action on `$parent` as intended.

## Customer Communication

- Internal: redeploy not strictly required for local; production needs deploy including this controller change.

## Prevention & Follow-up

- [ ] When adding policy checks, prefer base `Controller` including `AuthorizesRequests` (or use `Gate::authorize()` / `abort_unless()` explicitly and document the choice).
- [ ] Add a feature test that POSTs to `/thoughts` with `parent_id` to catch missing authorization wiring.

## Related Issues

- Implementation plan: `dev/comment-on-thought-implementation-plan.md`
- Policy: `ThoughtPolicy::comment` (if defined)

## Lessons Learned

Minimal Laravel app `Controller` stubs omit `AuthorizesRequests`; any controller using `$this->authorize()` must either use the trait on base Controller or import it per controller.

## References

- Laravel: [Authorizing Actions](https://laravel.com/docs/authorization#authorizing-actions-using-policies) — `$this->authorize()` on controllers
- `Illuminate\Foundation\Auth\Access\AuthorizesRequests`
