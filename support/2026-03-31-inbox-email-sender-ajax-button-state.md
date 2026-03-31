# Inbox Allow Sender Button Lacks Inline Feedback - Customer Support Investigation

**Date**: 2026-03-31
**Status**: Resolved
**Customer**: Internal report
**Priority**: Medium
**Reported By**: Internal

## Issue Description
When triaging `email_sender_review` items in the inbox, clicking `Allow sender` did not visibly change the button inline while the request was running. Other inbox actions already behaved like AJAX actions, so this flow felt inconsistent and looked stalled.

## Customer Impact
- 1 reported user flow affected
- Medium UX impact during inbox triage
- Could make the allow flow look broken because it performs more work than the other sender actions

## Investigation Steps
1. Verified the inbox sender-review forms all submit through the shared Alpine `submitAction()` AJAX handler.
2. Confirmed the `allow` route already returns JSON and has focused feature coverage for AJAX success paths.
3. Compared the frontend request handling with the rendered button markup and found no per-button pending labels or inline state changes during submission.
4. Added a failing inbox page regression test for sender-review pending labels before implementing the fix.

## Root Cause Analysis
The `allow` flow was already using AJAX, but the clicked button only became disabled. Because `allow` also performs synchronous thought creation, it can take longer than the other sender-review actions, making the unchanged label look like the request was not being handled inline.

## Resolution
Added explicit idle and pending labels to inbox action buttons and updated the shared inbox AJAX handler to swap the clicked button text while a request is in flight, then restore it if the request fails or needs re-enabling.

## Customer Communication
- 2026-03-31: Fixed the inbox sender-review button state so the allow action now shows inline progress feedback during AJAX submission.

## Prevention & Follow-up
- [x] Add a regression test covering sender-review pending labels on the inbox page
- [ ] Consider adding browser-level coverage for inbox AJAX button state changes if similar issues recur

## Related Issues
- `support/2026-03-13-store-thought-button-not-functioning-after-ajax-change.md`

## Lessons Learned
Longer-running AJAX actions need explicit in-flight feedback even when the transport layer is already correct, otherwise users read the lack of label change as a broken interaction.
