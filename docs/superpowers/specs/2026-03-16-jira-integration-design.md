# IdeaTub — Jira integration (searchable activity, project as tag)

**Date:** 2026-03-16  
**Status:** Draft  
**Scope:** Ingest Jira activity (issues created/updated/commented, admin-style actions) as thoughts with type `jira` and project as tag; user-provided API key; on-demand sync from web and MCP. Discoverable via search and Stream.

## Overview

- **Goal:** Let users connect their Jira (API key), sync their activity into IdeaTub as thoughts, and discover it via semantic search and Stream (by tag/type). Use case: “What tickets did I touch in the last week?” before a client call, in both AI chat (MCP) and the IdeaTub web app.
- **Scope (v1):** On-demand sync only (UI + MCP trigger). Optional scheduled sync can be added later using the same job.
- **Credentials:** Per-user Jira site URL + API token, stored encrypted. No server-wide Jira key.
- **Data model:** Each Jira “event” (issue created, updated, commented, status/field change) becomes one **Thought** with `metadata.type = 'jira'`, **project key as tag** (plus `jira` tag), and rich `source_metadata` for display and idempotency.

### Feature toggle (on/off)

- **Config:** One app-level switch controls whether Jira is available: `config('services.jira.enabled', true)` from env `JIRA_ENABLED`. Default: enabled.
- **When off:** Jira is hidden everywhere: no Jira entry in settings nav or elsewhere; Jira settings routes return 404 (or redirect). MCP: `sync_jira` is not in `tools/list` and is not callable (method not found or "Jira integration is disabled").
- **When on:** Behaviour as in the rest of this spec. Existing Jira thoughts remain searchable and visible in Stream regardless of the toggle.

## 1. User credentials and storage

### 1.1 What we store

