# IdeaTub - Email sender rules and newsletter research design

**Date:** 2026-03-21  
**Status:** Draft  
**Scope:** Add per-user exact-sender rules for inbound email processing across Postmark and Fastmail sync, support explicit allow/ignore/review/extra-processing behaviors, queue best-effort newsletter research for selected senders, and link stored emails, email thoughts, and research outputs inside IdeaTub.

## 1. Summary

- Add a user-scoped sender-rule layer that evaluates exact sender email addresses before normal email-thought creation.
- Apply the same rule model to both existing email ingestion paths:
  - Postmark inbound capture
  - Fastmail mailbox sync
- Support four sender actions:
  - `allow`
  - `ignore`
  - `review`
  - `extra_process`
- Unknown senders default to `review` in v1.
- For `extra_process`, create the normal email thought, then queue best-effort newsletter research that can use the email body, extracted links, and YouTube transcript text when available.
- Save the research output into IdeaTub as a research document and preserve explicit links between:
  - the stored email record
  - the email thought
  - the research thought/document

## 2. Goals and non-goals

### 2.1 Goals

- Let each user maintain an exact-address whitelist and ignore list without introducing a complex rule builder.
- Allow specific sender addresses to trigger richer processing beyond normal email import.
- Keep original emails as first-class stored records, not just transient input to thought creation.
- Route unknown senders into a review path instead of silently importing or silently discarding them.
- Keep behavior consistent across Postmark inbound and Fastmail sync.
- Reuse IdeaTub's existing thought and research capture patterns wherever practical.

### 2.2 Non-goals for v1

- Domain-wide or wildcard rules such as `@substack.com`
- Subject-keyword rules
- User-authored boolean logic or a general rule DSL
- A full research workflow dashboard
- Support for non-YouTube transcript extraction
- Perfect newsletter parsing for every provider format
- Deduplication across unrelated sender rules or across multiple real-world copies of the same message beyond the existing per-flow idempotency rules

## 3. Product model

### 3.1 Sender rule behaviors

Each rule belongs to one user and matches one exact normalized sender email address.

Allowed actions:

- `allow`
  - Store the email record
  - Create a normal email thought
- `ignore`
  - Drop the email completely
  - Do not store the email
  - Do not create a thought
  - Do not create a review item
- `review`
  - Store the email record
  - Do not create a thought
  - Create a review item so the user can triage it later
- `extra_process`
  - Store the email record
  - Create a normal email thought
  - Queue newsletter research generation

### 3.2 Unknown senders

If no sender rule exists for the normalized sender email, the system should treat the message as `review` in v1.

This means:

- store the original email record
- do not create an email thought yet
- create a user-visible review item

### 3.3 Why exact sender only

V1 should support only exact sender addresses because:

- it matches the user's current examples and intent
- it avoids over-capturing or over-skipping mail from broad domains
- it keeps the settings UI small
- it allows later expansion to domain rules without reshaping the core action model

## 4. Architecture

### 4.1 High-level flow

Recommended pipeline for both Postmark and Fastmail:

`ingestion -> normalize sender -> resolve sender rule -> if ignore, stop -> otherwise store durable email record -> branch to thought/review/research actions`

The key design rules are:

- sender-rule evaluation happens before ordinary thought creation so both email ingestion paths behave consistently
- `ignore` exits before any durable storage or follow-on action

### 4.2 Shared rule evaluator

Add a shared service such as `EmailSenderRuleService` or equivalent application-layer component that:

- normalizes the sender email
- loads the matching per-user rule if one exists
- returns the resolved action:
  - explicit rule action, or
  - default `review` for unknown sender

Normalization rule for v1:

- parse the mailbox email address rather than the display name
- lowercase and trim it
- do not strip plus-addressing
- if multiple senders are present unexpectedly, use the first parsed mailbox and record the raw sender string in metadata for debugging

This service should be used by:

- `PostmarkInboundService`
- `EmailImportService`

Do not duplicate sender-rule logic in both paths.

### 4.3 Relationship to existing email filtering

The existing Fastmail filtering logic currently decides whether a message is included based on direction, direct address checks, and bulk heuristics.

In v1, sender rules should sit above that logic:

- `ignore` always wins
- `review` and `extra_process` are explicit user intent and should bypass ordinary heuristic exclusion
- `allow` is also explicit user intent and should create the normal email thought for that sender unless there is a hard processing failure such as malformed content or an existing duplicate protected by idempotency
- unknown senders go to `review` rather than falling back to the current default import heuristics

This changes the product model from "heuristics decide by default" to "sender policy decides by default."

If there is concern about rollout safety, this behavior can be guarded by a feature flag during implementation, but the target product behavior should remain sender-policy-first.

### 4.4 Rollout boundary

Because unknown senders now default to `review`, rollout can materially change production behavior and may increase Inbox volume.

Implementation should therefore support a controlled rollout path such as:

