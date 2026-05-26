# Stream: Videos tab and Jira via account menu

**Date:** 2026-05-26  
**Status:** Approved (brainstorming)  
**Scope:** IdeaTub Stream type navigation and account menu — add Videos as a first-class stream collection; move Jira discovery out of stream tabs into the account dropdown.

## Summary

- Add a **Videos** stream tab (`/stream/videos`) for top-level video thoughts. Videos remain visible on **All thoughts** (same pattern as Articles and Meetings).
- **Remove Jira** from the stream type segment control. Keep `/stream/jira` and existing Jira stream behaviour.
- Add **Jira** to the account dropdown (under **Inbox**, above **Shared documents**), linking to `/stream/jira` when Jira integration is enabled. Jira settings stay on Profile.

## Decisions (locked)

| Topic | Decision |
|-------|----------|
| Videos on All thoughts | **Included** — not excluded from main stream |
| Jira account menu target | **Activity feed** (`idea.stream.jira`), not settings |
| Jira account menu position | After **Inbox**, before **Shared documents** |
| Implementation approach | **Single `ThoughtTypeNavigation` registry** with `show_in_stream_nav` (or equivalent filter); do not split into separate hardcoded registries |

## Current behaviour

- `ThoughtTypeNavigation::orderedNavTypes()` drives `idea/partials/stream_type_nav`: All → Jira → Emails → Research → Plans → Meetings → Articles.
- Video thoughts (`source=video`, `metadata.type=video`) render with dedicated stream cards on **All thoughts** (`VideoStreamDisplayTest`) but have no typed collection route.
- Jira thoughts are **excluded** from All via `Thought::scopeExcludingJira()`; they appear only on `/stream/jira`.
- Jira settings: Profile page link to `settings.jira.index`. Account menu has no Jira entry today.

## Target behaviour

### Stream type navigation

| Tab | Route | Query / filter |
|-----|-------|----------------|
| All thoughts | `idea.stream` | Top-level, `visibleInStream()`, `excludingJira()` — **includes videos** |
| Emails | `idea.stream.emails` | `matchingCanonicalSourceType('email')` |
| Research | `idea.stream.research` | Existing research rules (includes video-child research) |
| Plans | `idea.stream.plans` | `matchingCanonicalMetadataType('plan')` |
| Meetings | `idea.stream.meetings` | `matchingCanonicalMetadataType('meeting')` |
| Articles | `idea.stream.articles` | `matchingCanonicalSourceType('article')` |
| **Videos** | **`idea.stream.videos`** | Top-level, `metadata.type = video` (same predicate as `StreamThoughtCardPresenter` / video detail) |
| ~~Jira~~ | *(not in nav)* | — |

**Tab order (stream nav only):** `email`, `research`, `plan`, `meeting`, `article`, `video` — Jira omitted.

### Videos collection page

- New controller method `IdeaController::streamVideos()` following `streamArticles()` / `streamMeetings()`.
- Route: `GET /stream/videos` → `idea.stream.videos` (auth middleware, same group as other stream routes).
- Pagination, AJAX infinite scroll, and card presenters: reuse `streamCollectionResponse()` with `streamCollectionKey = 'video'`.
- Empty state: short copy + link to capture video (existing web capture / home capture pattern used elsewhere for typed empty states).
- No change to video card markup or transcript/research actions on stream cards.

### Jira access

- **Account menu** (`resources/views/layouts/idea.blade.php`): insert link after Inbox:
  - Label: `Jira`
  - `href`: `route('idea.stream.jira')`
  - Visible only when `config('services.jira.enabled', true)` (same gate as current Jira tab via `ThoughtTypeNavigation::isAvailable('jira')`)
  - `data-testid="account-menu-jira-link"`
