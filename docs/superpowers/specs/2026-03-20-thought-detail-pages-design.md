# IdeaTub - Thought detail pages design

**Date:** 2026-03-20  
**Status:** Draft  
**Scope:** Add clickable thought cards that open a dedicated detail page for all thoughts, with richer email-specific rendering for `source = email`.

## Overview

- **Goal:** Let a user click any thought card and open a dedicated detail page instead of relying on the compact card summary alone.
- **Primary user need:** Email-backed thoughts currently surface too little information in cards. The new detail page should provide a larger readable body and structured metadata for email thoughts.
- **Product direction:** This should apply to all thoughts, not just email thoughts, so IdeaTub gains a consistent "open this thought" interaction model.
- **Rendering model:** Use one shared thought detail route/view and adapt the page based on the thought source. Email thoughts get a richer presentation, while non-email thoughts use a simpler full-content page.

---

## 1. Goals and non-goals

### 1.1 Goals

- Every thought card can be clicked to open a dedicated thought detail page.
- Email thoughts show:
  - the cleaned email body prominently
  - a metadata sidebar with subject, participants, direction, timestamps, and provider context
- Non-email thoughts show:
  - the full thought content
  - existing metadata
  - existing replies/comments
- The detail page continues to support replies/comments for all thoughts, including email thoughts.
- Ownership rules stay consistent with the rest of the app: only the thought owner can view the detail page.

### 1.2 Non-goals

- A dedicated email inbox UI
- Thread-level email navigation in v1
- Rendering raw MIME, original HTML email, or attachments
- Reworking the overall stream/search layout beyond making cards clickable
- Turning the detail page into a full editing experience for every thought

---

## 2. Recommended approach

### 2.1 Shared thought detail page

The recommended approach is a single authenticated thought detail route and page shell for all thoughts.

Why:

- It creates one consistent interaction model for the app: click a card, open a thought.
- It avoids maintaining separate generic-thought and email-thought page architectures.
- It allows source-specific rendering without forking the whole navigation and controller structure.
- It gives email thoughts the richer layout they need while keeping non-email thoughts simple.

### 2.2 Rejected alternatives

#### Separate email and generic detail pages

This would allow fast email-specific tailoring, but it would duplicate routing, authorization, and layout concerns early.

#### Generic detail page first, email enhancements later

This would be simpler to implement, but it would delay the main user value of this work: a much richer readable view for email thoughts.

---

## 3. User experience

### 3.1 Card interaction

On any app surface that renders a thought card in v1, including the idea index and stream:

- the visible thought card becomes clickable
- clicking opens the thought detail route
- the card itself remains compact and summary-oriented

This keeps list pages scannable while moving the "read the whole thing" experience to the detail page.

If a surface uses a materially different card treatment that is not part of the current shared thought-card pattern, it should either:

- adopt the same detail-link behavior in this slice, or
- be explicitly excluded during implementation and called out in follow-up work

### 3.2 Generic thought detail experience

For non-email thoughts, the detail page should prioritize:

- full thought content as currently stored/rendered by IdeaTub
- created date and source
- metadata/tags when present
- existing replies/comments below the main thought
- the ability to add a reply/comment from the detail page

This should feel like a straightforward, readable expansion of the current card model rather than a completely different product surface.

### 3.3 Email thought detail experience

For email thoughts, the detail page should use the same shell but add an email-specific presentation:

- **main content area:** cleaned email body, readable and spacious
- **metadata sidebar:** subject, direction, from, to, cc, sent/received timestamps, provider, mailbox/thread/account context
- **replies/comments area below:** same IdeaTub reply model as any other thought

This page should feel like an expanded email record inside IdeaTub, not just a generic thought with a long block of text.

For this slice, "cleaned email body" means the plain-text body already prepared by the import pipeline and stored on the synced record, not raw MIME, not original HTML rendering, and not attachment content.

---

## 4. Routing and controller design

### 4.1 Route

Add a new authenticated show route for thoughts, for example:

- `GET /thoughts/{thought}`

The exact naming should follow current route conventions in the app, but the route should clearly represent a single thought detail page.

### 4.2 Authorization

Only the owner of the thought should be able to access the page.

Expected behavior:

- owner gets `200`
- another authenticated user gets `403`
- missing thought gets `404`
- guest follows existing auth redirect/unauthorized behavior for the route group

### 4.3 Controller responsibilities

