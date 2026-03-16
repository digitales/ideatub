# IdeaTub — Real-time Stream, Home, and Ideas (new thought live updates)

**Date:** 2026-03-16  
**Status:** Draft  
**Scope:** When a new thought is created (web, MCP, or any source), open tabs on Stream, Home, or Ideas update without full page reload. Support both Reverb (production) and polling (local) via a config toggle.

## 1. Architecture and driver toggle

### 1.1 Goal

- **Scope (v1):** New thought created anywhere (web capture, MCP `capture_thought` / `capture_plan`, email, etc.) → every open tab on **Stream**, **Home**, or **Ideas** for that user reflects it without refresh (e.g. refetch and replace the list).
- **Out of scope (v1):** Pushing “thought updated” (e.g. tag edits) to other tabs.

### 1.2 Two modes, one toggle

Support two mechanisms and choose at runtime via config:

- **Reverb (production):** Laravel Broadcasting + Reverb. Backend broadcasts `ThoughtCreated` to a private user channel; front end uses Laravel Echo to subscribe and, on event, runs the shared “new thought” handler (refetch current view).
- **Polling (local / fallback):** Front end periodically (e.g. 15–30 s) calls a light endpoint (“new thoughts since last check”); if the response indicates new data, run the **same** refetch logic as for Reverb.

The only difference is **how** the client learns “something new exists”; once it knows, behaviour is identical.

### 1.3 Toggle and fallback

- **Config:** e.g. `REALTIME_DRIVER=reverb` or `polling` (env). Default `polling` if unset so local runs without Reverb.
- **Front end:** Receives driver + Reverb config (when present) from Blade. If driver is `reverb` and Reverb keys are present, init Echo and subscribe; otherwise use polling (or if Reverb fails to connect, fall back to polling).
- **Local:** No Reverb; `REALTIME_DRIVER=polling` or unset. Polling endpoint is a cheap query (e.g. by `user_id` and `created_at`).
- **Production (Laravel Cloud):** Attach Reverb WebSocket app, set `REALTIME_DRIVER=reverb` and Reverb env vars; Echo is used, polling not started.

## 2. Backend: events, channels, polling endpoint

### 2.1 Channel (Reverb only)

- **Channel:** Private channel per user, e.g. `private-App.Models.User.{id}`. Authorize in `routes/channels.php`: user can subscribe only if `auth()->id()` matches the channel user id.

### 2.2 Event (Reverb only)

- **Event:** `App\Events\ThoughtCreated`, fired when a thought is created. Trigger from a `Thought::created` model listener (or observer) so all creation paths (web, MCP, research, chunked) are covered.
- **Payload:** `thought_id`, `user_id`, `parent_id`, `metadata` (at least `type`, `tags`) so the client can decide Stream/Home/Ideas and tag filter.
- **Broadcast:** Only when `REALTIME_DRIVER=reverb` (or only register the listener when Reverb is enabled), to avoid unnecessary work when using polling only.

### 2.3 Polling endpoint (polling only)

- **Route:** e.g. `GET /api/thoughts/realtime-check` (auth required). Query params: `since` (ISO8601 or timestamp) or `last_id` (UUID of last known thought).
- **Response:** Light payload, e.g. `{ "has_new": true }` or `{ "has_new": false }`, or `{ "latest_id": "...", "latest_created_at": "..." }` so the client can compare. Server: single cheap query (e.g. max `created_at` or count for user since `since` / after `last_id`).
- **Rate:** Client polls every 15–30 s; optional `Retry-After` or backoff if we want to throttle.

## 3. Front-end: shared behaviour and driver-specific setup

### 3.1 Shared “new thought” handler

- **Input:** Client knows “there might be new thoughts” (from Echo event or polling response).
- **Behaviour:** Same for both drivers:
  - **Stream:** If current view is “all” or “by tag”, refetch first page of Stream (same URL as current, page 1) and replace `#stream-thoughts-list`; update “Showing X of Y” if present.
  - **Home:** Refetch the “recent thoughts” block (endpoint returning same HTML as initial recent list) and replace that block.
  - **Ideas:** Refetch first page of ideas list and replace the ideas container.
- **Filtering:** For Stream with tag filter, refetch with that tag; client can use payload from Reverb (`parent_id`, `metadata.tags`) to avoid refetch when the new thought can’t match (optional optimization). Simplest v1: always refetch when `has_new` or event received; server returns correct list.

### 3.2 Reverb path

- **Echo:** Laravel Echo + Pusher driver; config from Blade (e.g. `window.ideatub.reverb`). Subscribe to `private-App.Models.User.{userId}` and listen for `ThoughtCreated`. On event, run shared handler (and optionally only if `parent_id === null` for Stream/Home, and `metadata.type === 'idea'` for Ideas).
- **Pages:** Only on Stream, Home, Ideas (subscribe or poll only when on these pages).

### 3.3 Polling path

- **Interval:** When driver is `polling`, start a timer (e.g. 20 s). Call `GET /api/thoughts/realtime-check?since=...` with last known `created_at` or `last_id` from the current list. If `has_new` (or equivalent), run the same shared refetch handler and update `since` / `last_id` for next poll.
- **Pages:** Same as Reverb; only on Stream, Home, Ideas. Clear interval when leaving the page or when tab is hidden (optional) to reduce load.

### 3.4 Refetch endpoints

- **Stream:** Existing AJAX endpoint (same as “load more”) with `page=1` and current `tag` if any; returns HTML + optional count. Use it to replace the list.
- **Home:** New or existing endpoint that returns the “recent thoughts” HTML fragment (same as initial load). Replace the recent-thoughts container.
- **Ideas:** New or existing endpoint that returns the first page of ideas list HTML; replace ideas container.

## 4. Error handling, testing, Laravel Cloud

### 4.1 Errors

- **Echo:** If connection fails or `/broadcasting/auth` returns 403, fail gracefully (no live updates); page still works. Optional: fall back to polling when Reverb is configured but connection fails.
- **Polling:** On repeated errors, back off (e.g. double interval, max 60 s). Don’t block page render.

### 4.2 Testing

- **Backend:** Assert that creating a thought dispatches `ThoughtCreated` when Reverb is enabled; assert polling endpoint returns `has_new` when a thought was created after `since`. Channel auth: `POST /broadcasting/auth` 200 for owning user, 403 for others.
- **Front-end:** Manual or E2E: open Stream (or Home/Ideas), create thought via MCP or other tab; confirm list updates. Test both driver=reverb (if Reverb available) and driver=polling.

### 4.3 Laravel Cloud

- Use managed Reverb when `REALTIME_DRIVER=reverb`; Reverb env vars injected by Cloud. No long-lived PHP for Reverb; polling is normal HTTP. Load: one connection per tab when using Reverb, or one poll request per tab every 15–30 s when using polling.

## 5. Out of scope (v1)

- Thought **updated** (e.g. tag edits) live push.
- Prepend-single-card optimization (refetch is sufficient for v1).
