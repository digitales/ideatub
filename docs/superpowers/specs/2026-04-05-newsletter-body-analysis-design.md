# IdeaTub – Newsletter body analysis design

**Date:** 2026-04-05
**Status:** Draft for review
**Scope:** Add async AI-generated analysis of newsletter body content to the newsletter research view, covering summary, key points, positives/negatives, and highlights.

## 1. Summary

- When newsletter research is created, dispatch a new queued job that analyses the email body using AI.
- Store the structured analysis result in a dedicated `newsletter_analyses` table — one row per research thought.
- Render the analysis sections above the existing raw email content on the research view.
- Mirror the partial-state and failure patterns already established by editorial link summaries.

## 2. Goals and non-goals

### 2.1 Goals

- Generate a 2–4 sentence summary of what the newsletter covers.
- Extract key points — the main claims, findings, or stories the author highlights.
- Capture positives and negatives on two lenses: opinions the newsletter author expresses about subjects, and quality observations about the newsletter itself.
- Surface a highlights list for anything pertinent that doesn't fit the structured fields.
- Keep the raw email body visible below the analysis for reference.
- Handle partial and failure states gracefully without blocking the research page.
- Remain idempotent — re-dispatching the job should not create duplicate analysis rows.

### 2.2 Non-goals

- Replacing or modifying the editorial link summaries feature.
- Analysing attachments or HTML-only emails without plain text bodies.
- Exposing analysis as a standalone thought type.
- Backfilling existing research thoughts in this initial implementation.

## 3. Data model

### 3.1 `newsletter_analyses` table

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `research_thought_id` | string FK → thoughts | |
| `source_thought_id` | string FK → thoughts | the originating email thought |
| `stored_email_type` | string | `imported_email` or `captured_inbound_email` |
| `stored_email_id` | bigint | |
| `status` | string | `queued`, `processing`, `completed`, `failed` |
| `summary` | text nullable | 2–4 sentence newsletter overview |
| `key_points` | JSON nullable | array of strings |
| `positives_mentioned` | JSON nullable | array of strings |
| `negatives_mentioned` | JSON nullable | array of strings |
| `highlights` | JSON nullable | array of strings |
| `quality_notes` | text nullable | caveats about content quality |
| `failure_reason` | string nullable | populated on failure |
| `completed_at` | timestamp nullable | |
| `created_at` / `updated_at` | timestamps | |

A unique constraint on `research_thought_id` ensures one analysis per research thought.

### 3.2 `NewsletterAnalysis` model

- `belongsTo(Thought::class, 'research_thought_id')`
- `belongsTo(Thought::class, 'source_thought_id')`
- Cast `key_points`, `positives_mentioned`, `negatives_mentioned`, `highlights` as arrays.

## 4. AI prompt and generator

### 4.1 `OpenRouterService::analyzeNewsletter()`

Accepts `string $subject` and `string $body`. Returns a strict JSON object:

```json
{
  "summary": "2–4 sentence neutral overview of what this newsletter covers",
  "key_points": ["...", "..."],
  "positives_mentioned": ["...", "..."],
  "negatives_mentioned": ["...", "..."],
  "highlights": ["...", "..."],
  "quality_notes": "..." | null
}
```

System prompt instructions:

- **summary**: neutral overview of the newsletter's topics, framing, and scope.
- **key_points**: the main claims, findings, or stories the author highlights.
- **positives_mentioned**: both (a) things the newsletter author is positive about (e.g. bullish on X, praising Y), and (b) quality strengths of the newsletter itself (e.g. well-sourced, clearly structured).
- **negatives_mentioned**: both (a) things the author is critical or sceptical about, and (b) quality weaknesses of the newsletter itself (e.g. surface-level on Z, lacks evidence for claim).
- **highlights**: anything else pertinent — notable data points, surprising assertions, calls to action, recurring themes.
- **quality_notes**: caveats only — thin content, truncated body, unclear authorship. Null if none.
- Write in British English. Factual, neutral, analytical tone. No padding.

