# Email Sender Whitelist & Ignore UI Design

**Date:** 2026-03-21
**Status:** Approved

## Overview

The backend infrastructure for sender rules (whitelist/ignore) already exists. The inbox already renders Allow and Ignore buttons for email review items. What's missing is:

1. **Allow button currently only saves the sender rule** — it does not create a thought. The user wants Allow to do both: save the `allow` rule AND import the email as a thought.
2. **The sender rules settings page exists at `/settings/email-sender-rules`** but is not linked from the inbox section. A "Manage sender rules" link in the inbox page header is needed.

## Existing Infrastructure (Do Not Recreate)

| Component | Status | Location |
|-----------|--------|----------|
| `EmailSenderRule` model + table | ✅ exists | `app/Models/EmailSenderRule.php` |
| `EmailSenderRuleService` | ✅ exists | `app/Services/Email/EmailSenderRuleService.php` |
| `EmailReviewActionService` | ✅ exists | `app/Services/Email/EmailReviewActionService.php` |
| `InboxController@applyEmailReviewAction` | ✅ exists | route: `POST /inbox/{inboxItem}/email-review/action` |
| Allow/Ignore/Save Thought buttons in inbox view | ✅ exists | `resources/views/inbox/index.blade.php` |
| `EmailSenderRuleSettingsController` (index, store, update, destroy) | ✅ exists | `app/Http/Controllers/EmailSenderRuleSettingsController.php` |
| Settings page view | ✅ exists | `resources/views/settings/email-sender-rules.blade.php` |

## Change 1: Allow = Save Rule + Create Thought

### Current behaviour

`action=allow` calls `EmailReviewActionService::applySenderClassification()` which:
1. Upserts `EmailSenderRule` with `action = allow`
2. Writes triage metadata to the email record
3. Marks the `InboxItem` done

No thought is created.

### Desired behaviour

`action=allow` should:
1. Upsert `EmailSenderRule` with `action = allow`
2. Mark the `InboxItem` done
3. Create a thought from the email (same as `action=save_thought` via `saveReviewedEmailAsThought`)

### Implementation

Update `InboxController@applyEmailReviewAction` so that when `action=allow`:

1. Call `applySenderClassification($inboxItem, $user, 'allow')`.
   - If it returns `false`, the item was already classified — redirect with "Sender classification was already handled." (same as the current non-allow path). Do not proceed to thought creation.
   - If it returns `true`, proceed.
2. Call `saveReviewedEmailAsThought($inboxItem, $user)`.

**Why the sequential call is safe:** `saveReviewedEmailAsThought` does not check `isActionable` (it does not verify `status === 'pending'`). Its idempotency guard is the `save_as_thought` action-row check: if a `save_as_thought` action already exists on the item it returns early. After only `applySenderClassification` has run, no such row exists, so `saveReviewedEmailAsThought` will proceed. The second call will issue a redundant `InboxItem::update(['status' => 'done'])` on an already-done item — this is harmless. Note: `actioned_at` on the inbox item will be overwritten with the timestamp of the `saveReviewedEmailAsThought` call, not the earlier classification call. This is acceptable.

**Error handling:** If `saveReviewedEmailAsThought` throws, catch `\Throwable`, call `report($e)`, and redirect to the inbox with a success flash ("Sender rule saved. Could not import email as a thought."). This is the correct UX because the sender rule is already committed in its own transaction — the item is already done and will not reappear in the inbox. A full error redirect would confuse the user. The thought creation failure is non-fatal.

**Authorization:** The existing `$this->authorize('update', $inboxItem)` check in `applyEmailReviewAction` covers this. No additional authorization is needed.

**Feature flag:** The existing `POST /inbox/{inboxItem}/email-review/action` route is not gated by `email_sender_policy.enabled`. This is unchanged.

### No changes needed to

- Routes
- Inbox view (buttons already exist in `inbox/index.blade.php`)
- `EmailReviewActionService` internals

## Change 2: Navigation Link from Inbox Section

Add a "Manage sender rules →" link in the inbox page header (`resources/views/inbox/index.blade.php`), inside the existing `<div class="mb-8">` block that contains the "Inbox" heading.

Gate the link with `@if(config('services.email_sender_policy.enabled'))` — consistent with the existing settings nav item which uses the same gate.

The link points to the existing route `settings.email-sender-rules.index` (`/settings/email-sender-rules`). No changes to the settings page or controller are needed.

## Out of Scope

- Changing the settings page layout (it already shows all rules with update/delete per row)
- Retroactive processing of previously ignored emails when a rule changes
- Domain-level rules (e.g., ignore all from `@domain.com`)
- Confirmation step before applying allow/ignore
