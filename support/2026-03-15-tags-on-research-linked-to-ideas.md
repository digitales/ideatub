# Add tags to research pieces linked to ideas — Customer Support

**Date**: 2026-03-15  
**Status**: Resolved  
**Priority**: Low  
**Reported By**: Customer

## Issue Description

Customer requested that research pieces linked to ideas have tags, so they can be discovered and filtered (e.g. in Stream) like other thoughts.

## Customer Impact

- Single user / product improvement
- Research thoughts were stored with `metadata.type = 'research'` and `metadata.idea_id` but had no `metadata.tags`. Stream and tag-based navigation only show thoughts that have `metadata->tags`; research thoughts were therefore not filterable by tag and did not show tag pills.

## Resolution

- **Backend:** When creating a research thought in `ResearchService::runResearchForIdea()`, set `metadata.tags` to at least `['research']`, normalized with `Thought::normalizeMetadataTags()` so tags are lowercase and consistent with the rest of the app.
- **Result:** New research thoughts appear in Stream with a `#research` tag and can be filtered via `/stream?tag=research`. Existing research thoughts in the database are unchanged (no migration); only newly created research gets tags.

## Implementation Details

- File: `app/Services/ResearchService.php`
- Research metadata is built as `['type' => 'research', 'idea_id' => $idea->id, 'tags' => ['research']]`, then tags are normalized via `Thought::normalizeMetadataTags()` so the stored value matches Stream/tag conventions.

## References

- `app/Services/ResearchService.php` — `runResearchForIdea()`
- `app/Models/Thought.php` — `normalizeMetadataTags()`, Stream tag query uses `metadata->tags`
- `docs/superpowers/specs/2026-03-12-tag-and-stream-design.md` — tag-based navigation and Stream
- Related: `support/2026-03-15-ideas-feed-no-research-briefs.md` (research hidden from Ideas feed but still stored; tags make research findable in Stream)
