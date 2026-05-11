# Article Capture Pipeline Design

**Date:** 2026-05-11
**Status:** Draft

## Overview

A pipeline to capture web articles into IdeaTub, extract their content and metadata (including copyright notices and editorial links), and automatically run research on the captured content. Articles are submitted via MCP tool or web UI, scraped in the background, and stored as a structured thought tree with link summaries and research output.

## Goals

- Capture article text, metadata, and copyright notices from any public URL
- Extract editorial links from the article body and generate summaries for each
- Automatically run research on every captured article
- Expose capture via both MCP (`capture_article`) and a dedicated web UI (`/articles`)
- Reuse existing infrastructure: `ThoughtCaptureService`, `ThoughtChunkingService`, `ProcessThoughtLinkSummary`, `ResearchService`

## Architecture: Staged Pipeline

Three async jobs form a sequential pipeline, each with independent retry/failure. Partial results are preserved if later stages fail.

```
User submits URL
       │
       ▼
ArticleCaptureService::capture()
  → creates root thought (source='article', status='queued')
  → dispatches ScrapeArticleContent
       │
       ▼
ScrapeArticleContent job
  → fetches HTML (reuses LinkSummaryFetcher patterns)
  → ArticleContentExtractor extracts text, title, author, date, copyright, links
  → creates full-text child thought (auto-chunked if >500 words)
  → updates root status to 'scraped'
  → dispatches ClassifyArticleLinks
       │
       ▼
ClassifyArticleLinks job
  → filters editorial links (removes nav, social, ads, self-refs)
  → creates ThoughtLinkSummary rows
  → dispatches ProcessThoughtLinkSummary per link (existing job)
  → queues RunResearchRun on root thought (existing job)
  → updates root status to 'complete' (pipeline done, downstream jobs independent)
```

## Entry Points

### MCP Tool: `capture_article`

Exposed via `McpController` alongside existing tools.

**Parameters:**

| Name | Type | Required | Description |
|------|------|----------|-------------|
| `url` | string | yes | The article URL to capture |
| `title` | string | no | Override for extracted title |
| `tags` | array | no | Additional tags to apply |
| `project` | string | no | Project context |

**Response:** Returns the root thought UUID immediately. Scraping runs asynchronously.

### Web UI: `GET /articles`

Dedicated page with:
- URL input field at top (paste URL, submit)
- List of captured articles with status indicators (queued / scraping / scraped / links processing / researching / complete / failed)
- Each article row links to its thought in Stream

**Route:** `POST /articles` submits a URL for capture via `ArticleCaptureController`.

Both entry points call `ArticleCaptureService::capture(url, options)`.

## Components

### New: `ArticleCaptureService`

**Location:** `app/Services/ArticleCaptureService.php`

Single public method:

```php
public function capture(string $url, array $options = []): Thought
```

Options: `title`, `tags`, `project`, `user_id`.

Responsibilities:
1. Validate URL (reject private IPs, invalid schemes)
2. Check for duplicate captures (same `normalized_url_hash` with source='article' for the user)
3. Create root thought via `ThoughtCaptureService::create()`:
   - `source = 'article'`
   - `content = "Capturing article: {url}"` (placeholder until scraped)
   - `source_metadata = {url, domain, status: 'queued', captured_at: now}`
   - `metadata.tags` includes user-supplied tags + `['article']`
4. Dispatch `ScrapeArticleContent` after DB commit
5. Return root thought

### New: `ArticleContentExtractor`

**Location:** `app/Services/Article/ArticleContentExtractor.php`

Extracts structured content from raw HTML. Isolated from HTTP fetching for testability and future replacement (e.g., Scrapegraph-ai upgrade).

**Input:** Raw HTML string + source URL.

**Output (DTO or array):**

| Field | Source | Fallback |
|-------|--------|----------|
| `title` | `og:title` → `<title>` → first `<h1>` | URL path |
| `author` | `meta[name=author]` → JSON-LD `author` → byline patterns | null |
| `published_date` | `meta[property=article:published_time]` → JSON-LD `datePublished` → `<time>` | null |
| `copyright` | `<footer>` text matching `©` / "copyright" / "all rights reserved" → `meta[name=copyright]` | null |
| `body_text` | Text within `<article>` → `<main>` → largest content block heuristic. Strips scripts, styles, nav, ads. | Full visible text |
| `links` | All `<a href>` within body_text container, as `[{url, anchor_text, context_sentence}]` | `[]` |
| `description` | `meta[property=og:description]` → `meta[name=description]` | First 300 chars of body |

