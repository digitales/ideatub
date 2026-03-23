# Email View Sender Rule Management Design

**Date:** 2026-03-22
**Status:** Approved

## Overview

The app already supports sender-rule management in a dedicated settings page at `/settings/email-sender-rules`. The email thought detail page already exposes sender metadata in a sidebar, but it does not yet let the user manage the sender's whitelist or broader sender rule from that context.

This design adds a sender-specific management surface to the email detail page so the user can:

1. quickly whitelist the sender from the email view
2. remove the sender from the whitelist when already whitelisted
3. manage the sender's full rule inline without leaving the email view

The existing settings page remains the global manager for all sender rules. The email detail page becomes an additional, context-aware entry point for the exact same underlying rule system.

## Goals

- Add a fast, one-click whitelist action to the email detail page
- Let the user manage the current sender's full rule inline from the same page
- Reuse the existing `email_sender_rules` table and rule semantics
- Keep the email detail page focused on the sender for the current message, not on global rule administration

## Non-Goals

- Replacing the existing sender-rules settings page
- Adding domain-level rules or pattern-based matching
- Retroactively reprocessing old emails when a sender rule changes
- Embedding the entire global sender-rules manager into the email page

## Existing Infrastructure To Reuse

The implementation should reuse, not recreate, the existing sender-rule stack:

- `App\Models\EmailSenderRule`
- `App\Services\Email\EmailSenderRuleService`
- `App\Http\Controllers\EmailSenderRuleSettingsController`
- `resources/views/settings/email-sender-rules.blade.php`
- `App\Models\ImportedEmail`
- `App\Models\CapturedInboundEmail`
- the existing stored-email sender resolution used by `App\Services\Email\EmailReviewActionService`
- `resources/views/idea/partials/thought_detail_email_sidebar.blade.php` for the email-specific sidebar UI

## UX Design

### Sender rule card

Add a compact `Sender rule` card to the email metadata sidebar on the thought detail page.

The card should show:

- the normalized sender email derived from the message's `From` data
- the current rule state, if one exists
- a fast primary action for whitelist management
- a secondary inline control for setting any supported sender action

### Quick action behavior

The quick action should optimize for the most common case:

- if no sender rule exists, show `Whitelist sender`
- if a sender rule exists but is not `allow`, still show `Whitelist sender`
- if the sender rule is currently `allow`, show `Remove from whitelist`

`Remove from whitelist` should remove the sender rule record entirely rather than converting it to another action automatically.

### More options behavior

The same card should also include an inline full-rule manager so the page can do more than just whitelist:

- an action selector containing `allow`, `ignore`, `review`, and `extra_process`
- a save/update action that upserts the selected rule for the current sender
- a remove action when a rule exists

This should feel like a compact sender-specific manager, not a full settings-page clone.

### Visibility

The card should render only when the thought is an email thought and a sender email can be resolved from the imported email or fallback source metadata.

If the sender cannot be resolved, the page should not show broken controls. It may instead show a small non-interactive message such as `Sender rule unavailable for this email.`

The card and its routes should follow the existing sender-rule feature flag. When `services.email_sender_policy.enabled` is disabled, the card should not render and the page-specific sender-rule endpoints should not be available.

## Backend Design

### Thought show page data

`IdeaController@show` should continue to render the thought detail page, but for email thoughts it should also resolve:

- the normalized sender email for the message
- the current `EmailSenderRule` row for that sender and user, if one exists

This data should be passed to the view so the sidebar can render the correct current state and actions.

The show flow should not assume every email thought is backed by `ImportedEmail`. It must support both existing stored-email shapes used in the app:

- Fastmail/import flows backed by `ImportedEmail`
- Postmark/sender-policy flows backed by `CapturedInboundEmail`

### Route and controller shape

Add a focused controller for sender-rule actions initiated from the email thought page. The controller should be sender-specific and thought-context-aware, rather than reusing the global settings controller directly.

Recommended shape:

- `POST` endpoint to create or update the sender rule for the email thought's sender
- `DELETE` endpoint to remove the sender rule for the email thought's sender