- **Jira site URL** (e.g. `https://your-domain.atlassian.net`). Required.
- **API token** (Jira Cloud: user creates at [id.atlassian.com](https://id.atlassian.com/manage-profile/security/api-tokens); auth is email + API token). Stored encrypted.

Store per user. Options:

- **Option A:** New table `user_jira_credentials` (or `integrations`): `user_id`, `jira_site_url`, `jira_api_token` (encrypted via Laravel `encrypted` cast or `Crypt::encryptString`), `updated_at`. One row per user when connected.
- **Option B:** JSON column on `users` table (e.g. `integrations` or `jira_credentials`) with encrypted payload.

**Recommendation:** Option A — keeps credentials out of the main users table and makes revoke/delete clear (drop row).

### 1.2 Settings UI

- **Location:** User settings (or “Integrations”) section.
- **Fields:** Jira site URL (text), API token (password input). “Connect” / “Save” persists encrypted. “Disconnect” clears stored credentials.
- **Validation:** **v1 scope:** Credential validation on save is **optional**. If implemented, call `GET /rest/api/3/myself` when the user saves; if it fails, show “Invalid Jira credentials” and do not persist. If not implemented in v1, the first sync will fail and the user sees the job outcome (e.g. “Sync failed”); acceptable for v1.
- **Security:** Token never echoed back; mask in UI (e.g. “••••••••” when present). Use HTTPS only for site URL.

## 2. What we fetch from Jira (API)

- **Target:** Jira Cloud REST API v3. Auth: Basic (email + API token) or Bearer (if using OAuth later; v1 = API token).
- **Scope:** Issues where the authenticated user is reporter, assignee, or commenter, updated in the last N days (e.g. N = 14 or 30; configurable per sync).

### 2.1 Issue activity (A)

- **Search:** `GET /rest/api/3/search` with JQL: `(reporter = currentUser() OR assignee = currentUser()) AND updated >= -Nd`. This returns issues the user reported or is assigned to. For “commented by me”, **v1 approach:** do not use JQL; fetch the same set of issues (updated in range) and when loading each issue’s comments, filter by current user as author. That yields all events (reporter/assignee + comments by me) with one consistent strategy.
- **Per issue:** Request with `expand=changelog,renderedFields` (or minimal expand) to get:
  - **Created:** issue creation → one “created” event.
  - **Updates:** from `changelog.histories`: each item where `author.accountId` (or equivalent) matches current user → one “updated” event (field change, status transition, assignee change, etc.). Covers “admin-style” actions (B).
- **Comments:** `GET /rest/api/3/issue/{issueId}/comment` (or from issue expand). Filter by current user as author → one “comment” event per comment in range.

### 2.2 Admin-style actions (B)

- **Source:** Same changelog. Status changes, assignee changes, field edits, transitions performed by the user are all in `changelog.histories` with the user as author. No separate API needed; one thought per relevant history item (or one per “logical” action; see §3).

### 2.3 Project and idempotency

- **Project:** From issue `fields.project.key` (e.g. `PROJ`). Normalize to lowercase for tag: `proj`.
- **Stable event id:** For each event we need a unique, stable id so we don’t create duplicate thoughts on re-sync.
  - **Created:** `{issueKey}:created:{issueCreatedAt}` (or issue id + created).
  - **Changelog entry:** `{issueKey}:changelog:{history.id}` (Jira provides id in history).
  - **Comment:** `{issueKey}:comment:{comment.id}`.
  Store this as `source_metadata.jira_event_id`. Before creating a thought, check that no thought for this user already has `source = 'jira'` and `source_metadata->>'jira_event_id' = ?` (JSON query). If exists, skip.

## 3. Thought shape (one per event)

- **content:** Short, searchable summary. Examples:
  - Created: `Created PROJ-123: Implement login form`
  - Update: `PROJ-123: Status → In Progress`
  - Comment: `Commented on PROJ-123: Looks good, please add tests`
- **embedding:** Generated from `content` via existing OpenRouterService (same as other thoughts) so semantic search works.
- **metadata:**
  - `type`: `'jira'`.
  - `tags`: `['jira', project_key_lowercase]` (e.g. `['jira', 'proj']`). Always include `jira`; always include the issue’s project key (lowercase) so Stream can filter by project. Use `Thought::normalizeMetadataTags()`.
  - Optional: `jira_issue_key`, `jira_updated_at` (ISO string) for display/sorting.
- **source:** `'jira'`.
- **source_metadata:** At least:
  - `jira_event_id` (string) — for idempotency.
  - `jira_issue_key` (e.g. PROJ-123).
  - `jira_issue_summary` (title).
  - `jira_project_key` (e.g. PROJ).
  - `jira_event_type`: `created` | `updated` | `comment`.
  - `jira_updated_at` (ISO) or `jira_created_at` for comments.
  - `jira_link` (browser URL to the issue or comment).

Other metadata keys (e.g. field name for “updated” events) can be added for richer display later.

## 4. Sync job and idempotency

### 4.1 JiraSyncService contract

- **Purpose:** Fetch the user’s Jira activity and return a list of events suitable for creating thoughts. Keeps API and parsing logic out of the job.
- **Public method:** `fetchEvents(User $user, int $days = 14): array`
- **Returns:** Array of event arrays, each with at least: `jira_event_id`, `content` (short summary string), `metadata` (type, tags), `source_metadata` (keys listed in §3). The job iterates this list and creates thoughts (with idempotency check). No direct Thought or OpenRouter calls inside the service; the job owns embedding and persistence.
- **Dependencies:** HTTP client (Laravel), user’s decrypted Jira credentials. Throws on unrecoverable API errors (see §7) so the job can fail or retry.

### 4.2 Job steps

- **Job:** e.g. `SyncUserJiraActivity`. Accepts `user_id` and optional `days` (default 14). Runs in queue.
- **Steps:**
  1. Load user’s Jira credentials; if missing, exit (no failure; user not connected).
  2. Call `JiraSyncService::fetchEvents($user, $days)`. On exception (e.g. 401, 5xx), rethrow so the job fails and can retry or surface to user.
  3. For each event, check if a thought already exists for this user with `source = 'jira'` and same `jira_event_id` (query using Laravel JSON: `Thought::where('user_id', $id)->where('source', 'jira')->where('source_metadata->jira_event_id', $event['source_metadata']['jira_event_id'])->exists()`). If exists, skip.
  4. From each event use `content` and `metadata` (and `source_metadata`) returned by `fetchEvents`; call OpenRouterService to embed content; create Thought with content, embedding, metadata, user_id, source, source_metadata.
  5. **Evernote:** For v1, do **not** trigger Evernote sync for thoughts with `source = 'jira'` (either skip in the Thought model observer when source is jira, or ensure Evernote notebook mapping does not include `jira` by default). One-way: Jira thoughts stay in IdeaTub only unless the user explicitly adds a jira notebook mapping later.
- **Rate limits:** Inside JiraSyncService, respect Jira rate limits (back off / throttle if needed); prefer batching and minimal requests (search with maxResults, then batch issue expand).

## 5. Triggers (v1: on-demand only)

- **Web:** In settings/integrations, “Sync Jira now” button. Calls a controller that dispatches `SyncUserJiraActivity` for the current user (and optionally redirects with flash “Sync started”).
- **MCP:** New tool `sync_jira` (optional params: `days`). Handler resolves user from MCP key, dispatches the same job, returns a message like “Jira sync started for the last N days. You can search or browse recent thoughts for your Jira activity.”
- **Scheduled (later):** Same job can be invoked by a scheduler for users who have Jira connected and optionally “Sync every X hours” enabled; no change to job contract.

## 6. Discoverability (search and Stream)

- **Search:** Existing semantic search (web `?q=...` and MCP `search_thoughts`) already runs over all thoughts; Jira thoughts are included. Users can ask “tickets I updated last week” and get Jira thoughts if the content/embedding match.
- **Stream:** Existing Stream filtered by tag shows thoughts with that tag. Filter by `jira` = all Jira activity; filter by project tag (e.g. `proj`) = all thoughts tagged with that project, including Jira events for that project. No new UI required; tags are already shown and filterable.
- **MCP:** No new read tools. `search_thoughts` and `browse_recent` return Jira thoughts like any other. Tool list or docs can mention that Jira activity is stored as thoughts with type `jira` and project tags.

## 7. Edge cases and security

- **Credentials:** Never log or expose API token. Store encrypted; decrypt only in sync job or when validating.
- **No Jira data in logs:** Avoid logging issue keys or summaries in plain text at info level; at most log “sync completed for user X, N events”.
- **Missing project:** If an issue has no project (shouldn’t happen in Cloud), use tag `['jira']` only.
- **Duplicate events:** Idempotency via `jira_event_id` prevents duplicate thoughts on re-sync.
- **Large history:** Cap events per sync (e.g. last 500 events or last 30 days) to avoid timeouts and rate limits; document in UI.

### 7.1 Jira API errors

- **401 / 403 (invalid or revoked token):** JiraSyncService throws (or returns a structured error); job fails. On next sync trigger (or in UI), surface a clear message: “Invalid Jira credentials. Please check your API token and site URL in settings.”
- **429 (rate limit):** JiraSyncService should throttle (retry with backoff). If repeated 429s, fail the job after a sensible retry count and log; user can re-trigger sync later.
- **5xx / timeout:** Fail the job (or retry once with backoff, then fail). Do not create partial thought sets; user can re-run sync. Optionally show “Jira sync failed. Try again later.”
- **UI/MCP:** When the job fails, “Sync Jira now” / `sync_jira` should indicate failure (flash message or MCP response) so the user knows to check credentials or retry.

## 8. Out of scope (v1)

- OAuth for Jira (use API token only).
- Jira Data Center (assume Cloud).
- Scheduled sync (add later with same job).
- Editing or deleting Jira-linked thoughts from Jira (one-way sync only).
- Webhooks (polling/on-demand only).

## 9. Implementation notes

- **Jira API client:** Use Laravel HTTP client or a minimal wrapper (GET with Basic auth). No need for a full SDK if we only need search + issue + changelog + comments.
- **Evernote:** If Evernote notebook mapping includes `jira`, Jira thoughts may sync to Evernote; else leave default behaviour (no mapping = no sync for that type, or map to default notebook). No change required for v1.
- **Tests:** Unit tests for JiraSyncService (parse Jira response → events, build thought payload; `fetchEvents` return shape); feature test for “sync creates thoughts with correct type and tags”; idempotency test (run twice, same event count).
- **DB queries:** Use Laravel’s JSON query syntax for the active driver (e.g. `where('source_metadata->jira_event_id', $id)` for MySQL/PostgreSQL compatibility).
