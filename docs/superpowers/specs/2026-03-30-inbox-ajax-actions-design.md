# Inbox AJAX Actions — Design Spec

**Date:** 2026-03-30
**Status:** Approved

## Overview

Make `/inbox` actions AJAX-driven so acting on an item updates the page in place instead of reloading the full screen.

This applies to all inbox actions:

1. Standard inbox actions: `Done`, `Tomorrow`, `Next week`, `Save as thought`
2. Email review actions: `Allow sender`, `Ignore sender`, `Extra process sender`, `Save as thought`

The existing server-rendered inbox page remains the source of truth. JavaScript enhances the page so successful actions remove the affected card immediately, update the inbox badge, and show lightweight feedback without a refresh.

## Problem

The current inbox experience uses plain POST forms that always redirect back to `/inbox` with flash messages. This works, but it makes triage slower because every action causes a full-page refresh even though the user only acted on one item.

The rest of the app already uses small Alpine + `fetch` interactions for inline updates, so the inbox now feels heavier than nearby flows.

## Solution

Keep the current authenticated Blade inbox page and existing POST routes, but add progressive enhancement:

- normal form submissions continue to work through redirects and flash messages
- AJAX submissions return JSON responses instead
- a small Alpine handler on the inbox page intercepts action submissions and performs in-place updates

This keeps the feature aligned with current app patterns while preserving graceful fallback if JavaScript is unavailable.

---

## Backend

### Controller behavior

`App\Http\Controllers\InboxController` should support both HTML and JSON response modes for:

- `markDone()`
- `snooze()`
- `saveAsThought()`
- `applyEmailReviewAction()`

Each action continues to:

- authorize access with the existing policy
- validate input where needed
- delegate behavior to the existing inbox and email-review services

Each action should then branch on `Request::expectsJson()`, matching the pattern already used elsewhere in the app.

### HTML response mode

For non-AJAX requests, preserve the current behavior:

- redirect back to `route('inbox.index')`
- include success or error flash messaging

This keeps the page usable without JavaScript and avoids breaking the existing server-rendered workflow.

### JSON response mode

For AJAX requests, return JSON instead of redirecting.

Successful responses should include:

- `ok: true`
- `message`: user-facing success message
- `item_id`: the acted-on inbox item ID
- `remaining_count`: the updated actionable inbox count for the current user

Error responses should return appropriate HTTP status codes and JSON payloads instead of redirects:

- validation errors: `422`
- authorization failures: existing Laravel authorization response behavior
- recoverable action failures such as save/import problems: `422` or `503` depending on the failure class

The client should treat HTTP status as the primary failure signal and read Laravel-style JSON error bodies defensively:

- use `message` when present
- use `errors` when present for validation failures
- do not assume every failed JSON response includes `ok: false`

### Badge count source

The JSON response should compute `remaining_count` using the same actionable inbox query that drives the badge and page visibility rules:

- current user only
- `status = pending`
- not snoozed into the future

This ensures the inbox page and navigation badge stay synchronized after every successful AJAX action.

### Routes

No route changes are required.

Existing routes in `routes/web.php` remain the submission targets:

```php
Route::post('/inbox/{inboxItem}/done', [InboxController::class, 'markDone'])->name('inbox.done');
Route::post('/inbox/{inboxItem}/snooze', [InboxController::class, 'snooze'])->name('inbox.snooze');
Route::post('/inbox/{inboxItem}/save-thought', [InboxController::class, 'saveAsThought'])->name('inbox.save-thought');
Route::post('/inbox/{inboxItem}/email-review/action', [InboxController::class, 'applyEmailReviewAction'])->name('inbox.email-review.action');
```

---

## Frontend

### Page strategy

`resources/views/inbox/index.blade.php` remains a Blade page rendered from the server.

The enhancement should be localized to the inbox page rather than introducing a larger SPA-like component. A small Alpine component can own:

- transient success/error feedback on the page
- current visible card count
- empty-state switching or reload behavior when the current page becomes empty

Each card or action group can participate in that state through Alpine events or a shared parent component.