Add a dedicated show action that:

- loads the requested thought
- enforces ownership
- loads existing comments/replies
- for email thoughts, resolves the linked imported email record if available
- passes a normalized page view model into the Blade template

The controller should keep source-specific formatting light and push display branching into view helpers/partials where practical.

---

## 5. Data loading model

### 5.1 Base record

`Thought` remains the primary record for the page in all cases.

The page always needs:

- the thought itself
- its owner-scoped comments/replies
- metadata/source/source metadata

### 5.2 Email enrichment

For `source = email`, the detail page should enrich the base thought with the linked `ImportedEmail`.

Preferred lookup order:

1. `source_metadata.imported_email_id` if present
2. fallback lookup by `thought_id`

Why this order:

- `imported_email_id` is the most explicit durable link from the capture pipeline
- `thought_id` supports older or edge-case rows if metadata is incomplete

### 5.3 Fallback behavior

If the `ImportedEmail` row cannot be found, the page should still render.

Fallback rules:

- use the `Thought` content as the main body
- use `source_metadata` for whatever email context is still available
- omit missing sidebar fields instead of failing the page

This ensures previously captured email thoughts remain viewable even if the import row is unavailable.

---

## 6. Rendering model

### 6.1 Shared page shell

The page should use one shared layout with:

- top-level thought header
- main content region
- optional metadata sidebar
- replies/comments section

### 6.2 Source-specific sections

#### Non-email thoughts

Render:

- full thought content
- metadata chips/list if available
- replies/comments

#### Email thoughts

Render:

- full email body in the main content region
- metadata sidebar with:
  - subject
  - source/provider
  - direction
  - from/to/cc
  - sent/received timestamps
  - mailbox/thread/account identifiers where available

This should be implemented as source-specific partials inside the shared shell, not as an entirely separate page template.

On narrower screens, the sidebar should collapse beneath the main body rather than forcing a cramped side-by-side layout.

### 6.3 Clickable cards

List/stream card partials should wrap the summary presentation in a link to the show route.

Important UX constraint:

- the whole card should feel clickable without breaking existing inline controls or reply affordances where they still appear in list contexts

If current card markup has nested interactive elements, the card should be restructured so navigation remains accessible and valid HTML is preserved.

The implementation should apply this to the shared thought-card surfaces used by the app now, not only to one page.

---

## 7. Replies and comment flow

The detail page should continue using the existing IdeaTub reply model.

Requirements:

- existing replies/comments render beneath the thought
- the user can add a reply from the detail page
- email thoughts are not read-only in v1; they still participate in IdeaTub's comment/reply flow

This preserves the current product model where imported email thoughts are still ordinary IdeaTub thoughts after capture.

---

## 8. Testing

### 8.1 Feature tests

Add feature coverage for:

- owner can open the thought detail page
- another user cannot open the page
- generic thought detail page shows full content
- email thought detail page shows:
  - full body
  - metadata sidebar content
- replies/comments are rendered on the detail page
- reply submission from the detail page still works
- list/stream thought cards link to the detail route

### 8.2 Regression focus

Key regressions to guard against:

- email thoughts rendering as plain generic thoughts with missing metadata
- missing `ImportedEmail` row causing the page to fail
- cards becoming clickable in a way that breaks nested buttons/controls
- reply flow working on index pages but not on detail pages

---

## 9. Implementation notes

### 9.1 Keep list pages summary-first

Do not expand cards dramatically in the index/stream just because a detail page now exists. The list views should remain optimized for scanning.

### 9.2 Prefer durable email fields over loose metadata

When both are available, the page should prefer `ImportedEmail` fields over `Thought.source_metadata` because the imported row is the canonical sync record.

### 9.3 Keep the page resilient

The detail page should not assume every source has the same metadata structure. The page must gracefully handle:

- missing imported email row
- missing participant fields
- missing timestamps
- thoughts without tags or metadata

---

## 10. Summary

This feature adds a consistent detail-page navigation model to IdeaTub while solving the immediate email UX problem: cards are too compact to inspect imported mail properly.

The design keeps the app coherent by:

- using one thought detail route for every thought
- enriching that page for email thoughts instead of inventing a separate email product surface
- preserving replies/comments as normal IdeaTub behavior
- keeping list pages compact and readable

The result is a cleaner "browse summaries -> open full thought" flow that scales beyond email while still delivering the richer email reading experience requested here.
