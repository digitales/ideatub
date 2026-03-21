# Email Sender Whitelist & Ignore UI Design

**Date:** 2026-03-21
**Status:** Approved

## Overview

The backend infrastructure for sender rules (whitelist/ignore) already exists. The inbox already renders Allow and Ignore buttons for email review items. What's missing is:

1. **Allow button currently only saves the sender rule** — it does not create a thought. The user wants Allow to do both: save the `allow` rule AND import the email as a thought.
2. **The sender rules settings page exists at `/settings/email-sender-rules`** but is not linked from the inbox section. A navigation link from the inbox section is needed.

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
1. Upsert `EmailSenderRule` with `action = allow` (unchanged)
2. Create a thought from the email (same as `action=save_thought` via `saveReviewedEmailAsThought`)
3. Mark the `InboxItem` done

### Implementation

Update `InboxController@applyEmailReviewAction` so that when `action=allow`:
1. Call `applySenderClassification($inboxItem, $user, 'allow')` first to save the rule
2. Then call `saveReviewedEmailAsThought($inboxItem, $user)` to create the thought

Both methods handle their own idempotency (locking, duplicate action checks), so calling them sequentially is safe. The `saveReviewedEmailAsThought` method already checks `thought_id` on the stored email to avoid duplicate thought creation.

If `saveReviewedEmailAsThought` throws, catch and report but still redirect with success (rule was saved; thought creation failure is non-fatal). Or surface the error — decision for implementer to make based on UX preference.

**Authorization:** The existing `$this->authorize('update', $inboxItem)` check in `applyEmailReviewAction` covers this.

**Feature flag:** The existing route is not gated by `email_sender_policy.enabled`. The allow/ignore actions work independently of whether the policy feature flag is on — the flag only gates the settings page.

### No changes needed to

- Routes (existing `POST /inbox/{inboxItem}/email-review/action` is reused)
- View (buttons already exist in `inbox/index.blade.php`)
- `EmailReviewActionService` (its two methods are composed in the controller, not changed internally)

## Change 2: Navigation Link from Inbox Section

Add a link to the existing `/settings/email-sender-rules` page from within the inbox section — e.g., a "Manage sender rules" link in the inbox page header or sidebar navigation.

The settings page itself does not need to change. It already shows all sender rules with delete and update actions.

## Out of Scope

- Changing the settings page layout (it already shows all rules with update/delete per row)
- Retroactive processing of previously ignored emails when a rule changes
- Domain-level rules (e.g., ignore all from `@domain.com`)
- Confirmation step before applying allow/ignore
