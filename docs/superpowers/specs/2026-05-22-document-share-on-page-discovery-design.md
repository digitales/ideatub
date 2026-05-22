# IdeaTub — On-page document share discoverability

**Date:** 2026-05-22  
**Status:** Approved for planning  
**Scope:** Improve discoverability and in-context creation of **public readonly share links** for eligible long-form capture documents (plans, specs, meetings, research, etc.). Reuse existing `ResearchShare`, `/r/{token}`, and `Thought::isShareableDocumentRoot()` — no eligibility expansion, no new tables.

**Related:**

- `docs/superpowers/specs/2026-04-02-shared-longform-documents-design.md` — eligibility and Shared documents index
- `docs/superpowers/specs/2026-03-17-shareable-research-design.md` — original readonly share mechanics

## Problem

Sharing works today but is easy to miss:

- Stream card **Share** lives only inside the **⋮** overflow menu.
- **Create share link** on thought detail redirects to **Shared documents** (`/shared-research?create={id}`) instead of staying in context.
- No **Shared** indicator on cards when a link already exists.

Users want **public readonly links** (unchanged model) with **on-document** affordances: visible controls, inline create, immediate copy.

## Goals

1. Eligible document owners see **Share** without opening ⋮.
2. Owners create a link **from the document** (modal with optional password/expiry) and **copy the URL** without visiting the management index first.
3. Shared documents show a clear **Shared** state on stream/type cards and detail.
4. **Shared documents** index remains the hub for revoke, password, and expiry changes.

## Non-goals (v1)

- Broadening share eligibility (ideas, quick captures, video, email, jira, section children).
- In-app sharing between IdeaTub users (authenticated collaborators).
- Inline revoke or password change on card/detail (stay on index).
- Renaming `ResearchShare`, `research_shares`, or `/r/{token}` routes.
- JSON/API-only create flow (redirect-back + flash is sufficient).
- Fixing public readonly section ordering vs thought detail (`section_index` follow-up from 2026-04-02 spec).

## Approach

**Unified on-document share widget** (recommended in brainstorm): one Blade partial + small Alpine module on **thought detail** and **stream/type cards**, backed by existing `POST shared-research.store` with **validated redirect-back** instead of always landing on the index.

Alternatives rejected:

- Visible controls only without inline create (does not meet “stay on document”).
- New JSON API layer (unnecessary scope for discoverability).

## Eligibility (unchanged)

Reuse `Thought::isShareableDocumentRoot()` everywhere. No change to allowed `metadata.type` set or stream visibility rules. See `docs/superpowers/specs/2026-04-02-shared-longform-documents-design.md` §1.

## UI

### Stream and typed collection cards

Applies to main Stream, Plans, Meetings, Research, and any view using `stream_thoughts` / `thought_card_actions` with `documentShareEligible` and `share` loaded.

| State | UI |
|-------|-----|
| Eligible, not shared | Visible **Share** control (button or link) opens create modal — **not** only inside ⋮ |
| Eligible, shared | **Shared** pill + **Copy link** (+ optional **Open** in new tab); ⋮ may still offer **Manage** → Shared documents index |
| Ineligible | No share UI |

**Badge:** Show **Shared** when `ResearchShare` exists for the card’s `thought_id` (`StreamThoughtCardPresenter::share()` non-null). Add accessor `hasDocumentShare(): bool` if useful for Blade.

### Thought detail

Replace “Create share link” link to index with the **same widget** as stream cards:

- Not shared: **Share** → modal.
- Shared: **Shared** label + **Copy link** + **Open** + **Manage** (index with `?share={id}`).

Keep the Share block in `thought_detail_actions_row` for eligible roots (existing `showDocumentShareBlock()` gate).

### Create modal (shared partial)

`resources/views/idea/partials/document_share_widget.blade.php` (name in plan may vary):

- Fields: optional password, optional expiry date (same validation as index form).
- Hidden: `thought_id`, `return_to` (current page URL).
- Actions: **Create link**, **Cancel**.
- On success (after redirect-back): close modal context, show public URL + **Copy link** (clipboard + brief “Copied!” feedback).

Demo mode: disable/hide widget when demo blocks owner edits (consistent with other thought actions).

### Shared documents index

Unchanged functionally. Optional one-line help: sharing can also be started from a document’s detail page or Stream card.

## Backend

### `SharedResearchController::store`

Keep validation, authorization, eligibility checks, and one-share-per-thought rule.

**Redirect target:**

| Outcome | Redirect |
|---------|----------|
| Success | Validated `return_to` URL with flash `success` and session payload for created share (e.g. public URL or `share_id` for widget) |
| Validation / eligibility error | `return_to` with errors |
| Already shared | `return_to` with `error` flash; UI shows shared state + Manage link |

**`return_to` validation:**

- Required when submitted from on-page modal; optional fallback `redirect()->back()` or safe default.
- Must be same-application URL: same host, path allowlist including at minimum:
  - `thoughts.show`
  - `idea.stream`
  - `idea.stream.plans`
  - `idea.stream.meetings`
  - `idea.stream.research`
  - `idea.stream.articles`
  - `idea.stream.emails` / `idea.stream.jira` only if those pages ever show eligible cards (typically none)
  - `idea.index` (ideas list) if cards use the widget
- Reject external URLs and open redirects.

Index form (`/shared-research` “Share another” dropdown) may omit `return_to` or set it to index — success continues to redirect to index with `?share=` focus (current behavior preserved).

### Presenters and queries

- Stream: `shareByThoughtId` already loaded in `IdeaController` — no new query if partial receives `$share`.
- Detail: existing `documentShare` / `documentShareEligible` on `ThoughtDetailPresenter`.
- Ideas list: verify `ResearchShare` is eager-loaded or passed for eligible cards; align with stream if gaps exist.

### Files (implementation plan will detail)

| Area | Expected touchpoints |
|------|----------------------|
| Controller | `SharedResearchController::store`, optional small `ReturnToValidator` or private method |
| Views | `document_share_widget` partial; `thought_card_actions`, `thought_detail_document_share_links`, `stream_thoughts` |
| Presenter | `StreamThoughtCardPresenter::hasDocumentShare()` (optional) |
| Help | `resources/views/help.blade.php` one line |
| Tests | Feature tests for store redirect + UI assertions |

## Security

Unchanged: owner-only create; token-based public read; optional password cookie on `/r/{token}`.

Additional: strict `return_to` validation on store.

## Testing

### Automated (Pest feature tests)

- Store with valid `return_to` from thought detail → redirect to detail, share created, flash present.
- Store with valid `return_to` from plans stream URL → redirect to that stream.
- Invalid/external `return_to` → safe fallback, no open redirect.
- Duplicate create → error flash, single share row.
- Ineligible thought → error (unchanged).
- Detail page: eligible root shows visible Share; ineligible does not.
- Stream/plan card: Share visible outside ⋮; shared card shows Shared badge (HTML or presenter assertion).

### Manual smoke

- Create from Plans stream → modal → copy → open readonly `/r/{token}`.
- Create from thought detail → same.
- Manage/revoke/password on Shared documents index still works.

## Rollout

No migration, feature flag, or config. Ship as a single UX pass.

## Open follow-ups (not v1)

- Public readonly section ordering parity with detail page.
- Optional `wantsJson()` on store for no full-page reload after create.
- Promote Shared documents in main nav (user chose on-document focus only).
