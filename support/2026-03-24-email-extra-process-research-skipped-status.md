# Email Extra Process vs Research Skipped - Customer Support Investigation

**Date**: 2026-03-24
**Status**: Resolved
**Customer**: Internal user
**Priority**: Medium
**Reported By**: Internal

## Issue Description
An email thought shows `Research skipped` on the email listing page while the email detail page shows the sender rule as `Extra process`.

## Customer Impact
- 1 known user affected
- Confusing status presentation on email thoughts
- May lead users to believe sender rule state and per-email processing state are inconsistent

## Investigation Steps
1. Reviewed the listing badge partial in `resources/views/idea/partials/email_newsletter_research_status.blade.php`.
2. Reviewed the sender rule card in `resources/views/idea/partials/thought_detail_sender_rule_card.blade.php`.
3. Traced email import and newsletter research state updates through `app/Services/Email/EmailImportService.php`, `app/Jobs/ProcessExtraEmailResearch.php`, and `app/Http/Controllers/EmailResearchController.php`.
4. Reviewed tests and specs covering skipped newsletter research and manual re-trigger behavior.

## Root Cause Analysis
The two UI surfaces read different pieces of state:

- The listing page badge reads `thought.source_metadata.newsletter_research.status`, which represents the outcome of newsletter research for that specific email thought.
- The detail page sender rule card reads the current `email_sender_rules` row for the sender, which represents the active rule for that sender now.

These values can legitimately differ. In particular:

- `extra_process` means the sender is configured for newsletter research processing.
- `research_skipped` means the newsletter research run for this specific email was attempted (or manually re-triggered) but skipped, for example because the content was too thin.

Saving `Extra process` from the thought page does not retro-process the current email or clear a prior `newsletter_research` status. The current email must be re-queued via the `Run newsletter research` action if the user wants to retry processing.

## Resolution
No backend data fix was required. The behavior matches the current implementation and spec:

- sender rule changes affect sender classification state
- newsletter research status reflects the last processing outcome for the specific email thought
- retrying newsletter research is an explicit separate action

## Customer Communication
- 2026-03-24: Confirmed that the UI is showing two different states, not conflicting data. Advised that `Run newsletter research` is the action that re-queues the current email.

## Prevention & Follow-up
- [ ] Consider making the listing badge copy more explicit, e.g. `Newsletter skipped` instead of `Research skipped`.
- [ ] Consider showing both sender-rule state and newsletter-processing state together on email listings when present.
- [ ] Consider prompting or auto-requeueing when a user changes a thought's sender rule to `extra_process`.

## Related Issues
- `docs/superpowers/specs/2026-03-21-email-sender-rules-and-research-design.md`
- `docs/superpowers/specs/2026-03-23-email-research-button-design.md`

## Lessons Learned
The current UI exposes sender classification state and per-email processing outcome separately, but the labels are close enough that users can reasonably read them as the same thing. This is a UX clarity gap rather than a storage or processing bug.

## References
- `resources/views/idea/partials/email_newsletter_research_status.blade.php`
- `resources/views/idea/partials/thought_detail_sender_rule_card.blade.php`
- `app/Services/Email/EmailImportService.php`
- `app/Jobs/ProcessExtraEmailResearch.php`
- `app/Http/Controllers/EmailResearchController.php`
