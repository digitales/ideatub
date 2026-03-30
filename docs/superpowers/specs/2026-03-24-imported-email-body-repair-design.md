# Imported Email Body Repair Design

**Date:** 2026-03-24
**Status:** Approved
**Scope:** Fix Fastmail body fetching for future imports and add a targeted repair path for existing `imported_emails` rows missing `body_text`, while preserving existing linkage and processing state.

## Overview

Imported Fastmail emails are currently missing `body_text` in some cases because the connector assumes `Email/get` returns inline text body values without explicitly requesting them. The repair should address two needs:

- future imports must fetch body content correctly
- existing `imported_emails` rows with missing `body_text` must be repairable without deleting and recreating rows

The chosen approach is a targeted backfill, not delete-and-reimport.

## Goals

- Fetch body text correctly from Fastmail/JMAP for new imports.
- Preserve `imported_emails` as the durable idempotency and linkage record.
- Repair existing rows with missing `body_text` using a focused backfill flow.
- Avoid recreating thoughts, inbox items, or research state.
- Keep the repair safe to run incrementally and repeatedly.

## Non-goals

- Deleting and recreating `imported_emails` rows.
- Rebuilding thought content for already-linked thoughts in this first pass.
- Re-running newsletter research or sender-review flows automatically.
- Changing sender-policy semantics.
- Bulk reprocessing of rows that already have non-empty `body_text`.

## Problem Statement

The current Fastmail connector issues `Email/get` with only `accountId` and `#ids`, then reads `textBody[0].value` during normalization. Per RFC 8621, body values are not guaranteed to be returned unless the request explicitly asks for them.

As a result:

- future imports may store empty `body_text` even when the email has real content
- existing rows may have valid provider message ids but incomplete stored content

This is separate from intentional empty-body cases:

- filtered rows may store `body_text = null`
- cleanup may reduce real content to `''`

The repair flow should only target the fetch-contract bug, not override intentional filtering behavior.

## Design

### 1. Fastmail connector fix

Update the Fastmail `Email/get` request shape so it explicitly requests the data needed to reconstruct body text from JMAP.

Expected connector changes:

- request the needed email properties for body extraction, including `textBody` and `bodyValues`
- set `fetchTextBodyValues = true`
- include the body-part properties needed to map `textBody` parts to returned `bodyValues`

For messages without a usable `textBody`, the connector should also support HTML-only mail by:

- requesting `htmlBody`
- requesting `fetchHTMLBodyValues = true`
- deriving plain text from fetched HTML body values before passing content into the existing cleanup flow

Normalization should then:

- inspect `textBody`
- resolve the matching `partId` entries in `bodyValues`
- concatenate the relevant text body values into `NormalizedEmailMessage::$bodyText`
- if no usable text body is present, inspect `htmlBody`, resolve matching `bodyValues`, and convert HTML to text before assigning `NormalizedEmailMessage::$bodyText`

The connector should not rely on `textBody[].value` being present inline.

### 2. Single-message fetch capability

Add a connector method for fetching one message by provider id, scoped to the connected account, for example:

- `FastmailConnector::fetchMessageById(MailAccount $account, string $providerMessageId): ?NormalizedEmailMessage`

This method should reuse the same normalized body-fetch behavior as backfill and incremental sync. If Fastmail no longer returns the message, the method should return `null`.

This gives the repair flow a narrow, deterministic API instead of forcing a mailbox-wide re-sync.

### 3. Repair service / command

Add a focused repair path for existing rows with missing bodies.

Recommended shape:

- a dedicated repair service that updates one `ImportedEmail`
- a console command that batches through eligible rows and calls the service

Example responsibilities:

- identify eligible rows
- fetch the provider message again by `provider_message_id`
- clean the fetched body using the existing cleanup service
- persist repaired body fields only when a usable body is found

The command is the operator-facing entry point. The repair service contains the per-row rules so it can also be reused later from jobs or admin flows if needed.

Minimum operator controls for the command:

- dry-run mode
- `--limit`
- optional `mail_account_id` scope
- batched processing with conservative pacing so production runs do not hammer the Fastmail API

### 4. Eligibility rules

The repair flow should only target rows that are missing body content and are safe to repair.

Initial eligibility:

- provider is `fastmail`
- `body_text` is `null` or empty after trim
- row exists in `imported_emails`
- row is associated with an existing `mail_account`
- `processing_status != 'filtered'`
- row does not represent an ignored sender

