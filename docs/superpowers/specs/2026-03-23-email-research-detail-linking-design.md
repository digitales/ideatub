# IdeaTub - Email and research detail linking design

**Date:** 2026-03-23  
**Status:** Draft  
**Scope:** Add explicit bidirectional links between researched emails and research detail pages so users can navigate from email detail to research detail and back again.

## 1. Summary

- When an email has newsletter research, the email detail page should show a lightweight link to the related research.
- The research detail page should show a more prominent related-email card that links back to the email thought detail page.
- New research runs should always persist explicit bidirectional linkage metadata.
- Existing records should be upgraded through a safe backfill command rather than request-time heuristics.

## 2. Goals and non-goals

### 2.1 Goals

- Let users move directly from an email detail page to its related research detail page.
- Let users move directly from a research detail page back to the related email thought detail page.
- Keep the email page treatment lightweight and metadata-oriented.
- Make the research page backlink more prominent by showing a related-email card with enough context to recognize the email before clicking.
- Keep link resolution deterministic and avoid best-guess matching at render time.
- Support existing historical data with a one-time or repeatable backfill command.

### 2.2 Non-goals

- A dedicated original stored-email detail page.
- Heuristic linking by subject, sender, or timestamps at request time.
- Showing a placeholder or unavailable state when the research page cannot resolve a related email.
- Introducing many-to-many email-to-research relationships in v1.

## 3. Product behavior

### 3.1 Email detail page

On the email thought detail page:

- Keep the related research link in the existing email metadata/sidebar area.
- Use a lightweight action such as `View research`.
- Show the link only when the related research thought exists and is viewable by the current user.

### 3.2 Research detail page

On the research detail page:

- Add a prominent related-email card near the top of the page, above the main rendered research body.
- The card should contain:
  - sender
  - subject
  - `View email` action
- The card should link to the email thought detail page, not to a stored original-email record.
- If no valid linked email thought can be resolved, show nothing.

### 3.3 Why asymmetric emphasis

The user wants the email-to-research link to feel like related metadata, while the research-to-email link should be easier to notice and use as a primary navigation step back to the original email thought.

## 4. Data contract

### 4.1 Source of truth

Use explicit thought IDs as the primary relationship contract.

For newly created newsletter research:

- the email thought metadata should include `research_thought_id`
- the research thought metadata should include `email_thought_id`

The research thought metadata should also include display-ready values for the related-email card:

- `email_subject`
- `email_sender`

These display fields let the research page render the card without needing fallback heuristics from other records.

### 4.2 Existing durable email-record linkage

The durable stored email record already carries linkage information such as:

- `thought_id`
- `research_thought_id`

That linkage should remain the authoritative backfill source for historical records that are missing `email_thought_id` on the research thought.

### 4.3 Validity rules

A relationship is valid for page rendering only when:

- the linked thought exists
- it belongs to the current user
- it is viewable under existing authorization rules

If those checks fail, the page should omit the related link/card rather than trying to recover dynamically.

## 5. Request-time behavior

### 5.1 Email detail rendering

The email detail page may continue reading the related research thought ID from existing email thought metadata and/or stored email processing metadata, but it should only render the link after resolving the related thought successfully.

Precedence and conflict rules:

- prefer `research_thought_id` from email thought metadata
- if that value is missing, fall back to the stored email processing metadata
- if both values exist and disagree, treat the relationship as invalid and omit the link

This protects the UI from linking to deleted or inaccessible research records.

### 5.2 Research detail rendering

The research detail controller should resolve the related email thought using the explicit `email_thought_id` from research metadata.

It should then pass a compact related-email view model to the template containing:

- email thought route target
- sender display text
- subject display text

Render the card only when all of the following are true:

- the linked email thought resolves successfully
- sender display text is present
- subject display text is present

If the ID resolves but the display data is incomplete, omit the card rather than rendering a partial version.

No request-time subject/sender/date matching should be attempted.

## 6. Write-path changes

### 6.1 Newsletter research creation

Update newsletter research persistence so that when research is created successfully:

- the stored email record keeps `research_thought_id`
- the email thought metadata keeps `research_thought_id`
- the research thought metadata also stores:
  - `email_thought_id`
  - `email_subject`
  - `email_sender`

### 6.2 Metadata sourcing

Preferred sources for research-card display fields:

- subject from the stored email record when available
- sender from the stored email record when available
- otherwise fall back to email thought `source_metadata` if necessary

The goal is consistency with the actual email being linked, not perfect normalization of every historical format.

The same fallback order should apply during backfill when populating `email_subject` and `email_sender`.

## 7. Backfill command

### 7.1 Purpose

Add an Artisan command to populate missing research-to-email linkage metadata for existing newsletter research records.

### 7.2 Backfill source

The command should scan stored email records that already have both:

- `thought_id`
- `research_thought_id`

This avoids guesswork because the durable record already encodes the intended one-to-one relationship.

### 7.3 Update rules

For each candidate row, update the linked research thought only when all of the following are true:

- the research thought exists
- the email thought exists
- both belong to the same user
- the research thought is missing `email_thought_id`, or already has the same value

When updating, write:

- `email_thought_id`
- `email_subject`
- `email_sender`

### 7.4 Skip rules

Skip and report rows when:

- research thought is missing
- email thought is missing
- ownership does not match
- the research thought already contains a conflicting `email_thought_id`
- required source fields are too incomplete to produce a trustworthy related-email card

### 7.5 Output

The command should print a clear summary, such as:

- scanned
- updated
- skipped
- conflicted

This makes the backfill safe to run and easy to audit.

## 8. Error handling

### 8.1 Missing or stale research link from email page

If the email detail page cannot resolve the linked research thought, it should omit the research link.

### 8.2 Missing or stale email link from research page

If the research detail page cannot resolve the linked email thought, it should omit the related-email card entirely.

### 8.3 No heuristic recovery

Do not attempt to reconstruct the relationship at request time from sender, subject, timestamps, or content similarity. Incorrect links would be worse than absent links.

## 9. Testing strategy

### 9.1 Feature tests

Add feature coverage for:

- email detail shows `View research` when linked research exists and is viewable
- email detail omits the link when linked research is missing or inaccessible
- research detail shows the related-email card when `email_thought_id` resolves successfully
- research detail omits the related-email card when metadata is missing or stale

### 9.2 Backfill tests

Add command coverage for:

- updating missing `email_thought_id`, `email_subject`, and `email_sender`
- skipping missing-thought cases safely
- skipping conflicting-link cases safely
- reporting counts accurately

### 9.3 Invariants

Protect these invariants:

- request-time rendering follows explicit stored IDs only
- new newsletter research writes both directions of the relationship
- historical backfill never overwrites a conflicting explicit relationship

## 10. Recommended implementation direction

The recommended v1 approach is:

- keep the existing lightweight email-detail research link pattern
- add a prominent related-email card to research detail
- treat explicit thought IDs as the navigation contract
- store display metadata on the research thought for the card
- add a safe Artisan backfill command for historical records

This gives users the navigation they want without introducing brittle lookup logic or requiring a new stored-email detail experience first.
