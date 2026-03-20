# IdeaTub - Fastmail email sync design

**Date:** 2026-03-20  
**Status:** Draft  
**Scope:** Fastmail-first mailbox history import and ongoing sync using a provider connector architecture; one message per thought with thread metadata, participant metadata, summaries, and per-user connected accounts inside IdeaTub.

## Overview

- **Goal:** Let a user connect a Fastmail mailbox, backfill past email, and keep syncing new mail into IdeaTub as searchable, processable email thoughts.
- **Inspiration:** Follow the spirit of the OB1 email history import recipe: email should become durable knowledge with filtering, deduplication, metadata extraction, and semantic retrieval, while adapting the flow to IdeaTub's existing thought pipeline and multi-user application model.
- **v1 provider:** Fastmail first, with a provider abstraction thinly designed so Gmail and Microsoft 365 can be added later without reshaping the core data model.
- **Thought model:** Each imported email message becomes one thought. Thread and participant relationships are stored as metadata so related emails can be grouped later without introducing mutable thread-rollup thoughts in v1.
- **Product model:** This is separate from the existing Postmark inbound "email a thought in" flow. Mailbox sync is a connected-account product, not a webhook capture product.

---

## 1. Product goals and non-goals

### 1.1 Goals

- Users can connect a Fastmail account inside IdeaTub.
- Users can run an initial backfill and then keep syncing new mail incrementally.
- The system imports:
  - sent mail
  - directly addressed personal received mail
- The system excludes low-signal or automated mail by default:
  - newsletters
  - notifications
  - no-reply or bulk-style mail
- Each imported message is:
  - cleaned
  - deduplicated
  - summarized
  - enriched with metadata such as participants, topics, action items, provider, mailbox, and thread identifiers
- Imported messages become normal IdeaTub thoughts with `source = 'email'`.

### 1.2 Non-goals for v1

- Gmail connector
- Microsoft 365 / Outlook connector
- A full contacts or address-book system
- Attachment body ingestion or attachment search
- A dedicated in-app email inbox UI
- User-authored custom rule builders for filtering
- "One thread = one mutable thought" storage

---

## 2. Architecture

### 2.1 High-level flow

The mailbox sync system should be a dedicated subsystem that sits alongside the existing thought capture flows:

`Mail account connection -> provider connector -> normalized imported email records -> email processing pipeline -> thoughts`

This keeps provider-specific fetch and sync logic separate from IdeaTub's core ingestion logic.

### 2.2 Core units

#### A. Mail account management

Responsible for:

- storing per-user connected mailbox accounts
- storing encrypted credentials or tokens
- storing provider sync checkpoints
- storing sync preferences such as enabled/disabled status and backfill defaults

#### B. Provider connector layer

Responsible for:

- Fastmail authentication or credential use
- mailbox discovery
- backfill fetching
- incremental sync fetching
- provider checkpoint handling
- fetching provider-specific message data

In v1 there is only one real connector: Fastmail.

#### C. Normalization/import layer

Responsible for converting provider-specific email data into a consistent internal representation before IdeaTub-specific processing happens.

This prevents Fastmail field names and semantics from leaking into the rest of the application.

#### D. Thought ingestion layer

Responsible for:

- filtering
- body cleanup
- deduplication
- metadata extraction
- summary generation
- thought creation through shared IdeaTub thought capture primitives

### 2.3 Separation from inbound email capture

IdeaTub already has Postmark-based inbound email capture for "email a thought into the app." That flow should remain separate.

- **Inbound email capture:** user sends a message to an IdeaTub/Postmark capture address
- **Mailbox sync:** IdeaTub connects to a user mailbox and imports historical plus ongoing mail

The two flows may share utilities and metadata conventions, but they should not share account UI, sync state, or webhook assumptions.

---

## 3. Data model

### 3.1 `mail_accounts`

One row per connected mailbox account.

Suggested fields:

- `id`
- `user_id`
- `provider` - `'fastmail'` in v1
- `display_name`
- `account_email`
- `status` - e.g. `active`, `needs_reauth`, `disabled`, `sync_error`
- `credentials_json` - encrypted at rest
- `settings_json` - include sent/received toggles, mailbox selection, exclusion toggles
- `provider_checkpoint_json` - persisted incremental sync cursor/checkpoint
- `last_synced_at`
- `last_successful_sync_at`
- `created_at`
- `updated_at`

Important rule: secrets and checkpoints belong here, not in thought metadata.

### 3.2 `mail_sync_runs`

One row per backfill or incremental sync run for observability, diagnostics, and retries.

Suggested fields:

- `id`
- `mail_account_id`
- `run_type` - `backfill` or `incremental`
- `status` - `queued`, `running`, `completed`, `completed_with_errors`, `failed`
- `started_at`
- `finished_at`
- `stats_json` - counts for fetched, imported, skipped, filtered, failed
- `error_summary`
- `created_at`
- `updated_at`