For v1, filtered rows are out of scope even if `body_text` is empty, because filtered rows may intentionally omit stored content. This keeps the repair focused on the fetch-contract bug rather than overriding existing filter outcomes.

For v1, "not ignored" should be interpreted conservatively:

- if a row has `rule_action = 'ignore'`, skip it
- if ignored senders continue to produce no `imported_emails` row, they are naturally out of scope

This keeps eligibility aligned with both the current schema and any future schema changes that may persist ignored-message rows differently.

For v1, the repair flow intentionally uses this broader eligibility rule instead of trying to perfectly distinguish "missing because Fastmail body values were not fetched" from "empty after cleanup". If a row is non-filtered, non-ignored, and missing `body_text`, the system should attempt a conservative refetch and rerun cleanup. This may re-evaluate some rows that were previously emptied by cleanup alone, but it keeps the rule simple, safe, and aligned with the product intent that non-ignored imported emails should retain usable body text when possible.

### 5. Persistence rules

The repair must be conservative and preserve existing linkage.

Allowed updates:

- `body_text`
- `content_fingerprint`, using the same formula as normal import: `sha256(provider_message_id | subject | cleaned_body_text)`
- optional repair metadata in `processing_metadata_json` only, under a dedicated key such as `body_repair`

Must not change:

- `thought_id`
- `thought_deleted_at`
- `review_inbox_item_id`
- `research_thought_id`
- `processing_status`
- `rule_action`
- `rule_email`
- `failure_count`
- `failure_reason`
- `summary`

For v1, the repair flow also must not recompute or refresh non-repair metadata such as extracted review-path links already stored in `processing_metadata_json`. If repair metadata is added, it should be merged under a dedicated `body_repair` key only, leaving existing metadata untouched.

This is a content repair, not a reimport.

### 6. Missing or empty re-fetch results

If the provider message cannot be fetched:

- skip the row
- record the skip in command output and optionally in repair metadata

If the provider body is fetched but cleanup still produces an empty body:

- skip updating `body_text`
- record the row as unresolved rather than forcing a blank value

This avoids converting uncertainty into destructive writes.

### 7. Thoughts and downstream state

The first-pass repair should not rewrite existing thought content even if `body_text` is successfully repaired.

Reasoning:

- existing thoughts may already have user expectations or linked research state
- changing thought content is a user-visible mutation
- the current request is specifically about fixing `imported_emails.body_text`

Future follow-up can decide whether repaired rows should optionally refresh thought content, but that is intentionally out of scope here.

## Data Flow

### Future imports

`Fastmail Email/get -> bodyValues/textBody normalization -> cleanup -> sender policy/filtering -> imported_emails.body_text`

### Existing-row repair

`eligible imported_emails row -> fetch provider message by provider_message_id -> normalize body -> cleanup -> update body_text/content_fingerprint only`

## Error Handling

- Command should continue past per-row failures.
- Per-row failures should be counted and surfaced in command output.
- Missing provider messages are skips, not fatal command errors.
- Repair should be idempotent: rerunning it should not duplicate rows or linked records.

## Testing Strategy

### Connector tests

- `Email/get` requests `bodyValues`, `textBody`, and `fetchTextBodyValues`
- `Email/get` requests `htmlBody` and `fetchHTMLBodyValues` for HTML-only support
- body normalization reads from `bodyValues` keyed by `partId`
- multiple text body parts are combined correctly if needed
- HTML-only messages are converted into usable plain text
- missing `bodyValues` yields empty normalized body without crashing

### Repair service tests

- repairs an existing row with empty `body_text`
- skips rows whose provider message is missing
- skips rows whose cleaned body remains empty
- preserves `thought_id`, review linkage, research linkage, and processing status
- does not create a new `ImportedEmail`

### Command tests

- batches through eligible rows only
- reports repaired, skipped, and failed counts
- is safe to rerun

## Rollout

1. Ship the connector fix for future imports.
2. Ship the targeted repair command.
3. Run the repair command against existing affected rows.
4. Inspect counts and spot-check repaired records before considering any broader follow-up.

## Recommendation

Implement the connector fix and targeted repair flow together.

This resolves the root cause for new imports and provides a low-risk way to repair existing `imported_emails` rows without deleting durable linkage records or rebuilding downstream state.
