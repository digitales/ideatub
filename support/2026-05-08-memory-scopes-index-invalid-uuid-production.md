# Memory scopes index — PostgreSQL invalid uuid — Customer Support Investigation

**Date**: 2026-05-08  
**Status**: Resolved  
**Customer**: User ID 3 (production)  
**Priority**: High  
**Reported By**: Monitoring / exception logs  

## Issue Description

Request to the working memory scopes index (`MemoryScopesController` → `WorkingMemoryScopesIndexBuilder`) failed with:

`SQLSTATE[22P02]: Invalid text representation: invalid input syntax for type uuid: "ideatub"`

The generated SQL used `where "id" in (ideatub, 019d88e2-..., dezeen, ...)` — a mix of UUID strings and non-UUID **project scope keys** (metadata slugs such as `ideatub`, `dezeen`, `pr-review-continuity`).

## Customer Impact

- User could not open **All memories** / memory scopes UI when they had any project-scoped working memory whose `scope_key` was not a UUID.
- API and other flows using slug-style project keys remain valid by design (`WorkingMemoryScopeNormalizer`, thought `source_metadata.project`, tests using `dezeen`, etc.).

## Investigation Steps

1. Confirmed stack trace: `WorkingMemoryScopesIndexBuilder::projectsFor` line ~119, `whereIn('id', $projectIds)`.
2. Confirmed `projects.id` is UUID (model uses `HasUuids`).
3. Confirmed `working_memories.scope_key` for `scope_type = project` may be either:
   - a project UUID (linked project / consolidate paths), or
   - a lowercase slug from capture metadata (e.g. CLAUDE.md `project` name), not a database id.

## Root Cause Analysis

`projectsFor()` treated every `scope_key` as a primary key for `Project`. PostgreSQL correctly rejects non-UUID literals bound for a UUID column.  

Additionally, the lookup map was keyed only by `Str::lower(project.id)`, so slug keys would not resolve to a `Project` even if the query had not failed.

## Resolution

**Code change** (2026-05-08):

- Split scope keys with `Str::isUuid()`: only UUIDs are passed to `whereIn('id', ...)`.
- For non-UUID keys, load the user’s projects and match `Str::slug($project->title)` to the scope key (same semantic family as metadata project strings).
- Build a lookup map keyed by both lowercased UUID and lowercased title slug so `rowFor()` resolves titles and routes correctly.

Files: `app/Services/WorkingMemory/WorkingMemoryScopesIndexBuilder.php`, test `tests/Feature/MemoryScopesIndexTest::test_project_scope_slug_resolves_via_title_slug_not_uuid`.

## Customer Communication

- Deploy updated application to production; no data migration required.

## Prevention & Follow-up

- [ ] Consider a follow-up spec or migration to normalize legacy project `scope_key` rows to project UUIDs where a single project unambiguously matches (optional hygiene).
- [x] Regression test for slug-based project scope on scopes index.

## Related Issues

- Design / behavior: project scope keys are intentionally string slugs or UUIDs (`WorkingMemoryScopeResolver`, `WorkingMemoryBuilderService` scoped thought filter).

## Lessons Learned

Any query that maps `working_memories.scope_key` → `projects.id` must branch on UUID vs slug semantics; never pass unvalidated strings into UUID columns on PostgreSQL.

## References

- `app/Services/WorkingMemory/WorkingMemoryScopesIndexBuilder.php`
- `app/Services/WorkingMemory/WorkingMemoryScopeResolver.php`
- `tests/Feature/MemoryScopesIndexTest.php`
