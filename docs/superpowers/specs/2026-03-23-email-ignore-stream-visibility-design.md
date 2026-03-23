# Email Ignore Stream Visibility Design

**Date:** 2026-03-23
**Status:** Approved

## Overview

The app already supports sender rules such as `allow`, `ignore`, `review`, and `extra_process`. Today, applying an `ignore` rule affects sender classification and future processing, but email thoughts from that sender can still appear in streams and other top-level thought lists once they exist as `Thought` records.

This design adds explicit stream visibility for email thoughts so that:

1. email thoughts from ignored senders are hidden from all stream-style thought lists
2. newly created email thoughts for ignored senders start hidden immediately
3. existing email thoughts are reconciled in the background when a sender rule changes
4. previously hidden email thoughts reappear automatically if the sender is no longer ignored

The change uses an explicit visibility flag on `thoughts` rather than overloading deletion semantics such as `deleted_at`.

## Goals

- Hide email thoughts from ignored senders across all stream-style thought listings
- Keep deletion semantics separate from sender-rule visibility
- Make new email thoughts respect ignore rules immediately at creation time
- Use a background reconciliation flow to update existing thoughts when sender rules change
- Restore hidden email thoughts automatically when an `ignore` rule is removed or changed

## Non-Goals

- Soft-deleting thoughts or email records
- Hiding non-email thoughts
- Hiding thought detail pages by direct URL
- Introducing manual hide/unhide controls as part of this change
- Replacing the existing sender-rule settings page or inbox review flows

## Existing Infrastructure To Reuse

The implementation should extend the existing sender-rule and email-thought stack rather than creating a parallel rule system:

- `App\Models\Thought`
- `App\Models\ImportedEmail`
- `App\Models\CapturedInboundEmail`
- `App\Models\EmailSenderRule`
- `App\Services\Email\EmailSenderRuleService`
- the existing sender resolution precedence used by `App\Services\Email\EmailReviewActionService`
- the existing email-thought creation flows for Fastmail/imported and Postmark/captured inbound email
- stream and top-level thought listing queries in `App\Http\Controllers\IdeaController`

This design intentionally changes an earlier boundary in prior sender-rule docs. Earlier specs treated retroactive processing of old emails as out of scope. That remains true for re-import, re-triage, or pipeline reprocessing. This design newly allows retroactive stream-visibility reconciliation for already-created `Thought` rows.

## Visibility Model

Add explicit visibility fields to `thoughts`:

- `is_visible_in_stream` boolean, default `true`
- `visibility_reason` nullable string

Initial rule-driven reason values:

- `ignored_sender`

Behavior rules:

- only email thoughts participate in sender-rule visibility changes
- a hidden email thought remains hidden only while the sender is effectively ignored
- automatic unhide only applies when `visibility_reason = ignored_sender`
- non-email thoughts ignore these fields for sender-rule purposes

This keeps stream visibility separate from deletion and leaves room for future visibility reasons without conflating them with sender rules.

Implementation guidance:

- use a shared constant or enum for `ignored_sender` rather than ad hoc string literals
- treat the visibility filter as additive to existing query rules, not as a replacement for any current exclusion behavior

## Sender Rule To Visibility Flow

### When sender rule becomes `ignore`

When a sender rule is created or updated to `ignore`:

1. future email thoughts for that sender should be created with `is_visible_in_stream = false` and `visibility_reason = ignored_sender`
2. a queued reconciliation job should be dispatched for the current user and normalized sender email
3. that job should find matching existing email thoughts and hide them

### When sender rule stops being `ignore`

When a sender rule is changed from `ignore` to another action, or the rule is deleted:

1. a queued reconciliation job should be dispatched for the current user and normalized sender email
2. that job should restore matching email thoughts to visible only if they are currently hidden for `ignored_sender`

This ensures the system converges after rule changes while keeping the write path fast.

## Initial Rollout And Backfill

Rule-change events alone are not sufficient for rollout because some users may already have `ignore` rules before this feature ships.

The implementation should include a one-time reconciliation path that processes all existing ignored sender rules and hides matching existing email thoughts.

Acceptable rollout shapes:

- a one-time artisan command run during deploy
- a dedicated backfill job that fans out reconciliation jobs for all current `(user_id, sender_email)` pairs where the action is `ignore`

Without this initial backfill, existing ignored senders would keep showing visible historical email thoughts until the rule was edited again.

## New Email Thought Creation

Email-thought creation flows should check the effective sender rule during creation so that new thoughts do not briefly appear in the stream before the background reconciliation job runs.

For a sender whose normalized rule is `ignore`:

- create the `Thought` with `is_visible_in_stream = false`
- set `visibility_reason = ignored_sender`

For all other senders:

- create the `Thought` with `is_visible_in_stream = true`
- set `visibility_reason = null`

This behavior should apply consistently to both `ImportedEmail`-backed and `CapturedInboundEmail`-backed thought creation.

## Reconciliation Job Design

Add a dedicated queued job that reconciles stream visibility for one sender and one user.

Recommended responsibility:

