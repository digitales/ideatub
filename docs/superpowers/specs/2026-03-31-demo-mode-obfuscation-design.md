# Demo Mode Obfuscation Design

**Date:** 2026-03-31
**Status:** Ready for review
**Scope:** Add a per-session demo mode that preserves real application structure and live writes while obfuscating sensitive text at HTML render time and showing a visible banner when the safe render path is active.

## Overview

The app contains highly sensitive user-authored content such as thought bodies, email subjects, summaries, and imported email text. That makes live demos risky, especially when the presenter wants to show real workflows, current data shape, and content created during the demo without exposing the original text.

The app should introduce a demo mode that can be enabled for a single browser session. When enabled, the app must continue using the real records, relationships, dates, statuses, and routing structure, but sensitive narrative text should be replaced at render time with deterministic fake output. No stored data should be mutated.

## Goals

- Allow safe live demos against real application data and structure
- Preserve real record relationships, navigation, dates, statuses, and page flow
- Obfuscate sensitive narrative text only at HTML render time
- Keep content created during demo mode visible in the UI without exposing its raw text
- Make demo mode reversible per browser session
- Show a visible banner so the presenter knows the app is currently in safe render mode

## Non-Goals

- Rewriting or mutating stored data in the database
- Obfuscating JSON or API responses in the first version
- Building a separate seeded demo dataset or dedicated demo database
- Hiding every possible identifier in the system regardless of display risk
- Introducing response-rewriting middleware that edits rendered HTML after the fact

## Current State

The current application renders real model content directly across multiple Blade surfaces. In the existing workspace, `Thought` stores freeform `content`, and `ImportedEmail` stores fields such as `subject`, `body_text`, and `summary`. Those values are currently treated as ordinary display data.

There is no existing main-workspace demo mode, seeded demo data pathway, or central obfuscation layer. That means a live demo against production-like data currently depends on either avoiding sensitive screens or accepting exposure risk.

Recent presenter work in the codebase is relevant because it establishes a pattern for moving display-oriented logic out of Blade into read-only presenter classes. That pattern is a good fit for demo-safe rendering because it provides a central boundary between raw models and visible output.

## Approaches Considered

### 1. Helper-based view formatting

Add a shared helper or formatter and call it from Blade anywhere sensitive text is rendered.

This is relatively small and can work for HTML-only obfuscation, but it depends on every sensitive template path remembering to use the helper. It is easy to miss one field or introduce a regression when new templates are added.

### 2. Presenter-driven obfuscation

Use presenters as the render boundary. In normal mode, presenters expose the real display text. In demo mode, presenters pass sensitive fields through a shared obfuscation service before returning display strings.

This adds some upfront structure, but it makes the safety boundary much clearer and better matches the direction the codebase is already moving toward.

### 3. Response rewriting

Post-process rendered HTML and replace sensitive content in the final response body.

This offers broad theoretical coverage, but it is brittle, hard to scope safely, difficult to keep deterministic, and risky around markup and layout behavior.

## Recommendation

Use presenter-driven obfuscation with a shared obfuscation service and a lightweight session-based mode service.

This approach best preserves real application behavior while making the safe render path explicit and testable. It also aligns with the project’s emerging presenter pattern and avoids mutating stored data or relying on fragile HTML rewriting.

## Activation And Request Flow

Demo mode should be a per-session flag, not an environment-wide setting and not a user-profile setting.

The toggle must not be universally available. In the first version, demo mode should only be enableable by an authenticated user who is already authorized to present or administer the app. If the app does not yet have a dedicated staff/admin permission boundary, the toggle should be additionally environment-gated so it is available only on trusted non-public deployments until that authorization rule exists.

Expected flow:

1. the presenter enables demo mode through a lightweight toggle route or controller action
2. the app stores a session flag such as `demo_mode = true`
3. subsequent requests read that session flag
4. layouts show a persistent demo banner when the flag is active
5. presenters switch from real display strings to obfuscated display strings for sensitive fields only
6. disabling demo mode clears the session flag and immediately returns the session to normal rendering

This keeps demo mode simple, reversible, and isolated to the current browser session. It also ensures that content created during demo mode is still stored normally and remains part of the real dataset once demo mode is turned off.

## Obfuscation Boundaries

The first version should obfuscate only narrative text surfaces, not all metadata.

Initial sensitive fields should include:

- `Thought.content`
- `ImportedEmail.subject`
- `ImportedEmail.body_text`
- `ImportedEmail.summary`
- similar presenter-exposed narrative text blocks derived from those fields

Fields that should remain real in the first version:

- timestamps and relative dates
- status labels and processing states
- routing identifiers needed for page navigation
- record relationships and page structure
- counts, ordering, and collection shape

This preserves the realism of the demo while avoiding needless UI distortion. If later review shows additional metadata should be hidden, the obfuscation list can expand field by field rather than through an opaque global rewrite.

For covered pages, v1 scope includes:

- main visible content regions
- page titles or headings derived from classified narrative fields
- textual HTML attributes such as `title` or `aria-label` when they are built from classified narrative fields

V1 does not include:

- JSON or API responses
- share pages unless they are explicitly migrated into the covered presenter path
- email, PDF, or other export surfaces
- social/meta tags or other outbound metadata not rendered through the covered presenter path

Any excluded surface that still renders classified raw text remains a known gap until explicitly covered.

## Obfuscation Behavior

The obfuscator should produce deterministic fake output within a single session.

That means:

- the same original source text maps to the same replacement during one session
- the mapping may change across sessions
- empty or null values stay empty or null unless a presenter explicitly wants a placeholder

The determinism contract should be explicit:

