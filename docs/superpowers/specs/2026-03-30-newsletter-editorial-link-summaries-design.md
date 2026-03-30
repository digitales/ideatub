# IdeaTub - Newsletter editorial link summaries design

**Date:** 2026-03-30
**Status:** Draft for review
**Scope:** Add queued editorial-link summarization for newsletter research, with reusable link-summary records that can support future non-email thought types.

## 1. Summary

- Extend newsletter research so it ignores noise links and summarizes only editorial/content links.
- Group summarized links by newsletter section and order them within each section by usefulness.
- Process editorial links asynchronously through queued jobs rather than inside the main newsletter-research write path.
- Store normalized link-summary records in reusable structured storage so future thought types can request summaries for arbitrary links.
- Link completed summaries back to both the source email thought and the generated newsletter research thought.

In this document, `source_thought_id` means the originating email thought, while `parent_research_thought_id` means the newsletter research thought that renders grouped summaries.

## 2. Goals and non-goals

### 2.1 Goals

- Ignore low-value newsletter links such as social, contact, unsubscribe, referral, account-management, and footer/navigation links.
- Summarize every retained editorial/content link.
- Group retained links by the newsletter section they came from.
- Rank links within each section by usefulness and support strength.
- Record whether each linked page supports, extends, or weakly supports the email's framing.
- Make link summarization queue-driven and reusable outside the newsletter feature.
- Keep newsletter research usable even when some links fail to fetch or summarize.

### 2.2 Non-goals

- Summarizing every URL found in an email regardless of relevance.
- Building a general-purpose crawler that follows links beyond the original newsletter destination.
- Blocking newsletter research creation on completion of all link summaries.
- Solving every possible newsletter layout or publisher-specific HTML variation in the first version.
- Exposing standalone link-summary thoughts in the main product surface unless later product needs justify that.

## 3. Product behavior

### 3.1 Editorial-only summarization

When a newsletter is eligible for extra processing:

- continue creating the email thought and newsletter research thought
- classify extracted links into `editorial`, `noise`, `sponsor`, or `unknown`
- ignore `noise` links completely for summarization and research display
- treat `editorial` links as the primary summary set
- optionally keep `sponsor` links out of the first version's summary output unless product requirements later add a sponsor section
- allow `unknown` links to fall back to editorial processing only when they appear embedded in substantive newsletter sections rather than footer/navigation areas

For v1, "retained for summarization" means:

- all links classified as `editorial`
- selected `unknown` links that pass newsletter-body placement heuristics

Links classified as `noise` or `sponsor` are not summarized in the editorial output.

### 3.2 Grouped and ranked output

Newsletter research should render an `Editorial link summaries` section that:

- groups links under section headings inferred from the email, such as `Headlines & Launches`, `Deep Dives & Analysis`, or `Engineering & Research`
- renders section groups in the same order they first appear in the newsletter body
- renders every summarized editorial link in that section
- orders links within each section by usefulness score, highest first
- shows, for each link:
  - destination title
  - canonical destination URL
  - one to two sentence summary
  - relation to email: `supports`, `adds context`, `mostly tangential`, or `unclear`
  - `Why it matters` sentence
  - optional processing note if the summary is partial or fetch quality was poor

### 3.3 Partial-progress behavior

Newsletter research should not wait for every link summary to finish before becoming useful.

- the core research thought can be created immediately from the email body and available metadata
- editorial link summaries should appear as background jobs finish
- while summaries are still running, the research page may show a compact note such as `_3 editorial links still processing_`
- failed links should remain represented as failed items rather than silently disappearing

## 4. Architecture

### 4.1 Recommended direction

The recommended design is to separate newsletter research generation from reusable link-summary processing.

- `EmailNewsletterResearchService` remains responsible for creating the newsletter research thought and storing newsletter-specific linkage metadata
- a new reusable link-summary pipeline accepts:
  - source thought information
  - optional parent research thought
  - extracted links plus newsletter-specific context such as section label and nearby excerpt
- one queued job processes each retained editorial link
- newsletter rendering reads structured link-summary records and formats them for grouped output