### 3.3 `imported_emails`

One row per normalized imported message. This is the durable sync/idempotency record, separate from thoughts.

Suggested fields:

- `id`
- `user_id`
- `mail_account_id`
- `mail_sync_run_id` nullable
- `provider`
- `provider_message_id`
- `provider_thread_id` nullable
- `provider_mailbox_id` nullable
- `provider_mailbox_name` nullable
- `direction` - `sent` or `received`
- `subject` nullable
- `from_json`
- `to_json`
- `cc_json` nullable
- `bcc_json` nullable when available
- `participants_json` - normalized participant list
- `sent_at` nullable
- `received_at` nullable
- `body_text`
- `summary` nullable
- `message_metadata_json` - tags, people, action items, topics, filtering rationale, provider extras
- `content_fingerprint` nullable
- `thought_id` nullable
- `processing_status` - `pending`, `imported`, `filtered`, `failed`
- `failure_reason` nullable
- `created_at`
- `updated_at`

### 3.4 Thought mapping

Each imported email should create one IdeaTub thought.

- `source = 'email'`
- `source_metadata` should include:
  - `provider`
  - `mail_account_id`
  - `imported_email_id`
  - `provider_message_id`
  - `provider_thread_id`
  - `direction`
  - `subject`
  - `sent_at` / `received_at`
  - `participants`
  - `provider_mailbox_name`
  - `mail_sync_run_id`

This allows Stream and search to treat imported emails as ordinary thoughts while still retaining email-specific context.

### 3.5 Participants, not contacts

v1 should not introduce a first-class contacts system.

Instead:

- store normalized participants per message
- keep participant roles such as `from`, `to`, `cc`, `bcc`
- allow metadata extraction to include "people" tags or lists
- defer identity resolution across multiple addresses until a future contacts layer is justified

This design should refer to this concept as **participants**, not contacts.

---

## 4. Provider strategy

### 4.1 Fastmail-first connector

The first implemented provider should be a Fastmail connector using a Fastmail-native API approach rather than generic IMAP.

The rest of the system should only depend on a thin provider interface such as:

- connect/authenticate
- list mailboxes
- start backfill
- continue sync from checkpoint
- fetch messages
- map provider state into normalized email records

### 4.2 Thin abstraction rule

The connector abstraction should be intentionally small.

It exists to avoid a rewrite when Gmail and Microsoft are added later, not to model every possible provider feature in v1.

This keeps the design extensible without overbuilding.

### 4.3 Provider checkpoints

Incremental sync should rely on provider-specific checkpoint state stored on `mail_accounts`, not just "latest imported date."

Reason:

- safer for moved messages
- safer for provider-side edits
- more correct for provider sync semantics
- easier to resume reliably after failures

---

## 5. Sync behavior

### 5.1 Sync modes

There are two sync modes using the same processing pipeline.

#### Backfill

- triggered after account connection or by explicit user action
- imports historical mail over a selected time window
- runs in batches
- can cover 30 days, 90 days, 1 year, or all available history

#### Incremental sync

- runs on a schedule
- resumes from the stored provider checkpoint
- only fetches new or changed messages since the last successful checkpoint

### 5.2 Scheduling

Incremental sync should run as queued background jobs using the app's existing queue infrastructure.

Future scheduling frequency can be adjusted, but the architecture should assume repeated automated sync, not manual-only execution.

### 5.3 Message-level isolation

Failures should be isolated per message whenever possible.

If one message cannot be parsed or processed:

- mark that message as failed in `imported_emails`
- increment failure counts in `mail_sync_runs`
- continue processing the rest of the run

This is important for large backfills.

### 5.4 Idempotency

Primary idempotency should use provider message identity:

- one provider message id -> one `imported_emails` row for that account/user
- one imported email -> at most one IdeaTub thought

Secondary content fingerprinting may be useful as a fallback or analysis aid, but provider identity should be the main dedupe mechanism.

### 5.5 Thought mutability

Email thoughts should be effectively immutable after creation, except for safe metadata refresh if absolutely necessary.

If the sync sees the same provider message again, it should usually skip thought recreation and only update supporting import metadata if needed.

This keeps the knowledge surface stable and prevents silent rewriting of prior thoughts.

---

## 6. Filtering and processing

### 6.1 Default inclusion policy

By default, v1 should import:

- sent mail
- directly addressed personal received mail

By default, v1 should exclude:

- newsletters
- automated notifications
- bulk mail
- no-reply style mail
- low-signal auto-generated mail

### 6.2 User-configurable v1 settings

Keep configuration simple. Users should be able to control:

- whether sync is enabled
- backfill window
- whether to include sent mail
- whether to include received personal mail
- whether to exclude automated/bulk mail
- optionally which mailboxes/folders to include if Fastmail exposes that cleanly

Do not add a full custom rules builder in v1.

### 6.3 Cleaning pipeline

For each normalized message:

