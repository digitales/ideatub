# IdeaTub — Agent Inbox and Triage Queue

**Date:** 2026-03-20  
**Status:** Draft  
**Scope:** Add a web-first inbox to IdeaTub that stores agent-generated items for user triage. Inbox items are created by scheduled and rule-based generators, can be marked done, snoozed, or saved as thoughts, and may later be delivered to Discord as an optional channel.

## 1. Summary

- Add a first-class **Inbox** feature to IdeaTub for agent-generated prompts, reminders, and summaries.
- The inbox is a **triage queue**, not a general notification center and not a task manager.
- V1 inbox copy may be template-based; LLM-generated wording is optional per generator, not required by the platform design.
- V1 actions per item:
  - `done`
  - `snooze`
  - `save as thought`
- Inbox items are generated in two ways:
  - **scheduled** generators (time-based)
  - **rule-based** generators (IdeaTub data conditions)
- IdeaTub remains the **source of truth**. Discord is an optional future delivery surface, not the primary queue.

## 2. Goals and non-goals

### 2.1 Goals

- Give the user a single in-app place to process agent-generated prompts.
- Support both recurring time-based prompts and data-driven prompts.
- Keep actions intentionally small and clear.
- Preserve a clean separation between:
  - **knowledge records** (`thoughts`)
  - **operational prompts** (`inbox_items`)
- Allow future outbound delivery to Discord without redesigning the inbox model.

### 2.2 Non-goals for v1

- General inbound messaging or webhook ingestion.
- Full task management semantics such as priorities, projects, subtasks, or assignments.
- Discord-side triage or two-way Discord action handling.
- User-defined rule builders or a generic rules DSL.
- A full “agent workflow engine” with rich run orchestration.

## 3. Product model

### 3.1 Inbox item definition

An inbox item is a persisted, user-scoped record representing a single agent-generated prompt that the user should process.

Examples:

- “Review this neglected idea.”
- “This research note is stale.”
- “Weekly revisit: these items may need attention.”

### 3.2 Why inbox items are separate from thoughts

Inbox items are temporary and operational:

- They can be dismissed or snoozed.
- They may never become durable knowledge.
- They are created by system logic rather than direct user capture.

Thoughts remain the durable knowledge store. `save as thought` is an explicit conversion step from an inbox item into the existing capture model.

## 4. Architecture

### 4.1 Main components

- **Inbox item model**
  - Stores the queue visible to the user.
- **Inbox item action log**
  - Stores user actions taken on an inbox item.
- **Inbox generator interface**
  - Shared contract for all generator classes.
- **Scheduled generation runner**
  - Invokes time-based generators on a cadence.
- **Rule evaluation runner**
  - Invokes data-driven generators against the user’s IdeaTub content.
- **Inbox UI**
  - Web page and actions for triage.
- **Future delivery layer**
  - Sends selected items to Discord later without changing the inbox core.

### 4.2 Boundary between generation and display

The system should separate:

- **detecting** something that merits attention
- **creating** a normalized inbox item
- **displaying** that item in the user’s inbox

This boundary keeps generator logic small and makes future additions easier.

## 5. Data model

### 5.1 Table `inbox_items`

Suggested fields:

| Column | Type | Notes |
|--------|------|-------|
| `id` | bigint or UUID PK | Use existing project conventions |
| `user_id` | FK users | Owner of the inbox item |
| `generator_type` | string | e.g. `weekly_revisit`, `stale_research` |
| `title` | string | Short triage label |
| `body` | text | Main message content |
| `status` | string | V1: `pending`, `done` |
| `snoozed_until` | nullable timestamp | Hidden until this time if set |
| `generated_at` | timestamp | When the item was produced |
| `actioned_at` | nullable timestamp | When it was resolved |
| `dedupe_key` | string, indexed | Required for V1 generators to prevent duplicate active items |
| `source_data` | nullable json | Lightweight context for UI/actions |
| `created_at` | timestamp | |
| `updated_at` | timestamp | |

Notes:

- `status = pending` covers both active and snoozed items; snooze state is represented by `snoozed_until`.
- A snoozed item is still considered the same active logical item for dedupe purposes.
- V1 should treat `dedupe_key` as required and unique only within the scope of a user’s active logical items, not globally.
- For v1, an item is considered **active** when `status = pending`, regardless of whether `snoozed_until` is null or in the future.
- Implementation should enforce active dedupe at the database layer where practical, for example with a partial unique index on `(user_id, dedupe_key)` for rows where `status = 'pending'`, or an equivalent concurrency-safe approach.