The model truncates the body to a sensible token limit (e.g. 8,000 characters) before sending, recording a quality note if truncation occurred.

### 4.2 `NewsletterAnalysisGenerator`

Thin service wrapping `OpenRouterService::analyzeNewsletter()`, mirroring `LinkSummaryGenerator`.

## 5. Job

### 5.1 `ProcessNewsletterBodyAnalysis`

- Accepts: `researchThoughtId`, `sourceThoughtId`, `importedEmailId` or `capturedInboundEmailId` (exactly one).
- On construction: creates (or finds existing) `newsletter_analyses` row with `status: queued`, so partial state is visible immediately.
- `handle()`:
  1. Acquire cache lock keyed by `research_thought_id` to prevent duplicate runs.
  2. Set `status: processing`.
  3. Resolve the stored email and read `body_text` + `subject`.
  4. Call `NewsletterAnalysisGenerator::generate()`.
  5. Update the row to `status: completed` with all analysis fields populated and `completed_at` set.
- On failure: set `status: failed`, store `failure_reason`.
- Retries: 3 attempts, 60s backoff — consistent with `ProcessExtraEmailResearch`.
- Timeout: 120s.

### 5.2 Dispatch point

In `ProcessExtraEmailResearch::handleLocked()`, after `queueNewsletterEditorialLinks` is called, dispatch `ProcessNewsletterBodyAnalysis` for the same research thought. This applies to both the new-research path and the existing-research re-dispatch path.

## 6. Rendering

### 6.1 Presenter

`ThoughtDetailPresenter` gains a `newsletterAnalysis()` method that returns the associated `NewsletterAnalysis` record (or null). Template logic stays thin.

### 6.2 Research view layout

Analysis block renders **above** the existing email content section.

**When `completed`:**

```
## Summary
[summary paragraph]

## Key points
- ...

## Positives mentioned
- ...

## Negatives mentioned
- ...

## Highlights
- ... (section omitted if empty)

## Quality notes
[shown only if populated]

---

## Email content
[existing raw body]

## YouTube transcripts
[existing, if present]
```

**When `queued` or `processing`:**
Compact inline note: *"Newsletter analysis processing…"* — no spinner blocking the page. Same visual treatment as the link summaries pending state.

**When `failed`:**
Compact note: *"Newsletter analysis could not be completed."* — unobtrusive, does not dominate the page.

**When no record exists** (older research thoughts predating this feature):
Render nothing — graceful degradation.

## 7. Edge cases

### 7.1 Empty or very short body

If `body_text` is blank or under ~50 characters, skip dispatch and create the analysis row immediately as `failed` with `failure_reason: body_too_short`. Do not waste an API call.

### 7.2 Re-dispatch for existing research thoughts

If the job is dispatched for a research thought that already has a `completed` analysis row, the cache lock plus unique constraint ensure no duplicate work. The existing row is left unchanged.

### 7.3 Truncated bodies

If `body_text` exceeds the token limit, truncate at a paragraph boundary and set `quality_notes` to indicate the body was truncated. The analysis is still saved as `completed`.

## 8. Testing strategy

### 8.1 Unit tests

- `OpenRouterService::analyzeNewsletter()` prompt structure and JSON parsing.
- `NewsletterAnalysisGenerator` wraps the service correctly.
- Body truncation logic at the token limit.

### 8.2 Job tests

- Happy path: job creates analysis row and populates all fields.
- Short body: job marks row as failed without calling the API.
- API failure: row is set to `failed` with a reason.
- Idempotency: re-dispatching with an existing `completed` row leaves it unchanged.
- Lock contention: second concurrent dispatch releases rather than double-processes.

### 8.3 Feature tests

- Research view renders analysis sections when record is `completed`.
- Research view shows pending note when record is `queued` or `processing`.
- Research view shows failure note when record is `failed`.
- Research view renders nothing when no record exists.
- Raw email content section remains present below the analysis.
