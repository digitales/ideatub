# Imported Emails Missing Body Text - Customer Support Investigation

**Date**: 2026-03-24
**Status**: Escalated
**Customer**: Internal user
**Priority**: High
**Reported By**: Internal

## Issue Description
Imported Fastmail sync emails are appearing in `imported_emails` without the expected body text.

## Customer Impact
- 1 known internal user affected
- Imported email thoughts may fall back to subject-only content when the body is missing
- Newsletter and email research quality degrades because the pipeline is missing the main email content

## Investigation Steps
1. Reviewed the existing email support investigation in `support/2026-03-24-email-extra-process-research-skipped-status.md` for related import-path context.
2. Traced the `imported_emails` write path through `app/Services/Email/EmailImportService.php`, `app/Jobs/BackfillMailAccount.php`, and `app/Jobs/SyncMailAccountIncremental.php`.
3. Reviewed Fastmail normalization in `app/Services/Fastmail/FastmailConnector.php`.
4. Reviewed cleanup and filtering behavior in `app/Services/Email/EmailBodyCleanupService.php` and `app/Services/Email/EmailFilterService.php` to distinguish API-fetch issues from intentional null/empty-body cases.
5. Checked the local app database state via Laravel Tinker; there were `0` `imported_emails` rows locally, so no production-like sample rows were available in this workspace for direct confirmation.
6. Reviewed unit and feature tests covering Fastmail connector and import behavior.
7. Verified the JMAP mail spec behavior for `Email/get` body fetching in RFC 8621.

## Root Cause Analysis
The Fastmail connector currently assumes that `Email/get` returns `textBody[].value`, but the request does not explicitly ask JMAP to include body values.

- `FastmailConnector::fetchBackfillBatch()` and `FastmailConnector::fetchIncrementalBatch()` call `Email/get` with only `accountId` and `#ids`.
- `FastmailConnector::normalizeMessages()` then reads `textBody[0].value` and stores that as `NormalizedEmailMessage::$bodyText`.
- RFC 8621 defines `fetchTextBodyValues` with a default of `false`, meaning body values are not guaranteed unless explicitly requested through `bodyValues` plus the relevant fetch flag.

This means the connector is relying on a response shape the API is not required to provide. When Fastmail omits `textBody[].value`, the current code normalizes the message body to `''`, and `EmailImportService` then persists that empty result to `imported_emails.body_text` for included messages.

This is distinct from the two intentional body-loss paths already present in the app:

- filtered messages store `body_text = null` by design
- cleanup may reduce a real body to `''` after stripping quotes/signatures

Those intentional cases do not explain the broader missing-body issue. The connector fetch contract mismatch does.

## Resolution
No application fix was applied in this support investigation.

Recommended engineering follow-up:

- update the Fastmail `Email/get` request to explicitly request body values needed for import
- normalize from JMAP `bodyValues` instead of assuming inline `textBody[].value`
- add coverage for messages where `textBody` metadata exists but body values are absent unless explicitly fetched
- evaluate a repair/backfill path for already imported rows with missing `body_text`

## Customer Communication
- 2026-03-24: Confirmed this is a backend import bug, not expected filtering behavior. Root cause is the Fastmail/JMAP request shape not explicitly fetching body values.

## Prevention & Follow-up
- [ ] Update `FastmailConnector` to request body values explicitly.
- [ ] Add connector tests that match RFC 8621 default behavior instead of assuming `textBody[].value` is present by default.
- [ ] Add an integration test that proves imported included messages persist non-empty body text when Fastmail returns body values via the supported JMAP shape.
- [ ] Decide whether existing affected `imported_emails` rows need a re-sync or targeted repair job.

## Related Issues
- `support/2026-03-24-email-extra-process-research-skipped-status.md`
- `docs/superpowers/specs/2026-03-20-fastmail-email-sync-design.md`

## Lessons Learned
The current tests encode an optimistic Fastmail response shape rather than the stricter JMAP contract. That made the connector look correct while it depended on response data that was never explicitly requested.

## References
- `app/Services/Fastmail/FastmailConnector.php`
- `app/Services/Email/EmailImportService.php`
- `app/Services/Email/EmailBodyCleanupService.php`
- `app/Services/Email/EmailFilterService.php`
- `tests/Unit/Services/FastmailConnectorTest.php`
- `tests/Unit/Services/EmailImportServiceTest.php`
- RFC 8621, `Email/get` body fetching behavior (`fetchTextBodyValues`, `bodyValues`)