### New: `ScrapeArticleContent` Job

**Location:** `app/Jobs/ScrapeArticleContent.php`
**Config:** 3 tries, 60s backoff, 300s timeout, `ShouldQueue`

Steps:
1. Fetch HTML using patterns from `LinkSummaryFetcher` (HTTP GET, redirect following, SSRF guards, 2MB body cap)
2. Pass HTML to `ArticleContentExtractor`
3. Create full-text child thought:
   - `parent_id` = root article thought
   - `source = 'article'`
   - `content` = extracted body text + copyright notice appended at bottom
   - `source_metadata = {child_type: 'full_text', url, title, author, published_date, copyright, domain}`
   - Auto-chunking via `ThoughtChunkingService` applies if content exceeds 500 words
4. Update root thought:
   - `content` = `"{title}\n\n{url}\nBy {author} | {published_date}"`
   - `source_metadata.status = 'scraped'`
   - `source_metadata.title`, `.author`, `.published_date`, `.copyright`, `.description`, `.link_count`
5. Dispatch `ClassifyArticleLinks` with root thought ID and extracted links array

**On failure:** Set root `source_metadata.status = 'scrape_failed'`, store error in `source_metadata.error`.

### New: `ClassifyArticleLinks` Job

**Location:** `app/Jobs/ClassifyArticleLinks.php`
**Config:** 3 tries, 30s backoff, 120s timeout, `ShouldQueue`

Steps:
1. Filter links -- remove non-editorial links:
   - Same-domain navigation (`/about`, `/contact`, `/privacy`, anchors)
   - Social share links (twitter.com/intent, facebook.com/sharer, linkedin.com/shareArticle, etc.)
   - Known ad/tracking domains
   - Image/media file extensions (.jpg, .png, .gif, .svg, .mp4, etc.)
   - Self-references (links to the same article URL)
   - JavaScript links (`javascript:`)
2. For each editorial link, create a `ThoughtLinkSummary` row:
   - `source_thought_id` = root article thought
   - `original_url` = link URL
   - `normalized_url` = normalized form
   - `normalized_url_hash` = SHA-256
   - `classification = 'article_reference'`
   - `processing_status = 'queued'`
3. Dispatch `ProcessThoughtLinkSummary` for each row (existing job -- fetches page, generates LLM summary)
4. Queue research: `ResearchService::queueResearchRunForIdea(rootThought)` using the user's active research skill
5. Update root thought `source_metadata.status = 'complete'`, `source_metadata.editorial_link_count = N`

Pipeline terminal status `complete` means all article-specific work is done. Link summary and research jobs run independently with their own status tracking (`ThoughtLinkSummary.processing_status` and `ResearchRun.status`).

**On failure:** Set root `source_metadata.status = 'links_failed'`, store error.

## Thought Tree Structure

```
Root article thought
│   source: 'article'
│   content: "{title}\n{url}\nBy {author} | {date}"
│   source_metadata: {url, domain, title, author, published_date, copyright,
│                     status, link_count, editorial_link_count}
│   metadata.tags: ['article', ...user_tags]
│
├── Full-text child
│   │   source: 'article'
│   │   content: article body text + copyright notice
│   │   source_metadata: {child_type: 'full_text', ...}
│   │
│   ├── Section 1 (if auto-chunked)
│   ├── Section 2
│   └── ...
│
├── Link summaries (via ThoughtLinkSummary table)
│       source_thought_id → root
│       summary_text, support_judgment, why_it_matters, usefulness_score
│
└── Research child
        source: 'research'
        metadata.type: 'research'
        metadata.idea_id: root_uuid
```

## Copyright Handling

Copyright notices are stored in two places:
1. **`source_metadata.copyright`** on both root and full-text child thoughts (structured, machine-readable)
2. **Appended to content** at the bottom of the full-text child thought (human-visible)

Format in content:
```
---
© {copyright_notice}
Source: {url}
```

## Status Tracking

The root thought's `source_metadata.status` tracks pipeline progress through the article-specific stages:

