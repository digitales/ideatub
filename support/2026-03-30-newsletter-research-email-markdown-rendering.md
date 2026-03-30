# Newsletter Research Email Body Renders as Heading - Customer Support Investigation

**Date**: 2026-03-30
**Status**: Resolved
**Customer**: customer-reported
**Priority**: Medium
**Reported By**: Customer

## Issue Description
Newsletter research pages were rendering parts of raw email body text as markdown headings. In the reported case, the first body paragraph displayed as an `h2` instead of regular paragraph text.

## Customer Impact
- 1 known report
- Newsletter research content appeared visually incorrect on the research page
- Reduced trust in research-page rendering for long-form email newsletters

## Investigation Steps
1. Traced the rendering path from newsletter research generation to `IdeaController::showResearch()` and the shared research content partial.
2. Reproduced the issue with a focused unit test that created newsletter research from email body text containing a markdown-style separator (`---`).
3. Confirmed `EmailNewsletterResearchService::buildResearchMarkdown()` was inserting raw email body text directly into the markdown document.
4. Verified `CommonMarkConverter` interpreted that raw body text as markdown structure during research-page rendering.

## Root Cause Analysis
The newsletter research builder treated imported email body text as authored markdown. When the body contained markdown-like syntax such as a separator line, CommonMark promoted the preceding paragraph into a heading. This was not a view-layer bug; it originated in how the research markdown document was composed.

## Resolution
Updated `EmailNewsletterResearchService` so raw newsletter body text and transcript text are emitted as literal escaped paragraph HTML blocks inside the generated research document instead of raw markdown text. Added a regression test covering markdown-like separators in newsletter body content.

## Customer Communication
- 2026-03-30: Confirmed the rendering issue, identified the markdown-composition root cause, and implemented a fix with regression coverage.

## Prevention & Follow-up
- [x] Add regression coverage for markdown-like newsletter body text
- [ ] Re-run broader newsletter/research suites once the local test database migration state is healthy

## Related Issues
- `support/2026-03-24-imported-emails-missing-body-text.md`

## Lessons Learned
Any raw third-party text included inside stored markdown documents should be treated as literal content by default. Email/newsletter copy often contains syntax that markdown parsers will reinterpret structurally.

## References
- `app/Services/Email/EmailNewsletterResearchService.php`
- `tests/Unit/Services/EmailNewsletterResearchServiceTest.php`