This keeps newsletter-specific orchestration thin while creating a generic capability that future thought types can reuse.

### 4.2 Why queueing is the right default

Fetching and summarizing every editorial link can be slow and failure-prone. Queueing keeps email import and research creation responsive while allowing retries and partial completion.

Queueing also creates a clean extension point for:

- link summaries requested from non-email thoughts
- backfills or reprocessing of existing links
- improved fetchers or classifiers later without changing the email ingestion contract

## 5. Data model and linking

### 5.1 Source of truth

Use a dedicated structured table for link-summary work items and results rather than storing all state inside `processing_metadata_json`.

This table should hold both queue-state fields and the final summary payload. It becomes the durable source of truth for deduplication, retries, and future reuse.

### 5.2 Suggested fields

Each link-summary record should capture:

- `source_thought_id` for the originating email thought
- `source_type` such as `email_newsletter`
- `parent_research_thought_id` when present
- `stored_email_type` and `stored_email_id` when relevant
- `original_url`
- `normalized_url`
- `normalized_url_hash` or other lookup key for dedupe
- `newsletter_section_label` when known
- `newsletter_section_order` based on first appearance in the email body
- `source_excerpt` or nearby editorial blurb
- `classification` with values like `editorial`, `noise`, `sponsor`, `unknown`
- `processing_status` with values like `queued`, `fetching`, `summarized`, `failed`
- `fetch_status_code` or fetch failure reason when available
- `resolved_title`
- `summary_text`
- `support_judgment`
- `why_it_matters`
- `usefulness_score`
- `section_rank` as a stored render-order value derived from usefulness sorting within a section
- `content_fingerprint` for change detection if later re-fetches are supported
- timestamps for queue lifecycle and completion

Ordering contract:

- section groups render by `newsletter_section_order` ascending
- links inside a section sort by `usefulness_score` descending
- `section_rank` is an optional denormalized render field written after sorting, not an independent source of truth

### 5.3 Linking behavior

Each completed summary record should be discoverable from:

- the source email thought
- the newsletter research thought
- the stored email row when applicable

This allows the newsletter feature to render summaries directly, while still keeping the data reusable for other thought types later.

The first version does not need to create separate summary thoughts in the thought graph. If later product work benefits from standalone link-summary thoughts, they can be layered on top of the structured table without changing the initial ingestion contract.

## 6. Link classification and section mapping

### 6.1 Noise filtering

Before queueing summaries, classify links and drop obvious noise from the editorial pipeline.

Expected noise categories:

- social profiles
- contact/support/about pages
- account-management links
- manage-subscription and unsubscribe links
- referral/reward links
- generic site navigation
- footer utilities
- careers and hiring links when they are not part of the editorial body

Expected sponsor handling:

- sponsor links should not be included in editorial summaries in the first version
- they may be marked as `sponsor` for reporting or future product use

### 6.2 Section mapping

Each retained editorial link should be assigned to the newsletter section it came from using nearby email structure.

Preferred signals:

- nearest heading in the email body
- nearby editorial blurb or line immediately preceding the URL
- known section labels extracted from the newsletter text

Fallback behavior:

- if no reliable section can be inferred, place the link in `Uncategorized editorial links`

### 6.3 Why nearby context matters

Summarizing only from a destination page is not enough to judge whether the link supports the email. The processor should compare:

- the page's actual content
- the newsletter's local framing for that link

For that reason, the pipeline should preserve a short source excerpt or nearby blurb at queue time so the summary job has stable context even if the email body later changes format.

## 7. Processing flow

### 7.1 Queue pipeline

Recommended flow:

1. Extract links from the stored email.
2. Classify links into editorial-related buckets.
3. Map retained editorial links to newsletter sections and nearby excerpts.
4. Create structured link-summary rows for retained editorial links.
5. Dispatch one queue job per retained link.
6. Fetch the destination page.
7. Produce normalized summary output.
8. Update ordering metadata for the section if needed.
9. Render completed summaries in newsletter research views.

