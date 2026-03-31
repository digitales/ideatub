# Email Research Missing Backlinks - Customer Support Investigation

**Date**: 2026-03-31
**Status**: Escalated
**Customer**: Internal user
**Priority**: Medium
**Reported By**: Internal

## Issue Description
Email research pages are not always showing the expected link back to the original email thought.

## Customer Impact
- 1 known internal user affected
- Users can open email research from an email thought but may not be able to navigate back to the original email from the research page
- Historical email research can appear partially disconnected even though the underlying stored email row still knows the intended relationship

## Investigation Steps
1. Reviewed the current research-detail rendering path in `app/Http/Controllers/IdeaController.php` and `resources/views/idea/research_show.blade.php`.
2. Confirmed the research page only renders the related-email card when explicit `email_thought_id`, `email_subject`, and `email_sender` metadata can be resolved from the research root.
3. Reviewed the email-research write paths in `app/Services/ResearchService.php` and `app/Services/Email/EmailNewsletterResearchService.php` to verify whether new research writes bidirectional linkage.
4. Reviewed the backfill implementation in `app/Console/Commands/BackfillEmailResearchLinksCommand.php` and its feature coverage in `tests/Feature/BackfillEmailResearchLinksCommandTest.php`.
5. Reviewed the linking spec in `docs/superpowers/specs/2026-03-23-email-research-detail-linking-design.md` to confirm intended behavior for historical records.
6. Checked the local development database via Laravel Tinker; this workspace has `0` `thoughts`, `0` `imported_emails`, and `0` `captured_inbound_emails`, so no local production-like rows were available for direct data confirmation.

## Root Cause Analysis
This appears to be a historical-data linkage gap, not a research-page rendering bug.

- `IdeaController::showResearch()` calls `resolveResearchRelatedEmailCard()` and only renders the related-email card when the research root contains explicit `email_thought_id`, `email_subject`, and `email_sender` values.
- The controller intentionally omits the card when those fields are missing or conflicting. The spec explicitly says not to recover the relationship heuristically at request time.
- Both current write paths already persist bidirectional linkage for newly created email research:
  - `ResearchService::runResearchForIdea()` stores the email linkage on the research thought and writes `research_thought_id` back to the email thought and stored email row.
  - `EmailNewsletterResearchService::createFromEmailThought()` stores the reverse-link fields in research `source_metadata` and writes `research_thought_id` back to the email thought and stored email row.
- The codebase already includes `email-research:backfill-links`, a dedicated command whose only purpose is to populate missing reverse-link metadata on existing research thoughts from durable stored email rows.

Taken together, the most likely cause is that older email research records were created before the explicit backlink fields existed, or before the backfill was run in the affected environment. Those records still have enough durable linkage in stored email rows for repair, but the research page correctly refuses to guess.

## Resolution
No application code change was applied in this support investigation.

Recommended remediation in the affected environment:

- Run `php artisan email-research:backfill-links --dry-run` to measure affected rows.
- If the dry run output looks correct, run `php artisan email-research:backfill-links`.
- Spot-check repaired research pages to confirm the related-email card now appears.
- If any rows remain unlinked after backfill, inspect the command's `Conflicted` count because those rows likely already contain inconsistent explicit metadata and will need manual review.

## Customer Communication
- 2026-03-31: Confirmed this is most likely a historical email-research linkage gap. New write paths already store backlinks, and the intended repair path is the existing email-research backfill command rather than a request-time UI fallback.

## Prevention & Follow-up
- [ ] Run `php artisan email-research:backfill-links --dry-run` in the affected environment and record counts.
- [ ] Run `php artisan email-research:backfill-links` if the dry run matches expectations.
- [ ] Review any conflicted rows reported by the command and repair them manually if needed.
- [ ] Add a deployment/runbook step for data backfills when new explicit relationship metadata is introduced.

## Related Issues
- `support/2026-03-24-imported-emails-missing-body-text.md`
- `docs/superpowers/specs/2026-03-23-email-research-detail-linking-design.md`
- `docs/superpowers/plans/2026-03-24-email-research-preview.md`

## Lessons Learned
The backlink UI is behaving according to the spec: it only trusts explicit stored IDs. When new navigation metadata is introduced for existing records, the rollout is incomplete until the corresponding backfill has been run in the target environment.

## References
- `app/Http/Controllers/IdeaController.php`
- `resources/views/idea/research_show.blade.php`
- `app/Services/ResearchService.php`
- `app/Services/Email/EmailNewsletterResearchService.php`
- `app/Console/Commands/BackfillEmailResearchLinksCommand.php`
- `tests/Feature/ResearchShowTest.php`
- `tests/Feature/BackfillEmailResearchLinksCommandTest.php`
- `docs/superpowers/specs/2026-03-23-email-research-detail-linking-design.md`
