# IdeaTub - Focused navigation design

**Date:** 2026-03-21  
**Status:** Approved design direction  
**Scope:** Simplify the top navigation so the app feels more focused, while preserving access to type-based views and inbox/status destinations through clearer grouping.

## Overview

- **Goal:** Reduce clutter in the top bar and make the app's primary navigation easier to scan.
- **Primary direction:** Keep only the most important product spaces in the top navigation, move secondary/status destinations into contextual menus, and group type-based destinations under one shared menu.
- **Secondary goal:** Make thought types feel navigable, not just decorative, by turning routable type labels on thoughts into links.

## 1. Navigation architecture

### 1.1 Primary top navigation

The focused top navigation should contain exactly:

- `Ideas`
- `Stream`
- `Types`
- `Help`
- `Keyboard shortcuts`

For clarity, "primary top navigation" here means the set of labeled product destinations in the main nav cluster. Supporting chrome such as the logo/wordmark, search trigger, and avatar are not counted as part of this five-item set.

Why this set:

- `Ideas` and `Stream` are core product spaces and should remain instantly visible.
- `Types` provides one compact entry point for type-oriented browsing without bloating the top level.
- `Help` remains a stable support destination.
- `Keyboard shortcuts` stays top-level because the user explicitly wants it to remain immediately discoverable.

### 1.2 Secondary destinations

The following items should leave the primary top bar:

- `Inbox`
- `Ideas to revisit`
- `Jira`

They do not disappear; they move into more appropriate structures:

- `Inbox` moves into the account menu.
- `Ideas to revisit` becomes a secondary destination under `Ideas`.
- `Jira` moves into the `Types` menu.

These items are grouped here only because they leave the top bar. Their destination patterns are intentionally different:

- `Inbox` is an account/status destination
- `Ideas to revisit` is an ideas sub-view
- `Jira` is a thought-type collection

## 2. `Ideas` section structure

### 2.1 `Ideas to revisit`

`Ideas to revisit` should no longer appear as a peer to the entire app in the global nav.

Instead, it should become a secondary destination inside the `Ideas` area, such as:

- a sub-tab
- a segmented control
- a contextual secondary nav

The exact component can be chosen during implementation, but the architectural rule is fixed:

- `Ideas` is the parent section
- `Ideas to revisit` is a related mode within that section

The implementation must preserve a stable, bookmarkable route for this mode. It should remain possible to link directly to the `Ideas to revisit` view even if the UI control becomes a secondary tab or segmented switch.

This better reflects the product model already captured in earlier specs, where `Ideas to revisit` is a specialized ideas view rather than a separate app pillar.

## 3. Account menu and inbox signaling

### 3.1 Inbox placement

`Inbox` should move into the account/avatar menu instead of occupying permanent top-level space.

This keeps inbox access available while removing a status-oriented destination from the primary navigation.

### 3.2 Inbox notification indicator

When the user has actionable inbox items, the account avatar should display a visible notification indicator.

For this spec, "actionable" means the same condition that today drives the inbox actionable count. This work should reuse that existing backend/view-model signal rather than inventing a second definition of inbox urgency.

The v1 treatment should be:

- a small count badge when the actionable count is greater than zero
- no indicator at all when the actionable count is zero

Behavior rules:

- show the indicator only when there is something actionable
- remove the indicator when the inbox is clear
- do not show the indicator permanently as a static decoration
- if the count exceeds two digits, clamp the visual label to a compact overflow format such as `99+`
- provide accessible text so assistive tech can announce that inbox items need attention

The menu itself should continue to function the same way whether or not the indicator is present.

## 4. `Types` menu

### 4.1 Purpose

`Types` is a single top-level menu for browsing thought-type collections.

It exists to separate:

- primary product spaces (`Ideas`, `Stream`)
- support/navigation utilities (`Help`, `Keyboard shortcuts`)
- type-oriented collections (`Jira`, `Emails`, `Research`, `Plans`)

### 4.2 Initial contents

The initial `Types` menu should contain:

- `Jira`
- `Emails`
- `Research`
- `Plans`

This order should be preserved in v1 so the menu feels intentional rather than implementation-defined.

This menu should be designed to grow later without requiring more primary-nav items.

### 4.3 Availability rules

If a type view is disabled, unconfigured, or otherwise unavailable, it should be hidden from the `Types` menu rather than rendered as a broken or dead destination.

## 5. Clickable thought type labels

### 5.1 Product behavior

Thought type labels should become clickable when a dedicated type destination exists.

Examples:

- a thought labeled `Jira` links to the `Jira` type page
- a thought labeled `Email` links to the `Emails` type page
- a thought labeled `Research` links to the `Research` type page
- a thought labeled `Plan` links to the `Plans` type page

This creates a consistent navigation loop:

- users can browse into a type from the `Types` menu
- users can also jump into that same type view directly from an individual thought

The implementation should use one canonical mapping for both the `Types` menu and clickable thought labels:

