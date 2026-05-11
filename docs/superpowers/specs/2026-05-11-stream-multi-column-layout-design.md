# Stream Multi-Column Layout Toggle

**Date:** 2026-05-11
**Status:** Draft
**Scope:** All stream views (main Stream, tag-filtered, collection-filtered)

## Problem

The stream page renders thought cards in a single narrow column (`max-w-[600px]`). On wide viewports this wastes horizontal space — users must scroll through a long vertical list to scan their thoughts.

## Solution

Add a **list/grid layout toggle** to all stream views. Grid mode uses CSS Grid `auto-fill` for a fluid multi-column layout that adapts to viewport width. The user's preference is stored in the session so it persists across page loads.

## Layout Modes

### List Mode (default, current behavior)

- Container: `max-w-[600px] mx-auto`
- Cards stack vertically, full width of container
- No changes from current implementation

### Grid Mode

- Outer container: `max-w-[1400px] mx-auto` (replaces `max-w-[600px]`)
- The `h1` heading, tag action links, and empty state remain centered — only the thought card list and count line expand to the wider container
- Card list: `display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 0.5rem`
- Cards flow left-to-right, row by row — preserves chronological reading order
- Column count is purely viewport-driven:
  - < ~640px: 1 column
  - ~640px–960px: 2 columns
  - ~960px–1280px: 3 columns
  - 1280px+: 4 columns (capped by 1400px max-width)

### Card Truncation in Grid Mode

- Card content receives `max-h-[200px] overflow-hidden` with a fade-out gradient overlay (`absolute bottom-0 h-8 bg-gradient-to-t from-white/80 to-transparent`)
- Prevents tall cards (meeting notes, research) from dominating columns
- Truncation is purely visual; click-through to detail page is unchanged
- In list mode: no truncation — current behavior preserved exactly

## Toggle Button

### Placement

- Inline with the "Showing X of Y thoughts" count line
- Count text left-aligned, toggle button right-aligned (flex container)
- Appears on all views using `stream.blade.php`: main Stream, tag-filtered, and all collection views (Meetings, Plans, Research, etc.)

### Design

- Icon-only button, two states:
  - **List icon** (three horizontal bars): shown when list mode is active or as the option to switch to list
  - **Grid icon** (2×2 squares): shown when grid mode is active or as the option to switch to grid
- Active mode icon: `text-memory-violet`
- Inactive mode icon: `text-slate-brand/40 hover:text-slate-brand`
- Both icons are always visible side by side so the user can see which mode is active and click the other

### Interaction

- Click toggles layout immediately via Alpine.js (no page reload)
- Background `fetch` POST persists the preference to the session
- Server-side renders the correct initial state so there is no flash of wrong layout on page load

## Session Persistence

### Route

- `POST /stream/layout`
- Auth middleware required
- Accepts JSON body: `{ "layout": "list" | "grid" }`
- Returns `204 No Content`

### Controller

- `StreamLayoutController` with a single `store` method
- Validates `layout` is `in:list,grid`
- Sets `session('stream_layout', $validated['layout'])`

### View Composer

- Register a view composer for `layouts.idea` (or use `View::share`)
- Shares `$streamLayout` = `session('stream_layout', 'list')` to all views using the idea layout
- Default is `'list'` — preserves current behavior for existing users

### No Database Storage

Session is sufficient. The preference does not need to survive logout or persist across devices. If that changes later, migrating to a `user_preferences` column or similar is straightforward.

## Alpine.js Client-Side

- An `x-data` block wraps the stream content area: `{ layout: '{{ $streamLayout }}' }`
- Container width bound via `:class`: `layout === 'grid' ? 'max-w-[1400px]' : 'max-w-[600px]'`
- Thought list grid classes bound via `:class`: `layout === 'grid' ? 'grid grid-cols-[repeat(auto-fill,minmax(300px,1fr))] gap-2' : ''`
- Toggle method flips `layout` and fires:

```js
fetch('/stream/layout', {
    method: 'POST',
    headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
        'X-Requested-With': 'XMLHttpRequest',
    },
    body: JSON.stringify({ layout: this.layout }),
})
```

## Infinite Scroll & Real-time Updates

### Infinite Scroll

- Existing `IntersectionObserver` sentinel and `loadMore` JS are unchanged
- New cards appended via `insertAdjacentHTML('beforeend')` flow naturally into the grid — CSS Grid handles new children automatically

### Real-time Refetch

- Existing polling/Reverb refetch replaces `list.innerHTML` — works identically in grid mode since the container's CSS handles layout regardless of content changes

## Empty State

The empty-state message stays single-column centered regardless of layout mode. Only the thought card list is affected by the grid toggle.

## Files Changed

| File | Change |
|------|--------|
| `app/Http/Controllers/StreamLayoutController.php` | New controller, single `store` method |
| `routes/web.php` | Add `POST /stream/layout` route |
| `app/Providers/AppServiceProvider.php` | View composer sharing `$streamLayout` |
| `resources/views/idea/stream.blade.php` | Alpine toggle state, dynamic container width, grid classes on thought list, toggle button in count line |
| `resources/views/idea/stream_thoughts.blade.php` | Conditional card truncation classes in grid mode |

## Not In Scope

- Database-persisted preferences
- Masonry (JS-driven tight packing)
- Per-view layout memory (e.g. grid on Stream but list on tag page)
- Card reordering or drag-and-drop
- New dependencies (no JS libraries added)
