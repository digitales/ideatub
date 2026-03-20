# Fastmail JMAP validation

**Date:** 2026-03-20  
**Status:** Pre-implementation validation note  
**Feature:** Fastmail-first mailbox sync for IdeaTub

## Auth method actually supported for the chosen Fastmail/JMAP flow

### Documentation-backed findings

- Fastmail's developer docs state that **JMAP uses API tokens** for self-built/testing integrations.
- Fastmail's developer docs also state that **OAuth is recommended for distributed apps**, but OAuth clients are **registered manually by contacting Fastmail**.
- Fastmail's help docs state that **API tokens are not available on Basic plans**.
- Fastmail's help docs distinguish **API tokens** from **app passwords**:
  - API tokens are for JMAP access
  - app passwords are for "everything else" such as IMAP/SMTP

### V1 decision

IdeaTub v1 should support **Fastmail API token + JMAP**, not Fastmail OAuth and not IMAP/app-password auth.

Why:

- It matches the approved product direction: Fastmail-native/JMAP, not IMAP-first.
- It avoids blocking implementation on Fastmail's manual OAuth client registration process.
- It keeps the initial connector simple enough to ship and validate with a single-user or early-access workflow.

### Product implication

The connection UI should clearly instruct the user to create a Fastmail API token in:

- `Settings -> Privacy & Security -> Manage API tokens`

The UI must also disclose that synced message content will be sent through IdeaTub's configured AI pipeline for metadata extraction and summaries.

---

## Session-discovery endpoint and required headers

### Documentation-backed findings

- Fastmail's docs list the JMAP session resource as:
  - `https://api.fastmail.com/jmap/session`
- Fastmail's docs say JMAP requests authenticate with:
  - `Authorization: Bearer {token}`
- The JMAP crash course also documents a session object returned from the session resource, including:
  - `apiUrl`
  - `accounts`
  - `primaryAccounts`
  - `username`
  - capability data

### V1 decision

Use Fastmail's documented session endpoint directly:

- `GET https://api.fastmail.com/jmap/session`

with:

- `Authorization: Bearer {api_token}`
- `Accept: application/json`

Treat the session response as the source of truth for:

- JMAP `apiUrl`
- primary mail `accountId`
- capability availability
- canonical Fastmail username

### Implementation note

Do not hardcode `apiUrl` beyond the session bootstrap. Read it from the session object and persist only the minimum needed in account credentials/settings if caching becomes necessary.

---

## Alias discovery source

### Documentation-backed findings

- Fastmail help docs state that aliases now act as both sending and receiving addresses.
- The JMAP crash course exposes `username` in the session object.
- Fastmail/JMAP submission examples require an `identity_id` to send mail, implying JMAP identities exist and should be available under submission capability.
- Fastmail's published docs do **not** provide a simple alias-discovery example on the developer page itself.

### V1 decision

For v1, trust aliases in this order:

1. Session `username` as the canonical primary account address
2. JMAP `Identity/get` results, if available under `urn:ietf:params:jmap:submission`, as the source of additional send-capable addresses

This means the connector should request:

- `urn:ietf:params:jmap:core`
- `urn:ietf:params:jmap:mail`
- `urn:ietf:params:jmap:submission`

and attempt to discover aliases from JMAP identities.

### Risk / unresolved runtime check

This still needs a **live token-backed validation** with a real Fastmail test account to confirm:

- the exact `Identity/get` response shape we should trust
- whether all usable aliases appear there
- whether any receive-only aliases need a second discovery source

If `Identity/get` is incomplete in practice, v1 fallback should be:

- store the session `username` as canonical
- keep alias discovery best-effort
- add explicit manual alias support in a follow-up

---

## Mailbox listing call

### Documentation-backed findings

- JMAP mail capability covers mailbox access.
- The JMAP crash course shows standard JMAP usage through `apiUrl` with `Mailbox/*` and `Email/*` methods.

### V1 decision

Use a JMAP mailbox query/get flow through the session `apiUrl`:

- `Mailbox/query` for ids
- `Mailbox/get` for mailbox details if query output is insufficient