- use a session-scoped seed or token as the root of replacement generation
- normalize input consistently before lookup or generation, at minimum by trimming and using one Unicode normalization strategy
- namespace mappings by field context such as `thought_content` versus `email_subject` so unrelated fields do not accidentally share replacements
- persist the mapping only for the lifetime of the session and never in application records

The replacement output should look like plausible content rather than a repeated generic sentence whenever practical, but safety takes precedence over realism. If the obfuscator cannot produce a field-specific replacement, the safe fallback is a neutral placeholder such as `Demo content hidden`.

## Application Structure

The implementation should introduce two small services:

### `DemoMode`

Responsibilities:

- read whether demo mode is enabled for the current request
- enable demo mode in the session
- disable demo mode in the session
- expose a simple boolean for layouts, controllers, and presenters

### `DemoObfuscator`

Responsibilities:

- accept a raw string plus a field context such as `thought_content` or `email_subject`
- return a deterministic obfuscated replacement for the current session
- provide a safe placeholder fallback when obfuscation fails

The `DemoObfuscator` should not know about Blade, routes, or persistence. Its job is only string transformation for visible content.

## Presenter Responsibilities

Presenters should be the enforcement boundary for demo-safe rendering.

Required rules:

1. presenters expose display values, never raw sensitive text directly to Blade
2. presenters check `DemoMode` and obfuscate only the fields explicitly marked as sensitive
3. presenters leave non-sensitive structure, labels, and status values untouched unless separately classified
4. Blade should prefer presenter output over direct model property reads for screens that can show sensitive text
5. if a screen still renders raw model text directly, that screen is outside demo-mode coverage and should be treated as incomplete
6. routes that deliver client-side props for covered pages must not pass raw classified fields outside the presenter boundary; if a page cannot meet that rule, it is out of scope for v1 and must be called out explicitly

This keeps the obfuscation logic reviewable and avoids hidden global behavior. It also makes future coverage expansion straightforward: move additional sensitive render surfaces behind presenters.

## Banner And UX

When demo mode is active, the main application layout should show a persistent banner such as:

`Demo mode enabled. Sensitive text is obfuscated.`

The banner serves two purposes:

- it confirms to the presenter that the safe render path is active
- it reduces the chance of confusing obfuscated output for corrupted data

The enable and disable action can live behind a simple toggle affordance appropriate to the existing UI, but the exact control placement is less important than making the current mode obvious on every page.

## Failure Handling

The system must fail closed for sensitive display text.

If demo mode is active and the obfuscation path errors for a field, the app should render a neutral placeholder instead of the raw original string. It is acceptable for the demo to lose some textual richness in an edge case; it is not acceptable to leak the original content.

The app should also avoid mutating stored content or caching obfuscated values into persistent records. Demo mode is strictly a render-time behavior.

## Rollout Strategy

This should be implemented incrementally by render surface, not as a single all-or-nothing sweep.

Recommended first coverage targets:

1. thought detail pages
2. stream and index cards that display `Thought.content`
3. email-related views that display imported email subject, summary, or body text
4. shared layout banner and session toggle

After the first slice, maintain a checklist of known sensitive HTML render surfaces so future work does not silently bypass demo mode.

For each newly covered page, the implementation should document:

- which presenter-owned fields are classified as demo-sensitive
- which HTML surfaces on that page are intentionally covered in v1
- whether the page sends any client-side props that could bypass the Blade-only boundary

## Testing

Testing should focus on safety and behavior preservation.

### Unit coverage

Add focused tests for:

- `DemoMode` session enable and disable behavior
- `DemoObfuscator` deterministic replacement within a session
- fallback placeholder behavior on obfuscation failure
- presenter behavior in normal mode versus demo mode

### Feature coverage

Add representative page tests that verify:

1. enabling demo mode shows the banner
2. the same page structure, links, dates, statuses, and record order remain visible
3. sensitive text fields are replaced in demo mode
4. the original sensitive text remains visible in normal mode
5. toggling demo mode does not mutate stored records
6. if the obfuscator throws or fails, the response shows the neutral placeholder and never the raw classified value
7. for covered pages, the response HTML does not contain the original classified source strings anywhere in the rendered output

The initial feature tests should focus on one or two representative high-risk pages first, then expand as coverage grows.

## Risks

- Some Blade templates may still read raw model text directly and bypass the presenter boundary.
- The first pass may miss a sensitive narrative field that is not yet classified.
- Over-obfuscation could make the demo feel less realistic than necessary.
- Under-obfuscation could leave a sensitive field exposed if coverage is incomplete.

## Mitigations

- Treat presenter output as the required boundary for sensitive text screens.
- Roll out incrementally across known high-risk render surfaces first.
- Use explicit field classification rather than implicit global replacement.
- Fail closed to a neutral placeholder when obfuscation cannot safely complete.
- Add focused regression tests for representative pages as each surface is covered.

## First Implementation Slice

The recommended first slice is:

1. add `DemoMode` session handling and a lightweight toggle path
2. add the shared `DemoObfuscator`
3. add the persistent demo banner in the layout
4. update the highest-risk presenters or presenter-backed pages to obfuscate `Thought.content`
5. extend coverage to imported email subject, summary, and body text surfaces
6. add session, presenter, and representative page tests

This sequence delivers a usable safe-demo capability quickly while keeping the implementation narrow and reviewable.

## Recommendation Summary

Add a per-session demo mode that preserves the real application structure and stored data while obfuscating sensitive narrative text during HTML rendering. Use presenters as the safety boundary, a shared obfuscation service for deterministic session-scoped replacements, and a persistent layout banner to make the mode obvious during demos. The first rollout should focus on the highest-risk text surfaces and fail closed if obfuscation cannot complete safely.