- a feature flag for sender-policy-first behavior
- per-environment enablement
- a clear default for existing users before they add any rules

The target product behavior remains:

- sender policy first
- unknown sender -> `review`

But implementation should avoid forcing that behavior on all users at once without an intentional rollout decision.

## 5. Persistence model

### 5.1 Sender rules

Add a per-user sender-rules table, for example `email_sender_rules`, with fields such as:

- `id`
- `user_id`
- `sender_email`
- `action`
- `created_at`
- `updated_at`

Rules:

- store `sender_email` normalized to lowercase and trimmed
- enforce uniqueness on `(user_id, sender_email)`
- restrict `action` to:
  - `allow`
  - `ignore`
  - `review`
  - `extra_process`

### 5.2 Stored original email records

IdeaTub already has `imported_emails` as the durable record for Fastmail sync. V1 should ensure the same "stored email record" concept exists for matched Postmark emails as well.

There are two reasonable implementation shapes:

1. extend `imported_emails` so it can represent both mailbox-sync and Postmark-captured emails
2. add a second durable inbound-email table for matched Postmark emails

Recommended direction: prefer a unified durable email-record model if the implementation cost is reasonable, because the rest of this feature wants one concept for:

- original email storage
- review routing
- thought linkage
- research linkage

If unifying `imported_emails` would create too much migration risk, a separate Postmark-backed durable email table is acceptable in v1 as long as it supports the same user-visible guarantees:

- a durable original-email record exists for both ingestion paths unless the sender action is `ignore`
- review items can reference that record
- email thoughts can reference that record
- research outputs can reference that record

### 5.3 Review-path storage

Messages routed to `review` should still produce a durable stored email record. That record should include enough metadata to support later triage:

- sender
- subject
- timestamps
- body text
- extracted links if cheaply available
- ingestion source
- processing decision

### 5.4 Thought linkage

For messages that become thoughts, the stored email record should link to the created thought.

For Fastmail, this already exists through `imported_emails.thought_id`.

For Postmark, v1 should add an equivalent durable link so the system can answer:

- which email record created this thought
- whether the original email was deleted, reviewed, or enriched later

### 5.5 Research linkage

For `extra_process`, the stored email record should also keep linkage to the generated research output.

Recommended linkage fields/metadata:

- on the stored email record:
  - `research_thought_id` or an equivalent metadata field/list
- on the email thought `source_metadata`:
  - stored email record id
  - sender rule action
  - research linkage if created
- on the research thought `source_metadata`:
  - `doc_type = research`
  - source email record id
  - email thought id
  - sender email
  - ingestion path (`postmark` or `fastmail`)

The exact field names can be finalized during implementation, but the linkage must be bidirectional enough for future UI and debugging.

## 6. Review workflow

### 6.1 User experience

Messages routed to `review` should appear in the existing Inbox as review items.

Each review item should reference the stored email record and communicate that the sender is not yet classified.

### 6.2 V1 review actions

At minimum, the review path should support enough data and action wiring for future or immediate user actions such as:

- mark this sender as `ignore`
- mark this sender as `allow`
- save this specific email as a thought
- optionally mark this sender as `extra_process`

The initial implementation does not need a large bespoke review UI if the Inbox can carry the first slice.

### 6.3 Why review instead of silent fallback

The user explicitly wants non-whitelisted senders to be routed for review. This favors intentional curation over opportunistic import and avoids newsletter or low-signal mail silently filling the thought store.

## 7. Extra-processing pipeline

### 7.1 Trigger

Only emails from sender addresses marked `extra_process` should enter the research pipeline in v1.

Example:

- `natesnewsletter@substack.com`

### 7.2 Ordering

The processing order should be:

1. store the original email
2. create the normal email thought
3. queue the extra-processing job
4. generate and save research output asynchronously

This keeps the baseline email capture reliable even if enrichment fails later.

### 7.3 Research inputs

The extra-processing job should build a combined analysis input from:

- cleaned email body
- extracted links from the email body
- sender and subject context
- YouTube transcript text when available

The job should preserve provenance in metadata so future readers can tell what sources informed the research summary.

### 7.4 YouTube transcript behavior

V1 should special-case YouTube links only.

Rules:

- detect one or more YouTube URLs in the email body
- attempt transcript retrieval for each supported YouTube URL
- include retrieved transcript text in the analysis input
- if transcript retrieval fails, continue without it and record the failure in processing metadata

### 7.5 Multiple links

V1 should be best-effort:

- inspect all extracted links
- enrich what is supported
- do not fail the whole run just because one link cannot be parsed or fetched

Only YouTube requires dedicated transcript retrieval in v1. Other links may still be included as URLs or lightly analyzed if the implementation already has a safe path for that, but they do not require deep fetching to ship the first slice.

### 7.6 Minimal-content guardrail

If the message has too little usable content for meaningful enrichment, the research job may skip research creation, but only after trying the available best-effort inputs first.

Expected decision rule:

