# Project link save fails with Postgres `25P02` — support investigation

**Date**: 2026-07-14  
**Status**: Fixed (pending deploy)  
**Example**: `https://ideatub.com/thoughts/019f5c6d-4b5a-7029-9b40-6f1782037401/projects`  
**Reported symptom**: Adding a thought to a project returns 500; refreshing the form action URL shows **405 Method Not Allowed**.

## Issue description

POST `/thoughts/{thought}/projects` failed with HTTP 500 while attaching thoughts (e.g. meeting notes → **Redundancy**). The browser stayed on the POST URL; a subsequent GET produced 405 because that route is POST-only.

Production logs showed:

```text
SQLSTATE[25P02]: In failed sql transaction: ... current transaction is aborted ...
SQL: update "cache_locks" ... key = ...laravel_unique_job:App\Jobs\WorkingMemoryRebuildJob:wm-auto-rebuild:{projectId}
POST /thoughts/019f5c6d-4b5a-7029-9b40-6f1782037401/projects 500
```

The thought remained unlinked (`linked_projects=0`).

## Root cause

1. `ProjectMembershipService::addThought` attaches the pivot inside a DB transaction (also wrapped by `ThoughtProjectController` / `lockForUpdate` from PR #91).
2. `ProjectThought` `created` / `deleted` hooks immediately dispatched `WorkingMemoryRebuildJob` (`ShouldBeUnique`) and `RefreshWorkingMemoryIncremental`.
3. Unique job locking uses the **database** cache store (`cache_locks`) on the **same** Postgres connection.
4. When a rebuild unique lock for that project was already held (prior attach / in-flight job, `uniqueFor` = 600s), acquiring the lock hit a unique conflict. On Postgres that **aborts the whole transaction** even if Laravel catches the error for the lock helper.
5. Later SQL (including further `cache_locks` updates) fails with `25P02`, and the membership insert rolls back.

PR #91 (sort_order `FOR UPDATE`) was already deployed; it did not fix this path. The `25P02` errors continued after that deploy.

## Fix

Defer pivot side-effect dispatches with `DB::afterCommit(...)` in `ProjectThought`, and use `->afterCommit()` for observer-dispatched rebuild jobs, so unique cache locks are acquired only after membership commits.

## Verification

- Feature tests: attach deferred until commit; attach persists when unique lock already held; existing membership / detail attach coverage.
- After deploy: open the thought detail page (not `/projects`), **Add to project** → Redundancy (or target project) should succeed.

## Related

- PR #91: `project_thought` sort_order race under Octane (`FOR UPDATE`).
- Job: `WorkingMemoryRebuildJob` / key `wm-auto-rebuild:{projectId}`.