- input: `user_id`, normalized sender email
- resolve all email thoughts for that sender and user
- determine whether the sender is currently ignored
- hide or restore matching thoughts accordingly

Expected behavior:

- if the sender is currently ignored, matching email thoughts should be updated to:
  - `is_visible_in_stream = false`
  - `visibility_reason = ignored_sender`
  - only when the thought is currently visible or already hidden for `ignored_sender`
- if the sender is not currently ignored, matching email thoughts should be updated to:
  - `is_visible_in_stream = true`
  - `visibility_reason = null`
  - only where `visibility_reason = ignored_sender`

The job should be idempotent. Re-running it for the same sender and user should converge to the same result without creating duplicate side effects.

## Sender Matching Rules

Visibility reconciliation must reuse the same sender normalization and resolution rules as the existing sender-rule system. It should not introduce a second sender-matching algorithm.

For thought-backed email rows, sender resolution should stay aligned with the established precedence:

- `ImportedEmail`: `rule_email` first, then formatted sender from `from_json`, then any approved metadata fallback
- `CapturedInboundEmail`: `rule_email` first, then `sender_email`, then any approved metadata fallback

Matching should always use the normalized sender email produced by `EmailSenderRuleService::normalizeSender()`.

If a thought's sender cannot be resolved safely, reconciliation should skip that thought rather than making a potentially incorrect visibility change.

## Query Behavior

All stream-style thought listings that may surface email thoughts should exclude rows where `is_visible_in_stream = false`.

This includes:

- the main stream
- the email stream
- other top-level thought lists that currently include email thoughts, such as the recent homepage/index list

The filter should be applied at the `Thought` query layer so the UI does not need special-case hiding logic.

Recommended implementation shape:

- add a shared `Thought` query scope for visible-in-stream behavior
- use that scope everywhere user-facing stream-style lists, search results, counters, and realtime checks should exclude hidden thoughts

At minimum, the implementation must audit and apply the filter to every user-facing surface where email thoughts can appear. It is not sufficient to update only one controller action.

Because non-email thoughts are not affected by sender-rule hiding, the visibility filter should preserve normal behavior for all other sources.

Single-thought read paths such as thought detail pages, direct read-by-id endpoints, and any other owner-authorized single-record views must not apply the stream-visibility filter. A hidden email thought remains directly accessible by URL in this design.

Feature-flag behavior:

- when the sender-rule feature flag is enabled, read paths should honor `is_visible_in_stream`
- when the sender-rule feature flag is disabled, read paths should still honor persisted visibility on `thoughts`

The flag controls whether sender-rule-driven mutations and reconciliation run. It does not disable the visibility filter for already-hidden thoughts, because doing so would unexpectedly resurface rows that were intentionally hidden earlier.

## Error Handling And Safety

- If sender resolution fails during reconciliation, skip the affected thought and continue.
- If the sender-rule feature flag is disabled, sender-rule visibility reconciliation and hide-on-create behavior should not run.
- If a sender rule changes multiple times quickly, duplicate reconciliation jobs are acceptable as long as each job is idempotent and the final state matches the latest rule.
- Automatic restore must never unhide thoughts hidden for another future reason; only rows with `visibility_reason = ignored_sender` should be restored.
- Ignore-driven hiding must not overwrite another future visibility reason. A reconciliation job may hide a thought for `ignored_sender` only if it is currently visible or already hidden for `ignored_sender`.
- If the stored visibility columns are in an inconsistent partial state, reconciliation may normalize them conservatively. For this feature, the canonical combinations are:
  - visible thought: `is_visible_in_stream = true`, `visibility_reason = null`
  - ignored sender hidden thought: `is_visible_in_stream = false`, `visibility_reason = ignored_sender`

## Testing

Add feature coverage for the visibility model and sender-rule reconciliation behavior.

Required cases:

- rollout backfill hides existing email thoughts for senders that were already ignored before the feature shipped
- ignoring a sender hides their existing email thoughts from the main stream
- ignoring a sender hides their existing email thoughts from the email stream
- ignoring a sender hides their existing email thoughts from other top-level thought lists that include email thoughts
- changing an ignored sender to a non-ignore action restores previously hidden thoughts
- deleting an ignored sender rule restores previously hidden thoughts
- newly created email thoughts for ignored senders start hidden immediately
- newly created email thoughts for non-ignored senders remain visible
- non-email thoughts are unaffected by sender-rule visibility changes
- thoughts hidden for a reason other than `ignored_sender` are not restored by sender-rule reconciliation
- both `ImportedEmail` and `CapturedInboundEmail` backed thoughts participate correctly
- reconciliation is idempotent when the same job runs more than once
- unresolved-sender thoughts are skipped without changing visibility
- feature-flag-disabled mode does not run hide-on-create or reconciliation, while persisted hidden thoughts remain filtered out of listings
- at least one non-stream list or related surfaced view that includes top-level thoughts continues to respect the shared visibility filter

## Out Of Scope

- user-facing manual visibility controls
- audit history for visibility changes
- domain-level sender rules
- retroactive cleanup of thoughts whose sender data cannot be resolved
- hiding the thought detail page itself