These routes should be scoped to the thought detail page, not to a raw sender email string submitted by the browser. The server should derive the sender from the thought's linked email data.

Use route names aligned with the existing `thoughts.*` naming pattern so the feature reads as part of the thought detail page, not as a second global settings surface.

### Authorization and safety

The new controller should:

- authorize `update` on the thought for mutating requests
- ensure the thought is an email thought
- ensure the thought belongs to the current user
- resolve the sender from trusted server-side message data
- refuse the action if no normalized sender email can be extracted

This avoids trusting arbitrary sender addresses posted from the client.

### Rule persistence

The controller should upsert or delete rows in `email_sender_rules` for the current user and resolved sender email.

Behavior:

- quick whitelist action stores `action = allow`
- full-rule save stores the selected action
- remove action deletes the rule row

The existing normalization and action vocabulary in `EmailSenderRule` / `EmailSenderRuleService` remain the source of truth.

This page-specific flow should use upsert semantics (`updateOrCreate` or equivalent). It should not reuse the settings-page `store` behavior that rejects duplicates for an existing sender.

## Data Resolution Rules

Sender resolution must stay aligned with the existing sender-review workflow for stored-email columns, while allowing a final thought-metadata fallback only when the stored row does not provide a usable sender.

The controller/view layer should first identify the underlying stored email for the thought:

1. if the thought points to an `ImportedEmail`, use that row
2. if the thought points to a `CapturedInboundEmail`, use that row
3. only if neither stored-email row is available, fall back to `source_metadata`

For `ImportedEmail`, resolve the raw sender in this order:

1. `rule_email` when present
2. formatted sender derived from `from_json`
3. fallback `source_metadata.from`

For `CapturedInboundEmail`, resolve the raw sender in this order:

1. `rule_email` when present
2. `sender_email`
3. fallback `source_metadata.from`

`source_metadata.from` may be either a structured participant array or a plain string. The implementation must handle both forms.

After choosing the raw sender value, normalize it through `EmailSenderRuleService::normalizeSender()`.

If multiple sender-like values are present, use the first mailbox the existing normalization logic extracts. This keeps behavior aligned with current inbound sender-rule resolution.

Because this precedence already exists in review-related code, the implementation should prefer extracting a shared helper or otherwise centralizing sender resolution instead of creating a third slightly different sender-selection path. If `source_metadata.from` is kept as a last-resort fallback for thought detail, that behavior should be made explicit in the shared helper or companion helper rather than duplicated ad hoc in the controller and view.

## Error Handling

User-facing behavior should stay simple:

- on success, redirect back to the thought detail page with a success flash
- if the sender cannot be resolved, redirect back with an error flash
- if the sender-rule feature flag is off, the page-specific routes should return `404`
- if the current user does not own the thought, the page-specific routes should return `403`
- if the thought is not an email thought, the page-specific routes should return `404`
- validation should reject unsupported action values for the full-rule form

When the feature flag is off, `IdeaController@show` should also skip loading sender-rule state for the sidebar.

No background processing or re-import work should happen as part of this flow.

## Testing

Add feature coverage for the new email-page sender-rule entry point.

Required cases:

- email thought detail page shows the sender-rule card for an `ImportedEmail`-backed thought when a sender can be resolved
- email thought detail page shows the sender-rule card for a `CapturedInboundEmail`-backed thought when a sender can be resolved
- page shows `Whitelist sender` when no rule exists
- page shows current rule state when a rule exists
- quick whitelist action creates or updates an `allow` rule for the resolved sender
- remove-from-whitelist deletes an existing `allow` rule
- full-rule save can set `ignore`, `review`, and `extra_process`
- another user cannot manage sender rules through someone else's email thought
- non-email thoughts do not expose or accept these sender-rule actions
- feature-disabled mode hides the card and rejects the page-specific sender-rule routes
- unresolved-sender email thoughts do not expose working controls and reject submitted changes cleanly

## Out Of Scope

- changing how sender rules are applied during inbox review, Fastmail sync, or Postmark inbound
- synchronizing this card with live updates elsewhere in the UI
- showing a full table of all sender rules on the email detail page
- rule history, audit logs, or confirmation modals beyond existing simple patterns