Use mailbox ids/names from JMAP as the persisted mailbox source for:

- `provider_mailbox_id`
- `provider_mailbox_name`

### Implementation note

For v1, only include mailbox-selection UI if mailbox discovery is straightforward from this call path. Otherwise default to all user mailboxes and keep selection out of the first slice.

---

## Incremental change/checkpoint call

### Documentation-backed findings

- JMAP is designed around synchronization state, not timestamp-only polling.
- The JMAP crash course explicitly recommends using the session object plus API methods for synchronization and references query/change flows.
- JMAP mail data model treats email ids as stable when mail is moved between mailboxes.

### V1 decision

Use provider checkpoint state, not "latest imported date," as the incremental sync primitive.

Persist checkpoint state in `mail_accounts.provider_checkpoint_json`.

The checkpoint should be based on the mail change/query-change state returned by the JMAP mail APIs, not an app-generated timestamp cursor.

### Exact fields to persist in `provider_checkpoint_json`

Persist at least:

- `account_id`
- the latest mail query/change state used for replay-safe incremental sync
- any mailbox scope used when producing that checkpoint

### Risk / unresolved runtime check

The exact state field names still need a live implementation spike against a real Fastmail account, because the public docs reviewed here do not pin down the exact combination of:

- `Email/changes`
- `Email/queryChanges`
- mailbox-scoped filtering

to use for our connector.

The connector should therefore be written so the checkpoint payload is an opaque JSON structure owned by the connector rather than a schema the rest of the app depends on.

---

## Rate-limit or batch-size constraints

### Documentation-backed findings

- Fastmail documents rate limits for its masked email API.
- The reviewed Fastmail docs do not publish simple general JMAP mail rate-limit numbers on the main developer page.

### V1 decision

Treat Fastmail JMAP as rate-limit-sensitive even without published numeric limits in the docs reviewed here.

Start with conservative defaults:

- backfill batches: 50 messages
- incremental batches: 25 messages
- poll-based sync only in v1
- queued jobs with retry/backoff

### Implementation note

Keep the batch sizes configurable through app config/env so they can be lowered quickly if live validation shows tighter Fastmail limits than expected.

---

## Exact implementation decisions for v1

- **Auth path:** Fastmail API token only
- **No v1 OAuth:** Fastmail OAuth registration is manual and should be treated as a future enhancement
- **Session endpoint:** `https://api.fastmail.com/jmap/session`
- **Auth header:** `Authorization: Bearer {api_token}`
- **Capabilities to request:** `urn:ietf:params:jmap:core`, `urn:ietf:params:jmap:mail`, `urn:ietf:params:jmap:submission`
- **Canonical account email:** session `username`
- **Alias source to trust first:** JMAP `Identity/get` under submission capability, with session `username` as guaranteed baseline
- **Mailbox discovery:** `Mailbox/query` / `Mailbox/get`
- **Checkpoint strategy:** opaque connector-owned JSON from JMAP mail change/query-change state
- **Initial runtime posture:** poll-only, conservative batch sizes, queued retries with backoff

---

## Required live-account validation before shipping

Because this repo does not include Fastmail credentials, the following still need a one-off live check with a real test account before the connector should be considered production-ready:

1. Confirm the exact `Identity/get` fields to use for alias discovery
2. Confirm the exact mail change/query-change method combination used for incremental sync
3. Confirm response shape and error behavior for invalid or revoked API tokens
4. Confirm whether Fastmail returns useful rate-limit or retry headers on JMAP mail calls

This does **not** block schema/settings work, but it should happen before connector implementation is treated as complete.

## References

- [Fastmail developer docs](https://www.fastmail.com/for-developers/integrating-with-fastmail/)
- [Fastmail OAuth docs](https://www.fastmail.com/for-developers/oauth/)
- [Fastmail API tokens help](https://www.fastmail.help/hc/en-us/articles/5254602856719-API-tokens)
- [Fastmail identities help](https://www.fastmail.help/hc/en-us/articles/1500000280401-Identities)
- [JMAP crash course](https://jmap.io/crash-course.html)