### 7.2 Summary output contract

Each successful summary should produce:

- `title`
- `canonical_url`
- `summary_text`
- `support_judgment`
- `why_it_matters`
- `usefulness_score`
- `quality_notes` when fetch quality or source confidence is weak

### 7.3 Failure handling

The system should tolerate failures per link.

- if one link fails, other editorial links should continue processing
- failed links should be visible as failed records rather than being retried forever without visibility
- retry rules should be bounded
- if the destination page cannot be fetched, preserve the URL, source section, and failure reason
- if summary generation fails after a successful fetch, record that separately from fetch failure

## 8. Rendering contract

### 8.1 Research-page presentation

Newsletter research rendering should:

- keep the existing email content area
- keep existing YouTube transcript handling where relevant
- replace the flat extracted-links output in the main research presentation with a higher-value `Editorial link summaries` block
- render grouped sections in newsletter order
- sort items inside each section by usefulness score

The research page should not continue to render ignored noise links in the editorial summary UI. If raw extracted-link visibility is needed later for debugging, it should live in a separate diagnostic surface rather than in the main newsletter research presentation.

### 8.2 Incomplete-state presentation

If link summaries are still running:

- show completed sections/items normally
- show a concise pending count if useful
- avoid blocking the whole research page behind a loading state

### 8.3 Failed-item presentation

If some editorial links fail:

- keep the section visible when at least one item succeeded
- optionally show failed items as compact rows with URL plus failure note
- avoid making failed rows visually dominate successful summaries

## 9. Reuse beyond newsletters

### 9.1 General capability

The queue-driven link-summary pipeline should be designed so other thought types can submit links later without requiring newsletter-specific assumptions.

Required reusable inputs:

- source thought id
- source type
- URL
- optional parent thought id
- optional local context excerpt
- optional grouping label

### 9.2 Newsletter-specific layering

Newsletter-specific behavior should live at the orchestration layer:

- noise filtering tuned for newsletters
- section mapping from newsletter headings
- grouped rendering in newsletter research

The underlying summary processor should remain agnostic to whether the source came from an email, note, web research item, or another future thought type.

## 10. Edge cases

### 10.1 Redirect and tracker URLs

Many newsletters use wrapped URLs. The processor should prefer the final resolved destination when possible while still storing the original extracted URL for traceability.

### 10.2 Duplicate editorial links

If the same article appears more than once in one newsletter:

- avoid duplicate summaries
- allow multiple source-context references if useful
- keep one canonical summary result for that newsletter/source combination

### 10.3 Weak fetch results

If the fetched page returns very thin content, a soft paywall, or an unusable placeholder:

- record the fetch as partial or low-confidence
- still allow a weaker summary when there is enough content
- otherwise mark the item failed with a specific reason

### 10.4 Ambiguous support judgments

When the linked page is relevant but does not strongly support the email's blurb:

- prefer `adds context` or `unclear` rather than over-claiming support

## 11. Testing strategy

### 11.1 Unit tests

Add focused unit tests for:

- newsletter noise-link classification
- sponsor classification
- section mapping from nearby headings and blurbs
- usefulness ordering rules
- deduplication of repeated editorial URLs

### 11.2 Job tests

Add job-level tests for:

- queued editorial link processing
- successful fetch and summary storage
- fetch failure handling
- summary-generation failure handling
- bounded retries
- partial completion across multiple links

### 11.3 Feature tests

Add feature coverage for:

- newsletter research rendering with grouped editorial summaries
- omission of noise links from the rendered summaries
- correct section grouping
- usefulness-based ordering within a section
- partial processing states
- mixed success/failure states

## 12. Recommended implementation direction

The recommended implementation is:

- create a reusable structured link-summary model/table
- add newsletter-oriented classification and section-mapping logic before queue dispatch
- queue one job per retained editorial link
- store normalized summary results linked to email thought and research thought
- render grouped, usefulness-ordered editorial summaries in newsletter research

This achieves the immediate newsletter goal while also creating a durable link-summary capability that future thought types can reuse without redesigning the processing model.