### Submission behavior

For each inbox form:

- intercept submit with Alpine
- send the request using `fetch`
- set `redirect` handling defensively so redirect/HTML responses are not treated as successful JSON state updates
- include `X-Requested-With: XMLHttpRequest`
- include `Accept: application/json`
- include CSRF token and form payload

On success:

- remove the acted-on card from the DOM
- decrement or replace the nav badge count using `remaining_count`
- show a small success message on the inbox page
- if no visible cards remain on the current paginated page, reload the current inbox URL so the server can render the correct next state

On failure:

- keep the card visible
- re-enable controls
- show an inline or page-level error message

Before calling `response.json()`, the client should confirm the response is actually JSON, for example by checking `Content-Type`. If the request succeeds but the response is HTML or an unexpected redirect result, the client should fall back to reloading the current inbox URL so the page cannot drift from server state.

### Loading state

Only the clicked action button should enter a loading/disabled state while its request is in flight.

This avoids freezing the whole page and keeps other inbox items usable.

### Badge update behavior

The inbox badge in the navigation should update immediately after AJAX success.

The current layout renders two separate badge locations:

- `data-testid="avatar-inbox-badge"`
- `data-testid="account-menu-inbox-badge"`

The enhancement should update both locations consistently, ideally through a shared `data-*` hook or a small helper that updates every badge instance together.

If `remaining_count` is:

- greater than `99`, show `99+`
- between `1` and `99`, show the numeric count
- `0`, hide the badge entirely

The avatar button label should also stay in sync with the updated count so accessibility text does not drift from the visible badge.

The inbox page and navigation should use the same post-action count so they do not drift.

### Empty state and pagination behavior

`InboxController@index` paginates actionable items, so the page should not assume that removing the last visible card on the current page means the entire inbox is empty.

Behavior:

- if cards still remain on the current page after a successful action, update the page in place only
- if the current page becomes empty after a successful action, reload the current inbox URL and let the server render the correct result
- after reload, the server may render another page of inbox items or the true empty state copy: `No inbox items right now.`

---

## Data Flow

### Success path

```text
User clicks inbox action
  -> Alpine intercepts form submit
  -> fetch() POST to existing inbox route with JSON headers
  -> InboxController authorizes and runs existing service logic
  -> controller returns JSON { ok, message, item_id, remaining_count }
  -> client removes that card
  -> client updates nav badge
  -> client updates avatar aria-label
  -> client shows success message
  -> if the current paginated page becomes empty, client reloads the inbox URL
```

### Failure path

```text
User clicks inbox action
  -> Alpine intercepts form submit
  -> fetch() POST to existing inbox route with JSON headers
  -> controller or service returns/throws failure
  -> controller returns JSON error with status code
  -> client keeps card visible
  -> client clears loading state
  -> client shows error message
```

---

## Testing

### Feature tests

Add or extend server-side feature tests to cover JSON requests for all inbox actions:

- standard inbox actions return successful JSON when authorized
- email review actions return successful JSON when authorized
- JSON responses include `ok`, `message`, `item_id`, and `remaining_count`
- actionable count is updated correctly after each success
- invalid snooze presets return `422`
- unauthorized requests are rejected
- save/import failures return JSON errors rather than redirects when JSON is requested

### Manual verification

Because the client enhancement is intentionally small and Blade-based, manual browser verification is sufficient for the UI layer:

- clicking inbox actions updates in place, except for the documented fallback reload when the current paginated page becomes empty
- only the clicked button is disabled while submitting
- the acted-on card disappears on success
- the nav badge updates immediately
- both badge locations stay synchronized
- if the current page becomes empty, the page reloads into the correct paginated or empty result
- failures leave the card visible and show an error message
- non-JS fallback still works with normal form submission

---

## Out Of Scope

- Converting the inbox page to Vue, Inertia, Turbo, or HTMX
- Replacing the existing routes with a separate API namespace
- Optimistic UI that removes a card before the server confirms success
- Undo behavior after inbox actions
- Dedicated front-end test infrastructure for this small Alpine enhancement
