# IdeaTub — Shareable readonly research

**Date:** 2026-03-17  
**Status:** Draft  
**Scope:** Allow owners to share a user-friendly readonly view of an entire research note via a hashed URL, with optional per-share password and optional expiry. No WebSockets on the share view; minimal branding.

## Overview

- **Goal:** Share a link that shows the full research note (root thought + all child sections) in a readonly, minimal UI. Viewer needs no account; owner can optionally protect the link with a password and set an expiry.
- **Share creation:** From the research thought card (root of the document) and from a dedicated “Shared research” page. Each share has a unique hashed token in the URL (e.g. `/r/{token}`).
- **Auth:** Public route for viewing; optional per-share password (form + cookie). Owner routes under `auth` middleware.

## 1. Data model

### 1.1 Table `research_shares`

| Column         | Type         | Notes |
|----------------|--------------|--------|
| id             | bigint PK    | |
| user_id        | FK users     | Owner |
| thought_id     | FK thoughts  | Root thought of the research note |
| token          | string, unique, indexed | Unguessable URL-safe token (e.g. 32 chars) |
| password_hash  | nullable string | Set when owner enables “Require password” |
| expires_at     | nullable timestamp | Link returns 410 after this time |
| created_at     | timestamp    | |
| updated_at     | timestamp    | |

- One share record = one share link for one root thought. V1: allow only one active share per thought (create returns error or redirects to manage if share exists).
- Token generated on create (e.g. `Str::random(32)`); lookup by token only. No signing.

### 1.2 Policy

- Only the thought owner can create/update/delete a share for that thought. Viewing `/r/{token}` requires no login; access is token plus optional password.

## 2. Routes and auth flow

### 2.1 Public: view shared research

- **GET /r/{token}** — No `auth` middleware.
  1. Find share by `token`. If not found → 404 (“Link not found or no longer available”).
  2. If `expires_at` is set and `now() > expires_at` → 410 Gone (“This link has expired”).
  3. If `password_hash` is set: check for valid share-access cookie. If missing or invalid, show password form (same URL or POST to same URL). On POST with correct password, set signed cookie (e.g. 24h), redirect to GET /r/{token}. Wrong password → 401, show form with “Incorrect password”.
  4. Load root thought by `thought_id`; if thought deleted → 404.
  5. Load children (comments) ordered by `created_at`. Render readonly Blade view.

### 2.2 Password UX

- Form + cookie (not HTTP Basic): friendly “Enter password to view” page; on success set signed cookie so subsequent requests don’t re-prompt until cookie expires. No session required for the rest of the app.

### 2.3 Owner routes (authenticated)

- **GET /shared-research** — List this user’s research shares. Each row: preview (root title/first line), copy link, password indicator, expiry, actions (Copy, Set/Change password, Set expiry, Revoke).
- **POST /shared-research** — Create share. Body: `thought_id` (required), optional `password`, optional `expires_at`. Generate token, store; return or redirect to list with new link. If share already exists for this thought → 422 or redirect with “Already shared; manage below.”
- **PATCH /shared-research/{share}** — Update password or expires_at. Authorize: share belongs to current user.
- **DELETE /shared-research/{share}** — Revoke (delete record). Links then 404.

## 3. Readonly research page

- **Content:** Root thought (title/heading + full content) and each child thought as a section (heading if present, body). Same structure as Stream tag view but readonly: no edit, delete, tags, or reply.
- **Layout:** Dedicated Blade view. Reuse IdeaTub typography/colours; **minimal branding** — no prominent logo or “IdeaTub” header; at most a small, low-contrast footer line (e.g. “Shared via IdeaTub”) or none in v1.
- **Technical:** No WebSockets, no Echo, no polling. Server-rendered HTML only. No `@push('scripts')` for realtime. Optional minimal JS only for copy-link or expand/collapse if added later.
- **Errors:** 404 unknown/revoked token or deleted thought; 410 expired.

## 4. Owner UX

### 4.1 Thought card — “Share”

- On the **root** thought card of a research note (e.g. Stream tag view), add a **Share** action.
- **No existing share:** Click opens create flow (modal or inline): optional “Require password”, optional “Expires at”. Submit → POST create → show link + copy, or redirect to Shared research.
- **Share exists:** Click goes to Shared research with that share focused, or popover with “Copy link” and “Manage”. V1: one share per thought, so “Share” means “manage/copy” when one exists.

### 4.2 Shared research page

- List all shares for the user. Per row: preview, share URL with copy button, “Protected” / “No password”, expiry or “Never”, actions: Copy, Set/Change password, Set expiry, Revoke. “Share another” to create (e.g. thought picker or link to Stream).
- After creating from card, redirect here with new share in list and flash or row expanded with copy.

### 4.3 Copy, revoke, password, expiry

- **Copy:** Full URL `https://your-domain/r/{token}` to clipboard.
- **Revoke:** Delete share record; existing links → 404.
- **Password:** Set/Change/Remove via PATCH; store hash or clear `password_hash`. Invalidate share cookie when password changes so visitors re-enter.
- **Expiry:** Optional when creating or editing; display in list as “Expires &lt;date&gt;” or “Never”.

## 5. Error handling and edge cases

- **404** — Unknown or revoked token, or root thought deleted. Message: “Link not found or no longer available.”
- **410** — Token valid but expired. Message: “This link has expired.”
- **One share per thought (v1):** On create, if share exists for that `thought_id`, 422 or redirect to Shared research with “This research is already shared; manage it below.”
- **Validation (create):** `thought_id` required; thought must exist and belong to user; thought must be root (`parent_id` null). Optional `password` (string; min length if we require one when “Require password” checked). Optional `expires_at` (date; must be future if set).
- **Authorization:** Create/update/delete share only for thought owner. List only owner’s shares.

## 6. Implementation notes (for plan)

- **Loading sections:** Use `$rootThought->comments()->orderBy('created_at')->get()` (or equivalent) for child sections.
- **Share-access cookie:** Scope to this share (e.g. include token in cookie name or payload) so changing password for one share doesn’t affect others; invalidate when password changes.
- **“Share exists” focus:** On Shared research page, “focused” can be achieved via query param (e.g. `?share={id}`), scroll-to, or expand row — specify in plan.

## 7. Out of scope for v1

- Multiple shares per thought; short URLs; view count; noindex/SEO; HTTP Basic option.
