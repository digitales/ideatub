# Email Sender Whitelist & Ignore UI Design

**Date:** 2026-03-21
**Status:** Approved

## Overview

Adds UI for managing email sender rules: quick Allow/Ignore actions on inbox review items, and a Sender Rules settings page within the inbox section. The backend `EmailSenderRule` model and `EmailSenderRuleService` already exist; this spec covers the new UI and the controller endpoints that wire them together.

## Inbox Quick Actions

Email review inbox items display two new buttons: **Allow** and **Ignore**.

### Allow

- Route: `POST /inbox/email-review/{item}/allow`
- Controller: `EmailReviewInboxActionController@allow`
- Behavior:
  1. Upserts an `EmailSenderRule` with `action = allow` for the sender via `EmailSenderRuleService`
  2. Re-processes the underlying `ImportedEmail` through `EmailImportService` to create a thought
  3. Marks the `InboxItem` done via `InboxActionService`
- Result: inbox item disappears, email becomes a thought

### Ignore

- Route: `POST /inbox/email-review/{item}/ignore`
- Controller: `EmailReviewInboxActionController@ignore`
- Behavior:
  1. Upserts an `EmailSenderRule` with `action = ignore` for the sender via `EmailSenderRuleService`
  2. Sets `ImportedEmail.processing_status = filtered`
  3. Marks the `InboxItem` done via `InboxActionService`
- Result: inbox item disappears, email record kept (not imported as a thought)

## Sender Rules Settings Page

### Route & Navigation

- Route: `GET /inbox/sender-rules`
- Controller: `EmailSenderRuleSettingsController@index` (new action on existing controller)
- Navigation: "Sender Rules" link within the inbox section alongside the inbox itself

### Page Layout

Two lists, side by side on desktop, stacked on mobile:
- **Allowed senders** — rules with `action = allow`
- **Ignored senders** — rules with `action = ignore`

Each row shows the sender email address and a **Delete** button.

### Delete

- Route: `DELETE /inbox/sender-rules/{rule}`
- Controller: `EmailSenderRuleSettingsController@destroy` (new action on existing controller)
- Behavior: deletes the `EmailSenderRule` record
- Result: future emails from that sender fall back to the default `review` behavior (surfaced in inbox again)

## Backend Changes

| Change | Description |
|--------|-------------|
| New controller | `EmailReviewInboxActionController` with `allow` and `ignore` actions |
| New routes | `POST /inbox/email-review/{item}/allow` and `POST /inbox/email-review/{item}/ignore` |
| New controller actions | `index` and `destroy` on existing `EmailSenderRuleSettingsController` |
| No schema changes | `email_sender_rules` table already exists |

## Out of Scope

- Retroactive processing of previously imported/ignored emails when a rule changes
- Adding rules manually from the settings page (rules are created only from inbox quick actions)
- Confirmation step before applying allow/ignore
- Support for domain-level rules (e.g., ignore all from `@domain.com`)
