# IdeaTub

Personal knowledge and agent-context platform. Working memory, thoughts, inbox triage, and attention pulse are core operator surfaces.

## Language

### Inbox

**Inbox item**:
An agent-generated prompt awaiting user triage. Each item has a generator type, title, body, and lifecycle status.
_Avoid_: Notification, alert, message

**Generator type**:
The category of inbox item (e.g. `wm_fallback`, `email_sender_review`). Defines origin, dedupe rules, and available actions.
_Avoid_: Type, source, kind

**Actionable item**:
A pending inbox item that is not snoozed into the future. Only actionable items appear in the inbox.
_Avoid_: Active item, open item

**Done**:
Resolves a single inbox item. The underlying condition may persist; generators may create a new item on a later run.
_Avoid_: Dismiss, delete, clear

**Done all**:
Resolves every pending actionable item of one generator type for the user, regardless of pagination.
_Avoid_: Bulk dismiss, clear all

**Snooze**:
Defers a pending item until a future time without resolving it.
_Avoid_: Postpone, defer, remind later

**Dedupe key**:
Stable identifier preventing duplicate pending items for the same logical prompt (scoped per user).
_Avoid_: Unique key, hash

**Inbox group**:
A collapsed summary card representing two or more pending items of the same generator type. Offers type-specific bulk actions on the group header; individual items remain available when expanded.
_Avoid_: Batch, bundle, notification group

**Standard triage type**:
A generator type whose items share Done / Snooze / Save as thought actions. Eligible for group-level Done all.
_Avoid_: Normal type, default type

**Special-action type**:
A generator type with its own action set (e.g. email sender review). Eligible for inbox grouping with type-specific bulk actions instead of Done all.
_Avoid_: Custom type, non-standard type

### Working memory

**Fallback authoring**:
A working memory scope whose latest version has `authoring_status = fallback`. Indicates memory needs consolidate or external sync.
_Avoid_: Degraded, stale, broken

**Scope**:
A working memory target identified by `scope_type` and `scope_key` (global, project, tag, insights).
_Avoid_: Memory, context, project (when meaning WM scope)