- **`/stream/jira` page:** unchanged query, ordering, empty state, settings CTA. When opened from account menu, stream type nav has **no active Jira tab** (collection key still `jira` for page title/subtitle).
- **Profile → Jira** settings link: unchanged.
- **Thought type badges** on Jira thoughts: continue linking to `idea.stream.jira` via `ThoughtTypeNavigation::routeName('jira')`.

### `ThoughtTypeNavigation` changes

Add to `TYPE_DEFINITIONS`:

```php
'video' => [
    'collection_label' => 'Videos',
    'thought_label' => 'Video',
    'route_name' => 'idea.stream.videos',
    'stored_values' => ['video'],
    'show_in_stream_nav' => true,
],
```

Update `jira` entry:

```php
'show_in_stream_nav' => false,
```

API additions (names illustrative; match repo style):

- `showInStreamNav(string $canonicalType): bool` — default `true` for types without key.
- `orderedStreamNavTypes(): array` — keys where `show_in_stream_nav` is true, preserving product order above.
- `stream_type_nav.blade.php` iterates `orderedStreamNavTypes()` instead of `orderedNavTypes()`.

`resolveThoughtToTypeKey(Thought $thought)`:

- Return `'video'` when `source` normalizes to `video` **or** `metadata.type` normalizes to `video` (align with existing `isVideoThought()` predicates).

`orderedNavTypes()` (if retained): either deprecated for stream nav only, or redefined as alias of `orderedStreamNavTypes()` plus account-only types — prefer **stream nav uses `orderedStreamNavTypes()`**; tests that assert full product type keys include `jira` and `video` in a dedicated test for the full registry.

### Out of scope

- Jira ticket count badge on account menu.
- Excluding videos from homepage recent feed.
- Changing video-linked research listing on Research tab.
- Renaming `/stream/jira` URL or merging Jira into settings UI.

## Edge cases

| Case | Behaviour |
|------|-----------|
| Jira disabled in config | No account menu Jira link (same `isAvailable('jira')` gate as today’s stream tab). `/stream/jira` remains registered (unchanged); settings routes stay behind `services.jira.enabled` |
| Video with only child transcript/research | Root appears on Videos tab; children hidden from stream comment preview (existing video stream rules) |
| User bookmarks `/stream/jira` | Still works |
| Demo mode on video stream | Existing demo obfuscation on video cards applies on Videos tab |

## Testing

| Area | Tests |
|------|--------|
| Navigation registry | `ThoughtTypeNavigationTest`: `video` labels/routes; `orderedStreamNavTypes()` order; Jira `show_in_stream_nav` false; `resolveThoughtToTypeKey` for video |
| Stream nav HTML | `StreamPageTest`: segment includes Videos, excludes Jira; Videos tab active on `/stream/videos` |
| Videos collection | Feature test: only video roots on `/stream/videos`; non-video excluded; reuse assertions from `VideoStreamDisplayTest` where appropriate |
| Account menu | Feature test: authenticated user sees Jira link under Inbox when enabled; hidden when disabled; href is `idea.stream.jira` |
| Jira stream page | `ThoughtTypePagesTest` / `StreamPageTest`: Jira page still renders tickets; stream nav does not highlight Jira tab |
| Thought badges | Existing Jira badge link tests still pass |

## Files (expected touch set)

- `app/Support/ThoughtTypeNavigation.php`
- `app/Http/Controllers/IdeaController.php` — `streamVideos()`
- `routes/web.php`
- `resources/views/idea/partials/stream_type_nav.blade.php`
- `resources/views/layouts/idea.blade.php`
- `tests/Unit/Support/ThoughtTypeNavigationTest.php`
- `tests/Feature/StreamPageTest.php`
- `tests/Feature/ThoughtTypePagesTest.php`
- New or extended: `tests/Feature/StreamVideosPageTest.php` (optional split)

## Implementation note

Follow existing typed stream patterns (`streamArticles`, `streamCollectionResponse`) and existing video identity checks (`metadata.type === 'video'`, `source === 'video'`) — do not introduce a second video detection rule.
