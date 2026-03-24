# IdeaTub - Email research preview design

**Date:** 2026-03-24
**Status:** Ready for review
**Scope:** Show a compact, research-page-style preview of linked research directly on the email thought detail page.

## 1. Summary

- Add a `Research preview` card to the email thought detail page when linked research exists and is viewable.
- Place the preview in the main content column, directly below the email body, not in the sidebar.
- Render the preview using a shared Blade partial so the email page and research page stay visually aligned.
- Show the research intro plus up to the first two sections, then link to the full research page.
- Implement this in the existing Blade-rendered email and research detail views.

## 2. Goals and non-goals

### 2.1 Goals

- Make linked research easier to discover while reading an email.
- Reuse the typography and visual treatment from the research detail page so the preview feels familiar.
- Keep the sidebar focused on metadata, actions, and sender-rule controls.
- Limit preview length so the email page remains scannable.
- Avoid duplicating the research rendering markup in multiple templates.
- Match preview visibility to the same access rules used by the full research detail route.

### 2.2 Non-goals

- Replacing the full research detail page.
- Showing the entire research body inline on the email page.
- Introducing a new asynchronous loading flow for research preview content.
- Rendering a placeholder or error state when linked research cannot be resolved.

## 3. Product behavior

### 3.1 Email detail page

On the email thought detail page:

- Keep the existing two-column layout.
- Add a new `Research preview` card in the main column, beneath the `Email body` card.
- Render the card only when the linked research thought resolves successfully and is viewable by the current user.
- Show:
  - a section label/header
  - the root research body
  - up to the first two rendered child sections, in order
  - a `View full research` link
- Remove the existing sidebar `View research` link on the email detail page to avoid duplicate entry points.

### 3.2 Research detail page

On the full research page:

- Keep the current page structure and related-email card.
- Reuse the same extracted partial for the shared research-content markup where practical.
- Continue showing all sections on the full page.

### 3.3 Why main-column placement

The research preview is content, not metadata. Placing it below the email body keeps the reading flow natural and gives the preview enough horizontal space to feel consistent with the dedicated research page.

## 4. Rendering contract

### 4.1 Data required for preview rendering

The email detail view needs:

- the linked research URL
- rendered HTML for the root research content
- up to the first two rendered research sections

The rendering path should only provide preview data after the linked thought has been resolved and authorized successfully.

### 4.2 Section limit

Preview limits should be deterministic:

- always show the root research content
- show at most the first two child sections
- preserve section order from the research page

If the research has fewer than two sections, render only what exists.

### 4.3 Authorization and visibility

Only render the preview when the linked research thought can be shown under the exact same authorization and visibility rules as the full research detail page.

If any of these checks fail, omit the preview entirely.

## 5. Template structure

### 5.1 Shared partial

Extract the research-body rendering into a shared Blade partial, for example:

- `resources/views/idea/partials/research_content.blade.php`

The partial should accept enough data to support both contexts:

- root HTML content
- section collection
- mode or flag for preview vs full detail
- optional related link/footer content supplied by the parent view

The shared partial should own the prose styling so the email preview and research page stay in sync.

Preview mode should:

- render the same sanitized/rendered HTML pipeline as the full research page
- show the root content plus up to the first two sections
- include a footer link to the full research page
- omit any full-page-only wrappers or surrounding layout chrome

Full-detail mode should:

- render the full root content and all sections
- remain compatible with the related-email card and existing research page shell

### 5.2 Email page composition

`resources/views/idea/show.blade.php` should:

- keep the email body card as-is
- render the new preview card immediately after the email body when preview data exists
- continue rendering the right-hand email sidebar beside the main column

### 5.3 Research page composition

`resources/views/idea/research_show.blade.php` should:

- keep the outer page shell and related-email card
- delegate the repeated research-content markup to the shared partial

## 6. Edge cases

### 6.1 Minimal research content

If linked research has only root content and no child sections, render the root content plus the full-page link.

### 6.2 Missing or stale links

If the linked research thought cannot be resolved or is not viewable, the email page should show no preview and no sidebar research link.

### 6.3 Large first sections

The preview should still show up to the first two full sections even if they are somewhat long. The scope limit is section-count based, not character-count based, to keep implementation simple and predictable.

### 6.4 Empty content

If the root content is empty but previewable child sections exist, still render the preview card with those sections and the full-page link. If both root content and previewable sections are empty, omit the preview card.

## 7. Testing strategy

### 7.1 Feature coverage

Add or update feature tests for:

- email detail shows the research preview card when linked research exists and is viewable
- preview includes root content and no more than the first two sections
- preview omits later sections that appear on the full research page
- sidebar no longer shows the old `View research` link on the email detail page
- email detail omits the preview entirely when linked research is missing or inaccessible
- email detail omits the preview when the linked research exists but the current user cannot access it
- preview behaves correctly for zero, one, and three-plus child sections
- full research page still renders all sections after the shared partial extraction

### 7.2 Rendering consistency

Protect these behaviors:

- preview and full research page use the same prose styling source
- preview and full research page use the same sanitized HTML rendering path
- preview section ordering matches the full research page
- preview link target points to the existing research detail route

## 8. Recommended implementation direction

The recommended implementation is:

- resolve the linked research thought in the existing email detail flow
- pass a compact preview view model to the email page
- extract shared research rendering into a partial
- render the preview card in the main content column
- keep preview scope to root content plus the first two sections

This gives the email page a stronger research experience without overloading the sidebar or duplicating the full research page inline.