| Status | Set by | Meaning |
|--------|--------|---------|
| `queued` | `ArticleCaptureService` | Capture initiated, scrape job pending |
| `scraping` | `ScrapeArticleContent` (start) | HTML fetch in progress |
| `scraped` | `ScrapeArticleContent` (end) | Content extracted, full-text child created |
| `links_processing` | `ClassifyArticleLinks` (start) | Link filtering in progress |
| `complete` | `ClassifyArticleLinks` (end) | Links classified, summaries + research queued |
| `scrape_failed` | `ScrapeArticleContent` | HTML fetch or extraction failed |
| `links_failed` | `ClassifyArticleLinks` | Link classification failed |

Each job sets its "in progress" status at the start of execution (before doing work) and its "done" status at the end.

The pipeline's terminal status is `complete`, meaning all article-specific work is done and downstream jobs are dispatched. Research progress is tracked separately via the existing `ResearchRun` model (status: queued/running/completed/failed). Link summary progress is tracked per-link via existing `ThoughtLinkSummary.processing_status`. The `/articles` UI reads both `source_metadata.status` and the associated `ResearchRun` status to show full pipeline state.

## Web UI: `/articles`

**Vue component:** `ArticleCapture.vue` (under `resources/js/Pages/` or `resources/js/Components/`)

**Layout:**
- URL input field at top with submit button
- Table/card list of captured articles, sorted by most recent:
  - Title (linked to Stream view of root thought)
  - Domain
  - Status badge (color-coded)
  - Captured date
  - Link count / editorial link count
  - Research status indicator

**Controller:** `ArticleCaptureController`
- `index()` -- returns paginated articles for the user (thoughts with `source='article'` and no `parent_id`)
- `store(Request $request)` -- validates URL, calls `ArticleCaptureService::capture()`, redirects back

## MCP Tool Registration

Add `capture_article` to `McpController::getToolDefinitions()`:

```json
{
  "name": "capture_article",
  "description": "Capture a web article into IdeaTub. Scrapes the article content, extracts copyright and editorial links, summarizes each link, and runs research automatically.",
  "inputSchema": {
    "type": "object",
    "properties": {
      "url": {"type": "string", "description": "The article URL to capture"},
      "title": {"type": "string", "description": "Optional title override"},
      "tags": {"type": "array", "items": {"type": "string"}, "description": "Additional tags"},
      "project": {"type": "string", "description": "Project context"}
    },
    "required": ["url"]
  }
}
```

## Duplicate Detection

Before creating a root thought, `ArticleCaptureService` checks for existing article thoughts with the same normalized URL hash for the same user. If found:
- Return the existing thought (no re-scrape)
- Optionally: offer a `force` parameter to re-capture

URL normalization: lowercase scheme + host, remove trailing slash, remove tracking query params (utm_*, fbclid, etc.), sort remaining query params.

## Error Handling

- **Network errors** (timeouts, DNS failures): Job retries up to 3 times with backoff. Root status reflects failure after exhaustion.
- **SSRF protection**: Private IPs, localhost, and non-HTTP(S) schemes rejected at URL validation (before job dispatch) and at fetch time.
- **Oversized pages**: 2MB body cap on HTML fetch. Pages exceeding this are truncated.
- **Empty content**: If `ArticleContentExtractor` produces empty body text, mark status as `scrape_failed` with reason "no content extracted".
- **Paywall / login-required**: Basic HTML fetch won't bypass these. Content will be whatever the public-facing page returns. Future Scrapegraph-ai integration could improve this.

## Future Upgrade Path: Scrapegraph-ai

`ArticleContentExtractor` is isolated from the fetching layer specifically to allow replacement with an LLM-powered extractor. A future upgrade would:
1. Add a Scrapegraph-ai SaaS client or Python sidecar adapter
2. Create an alternative extractor implementation
3. Switch via config or feature flag
4. No changes needed to the rest of the pipeline (jobs, service, MCP tool, UI)

## Testing Strategy

- **Unit tests** for `ArticleContentExtractor` with fixture HTML files (blog post, news article, minimal page, paywall page)
- **Unit tests** for link classification logic (editorial vs. noise)
- **Feature tests** for `ArticleCaptureService` (creates correct thought tree, dispatches correct jobs)
- **Feature tests** for MCP `capture_article` tool
- **Feature tests** for web routes (`GET /articles`, `POST /articles`)
- **Job tests** for `ScrapeArticleContent` and `ClassifyArticleLinks` with mocked HTTP
