# Inbox Flash Banner Causes Layout Shift - Customer Support Investigation

**Date**: 2026-03-31
**Status**: Resolved
**Customer**: Internal report
**Priority**: Medium
**Reported By**: Internal

## Issue Description
When inbox success or error banners appeared and then hid again, the inbox content moved down and back up. This made repeated inbox triage frustrating because the list position visibly jumped.

## Customer Impact
- 1 reported inbox workflow affected
- Medium usability impact while processing multiple inbox actions
- Increased risk of clicking the wrong item as the list reflowed during interaction

## Investigation Steps
1. Reviewed the inbox view and confirmed both session flash messages and Alpine AJAX banners were rendered inline above the inbox content.
2. Traced the Alpine flow and confirmed `flashSuccess` / `flashError` are toggled with `x-show`, which removes and re-adds the banner nodes from normal layout flow.
3. Added a failing inbox page regression test for a fixed flash region before changing the markup.

## Root Cause Analysis
The flash banners were part of the normal document flow. Showing or hiding them changed the page height above the inbox list, so the content below reflowed every time a banner appeared or disappeared.

## Resolution
Moved the inbox flash region into a fixed overlay container positioned below the sticky nav. The banners now remain visible without affecting the inbox layout, so the list no longer jumps during AJAX feedback.

## Customer Communication
- 2026-03-31: Fixed the inbox flash banner layout shift by moving the messages into a fixed overlay region.

## Prevention & Follow-up
- [x] Add regression coverage for the fixed inbox flash region
- [ ] Consider browser-level regression coverage if more inbox motion issues appear

## Related Issues
- `support/2026-03-31-inbox-email-sender-ajax-button-state.md`

## Lessons Learned
Transient feedback should not live in normal page flow when it appears during repeated list interactions, especially on triage screens where users are likely to click multiple items in sequence.