- `jira` -> `Jira`
- `email` -> `Emails`
- `research` -> `Research`
- `plan` -> `Plans`

Thought cards may continue to render singular metadata labels such as `Email` and `Plan`, but those labels should resolve through the canonical mapping above to the plural collection destinations (`Emails`, `Plans`). If the current app stores or renders aliases such as `emails` or `plans`, the implementation may normalize them to the same destination, but there should still be one shared mapping table or helper so menu generation and thought-label linking cannot drift apart.

### 5.2 Non-routable types

If a type does not yet have a dedicated destination, or if its destination is unavailable because of feature/config gating, its label should remain non-clickable in v1.

Do not link users to placeholder pages or dead ends. Specifically:

- no empty or placeholder `href`
- no `#` links
- no control that looks like a navigable link but intentionally goes nowhere

### 5.3 Interaction design

Clickable type labels should still read as lightweight metadata, but they must have clear interactive affordances:

- hover state
- focus state
- accessible link semantics

The implementation should avoid making them look so heavy that they compete visually with the thought content itself.

## 6. Recommended implementation shape

### 6.1 Top-bar layout

Update the shared authenticated layout so the main top bar presents:

- `Ideas`
- `Stream`
- `Types` dropdown trigger
- `Help`
- `Keyboard shortcuts`

Search and the avatar remain on the right side as supporting controls, not part of the main navigation label cluster.

On smaller viewports, the implementation must preserve this focused information architecture without allowing the nav to become a wrapped cluttered row again. The exact responsive pattern may be chosen during implementation, but it must:

- keep `Ideas` and `Stream` easy to reach
- preserve access to `Types`, `Help`, and `Keyboard shortcuts`
- avoid treating a wrapped two-line desktop nav as the primary mobile/tablet solution

### 6.2 Existing menu reuse

Prefer reusing the existing avatar dropdown pattern for account-menu items and notification placement rather than inventing a second menu system.

Prefer reusing existing route names and enabled/disabled config checks where type destinations already depend on feature flags, such as `Jira`.

### 6.3 Routing alignment

The implementation plan should confirm or add stable routes for the `Types` entries:

- `Jira`
- `Emails`
- `Research`
- `Plans`

If any of these currently exist only as filtered views rather than dedicated named destinations, implementation can map the clickable labels and menu entries to those filtered views as long as the user experience is coherent and durable.

## 7. Edge cases

### 7.1 Empty type pages

A valid type page should still render a clear empty state when there are no matching thoughts.

Clicking a type label should never land on an ambiguous blank page.

### 7.2 Mixed or missing metadata

If a thought has missing or malformed type metadata, the UI should fall back gracefully:

- no broken label link
- no incorrect route generation
- no exception in list rendering

### 7.3 Feature-gated destinations

Type entries that depend on feature flags or configuration should follow the same visibility rules in both places:

- the `Types` menu
- clickable type labels on thoughts

If a destination is unavailable, the app should not render one path as clickable while hiding the other.

## 8. Testing

### 8.1 Navigation coverage

Add or update coverage for:

- top nav renders only the focused primary items
- `Inbox` no longer appears in the top bar
- `Jira` no longer appears as a top-level primary-nav item
- `Ideas to revisit` no longer appears as a top-level peer in the primary nav
- `Inbox` appears in the account menu
- avatar indicator appears only when actionable inbox items exist
- `Ideas to revisit` is reachable from the `Ideas` section
- `Ideas to revisit` retains a stable direct route / URL
- `Types` menu shows the expected destinations
- `Types` menu preserves the intended item order
- disabled or unavailable type destinations are hidden
- when a destination is unavailable, it is hidden consistently across both the `Types` menu and thought-label links

### 8.2 Type label coverage

Add or update coverage for:

- routable type labels render as links
- clicking a type label routes to the correct type page
- non-routable types remain non-clickable
- malformed or missing type metadata does not generate broken links

## 9. Out of scope

- Redesigning the visual style of the entire app header
- Replacing `Keyboard shortcuts` with command-only access
- Inventing a new inbox workflow beyond moving its entry point and indicator
- Reworking the meaning of existing thought types
- Defining or implementing brand-new thought categories as part of this navigation change (the `Types` menu may grow later, but creating new categories is not part of this scope)

## 10. Summary

This design simplifies the global navigation by making the top bar about core movement through the app rather than a flat list of unrelated destinations.

The key product rules are:

- keep the primary nav small and focused
- keep `Ideas`, `Stream`, `Types`, `Help`, and `Keyboard shortcuts` as the five labeled primary-nav destinations
- move inbox access into the account menu, with an avatar-level notification signal
- show that avatar-level signal only when inbox items are actionable
- nest `Ideas to revisit` under `Ideas`
- group type-based destinations under one `Types` menu
- make thought type labels clickable when those type destinations exist
- keep non-routable or unavailable types from becoming dead links

The result should feel calmer, easier to scan, and more structurally coherent without removing important paths through the product.
