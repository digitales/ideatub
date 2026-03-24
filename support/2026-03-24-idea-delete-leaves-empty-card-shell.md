# Idea delete leaves empty card shell - Customer Support Investigation

**Date**: 2026-03-24
**Status**: Resolved
**Customer**: User ID unknown
**Priority**: Medium
**Reported By**: Customer (support)

## Issue Description

On the Ideas page, deleting an idea removed the card content but left the rounded card background visible as an empty shell.

## Customer Impact

- Users saw a broken empty card after deleting an idea
- The delete action appeared unreliable even though the record was removed
- Impact was limited to the Ideas list UI

## Investigation Steps

1. Reviewed the screenshot and confirmed the visible symptom was an empty card shell left in the list
2. Read the Ideas list Blade partial and the shared `thoughtCardActions` Alpine component
3. Compared the Ideas card markup with the Home and Stream card markup
4. Added a regression test asserting the outer Ideas card element carries `data-thought-id`
5. Ran the new test red, then applied the minimal template fix and reran related tests

## Root Cause Analysis

The shared delete action removes the closest ancestor marked with `data-thought-id`.

On Home and Stream, that attribute is on the outer card wrapper, so the whole card is removed. On the Ideas page, the attribute was on an inner content wrapper instead of the outer `<li>`. As a result, successful delete removed only the inner content area and left the outer list item background behind.

## Resolution

Moved `data-thought-id` from the inner Ideas content wrapper to the outer `<li>` card element in `resources/views/idea/partials/ideas_list.blade.php`.

Added a regression test in `tests/Feature/IdeaIdeasTest.php` to ensure the Ideas outer card element continues to expose the DOM marker used by the shared delete behavior.

## Customer Communication

- 2026-03-24: Confirmed the issue, identified a DOM wrapper mismatch on the Ideas page, and fixed it with regression coverage

## Prevention & Follow-up

- [x] Add regression coverage for the Ideas outer card wrapper
- [ ] Add a browser-level UI test for delete interactions on the Ideas page if browser test coverage is introduced
- [ ] Review other shared card actions for assumptions about wrapper structure

## Related Issues

- `docs/superpowers/specs/2026-03-16-delete-thought-design.md`

## Lessons Learned

Shared frontend behaviors that remove or update DOM nodes should rely on a consistent wrapper contract across all views. When a shared Alpine action depends on a marker like `data-thought-id`, each rendering context needs explicit regression coverage for that structural assumption.

## References

- `resources/views/idea/partials/ideas_list.blade.php`
- `resources/js/app.js`
- `tests/Feature/IdeaIdeasTest.php`
- `tests/Feature/DeleteThoughtTest.php`