### 5.2 Table `inbox_item_actions`

Insert-only audit log of user actions.

Suggested fields:

| Column | Type | Notes |
|--------|------|-------|
| `id` | bigint PK | |
| `inbox_item_id` | FK inbox_items | |
| `action_type` | string | `done`, `snooze`, `save_as_thought` |
| `metadata` | nullable json | e.g. snooze-until timestamp, created thought id |
| `created_at` | timestamp | |
| `updated_at` | timestamp | optional; include only if project conventions require it |

This keeps the main item row simple while preserving user-visible history and debugging context.

## 6. Generation model

### 6.1 Generator contract

V1 should use plain Laravel classes behind a shared interface or service contract, not a configurable rules engine.

Each generator should:

1. Receive the user context.
2. Evaluate whether an inbox item should exist.
3. Build a normalized candidate item payload.
4. Provide a stable `dedupe_key`.

Generators should be registered explicitly in application configuration or a service container list so v1 can control ordering and enable/disable generators per deploy without inventing a user-facing rule builder.

### 6.2 Two generation paths

#### Scheduled generators

Time-based prompts that run on a cadence.

Examples:

- weekly revisit prompt
- morning summary
- end-of-week review

#### Rule-based generators

Generators that inspect IdeaTub data for situations that deserve attention.

Examples:

- stale research
- neglected idea
- captured but never processed item

### 6.3 Runner model and cadence

V1 should use **one scheduled Laravel command** as the single generation entry point.

That command should:

- resolve eligible users
- run both scheduled and rule-based generators
- insert any newly deduplicated inbox items

This keeps operations simple in v1. If needed later, individual generator families can be split into separate schedules or queues without changing the inbox model.

For v1:

- **eligible users** = all users for whom the inbox feature is enabled
- if no per-user feature flag exists yet, treat this as globally enabled for all users
- rule-based generators should run on the same cadence as scheduled generators, but each generator may enforce its own lightweight cooldown logic in code
- add a simple safety cap such as a maximum of **5 new inbox items per user per run** to avoid flooding
- if more than the cap qualify, generators should be processed in a stable configured order and later candidates should be skipped until the next run

### 6.4 Runner flow

Recommended pipeline:

1. A scheduled Laravel command runs.
2. The command resolves eligible users.
3. It executes the configured generators for each user.
4. Each generator returns zero or more candidate items.
5. Each candidate is deduplicated against existing active items.
6. New items are inserted and become visible in the inbox.

### 6.5 Dedupe behavior

Generators must be idempotent.

Rules:

- If an equivalent active item already exists, do not create another.
- Snoozed items still block regeneration of the same logical item.
- Resolved items (`done`) no longer block future generation.
- Any longer suppression window is out of scope for the shared platform in v1 and, if needed, should live as code-only logic inside a specific generator.

The core mechanism is a generator-defined `dedupe_key` plus an “active item exists” check. V1 generators should be required to return a non-empty `dedupe_key`.

Application-level dedupe checks are helpful for clarity, but they are not sufficient on their own under concurrent runs. V1 implementation should assume overlapping workers are possible and use a concurrency-safe insert strategy.

## 7. User experience

### 7.1 Inbox page

Add a dedicated Inbox page in the web app with:

- pending item list
- navigation badge/count
- clear empty state
- per-item actions

This should feel lightweight and operational rather than like a full productivity suite.

The navigation badge should use the same default visibility logic as the inbox list: items that are pending and not snoozed into the future.

V1 inbox list behavior:

- default sort: newest `generated_at` first
- show only actionable pending items in the default view: `status = pending` and not snoozed into the future
- no separate done-history view is required in v1
- simple pagination is acceptable if the list grows

### 7.2 Inbox item presentation

Each item should show:

- title
- concise body
- origin/generator label if helpful
- generated time
- snooze state if applicable

Optional later enhancements can include grouping or filtering, but not in v1.

### 7.3 Actions

#### Done

- Marks item as resolved.
- Sets `status = done`.
- Sets `actioned_at`.
- Writes an `inbox_item_actions` log row.

