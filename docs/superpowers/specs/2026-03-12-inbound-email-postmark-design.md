# IdeaTub — Inbound email capture (Postmark)

**Date:** 2026-03-12  
**Status:** Draft  
**Scope:** Inbound-only email → Thought via Postmark; multiple inbound addresses per user; unmatched emails stored for analysis; secret-path webhook auth; attachment names only in metadata.

## Overview

- **Goal:** Users can “email a thought into IdeaTub.” Emails sent to a Postmark inbound address are turned into Thoughts with `source = 'email'`, discoverable like any other thought.
- **Relay:** Postmark receives the email and POSTs JSON to IdeaTub. No mailbox sync (no IMAP/JMAP).
- **User resolution:** Match sender (`From`) to the user’s primary email or to any of their **inbound email addresses** (configurable in account settings). If no user matches, the email is stored in a separate **unmatched** store for later analysis.
- **Security:** Webhook URL includes a secret path segment (no Basic Auth). Only requests to the full path are accepted.
- **Attachments:** Store only attachment **names** in email metadata; do not store file content.

---

## 1. Webhook endpoint and auth

### 1.1 Route and secret path

- **Route:** `POST /webhooks/postmark/inbound/{token}`  
  The `{token}` segment must equal the value of `POSTMARK_INBOUND_WEBHOOK_SECRET` from config/env. If it does not match, return `404` (do not leak that the endpoint exists).
- **No session/auth middleware** on this route. It is “public” but only callable by someone who knows the full URL (Postmark is configured with that URL).
- **Single webhook URL per app:** e.g. `https://yourdomain.com/webhooks/postmark/inbound/your-secret-token`. Each deployment has its own secret; the path is the “custom inbound path” and acts as the shared secret.

### 1.2 Response policy

- **Success (thought created or skipped):** return `200`.
- **Unknown sender (no matching user):** store in unmatched store, return `200` (so Postmark does not retry).
- **Duplicate MessageID:** return `200`, no second thought.
- **Empty body:** return `200`, no thought (optionally do not store as unmatched).
- **Validation/parse error:** return `422` or `400` as appropriate.
- **Server error:** return `5xx`; Postmark will retry per their policy.

---

## 2. User resolution: primary email + inbound addresses

### 2.1 Matching rule

Resolve the sender to a user by normalised “From” address:

1. Take `From` or `FromFull.Email` from the Postmark payload; normalise (trim, lowercase).
2. Find a user where:
   - `users.email` equals that address, **or**
   - the user has an inbound address record with that email (see §3).

If exactly one user matches, that user owns the thought. If none match, treat as unmatched (§4). If more than one user could match (same address on multiple users), define a deterministic rule (e.g. prefer `users.email` match, then lowest `user_id`); in practice, emails should be unique across users.

### 2.2 Inbound addresses (multiple per user)

- **Data:** New table `user_inbound_addresses` (or `inbound_email_addresses`):
  - `id` (pk)
  - `user_id` (fk → users)
  - `email` (string, unique across the table so one address can’t be used by two users)
  - `created_at` / `updated_at`
- **Normalisation:** Store and compare emails in lowercase, trimmed. Uniqueness is enforced at DB level.
- **No verification in v1:** Adding an address is enough for it to be used for matching. Optional verification (e.g. send a link to confirm) can be added later.

### 2.3 Account UI: managing inbound addresses

- **Location:** New settings area, e.g. **“Inbound email”** or **“Email capture”**, reachable from the same nav as MCP keys (e.g. `/settings/inbound-emails`).
- **Behaviour:**
  - List the user’s **primary email** (from `users.email`) with a note that it’s always allowed for capture.
  - List all **inbound addresses** from `user_inbound_addresses` for this user.
  - **Add:** Form to submit a new email; validate format and uniqueness; insert into `user_inbound_addresses`.
  - **Remove:** Delete button per row; only allow removal of inbound addresses (not the primary account email from this screen).
- **Display:** Show the Postmark inbound address (or “your capture address”) and a short note: “Emails you send from any of these addresses will become thoughts.”
- Follow the same patterns as MCP keys: controller, policy if needed, Blade view, routes under `settings/`.

---

## 3. Thought creation (matched senders)

### 3.1 Content and body

- Prefer `TextBody` if present and non-empty; otherwise strip HTML from `HtmlBody` and use that. If both are empty, do not create a thought (and do not store as unmatched).
- Run the chosen body through the existing pipeline: embed (OpenRouter), extract metadata (tags, etc.), then create a Thought.

### 3.2 Source and source_metadata

- **source:** `'email'`.
- **source_metadata (JSON):**
  - `message_id` — Postmark `MessageID`
  - `from` — sender email
  - `subject` — Postmark `Subject`
  - `date` — Postmark `Date` string
  - Optional: `to`, `reply_to` for future “reply by email” or analytics
  - **attachment_names** — array of strings: the **names** (filenames) of attachments only. Do **not** store attachment content or any file data. Example: `["report.pdf", "screenshot.png"]`. If no attachments, omit or use `[]`.

### 3.3 Idempotency

- Before creating a thought, check whether a thought already exists for this user with `source = 'email'` and `source_metadata->message_id` equal to the incoming `MessageID`. If so, return `200` and do nothing.

### 3.4 Evernote and other behaviour

