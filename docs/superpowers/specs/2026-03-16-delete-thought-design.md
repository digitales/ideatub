# IdeaTub — Delete thought (web, card menu + inline confirm)

**Date:** 2026-03-16  
**Status:** Draft  
**Scope:** Allow users to delete their own thoughts from the web app (Home and Stream) via a card menu and inline confirmation. Deletion is blocked when the thought has comments.

## Overview

- **Goal:** Provide a way to delete thoughts from IdeaTub with intentional friction (menu → confirm).
- **UX:** Card menu (⋮) → “Delete” → inline “Delete thought?” with Cancel / Delete. If the thought has comments, show “This thought has comments. Remove them first.” and do not delete.
- **Backend:** Single DELETE endpoint; policy and controller enforce owner-only and block when comments exist.

## 1. Backend

### 1.1 Route and controller

- **Route:** `DELETE /ideas/{thought}` (name: `ideas.destroy`). Use existing `{thought}` route model binding; place alongside `ideas.toggle-completed`, `ideas.update-tags`, and `ideas.research` in `routes/web.php`.
- **Controller:** New method on `IdeaController`, e.g. `destroy(Request $request, Thought $thought)`. Authorize with `$this->authorize('delete', $thought)`.

### 1.2 Policy

- Add `ThoughtPolicy::delete(User $user, Thought $thought): bool` — allow only when `$thought->user_id === $user->id`. Comment check is done in the controller so the response body can return a clear message.

### 1.3 Controller behaviour

- After authorize, if the thought has comments: `$thought->comments()->exists()` → return 422 (JSON) with a message such as `"This thought has comments. Remove them first."` (do not delete).
- Otherwise: `$thought->delete()` (hard delete).
- **Response:** For AJAX (e.g. `Accept: application/json` or `X-Requested-With: XMLHttpRequest`): 204 No Content on success. For non-AJAX (e.g. form submit): redirect back with success flash.
- **Evernote:** Do not delete the note in Evernote. The thought is removed from IdeaTub only; the Evernote note (if any) remains. Document this; optional follow-up to add Evernote delete later.

## 2. Card menu and inline confirm (UX)

### 2.1 Card menu (⋮)

- Each thought card has a small **⋮** (vertical three dots) control, placed in the card’s top-right or next to the existing metadata row (e.g. after “Reply” / tags).
- Shown only when the viewer is the thought owner (same as editable tags: e.g. `auth()->id() === $thought->user_id`).
- Click opens a dropdown with one item: **“Delete”**.
- Clicking outside or elsewhere closes the menu.
- Same behaviour on **Home** (index thought cards) and **Stream** (stream thought cards). Use a shared partial so both views stay in sync.

### 2.2 Inline confirmation

- When the user chooses “Delete” from the menu, the menu closes and the same card shows an **inline confirmation**: e.g. “Delete thought?” with **Cancel** and **Delete** (Delete styled as danger).
- **Cancel:** Hide the confirmation and return to the normal card (no request).
- **Delete:** Send `DELETE /ideas/{thought}` (AJAX).
  - **Success (204):** Remove the card from the DOM (or refetch the list if preferred).
  - **Error (422 — has comments):** Keep the confirmation visible and show inline: “This thought has comments. Remove them first.”
  - **Other errors (4xx/5xx):** Show a short inline or toast message (e.g. “Couldn’t delete. Try again.”).

### 2.3 Implementation

- Use a shared Blade partial for the thought card actions (menu + inline confirm) and include it in both `index_thought_cards.blade.php` and `stream_thoughts.blade.php` (or the single card partial if one exists).
- JS can be Alpine on the card (e.g. `x-data` with `menuOpen`, `confirmOpen`, `deleteInProgress`, `errorMessage`) or a small script; avoid duplicating logic between views.

### 2.4 Accessibility

- Menu button has an accessible name (e.g. “Actions” or “More actions”).
- Confirm text and buttons are focusable and keyboard-usable; Escape cancels the confirmation.

## 3. Edge cases and testing

### 3.1 Edge cases

- **Thought has comments:** Backend returns 422 with message; frontend shows inline “This thought has comments. Remove them first.”
- **Concurrent delete:** If the thought was already deleted (e.g. another tab), server returns 404. Frontend: remove the card from the DOM, or show “Thought no longer exists” then remove.
- **Auth / session:** If the user is logged out before clicking Delete, request returns 401/403. Show a short message (e.g. “Please sign in again”) and clear the confirm state.
- **Evernote:** Thought is removed from IdeaTub only; the Evernote note (if any) is left unchanged.

### 3.2 Testing

- **Policy:** Assert `ThoughtPolicy::delete` allows owner and denies non-owner and guest.
- **Controller:** (1) Authorized user, no comments → delete returns 204 and thought is gone from DB. (2) Authorized user, has comments → 422 and thought still exists. (3) Unauthorized (wrong user or guest) → 403. (4) Missing thought → 404.
- **UI:** Manual or browser tests: open menu → Delete → Cancel leaves thought; Delete with no comments removes card; Delete with comments shows inline error and thought remains.