#### Snooze

- Sets `snoozed_until` to a user-selected future time.
- Keeps `status = pending`.
- Writes an action log row with the chosen snooze timestamp.

V1 recommendation:

- provide simple presets such as “tomorrow” and “next week”, with optional custom datetime later if desired
- store snooze times in UTC
- display them in the user’s local/browser timezone

#### Save as thought

- Creates a normal `Thought`.
- Uses the existing thought capture pipeline where practical so metadata extraction remains consistent.
- Writes an action log row containing the new thought id.
- The inbox item may remain pending or be auto-completed; **v1 recommendation:** mark it `done` after successful save to avoid duplicate work.
- The action should be idempotent for a single inbox item: once a thought has been successfully created from the item, repeated submissions should not create additional thoughts.

## 8. Save-as-thought behavior

`save as thought` should convert an inbox item into durable knowledge, not just duplicate text blindly.

Recommended behavior:

- Use item title/body and relevant `source_data` to build the thought content.
- Attribute the thought source so it is clear it originated from the inbox/agent flow.
- Preserve useful linkage in metadata where appropriate, such as original `generator_type` and `inbox_item_id`.

If the thought creation fails, the inbox item should remain pending and the failure should be surfaced clearly to the user.

Validation and failure handling:

- validation errors should be returned clearly without changing the inbox item state
- transient failures may be retried by the user, but successful creation must still remain idempotent for that inbox item
- double-submission from the UI should not create duplicate thoughts

## 9. Discord extension path

Discord is a later delivery surface layered on top of the inbox, not a second source of truth.

### 9.1 Principles

- Every item exists in IdeaTub first.
- Discord delivery is optional and selective.
- Delivery failure must not affect inbox persistence or user action history.

### 9.2 Likely future design

- A delivery job selects eligible inbox items.
- It sends a notification to Discord.
- Delivery metadata is stored separately or in item metadata.
- Discord does not own triage state in v1.
- No Discord delivery code is required for v1.

This keeps the current architecture compatible with later “send to Discord” behavior without requiring a redesign.

## 10. Error handling and guardrails

### 10.1 Generator failures

- One generator failing must not abort the whole generation run.
- Failures should be logged with generator type and user context.

### 10.2 Duplicate prevention

- Equivalent active items should not be re-created.
- Snoozed items must still participate in duplicate prevention.

### 10.3 Save-as-thought safety

- Do not mark the item complete unless the thought was actually created.
- Log the created thought id on success.

### 10.4 User scoping

- All generation, inbox queries, and actions must remain user-scoped.
- No user should ever see or action another user’s inbox items.

### 10.5 Routes and authorization

- V1 can use normal Laravel web routes and controller actions; a separate public API is not required.
- Every inbox read/write route should sit behind normal authenticated web access.
- Action handlers must authorize against the owning `user_id` before mutating an item or creating a thought from it.

### 10.6 Retention

- V1 may retain `done` inbox items and action logs indefinitely.
- Cleanup and archival policies are out of scope for the first implementation unless volume proves it necessary.

## 11. Testing

### 11.1 Unit tests

- Generator decision logic
- Dedupe key behavior
- Snooze blocking duplicate regeneration

### 11.2 Feature tests

- Inbox page shows only pending and unsnoozed-due items as intended
- `done` action resolves an item
- `snooze` action hides an item until due
- `save as thought` creates exactly one thought and logs the action

### 11.3 Integration tests

- Scheduled runner creates items from scheduled generators
- Rule-based runner creates items from data conditions
- Existing active item prevents duplicate insertion
- User isolation across generation and inbox actions

## 12. Recommended v1 slice

To validate usefulness quickly, start with:

- inbox schema (`inbox_items`, `inbox_item_actions`)
- inbox page and navigation badge
- one scheduled generator
  - recommended: `weekly_revisit`
- one rule-based generator
  - recommended: `stale_research` or `neglected_idea`
- actions:
  - `done`
  - `snooze`
  - `save as thought`
- no Discord sending yet, only a future-ready delivery boundary

## 13. Out of scope for v1

- User-configured generator builder UI
- Priority levels, labels, assignees, or projects
- Discord triage actions
- Push notification fan-out to multiple channels
- General inbound inbox items from arbitrary external systems