- if there is enough useful body content or transcript/link-derived content to generate a worthwhile result, save degraded-but-usable research
- only skip research entirely when the combined available input is too weak to produce a useful summary

When skipping, store a machine-readable reason such as:

- `insufficient_content`
- `no_supported_links`
- `transcript_unavailable_and_body_too_short`

The original email record and email thought should remain intact.

## 8. Research output

### 8.1 Output format

The research output should be stored in IdeaTub as a research document using the existing `capture_plan` semantics with:

- `doc_type = research`
- a consistent `plan_slug`
- project value set appropriately for IdeaTub

This keeps newsletter research aligned with existing long-form research handling.

### 8.2 Content

The generated research should include at least:

- a concise summary of the newsletter/email
- important links found in the email
- YouTube transcript-informed observations when transcript text was available
- any important caveats or partial-failure notes

### 8.3 Linking

The research record must be linked back to:

- the stored original email record
- the email thought created from the message

This is required so the system can present the original email and derived research as related but distinct knowledge units.

### 8.4 Why separate research instead of one combined thought

The user explicitly prefers:

1. one normal email thought for the original email
2. one separate research artifact

This separation preserves the source material as captured and prevents enrichment from silently rewriting the original email representation.

## 9. UI and settings

### 9.1 Sender-rules settings

Add a per-user settings page or section for sender rules, likely near the existing email settings surfaces.

Each row should represent:

- exact sender email
- action

### 9.2 V1 actions in UI

The user should be able to:

- add a sender rule
- edit a sender rule action
- delete a sender rule

The UI should stay intentionally small. A simple table plus add/edit form is enough.

### 9.3 Review surfacing

Unknown senders and explicit `review` senders should surface through Inbox rather than requiring a separate v1 dashboard.

### 9.4 Processing visibility

For `extra_process` records, show lightweight status in metadata or related UI so the user can tell whether research is:

- queued
- completed
- completed with partial failures
- skipped due to insufficient content
- failed

A full operational console is out of scope for v1.

## 10. Error handling and safety

### 10.1 Ignore path

`ignore` should produce no stored email, no thought, and no review item.

This is an intentional product choice, not an implementation accident.

### 10.2 Best-effort enrichment

Research enrichment failures should not delete or roll back the original email record or email thought.

### 10.3 Partial failure notes

The system should record best-effort failures in structured processing metadata where possible, for example:

- transcript fetch failed
- link parse failed
- research generation skipped for insufficient input

### 10.4 User scoping

All sender rules, stored emails, review items, thoughts, and research outputs must remain user-scoped.

### 10.5 Idempotency

The existing per-flow email idempotency protections should remain in place:

- Postmark duplicate `MessageID` should not create duplicate records
- Fastmail duplicate `(mail_account_id, provider_message_id)` should not create duplicate records

For `extra_process`, one stored email should create at most one research artifact for ordinary replay. If the product later wants "re-run research," that should be an explicit action rather than automatic duplication.

## 11. Testing strategy

### 11.1 Unit tests

Test:

- sender email normalization
- exact sender rule matching
- unknown sender defaulting to `review`
- `ignore` / `allow` / `review` / `extra_process` decision mapping
- best-effort link and transcript input preparation

### 11.2 Integration tests

Test for both Postmark and Fastmail paths:

- exact sender with `allow` stores the email and creates a thought
- exact sender with `ignore` stores nothing
- exact sender with `review` stores the email and creates a review item only
- exact sender with `extra_process` stores the email, creates the thought, and queues research
- unknown sender stores the email and creates a review item only

### 11.3 Research pipeline tests

Test:

- YouTube URL detected and transcript included when available
- transcript fetch failure still saves research when other inputs remain sufficient
- multiple links are processed best-effort
- insufficient-content cases skip research creation cleanly
- generated research links back to both the stored email and the email thought

### 11.4 Invariants to protect

The most important assertions are:

- exact sender rules behave the same across Postmark and Fastmail
- ignored messages leave no stored output
- review messages do not create thoughts
- extra-processing messages always keep the original email and email thought even if enrichment fails
- research output is linked to the original email and the email thought

## 12. Recommended implementation direction

The recommended v1 approach is:

- add a shared sender-rule evaluator
- add a per-user sender-rules settings UI
- make sender policy the first decision point for both Postmark and Fastmail
- ensure both ingestion paths create or reference a durable stored email record
- route unknown senders to Inbox review
- queue best-effort research for `extra_process` senders
- save the research result as a separate IdeaTub research document linked to the original email

This gives the user a curated, explicit email-ingestion model without introducing a large rule system or overcomplicating the existing email architecture.

## 13. Future expansions

Likely future follow-ons:

- domain rules
- subject-keyword rules
- per-rule configurable storage behavior
- manual re-run of newsletter research
- richer review actions directly inside Inbox
- support for additional transcript/content extractors beyond YouTube
- a unified email detail page showing original email, review history, and linked research