- Same as web/MCP: new thought triggers existing Evernote sync and any other `Thought::created` behaviour. No special case for email.

---

## 4. Unmatched emails: separate storage for analysis

### 4.1 Purpose

When the “From” address does not match any user (primary or inbound addresses), do not create a thought and do not drop the email. Store it in a **separate** store so it can be analyzed later (e.g. assign to a user manually, fix address mapping, or detect misconfigurations).

### 4.2 Schema

- **Table:** `unmatched_inbound_emails`
- **Columns (suggested):**
  - `id` (pk)
  - `message_id` (string, unique) — Postmark `MessageID`, for idempotency and deduplication
  - `from_email` (string)
  - `to_email` (string, nullable)
  - `subject` (string, nullable)
  - `body_text` (text, nullable) — stripped/plain body only, no raw HTML
  - `received_at` (timestamp, nullable) — parsed from Postmark `Date` if possible
  - `payload_json` (json, nullable) — optional: minimal copy of the Postmark payload (or key fields only) for debugging; avoid storing large binaries or attachment content
  - `created_at` / `updated_at`

- **Idempotency:** If an inbound webhook has the same `MessageID` and we have already stored it in `unmatched_inbound_emails`, do not insert again (return `200`).

### 4.3 Access and UI

- **v1:** No public UI required. Storage is for later analysis (admin tool, script, or future “claim this email” feature). Optionally add a simple internal/admin list or export.
- **Privacy:** Treat this table as sensitive; restrict access to admins or the owning system only.

---

## 5. Attachments

- **Store in thought:** Only the **names** of attachments (e.g. `["file.pdf", "image.png"]`) in `source_metadata.attachment_names`.
- **Do not store:** Attachment content, base64 bodies, or any file data. Do not persist attachments to disk or object storage in v1.
- **Postmark payload:** Read `Attachments[].Name` (or equivalent) from the webhook JSON and collect into an array of strings for `attachment_names`.

---

## 6. Edge cases (summary)

| Case | Action |
|------|--------|
| Empty body | No thought; do not store as unmatched; return 200 |
| Unknown sender | Store in `unmatched_inbound_emails`; return 200 |
| Duplicate MessageID (thought exists) | Return 200; no new thought |
| Duplicate MessageID (already in unmatched) | Return 200; no new row |
| Invalid/empty token in path | Return 404 |
| Malformed JSON / missing required fields | Return 4xx; no thought, no unmatched store |
| Spam (optional) | v1: no filtering; optional later: use Postmark `X-Spam-Score` to reject and return 200 |

---

## 7. Configuration and deployment

- **Webhook URL:** `{APP_URL}/webhooks/postmark/inbound/{POSTMARK_INBOUND_WEBHOOK_SECRET}`. Configure this exact URL in Postmark’s Inbound stream settings.
- **Env:**
  - `POSTMARK_INBOUND_WEBHOOK_SECRET` — long, random string used as the path segment.
  - `POSTMARK_INBOUND_CAPTURE_ADDRESS` — (optional) address shown in Settings (e.g. `capture@ideatub.com`).
  - `POSTMARK_INBOUND_LOG_EMAILS` — (optional) set to `true` to log incoming email payloads (sanitized: no attachment content, bodies truncated) to the default Laravel log. Set to `false` or leave unset when no longer needed.
- **Postmark:** Create an Inbound stream; set the webhook URL to the full path above. Configure MX or inbound forwarding so that mail to your capture domain reaches Postmark.
- **Fastmail (user side):** User adds a rule or alias to send (or forward) to the Postmark inbound address. No Postmark-specific config in Fastmail.

---

## 8. Implementation outline

1. **Migrations**
   - `user_inbound_addresses`: `user_id`, `email` (unique), timestamps.
   - `unmatched_inbound_emails`: `message_id` (unique), `from_email`, `to_email`, `subject`, `body_text`, `received_at`, `payload_json` (optional), timestamps.

2. **Models**
   - `UserInboundAddress` (belongs to User); `User` hasMany userInboundAddresses.
   - `UnmatchedInboundEmail` (no user association).

3. **Webhook**
   - Route: `POST /webhooks/postmark/inbound/{token}`; middleware that checks `$token === config('services.postmark_inbound_webhook_secret')` or return 404.
   - Controller or dedicated class: parse JSON, normalise From, resolve user (users.email + user_inbound_addresses), idempotency by MessageID, then either create Thought (with attachment_names in source_metadata) or insert into unmatched_inbound_emails; return 200.

4. **Settings UI**
   - Routes: e.g. `GET /settings/inbound-emails`, `POST /settings/inbound-emails` (add), `DELETE /settings/inbound-emails/{id}` (remove).
   - Controller: list primary email + inbound addresses; add (validation, uniqueness); delete (only inbound rows).
   - View: same layout as MCP keys; list + add form + delete per inbound address; show capture address and short help text.

5. **Nav**
   - Add “Inbound email” (or “Email capture”) link in the same menu as “MCP key” so users can manage their addresses and see the capture address.

---

## 9. Out of scope for v1

- Verification of inbound addresses (e.g. confirmation email).
- Storing or processing attachment file content.
- Admin UI for unmatched emails (storage only; analysis can be script or later feature).
- Plus-addressing (e.g. `capture+token@...`) for user resolution; v1 uses From matching only.
- Reply-by-email (storing `reply_to` is for future use).
