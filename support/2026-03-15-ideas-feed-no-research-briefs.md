# Recent thoughts (homepage) exclude research — Customer Support

**Date**: 2026-03-15  
**Status**: Resolved  
**Priority**: Low  
**Reported By**: Customer

## Issue Description

Customer requested that the **authenticated homepage** (recent thoughts feed at `/`) exclude research pieces—i.e. the 20 recent top-level thoughts should not include thoughts with `metadata.type = 'research'`. The `/ideas` page should be unchanged (still show research briefs linked to ideas).

## Customer Impact

- Single user preference
- Recent thoughts on the homepage (/) are cleaner; research is still visible on /ideas and elsewhere

## Resolution

- **IdeaController::index()**: When loading recent thoughts (no search query), the query now uses `->excludingResearch()` so thoughts with `metadata->type = 'research'` are excluded from the 20 recent items.
- **Thought model**: Added `scopeExcludingResearch()` so that thoughts where `metadata->type` is `'research'` are excluded (and thoughts with no type or other types are included).
- **Reverted** earlier change that had removed research briefs from the Ideas page (`/ideas`); `/ideas` again shows research block and loads `researchByIdea`.

## References

- `app/Http/Controllers/IdeaController.php` — `index()` (recent thoughts) and `ideas()` (unchanged)
- `app/Models/Thought.php` — `scopeExcludingResearch()`
- `resources/views/idea/ideas.blade.php` — Ideas list template (research block restored)
