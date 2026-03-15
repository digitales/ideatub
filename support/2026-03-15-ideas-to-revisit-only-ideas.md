# Ideas to revisit: restrict to only ideas — Customer Support

**Date**: 2026-03-15  
**Status**: Resolved  
**Priority**: Medium  
**Reported By**: Customer

## Issue Description

The "Ideas to revisit" list was showing more than just ideas (e.g. research thoughts or other thought types). Customer requested that the list be restricted to only ideas.

## Customer Impact

- Users could see non-idea thoughts (e.g. research briefs, notes) on the Ideas to revisit page and in the MCP `get_ideas` response, which is confusing and dilutes the purpose of the list.

## Investigation Steps

1. Confirmed the source of the list: `IdeasToRevisitService::forUser()` used by the revisit page (`IdeaController::revisit`) and MCP `get_ideas`.
2. The service already applied the `Thought::scopeIdeas()` scope (`metadata->type` = 'idea'). Possible causes for non-ideas appearing: DB JSON handling differences (SQLite vs PostgreSQL), or edge cases where `metadata->type` matched unexpectedly.
3. Added a defensive in-memory filter so only thoughts with `metadata['type'] === 'idea'` are returned, regardless of DB quirks.

## Root Cause Analysis

The query used the `ideas()` scope, but to guarantee only ideas are returned (and to guard against any JSON/driver edge cases), an explicit PHP-side filter was added.

## Resolution

- **IdeasToRevisitService**: After the query, filter the result so only thoughts with `metadata['type'] === 'idea'` are returned (`array_values(array_filter(...))`). The scope remains in place for efficiency; the filter is a safeguard.
- **Tests**: Added `returns_only_ideas_excludes_research_and_other_thought_types` in `IdeasToRevisitServiceTest` to assert that research, note, and thoughts without type are excluded.

## Prevention & Follow-up

- Rely on the same service for both the web "Ideas to revisit" page and the MCP `get_ideas` tool so one fix covers both.
- Unit tests now explicitly cover exclusion of non-idea types.

## References

- `app/Services/IdeasToRevisitService.php`
- `tests/Unit/Services/IdeasToRevisitServiceTest.php`
- `app/Http/Controllers/IdeaController.php` — `revisit()`
- `app/Http/Controllers/Api/McpController.php` — `get_ideas`
