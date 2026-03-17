# IdeaTub — Thought drafts, auto-save, and focus (full-window) mode

**Date:** 2026-03-17  
**Status:** Draft  
**Scope:** Server-side drafts for the thought capture form, debounced auto-save, draft list UX, and full-window (focus) overlay. Drafts are pre-analysis only; cross-device resume.

## Overview

- **Goals:** Prevent loss of in-progress thoughts (refresh, tab close, navigate away); allow resuming a thought on another device; support focus mode and long-form writing (e.g. meeting notes).
- **Draft:** Unsaved thought — plain text (+ optional `no_chunking`). No embedding, no metadata extraction, no chunking until the user clicks "Store thought".
- **Approach:** Separate `drafts` table; REST-style API; debounced auto-save; draft list shown only when drafts exist; full-viewport overlay for focus mode (Escape, Close button, or backdrop click to close).

---

## 1. Drafts — data model and API

### 1.1 Data model

- **Table:** `drafts`. Columns: `id` (uuid, primary key), `user_id` (foreign key), `content` (text), `no_chunking` (boolean, default false), `updated_at` (timestamp). No `created_at` required for v1; ordering by `updated_at` is sufficient.
- **Cap:** Maximum 10 drafts per user. When creating a new draft would exceed the cap, delete the oldest draft (by `updated_at`) before creating.
- **Isolation:** All draft operations are scoped by current user (web auth).

### 1.2 API (JSON, web auth)

- **List:** `GET /api/drafts` or `GET /ideas/drafts` — list current user's drafts, ordered by `updated_at` desc. Response: array of `{ id, content_preview, updated_at }` (e.g. preview first 60 chars or first line). Limit to cap (10).
- **Get one:** `GET /api/drafts/{id}` or `GET /ideas/drafts/{id}` — full draft for resume. Response: `{ id, content, no_chunking, updated_at }`. 404 if not found or not owned by user.
- **Create:** `POST /api/drafts` — body `{ content, no_chunking? }`. Returns `{ id, content, no_chunking, updated_at }`. Enforce cap by deleting oldest if needed.
- **Update:** `PATCH /api/drafts/{id}` — body `{ content, no_chunking? }`. Returns same shape. Used for auto-save.
- **Delete:** `DELETE /api/drafts/{id}` — discard draft. 204 or 200 with empty body.

All routes require authenticated user; authorize that the draft belongs to the user for get/update/delete.

---

## 2. Auto-save behaviour

- **Trigger:** Content or "Don't split into sections" checkbox changes. Debounce **1–1.5 seconds** after last change before sending request.
- **Create vs update:** First auto-save in a session (no draft id bound) → `POST /api/drafts`. Subsequent auto-saves → `PATCH /api/drafts/{id}` for the current draft id. When user chooses "Resume" on a draft, form is bound to that draft id; all further auto-saves update that draft until they Store thought or switch draft.
- **Empty form:** Do not create a draft when content is empty. If user had a draft loaded and clears the text, leave the draft as-is (they can discard from the list if they want).
- **After "Store thought":** On successful submit to the existing thought store endpoint, delete the draft that was bound to the form (if any), then clear form and show success. No "keep draft" option for that submission.
- **Cross-device / multiple tabs:** No merge. Last write wins; no conflict UI in v1.

---

## 3. Draft list UX

- **Visibility:** Show the draft list **only when there are drafts** (count > 0). No "No drafts" or empty state when the list is empty. Drafts appear when the user is writing (auto-save has created or updated at least one draft).
- **Placement:** Inside the capture area (above or beside the textarea). Same in inline and focus (overlay) mode. Collapsed by default: e.g. "Drafts (3)" that expands to show the list.
- **Expanded list:** Each row: short preview (e.g. first 40–60 chars), relative time (e.g. "2 min ago"), **Resume** (load into form, bind form to this draft id), **Discard** (DELETE draft; optional confirmation for non-empty). Resume loads content and `no_chunking` into the form.
- **Page load:** Do not auto-load any draft on page load in v1. Form starts empty unless the user explicitly clicks Resume.
- **Reply mode:** When the user is replying to a thought (`parent_id` set), do not create or update drafts; hide the draft list in reply mode. Drafts are for new thoughts only.

---

## 4. Full-window (focus) overlay

- **Control:** A button/link in the capture area (e.g. "Focus" or "Expand") toggles focus mode. In focus mode, the form is shown in a **full-viewport overlay**: capture box (textarea, checkbox, Store thought, draft list when present) in a centered max-width container; rest of page covered by a dimmed backdrop.
- **Same form, same state:** Overlay uses the same form and Alpine state as inline (or same component/state). No navigation; expanding does not re-mount. Content and current draft id stay consistent.
- **Closing:** **Escape**, explicit **Close / Collapse** button, or **backdrop click** closes the overlay and returns to inline view. No URL change.
- **Layout:** Overlay is `position: fixed; inset: 0` with high z-index. Inner content max-width (e.g. 600px) centered; textarea can grow (min-height or flex) for long-form. Optional: slightly larger text in focus mode.
- **Keyboard and a11y:** Escape closes overlay. Focus trap when open (focus textarea); on close, return focus to the expand button or previous element. Overlay has `role="dialog"` and `aria-modal="true"`; capture region labeled for screen readers.
- **Reply mode:** Expand control can still be shown in reply mode; draft list remains hidden when replying.

---

## 5. Error handling and edge cases

- **Auto-save failure:** On network or server error, do not block the user. Optionally show a small non-intrusive indicator ("Draft couldn't be saved") and retry on next debounce or on blur. Do not clear the form.
- **Draft load failure:** If GET draft fails when user clicks Resume, show a short message and leave form as-is.
- **Store thought with draft bound:** Always delete the draft on successful store so the draft list stays in sync.

---

## 6. Implementation notes

- **Routes:** Prefer `GET/POST /ideas/drafts` and `GET/PATCH/DELETE /ideas/drafts/{id}` for consistency with existing `ideas.*` routes, or use `api/drafts` if the app prefers API prefix for JSON-only. Implement in a controller that authorizes the current user.
- **Frontend:** Extend existing `captureBox()` Alpine component (or equivalent) to: (1) fetch drafts on init when not in reply mode, (2) debounced auto-save to POST/PATCH drafts, (3) draft list UI (conditional on drafts.length > 0), (4) focus overlay state and toggle. Reuse existing form submit for "Store thought" (existing endpoint); after success, call DELETE draft if bound, then clear form and redirect/update as today.
- **Migration:** Single migration adding `drafts` table with columns above; index on `user_id` and `updated_at` for list + cap enforcement.