1. Prefer clean text body when available
2. Convert HTML to text when needed
3. Strip quoted reply chains where possible
4. Strip signatures where possible
5. Preserve important context in metadata:
   - subject
   - timestamps
   - participants
   - thread id
   - mailbox/folder

### 6.4 AI processing

Each included message should go through AI processing to produce:

- short summary
- extracted topics
- extracted people/participants
- extracted action items
- other tags useful for semantic retrieval

The summary should be stored on `imported_emails` and may also inform the thought content or metadata.

### 6.5 Thought content strategy

The thought content should primarily reflect the cleaned message body, not only the summary.

Recommended format:

- keep the cleaned email body as the main content
- optionally prefix it with lightweight context such as sender, subject, and date if that improves retrieval
- keep summary and structured metadata outside the content body

This mirrors the useful part of the OB1 recipe approach: store the actual message as the durable knowledge unit while summaries and metadata improve navigation and search.

---

## 7. UI and user flow

### 7.1 Settings area

Add a new settings area for connected email accounts, separate from inbound email capture.

Suggested entry point:

- `Settings -> Email Accounts`

### 7.2 v1 account flow

The Fastmail account UI should support:

- connect a Fastmail account
- securely save credentials or tokens
- enable or disable sync
- choose the first backfill window
- view last sync status
- view last successful sync time
- view counts from the latest run

If mailbox/folder selection is feasible without heavy complexity, it can be included as a simple selector.

### 7.3 No dedicated inbox UI in v1

Imported email thoughts should surface through existing IdeaTub experiences first:

- search
- stream
- recency views

Email-specific display enhancements can be added later, but v1 does not need a separate inbox product surface.

### 7.4 Thread experience

Even though each message is its own thought, thread metadata should support future UX such as:

- grouping related emails
- filtering by thread id
- viewing all related messages in a conversation

That future UX should be enabled by metadata now, not by adding thread rollup records in v1.

---

## 8. Security and privacy

### 8.1 Secrets

- Encrypt account credentials/tokens at rest
- Never expose raw secret values in the UI after save
- Never log credentials or raw auth payloads

### 8.2 Logging

All logs should be sanitized.

If sync logs capture message examples for debugging, they should:

- avoid full raw payload dumps by default
- avoid raw secrets entirely
- truncate bodies if temporary logging is ever enabled

### 8.3 Data isolation

Every record in the subsystem must remain scoped to the owning user:

- `mail_accounts`
- `mail_sync_runs`
- `imported_emails`
- resulting thoughts

### 8.4 Attachments

Do not ingest attachment binaries in v1.

At most, store attachment metadata such as:

- attachment names
- attachment count
- maybe MIME types if already easily available

This keeps privacy and storage risk low while preserving future expansion points.

---

## 9. Testing strategy

### 9.1 Connector tests

Test the Fastmail connector for:

- auth/credential handling
- mailbox listing
- checkpoint pagination and resume behavior
- message normalization

### 9.2 Pipeline tests

Test:

- inclusion and exclusion filtering
- deduplication by provider message id
- quoted reply stripping
- signature stripping
- summary generation
- participant extraction
- failure handling per message

### 9.3 Integration tests

Test end-to-end flows:

- connect account
- queue backfill
- run backfill successfully
- create thoughts
- run incremental sync
- skip duplicates
- record run stats
- handle failed messages without failing the entire run

### 9.4 Invariants to protect

The most important assertions:

- one provider message must not create duplicate thoughts
- sent and directly addressed received mail is included by default
- bulk and automated mail is excluded by default
- sync resumes from checkpoint correctly
- imported thoughts always use `source = 'email'`
- email thoughts have stable rich `source_metadata`

---

## 10. Rollout and boundaries

### 10.1 v1 definition

v1 is:

- Fastmail-only
- provider-native connector approach
- per-user connected account settings inside IdeaTub
- backfill plus scheduled incremental sync
- one email message per thought
- thread metadata only
- participant metadata only
- AI summaries plus metadata extraction
- attachment metadata only

### 10.2 Future expansions

Designed for later addition of:

- Gmail / Google Workspace connector
- Microsoft 365 connector
- participant-to-contact identity resolution
- attachment extraction
- thread rollup views
- richer email-specific UI

### 10.3 Migration boundary from current email features

The existing inbound email feature should remain intact and separate.

Shared utilities can include:

- email cleanup helpers
- email metadata conventions
- shared thought creation services

But mailbox sync should have its own:

- settings UI
- account records
- sync runs
- imported message records

---

## 11. Recommended implementation direction

The recommended implementation approach is:

- build a generic mailbox sync subsystem
- keep the provider abstraction thin
- implement only Fastmail in v1
- normalize message data before IdeaTub-specific processing
- persist durable import records separate from thoughts
- create one message thought per imported message
- keep thread and participant awareness in metadata

This gives IdeaTub the right long-term foundation without forcing Gmail and Microsoft into the first release.
