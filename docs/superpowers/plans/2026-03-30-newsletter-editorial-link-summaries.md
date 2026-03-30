# Newsletter Editorial Link Summaries Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add queued, reusable editorial-link summaries for newsletter research so email research ignores noise links, summarizes every retained editorial link, and renders grouped usefulness-ranked summaries on the research page.

**Architecture:** Keep `EmailNewsletterResearchService` focused on creating the core research thought and linkage metadata. Add a reusable `ThoughtLinkSummary` persistence layer plus a link-summary dispatch/processing pipeline that classifies newsletter links, stores queued work items, and runs one job per retained editorial link. Render grouped editorial summaries from structured records in `IdeaController::showResearch()` instead of embedding a raw extracted-links dump into the markdown thought body.

**Tech Stack:** Laravel 12, PHP 8.2+, Blade, Laravel queues, Laravel HTTP client, OpenRouter-backed summarization, PHPUnit feature/unit tests via `php artisan test`

---

## File Structure

**Create:**

- `database/migrations/2026_03_30_120000_create_thought_link_summaries_table.php` — durable storage for queued link-summary work items and results.
- `database/factories/ThoughtLinkSummaryFactory.php` — factory for queued/summarized/failed link-summary test fixtures.
- `app/Models/ThoughtLinkSummary.php` — Eloquent model for reusable link summaries linked to an email thought and optional research thought.
- `app/Jobs/ProcessThoughtLinkSummary.php` — queued job that fetches one retained editorial link and writes summary results back to `thought_link_summaries`.
- `app/Services/LinkSummary/NewsletterEditorialLinkCandidateBuilder.php` — newsletter-specific classifier/section-mapper that filters noise and produces per-link queue payloads.
- `app/Services/LinkSummary/LinkSummaryDispatchService.php` — reusable orchestration service that upserts link-summary rows and dispatches `ProcessThoughtLinkSummary`.
- `app/Services/LinkSummary/LinkSummaryFetcher.php` — fetches destination pages and normalizes title/body text for summarization.
- `app/Services/LinkSummary/LinkSummaryGenerator.php` — turns fetched page text + source excerpt into structured summary fields.
- `resources/views/idea/partials/research_editorial_link_summaries.blade.php` — grouped/pending/failed editorial summary renderer for the research page.
- `tests/Unit/Models/ThoughtLinkSummaryTest.php` — schema/model/relation coverage for reusable link-summary records.
- `tests/Unit/Services/LinkSummary/NewsletterEditorialLinkCandidateBuilderTest.php` — noise filtering, dedupe, section mapping, and excerpt extraction coverage.
- `tests/Unit/Services/LinkSummary/LinkSummaryGeneratorTest.php` — summary output normalization and guardrails.
- `tests/Feature/ProcessThoughtLinkSummaryJobTest.php` — queue job success/failure/retry coverage.

**Modify:**

- `app/Services/Email/EmailNewsletterResearchService.php` — stop emitting raw extracted-links markdown in the main research body; keep core research creation and linkage metadata.
- `app/Jobs/ProcessExtraEmailResearch.php` — create newsletter research as today, then queue editorial link summaries after the research thought exists.
- `app/Http/Controllers/EmailResearchController.php` — clear stale link-summary rows when re-triggering newsletter research for the same email.
- `app/Http/Controllers/IdeaController.php` — load grouped `ThoughtLinkSummary` records for research thoughts and pass them into `idea.research_show`.
- `app/Models/Thought.php` — add helper relation/query access for source and research-linked `ThoughtLinkSummary` records.
- `app/Services/OpenRouterService.php` — add one structured summarization method for link summaries (JSON-only response contract).
- `resources/views/idea/research_show.blade.php` — include grouped editorial link summaries beneath the rendered research content.
- `tests/Unit/Services/EmailNewsletterResearchServiceTest.php` — update expectations for the generated markdown body now that raw extracted links move out of the main research presentation.
- `tests/Feature/ProcessExtraEmailResearchJobTest.php` — verify queued summary dispatch and rerun behavior.
- `tests/Feature/EmailResearchControllerTest.php` — verify newsletter requeue clears stale link-summary state.
- `tests/Feature/ResearchShowTest.php` — render grouped summaries, pending counts, and mixed success/failure states.

## Task 1: Create Durable Link Summary Storage

**Files:**

- Create: `tests/Unit/Models/ThoughtLinkSummaryTest.php`
- Create: `database/migrations/2026_03_30_120000_create_thought_link_summaries_table.php`
- Create: `app/Models/ThoughtLinkSummary.php`
- Modify: `app/Models/Thought.php`

- [ ] **Step 1: Write the failing schema/model test**

```php
<?php

namespace Tests\Unit\Models;

use App\Models\Thought;
use App\Models\ThoughtLinkSummary;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ThoughtLinkSummaryTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_stores_queue_state_and_thought_linkage_for_editorial_summaries(): void
    {
        $user = User::factory()->create();
        $emailThought = Thought::factory()->create([
            'user_id' => $user->id,
            'source' => 'email',
        ]);
        $researchThought = Thought::factory()->create([
            'user_id' => $user->id,
            'metadata' => ['type' => 'research', 'tags' => []],
        ]);

        $summary = ThoughtLinkSummary::query()->create([
            'user_id' => $user->id,
            'source_thought_id' => $emailThought->id,
            'parent_research_thought_id' => $researchThought->id,
            'source_type' => 'email_newsletter',
            'original_url' => 'https://example.com/post',
            'normalized_url' => 'https://example.com/post',
            'normalized_url_hash' => sha1('https://example.com/post'),
            'newsletter_section_label' => 'Headlines',
            'newsletter_section_order' => 1,
            'classification' => 'editorial',
            'processing_status' => 'queued',
        ]);

        $this->assertSame($emailThought->id, $summary->sourceThought?->id);
        $this->assertSame($researchThought->id, $summary->parentResearchThought?->id);
        $this->assertSame('queued', $summary->processing_status);
        $this->assertSame('Headlines', $summary->newsletter_section_label);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test tests/Unit/Models/ThoughtLinkSummaryTest.php`

Expected: FAIL with missing table/model/relation errors.

- [ ] **Step 3: Add the migration and model**

Create the table with the minimal v1 fields:

```php
Schema::create('thought_link_summaries', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->constrained()->cascadeOnDelete();
    $table->foreignUuid('source_thought_id')->constrained('thoughts')->cascadeOnDelete();
    $table->foreignUuid('parent_research_thought_id')->nullable()->constrained('thoughts')->nullOnDelete();
    $table->string('source_type', 64);
    $table->string('stored_email_type', 64)->nullable();
    $table->unsignedBigInteger('stored_email_id')->nullable();
    $table->text('original_url');
    $table->text('normalized_url');
    $table->string('normalized_url_hash', 64);
    $table->string('newsletter_section_label', 255)->nullable();
    $table->unsignedInteger('newsletter_section_order')->nullable();
    $table->text('source_excerpt')->nullable();
    $table->string('classification', 32);
    $table->string('processing_status', 32);
    $table->unsignedSmallInteger('fetch_status_code')->nullable();
    $table->string('resolved_title', 1024)->nullable();
    $table->text('summary_text')->nullable();
    $table->string('support_judgment', 32)->nullable();
    $table->text('why_it_matters')->nullable();
    $table->text('quality_notes')->nullable();
    $table->integer('usefulness_score')->nullable();
    $table->unsignedInteger('section_rank')->nullable();
    $table->string('failure_stage', 32)->nullable();
    $table->string('failure_reason', 255)->nullable();
    $table->string('content_fingerprint', 64)->nullable();
    $table->timestamp('processed_at')->nullable();
    $table->timestamps();

    $table->unique(['source_thought_id', 'normalized_url_hash', 'parent_research_thought_id'], 'thought_link_summaries_unique_source_url');
    $table->index(['parent_research_thought_id', 'newsletter_section_order']);
    $table->index(['processing_status', 'classification']);
});
```

Model expectations:

```php
public function sourceThought(): BelongsTo
{
    return $this->belongsTo(Thought::class, 'source_thought_id');
}

public function parentResearchThought(): BelongsTo
{
    return $this->belongsTo(Thought::class, 'parent_research_thought_id');
}
```

- [ ] **Step 3.5: Add the factory used by downstream job tests**

Create `database/factories/ThoughtLinkSummaryFactory.php` with sensible defaults:

```php
public function definition(): array
{
    return [
        'user_id' => User::factory(),
        'source_thought_id' => Thought::factory(),
        'parent_research_thought_id' => null,
        'source_type' => 'email_newsletter',
        'original_url' => 'https://example.com/article',
        'normalized_url' => 'https://example.com/article',
        'normalized_url_hash' => sha1('https://example.com/article'),
        'classification' => 'editorial',
        'processing_status' => 'queued',
    ];
}
```

- [ ] **Step 4: Add the `Thought` helper relations**

Add narrow relations on `Thought`:

```php
public function sourceLinkSummaries(): HasMany
{
    return $this->hasMany(ThoughtLinkSummary::class, 'source_thought_id');
}

public function researchLinkSummaries(): HasMany
{
    return $this->hasMany(ThoughtLinkSummary::class, 'parent_research_thought_id');
}
```

- [ ] **Step 5: Re-run the schema/model test**

Run: `php artisan test tests/Unit/Models/ThoughtLinkSummaryTest.php`

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_03_30_120000_create_thought_link_summaries_table.php database/factories/ThoughtLinkSummaryFactory.php app/Models/ThoughtLinkSummary.php app/Models/Thought.php tests/Unit/Models/ThoughtLinkSummaryTest.php
git commit -m "feat: add queued newsletter link summary storage"
```

## Task 2: Classify Newsletter Links And Map Sections

**Files:**

- Create: `tests/Unit/Services/LinkSummary/NewsletterEditorialLinkCandidateBuilderTest.php`
- Create: `app/Services/LinkSummary/NewsletterEditorialLinkCandidateBuilder.php`

- [ ] **Step 1: Write the failing candidate-builder tests**

Cover these behaviors in one test file:

```php
#[Test]
public function it_keeps_editorial_links_but_filters_social_footer_and_account_noise(): void
{
    $body = <<<'TEXT'
HEADLINES & LAUNCHES

CLAUDE MYTHOS (3 MINUTE READ) [5]
Anthropic is testing a larger Mythos tier.

Want to advertise? [22]
Manage your subscriptions [29]
TEXT;

    $links = [
        ['url' => 'https://links.tldrnewsletter.com/pRgBqs', 'type' => 'generic'],
        ['url' => 'https://advertise.tldr.tech/?utm_source=tldrai', 'type' => 'generic'],
        ['url' => 'https://tldr.tech/ai/manage?email=test@example.com', 'type' => 'generic'],
    ];

    $candidates = app(NewsletterEditorialLinkCandidateBuilder::class)->build($body, $links);

    $this->assertCount(1, $candidates);
    $this->assertSame('editorial', $candidates[0]['classification']);
    $this->assertSame('HEADLINES & LAUNCHES', $candidates[0]['newsletter_section_label']);
}

#[Test]
public function it_assigns_section_order_and_excerpt_from_nearby_newsletter_copy(): void
{
    $body = <<<'TEXT'
HEADLINES & LAUNCHES

CLAUDE MYTHOS (3 MINUTE READ) [5]
'Mythos' is the name for a new tier of Anthropic models.

DEEP DIVES & ANALYSIS

FUNCTION CALLING HARNESS: FROM 6.75% TO 100% (32 MINUTE READ) [8]
AutoBe boosts constrained function calling dramatically.
TEXT;

    $links = [
        ['url' => 'https://links.tldrnewsletter.com/pRgBqs', 'type' => 'generic'],
        ['url' => 'https://autobe.dev/blog/function-calling-harness-qwen-meetup-korea/', 'type' => 'generic'],
    ];

    $candidates = app(NewsletterEditorialLinkCandidateBuilder::class)->build($body, $links);

    $this->assertSame(1, $candidates[0]['newsletter_section_order']);
    $this->assertSame(2, $candidates[1]['newsletter_section_order']);
    $this->assertStringContainsString('Mythos', $candidates[0]['source_excerpt']);
    $this->assertStringContainsString('AutoBe', $candidates[1]['source_excerpt']);
}

#[Test]
public function it_classifies_sponsor_links_separately_from_editorial_links(): void
{
    $body = <<<'TEXT'
TOGETHER WITH [blackduck] [4]
BLACK DUCK SIGNAL: AGENTIC APPSEC BUILT FOR AI-NATIVE DEVELOPMENT (SPONSOR) [4]
TEXT;

    $links = [
        ['url' => 'https://www.blackduck.com/signal-ai-appsec.html', 'type' => 'generic'],
    ];

    $candidates = app(NewsletterEditorialLinkCandidateBuilder::class)->build($body, $links);

    $this->assertCount(1, $candidates);
    $this->assertSame('sponsor', $candidates[0]['classification']);
}

#[Test]
public function it_falls_back_to_uncategorized_editorial_links_when_no_heading_is_available(): void
{
    $body = "Interesting article [5]\nThis article explains a useful agent pattern.";
    $links = [
        ['url' => 'https://example.com/agent-pattern', 'type' => 'generic'],
    ];

    $candidates = app(NewsletterEditorialLinkCandidateBuilder::class)->build($body, $links);

    $this->assertSame('Uncategorized editorial links', $candidates[0]['newsletter_section_label']);
}
```

- [ ] **Step 2: Run the unit tests to verify they fail**

Run: `php artisan test tests/Unit/Services/LinkSummary/NewsletterEditorialLinkCandidateBuilderTest.php`

Expected: FAIL because the builder does not exist yet.

- [ ] **Step 3: Implement the minimal builder**

Implementation rules:

- accept `(string $bodyText, array $links): array`
- classify obvious sponsors before the editorial/noise fallback:
  - nearby `(SPONSOR)` marker
  - `TOGETHER WITH` section context
- classify obvious noise via hostname/path heuristics:
  - `linkedin.com`, `twitter.com`, `x.com`
  - `/unsubscribe`, `/manage`, `/advertise`, `/jobs`
  - referral/reward/account-management paths
- retain `editorial` by default for links referenced in substantive body sections
- preserve `unknown` only when they appear inside body sections, then normalize them to the retained editorial set for v1
- derive section labels by scanning uppercase heading lines and assigning first-appearance order
- default missing headings to `Uncategorized editorial links`
- extract `source_excerpt` from the closest non-empty lines around the retained link mention or the nearest article blurb block
- dedupe by normalized URL, keeping the earliest useful `source_excerpt` in v1 (explicitly defer multiple source-context references)

Suggested output shape:

```php
[
    [
        'original_url' => 'https://links.tldrnewsletter.com/pRgBqs',
        'normalized_url' => 'https://links.tldrnewsletter.com/pRgBqs',
        'normalized_url_hash' => sha1('https://links.tldrnewsletter.com/pRgBqs'),
        'classification' => 'editorial',
        'newsletter_section_label' => 'HEADLINES & LAUNCHES',
        'newsletter_section_order' => 1,
        'source_excerpt' => "'Mythos' is the name for a new tier of Anthropic models.",
    ],
]
```

- [ ] **Step 4: Re-run the builder tests**

Run: `php artisan test tests/Unit/Services/LinkSummary/NewsletterEditorialLinkCandidateBuilderTest.php`

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/LinkSummary/NewsletterEditorialLinkCandidateBuilder.php tests/Unit/Services/LinkSummary/NewsletterEditorialLinkCandidateBuilderTest.php
git commit -m "feat: classify editorial newsletter links"
```

## Task 3: Add The Single-Link Fetch And Summary Job

**Files:**

- Create: `tests/Unit/Services/LinkSummary/LinkSummaryGeneratorTest.php`
- Create: `tests/Feature/ProcessThoughtLinkSummaryJobTest.php`
- Create: `app/Services/LinkSummary/LinkSummaryFetcher.php`
- Create: `app/Services/LinkSummary/LinkSummaryGenerator.php`
- Create: `app/Jobs/ProcessThoughtLinkSummary.php`
- Modify: `app/Services/OpenRouterService.php`

- [ ] **Step 1: Write the failing generator and job tests**

Generator test:

```php
#[Test]
public function it_returns_structured_summary_fields_from_page_text_and_source_excerpt(): void
{
    $openRouter = Mockery::mock(OpenRouterService::class);
    $openRouter->shouldReceive('summarizeLink')->once()->andReturn([
        'title' => 'Claude Mythos',
        'summary_text' => 'Anthropic is testing a larger Mythos tier before release.',
        'support_judgment' => 'supports',
        'why_it_matters' => 'It confirms the email blurb with concrete rollout context.',
        'usefulness_score' => 87,
    ]);

    $service = new LinkSummaryGenerator($openRouter);

    $result = $service->generate(
        fetchedTitle: 'Claude Mythos',
        fetchedText: 'Full page text...',
        sourceExcerpt: "'Mythos' is the name for a new tier...",
    );

    $this->assertSame('supports', $result['support_judgment']);
    $this->assertSame(87, $result['usefulness_score']);
}
```

Job test:

```php
#[Test]
public function job_marks_summary_summarized_when_fetch_and_generation_succeed(): void
{
    Http::fake([
        'https://example.com/article' => Http::response('<html><title>Example</title><body>Useful body text.</body></html>', 200),
    ]);

    $summary = ThoughtLinkSummary::factory()->create([
        'processing_status' => 'queued',
        'classification' => 'editorial',
        'original_url' => 'https://example.com/article',
        'normalized_url' => 'https://example.com/article',
    ]);

    $generator = Mockery::mock(LinkSummaryGenerator::class);
    $generator->shouldReceive('generate')->once()->andReturn([
        'title' => 'Example',
        'summary_text' => 'Useful summary.',
        'support_judgment' => 'supports',
        'why_it_matters' => 'Matches the email framing.',
        'usefulness_score' => 92,
    ]);

    app()->instance(LinkSummaryGenerator::class, $generator);

    (new ProcessThoughtLinkSummary($summary->id))->handle(
        app(LinkSummaryFetcher::class),
        app(LinkSummaryGenerator::class),
    );

    $summary->refresh();
    $this->assertSame('summarized', $summary->processing_status);
    $this->assertSame('Example', $summary->resolved_title);
    $this->assertSame('supports', $summary->support_judgment);
}

#[Test]
public function job_marks_summary_failed_with_fetch_stage_when_destination_request_fails(): void
{
    Http::fake([
        'https://example.com/article' => Http::response('upstream failed', 502),
    ]);

    $summary = ThoughtLinkSummary::factory()->create([
        'processing_status' => 'queued',
        'original_url' => 'https://example.com/article',
        'normalized_url' => 'https://example.com/article',
    ]);

    $generator = Mockery::mock(LinkSummaryGenerator::class);
    $generator->shouldReceive('generate')->never();
    app()->instance(LinkSummaryGenerator::class, $generator);

    (new ProcessThoughtLinkSummary($summary->id))->handle(
        app(LinkSummaryFetcher::class),
        app(LinkSummaryGenerator::class),
    );

    $summary->refresh();
    $this->assertSame('failed', $summary->processing_status);
    $this->assertSame('fetch', $summary->failure_stage);
}

#[Test]
public function job_marks_summary_failed_with_summarize_stage_when_generation_throws(): void
{
    Http::fake([
        'https://example.com/article' => Http::response('<html><title>Example</title><body>Useful body text.</body></html>', 200),
    ]);

    $summary = ThoughtLinkSummary::factory()->create([
        'processing_status' => 'queued',
        'original_url' => 'https://example.com/article',
        'normalized_url' => 'https://example.com/article',
    ]);

    $generator = Mockery::mock(LinkSummaryGenerator::class);
    $generator->shouldReceive('generate')->once()->andThrow(new \RuntimeException('bad model output'));
    app()->instance(LinkSummaryGenerator::class, $generator);

    (new ProcessThoughtLinkSummary($summary->id))->handle(
        app(LinkSummaryFetcher::class),
        app(LinkSummaryGenerator::class),
    );

    $summary->refresh();
    $this->assertSame('failed', $summary->processing_status);
    $this->assertSame('summarize', $summary->failure_stage);
}

#[Test]
public function job_uses_bounded_retries_for_unstable_fetch_or_summary_failures(): void
{
    $job = new ProcessThoughtLinkSummary(123);

    $this->assertSame(3, $job->tries);
    $this->assertSame(60, $job->backoff);
}
```

- [ ] **Step 2: Run the targeted tests to verify they fail**

Run: `php artisan test tests/Unit/Services/LinkSummary/LinkSummaryGeneratorTest.php tests/Feature/ProcessThoughtLinkSummaryJobTest.php`

Expected: FAIL because the fetcher/generator/job/OpenRouter method do not exist yet.

- [ ] **Step 3: Implement the fetcher, generator, and queue job**

Fetcher expectations:

```php
public function fetch(string $url): array
{
    $response = Http::timeout(20)->get($url);
    $status = $response->status();
    $html = (string) $response->body();

    return [
        'status_code' => $status,
        'title' => $this->extractTitle($html),
        'text' => $this->extractVisibleText($html),
        'content_fingerprint' => sha1($html),
    ];
}
```

Generator expectations:

```php
public function generate(string $fetchedTitle, string $fetchedText, string $sourceExcerpt): array
{
    $payload = $this->openRouter->summarizeLink(
        fetchedTitle: $fetchedTitle,
        fetchedText: $fetchedText,
        sourceExcerpt: $sourceExcerpt,
    );

    return [
        'title' => trim((string) $payload['title']),
        'summary_text' => trim((string) $payload['summary_text']),
        'support_judgment' => trim((string) $payload['support_judgment']),
        'why_it_matters' => trim((string) $payload['why_it_matters']),
        'quality_notes' => isset($payload['quality_notes']) ? trim((string) $payload['quality_notes']) : null,
        'usefulness_score' => (int) $payload['usefulness_score'],
    ];
}
```

Job expectations:

- load the `ThoughtLinkSummary`
- bail early if missing or already `summarized`
- mark `processing_status` as `fetching`
- fetch page content
- if content is too thin, mark `failed`
- if content is thin but still barely usable, persist `quality_notes` and allow a lower-confidence success
- generate summary
- persist:
  - `resolved_title`
  - `summary_text`
  - `support_judgment`
  - `why_it_matters`
  - `quality_notes`
  - `usefulness_score`
  - `processing_status = summarized`
  - `processed_at`
- on HTTP failure, set `processing_status = failed`, `failure_stage = fetch`, and `failure_reason`
- on summary-generation failure, set `processing_status = failed`, `failure_stage = summarize`, and `failure_reason`
- set bounded retry policy on the job (`public int $tries = 3; public int $backoff = 60;`) so transient failures retry a few times without looping forever

Redirect handling:

- follow redirects and persist the final destination into either a dedicated `canonical_url` result field or back into `normalized_url` after successful resolution
- if redirect handling proves too noisy, document the v1 deviation explicitly before merging instead of silently ignoring it

OpenRouter contract:

```php
public function summarizeLink(string $fetchedTitle, string $fetchedText, string $sourceExcerpt): array
```

Require JSON-only output with:

```json
{
  "title": "string",
  "summary_text": "string",
  "support_judgment": "supports|adds_context|mostly_tangential|unclear",
  "why_it_matters": "string",
  "quality_notes": "string|null",
  "usefulness_score": 0
}
```

`section_rank` note:

- do not make the job invent `section_rank`
- treat `usefulness_score` as the source of truth
- write `section_rank` only later if the render query needs a denormalized stable order field

- [ ] **Step 4: Re-run the generator/job tests**

Run: `php artisan test tests/Unit/Services/LinkSummary/LinkSummaryGeneratorTest.php tests/Feature/ProcessThoughtLinkSummaryJobTest.php`

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/OpenRouterService.php app/Services/LinkSummary/LinkSummaryFetcher.php app/Services/LinkSummary/LinkSummaryGenerator.php app/Jobs/ProcessThoughtLinkSummary.php tests/Unit/Services/LinkSummary/LinkSummaryGeneratorTest.php tests/Feature/ProcessThoughtLinkSummaryJobTest.php
git commit -m "feat: process queued editorial link summaries"
```

## Task 4: Wire Newsletter Research To Queue Editorial Summaries

**Files:**

- Create: `app/Services/LinkSummary/LinkSummaryDispatchService.php`
- Modify: `app/Services/Email/EmailNewsletterResearchService.php`
- Modify: `app/Jobs/ProcessExtraEmailResearch.php`
- Modify: `tests/Unit/Services/EmailNewsletterResearchServiceTest.php`
- Modify: `tests/Feature/ProcessExtraEmailResearchJobTest.php`

- [ ] **Step 1: Write the failing orchestration tests**

Add one unit assertion to `EmailNewsletterResearchServiceTest` and one job assertion to `ProcessExtraEmailResearchJobTest`.

Research markdown guardrail:

```php
$this->assertStringNotContainsString('## Extracted links', $result['research_thought']->content);
```

Queued-summary dispatch guardrail:

```php
Queue::fake();

// ... create imported email with editorial + noise URLs ...

$job = new ProcessExtraEmailResearch(importedEmailId: $imported->id);
$job->handle(
    app(EmailNewsletterResearchService::class),
    app(EmailLinkExtractor::class),
);

$this->assertDatabaseHas('thought_link_summaries', [
    'source_thought_id' => $emailThought->id,
    'parent_research_thought_id' => $imported->research_thought_id,
    'classification' => 'editorial',
]);

Queue::assertPushed(ProcessThoughtLinkSummary::class, 1);
```

Add one multi-link guardrail in `ProcessExtraEmailResearchJobTest`:

```php
$ordered = ThoughtLinkSummary::query()
    ->where('parent_research_thought_id', $imported->research_thought_id)
    ->orderBy('newsletter_section_order')
    ->pluck('newsletter_section_label')
    ->all();

$this->assertSame(['HEADLINES & LAUNCHES', 'DEEP DIVES & ANALYSIS'], $ordered);
```

- [ ] **Step 2: Run the targeted tests to verify they fail**

Run: `php artisan test tests/Unit/Services/EmailNewsletterResearchServiceTest.php tests/Feature/ProcessExtraEmailResearchJobTest.php`

Expected: FAIL because research still emits `## Extracted links` and no summary rows/jobs exist.

- [ ] **Step 3: Implement `LinkSummaryDispatchService`**

Service shape:

```php
public function queueNewsletterEditorialLinks(
    Thought $emailThought,
    Thought $researchThought,
    ImportedEmail|CapturedInboundEmail $storedEmail,
    string $bodyText,
    array $extractedLinks,
): void
```

Responsibilities:

- build retained editorial candidates with `NewsletterEditorialLinkCandidateBuilder`
- upsert `thought_link_summaries` rows for the current research thought
- write `stored_email_type` / `stored_email_id`
- dispatch `ProcessThoughtLinkSummary` only for rows in the retained summarization set (`editorial` and any v1-promoted `unknown`)
- do not dispatch jobs for `noise` or `sponsor`; if those classifications are persisted for diagnostics, leave them non-queued and excluded from the editorial rendering path

- [ ] **Step 4: Modify `ProcessExtraEmailResearch` to dispatch summaries after research creation**

Right after a successful newsletter research creation:

```php
$researchThought = $result['research_thought'] ?? null;
if ($researchThought instanceof Thought) {
    $this->linkSummaryDispatch->queueNewsletterEditorialLinks(
        $thought,
        $researchThought,
        $stored,
        trim((string) ($stored->body_text ?? '')),
        $links,
    );
}
```

Keep existing newsletter research status handling intact.

- [ ] **Step 5: Update `EmailNewsletterResearchService` to remove the raw extracted-links body section**

Change `buildResearchMarkdown()` so the newsletter research body contains:

- title
- `## Email content`
- `## YouTube transcripts`
- `## Notes` when degraded

Do **not** emit `## Extracted links` in the main research markdown anymore.

- [ ] **Step 6: Re-run the targeted orchestration tests**

Run: `php artisan test tests/Unit/Services/EmailNewsletterResearchServiceTest.php tests/Feature/ProcessExtraEmailResearchJobTest.php`

Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add app/Services/LinkSummary/LinkSummaryDispatchService.php app/Services/Email/EmailNewsletterResearchService.php app/Jobs/ProcessExtraEmailResearch.php tests/Unit/Services/EmailNewsletterResearchServiceTest.php tests/Feature/ProcessExtraEmailResearchJobTest.php
git commit -m "feat: queue editorial summaries after newsletter research"
```

## Task 5: Clear Stale Summary Rows When Re-Triggering Newsletter Research

**Files:**

- Modify: `app/Http/Controllers/EmailResearchController.php`
- Modify: `tests/Feature/EmailResearchControllerTest.php`

- [ ] **Step 1: Write the failing requeue cleanup test**

```php
public function test_newsletter_research_requeue_deletes_stale_link_summary_rows_for_previous_research(): void
{
    Queue::fake();

    $user = User::factory()->create();
    $thought = $this->makeEmailThought($user, [
        'source_metadata' => ['newsletter_research' => ['status' => 'research_completed']],
    ]);
    $email = $this->attachImportedEmail($user, $thought);

    ThoughtLinkSummary::query()->create([
        'user_id' => $user->id,
        'source_thought_id' => $thought->id,
        'parent_research_thought_id' => $email->research_thought_id,
        'source_type' => 'email_newsletter',
        'original_url' => 'https://example.com/old',
        'normalized_url' => 'https://example.com/old',
        'normalized_url_hash' => sha1('https://example.com/old'),
        'classification' => 'editorial',
        'processing_status' => 'summarized',
    ]);

    $this->actingAs($user)->post(route('emails.newsletter-research', $thought))->assertRedirect();

    $this->assertDatabaseCount('thought_link_summaries', 0);
}
```

- [ ] **Step 2: Run the controller test to verify it fails**

Run: `php artisan test tests/Feature/EmailResearchControllerTest.php --filter=stale_link_summary_rows`

Expected: FAIL because the controller currently only resets `research_thought_id` and thought status metadata.

- [ ] **Step 3: Implement the cleanup inside the transaction**

Before nulling the old `research_thought_id`, capture it and delete any linked rows:

```php
$previousResearchThoughtId = $stored->research_thought_id;
if ($previousResearchThoughtId !== null) {
    ThoughtLinkSummary::query()
        ->where('source_thought_id', $thought->id)
        ->where('parent_research_thought_id', $previousResearchThoughtId)
        ->delete();
}
```

- [ ] **Step 4: Re-run the controller test**

Run: `php artisan test tests/Feature/EmailResearchControllerTest.php --filter=stale_link_summary_rows`

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/EmailResearchController.php tests/Feature/EmailResearchControllerTest.php
git commit -m "fix: clear stale newsletter link summaries on requeue"
```

## Task 6: Render Grouped Editorial Summaries On The Research Page

**Files:**

- Create: `resources/views/idea/partials/research_editorial_link_summaries.blade.php`
- Modify: `app/Http/Controllers/IdeaController.php`
- Modify: `resources/views/idea/research_show.blade.php`
- Modify: `tests/Feature/ResearchShowTest.php`

- [ ] **Step 1: Write the failing research-page rendering tests**

Add coverage for:

```php
public function test_research_show_renders_grouped_editorial_link_summaries_in_section_order(): void
{
    $owner = User::factory()->create();
    $emailThought = Thought::factory()->create([
        'user_id' => $owner->id,
        'source' => 'email',
    ]);
    $root = Thought::factory()->create([
        'user_id' => $owner->id,
        'metadata' => ['type' => 'research', 'tags' => []],
        'source_metadata' => ['email_thought_id' => $emailThought->id],
        'content' => "# Research title\n\nBody.",
    ]);

    ThoughtLinkSummary::query()->create([
        'user_id' => $owner->id,
        'source_thought_id' => $emailThought->id,
        'parent_research_thought_id' => $root->id,
        'source_type' => 'email_newsletter',
        'original_url' => 'https://example.com/headline',
        'normalized_url' => 'https://example.com/headline',
        'normalized_url_hash' => sha1('https://example.com/headline'),
        'newsletter_section_label' => 'Headlines',
        'newsletter_section_order' => 1,
        'classification' => 'editorial',
        'processing_status' => 'summarized',
        'resolved_title' => 'Headline article',
        'summary_text' => 'Headline summary.',
        'support_judgment' => 'supports',
        'why_it_matters' => 'Explains why this matters.',
        'usefulness_score' => 95,
        'section_rank' => 1,
    ]);

    $response = $this->actingAs($owner)->get(route('idea.research.show', $root));

    $response->assertOk();
    $response->assertSee('Editorial link summaries', false);
    $response->assertSee('Headlines', false);
    $response->assertSee('Headline article', false);
    $response->assertSee('Headline summary.', false);
    $response->assertSee('supports', false);
}

public function test_research_show_displays_pending_count_for_unsummarized_editorial_links(): void
{
    $owner = User::factory()->create();
    $emailThought = Thought::factory()->create([
        'user_id' => $owner->id,
        'source' => 'email',
    ]);
    $root = Thought::factory()->create([
        'user_id' => $owner->id,
        'metadata' => ['type' => 'research', 'tags' => []],
        'source_metadata' => ['email_thought_id' => $emailThought->id],
        'content' => "# Research title\n\nBody.",
    ]);

    ThoughtLinkSummary::factory()->create([
        'user_id' => $owner->id,
        'source_thought_id' => $emailThought->id,
        'parent_research_thought_id' => $root->id,
        'newsletter_section_label' => 'Headlines',
        'newsletter_section_order' => 1,
        'processing_status' => 'queued',
    ]);

    $response = $this->actingAs($owner)->get(route('idea.research.show', $root));

    $response->assertOk();
    $response->assertSee('1 editorial link still processing', false);
}

public function test_research_show_orders_items_within_section_by_usefulness_score(): void
{
    $owner = User::factory()->create();
    $emailThought = Thought::factory()->create([
        'user_id' => $owner->id,
        'source' => 'email',
    ]);
    $root = Thought::factory()->create([
        'user_id' => $owner->id,
        'metadata' => ['type' => 'research', 'tags' => []],
        'source_metadata' => ['email_thought_id' => $emailThought->id],
        'content' => "# Research title\n\nBody.",
    ]);

    ThoughtLinkSummary::factory()->create([
        'user_id' => $owner->id,
        'source_thought_id' => $emailThought->id,
        'parent_research_thought_id' => $root->id,
        'newsletter_section_label' => 'Headlines',
        'newsletter_section_order' => 1,
        'processing_status' => 'summarized',
        'resolved_title' => 'Higher scored article',
        'summary_text' => 'Higher score summary.',
        'support_judgment' => 'supports',
        'why_it_matters' => 'High score.',
        'usefulness_score' => 95,
    ]);

    ThoughtLinkSummary::factory()->create([
        'user_id' => $owner->id,
        'source_thought_id' => $emailThought->id,
        'parent_research_thought_id' => $root->id,
        'newsletter_section_label' => 'Headlines',
        'newsletter_section_order' => 1,
        'processing_status' => 'summarized',
        'resolved_title' => 'Lower scored article',
        'summary_text' => 'Lower score summary.',
        'support_judgment' => 'supports',
        'why_it_matters' => 'Low score.',
        'usefulness_score' => 40,
    ]);

    $response = $this->actingAs($owner)->get(route('idea.research.show', $root));

    $response->assertOk();
    $content = $response->getContent();
    $this->assertLessThan(
        strpos($content, 'Lower scored article'),
        strpos($content, 'Higher scored article')
    );
}

public function test_research_show_does_not_render_noise_or_sponsor_rows_in_editorial_summary_block(): void
{
    $owner = User::factory()->create();
    $emailThought = Thought::factory()->create([
        'user_id' => $owner->id,
        'source' => 'email',
    ]);
    $root = Thought::factory()->create([
        'user_id' => $owner->id,
        'metadata' => ['type' => 'research', 'tags' => []],
        'source_metadata' => ['email_thought_id' => $emailThought->id],
        'content' => "# Research title\n\nBody.",
    ]);

    ThoughtLinkSummary::factory()->create([
        'user_id' => $owner->id,
        'source_thought_id' => $emailThought->id,
        'parent_research_thought_id' => $root->id,
        'classification' => 'editorial',
        'processing_status' => 'summarized',
        'resolved_title' => 'Editorial article',
        'summary_text' => 'Editorial summary.',
        'support_judgment' => 'supports',
        'why_it_matters' => 'Editorial importance.',
    ]);

    ThoughtLinkSummary::factory()->create([
        'user_id' => $owner->id,
        'source_thought_id' => $emailThought->id,
        'parent_research_thought_id' => $root->id,
        'classification' => 'noise',
        'processing_status' => 'summarized',
        'resolved_title' => 'Manage subscriptions',
    ]);

    ThoughtLinkSummary::factory()->create([
        'user_id' => $owner->id,
        'source_thought_id' => $emailThought->id,
        'parent_research_thought_id' => $root->id,
        'classification' => 'sponsor',
        'processing_status' => 'summarized',
        'resolved_title' => 'Sponsor article',
    ]);

    $response = $this->actingAs($owner)->get(route('idea.research.show', $root));

    $response->assertOk();
    $response->assertSee('Editorial article', false);
    $response->assertDontSee('Manage subscriptions', false);
    $response->assertDontSee('Sponsor article', false);
}
```

- [ ] **Step 2: Run the research-page tests to verify they fail**

Run: `php artisan test tests/Feature/ResearchShowTest.php --filter=editorial_link_summaries`

Expected: FAIL because the controller/view do not load or render link-summary rows yet.

- [ ] **Step 3: Load grouped summary data in `IdeaController::showResearch()`**

Add a small helper:

```php
private function buildResearchEditorialLinkSummaryViewModel(Thought $root): array
{
    $rows = $root->researchLinkSummaries()
        ->where('classification', 'editorial')
        ->orderBy('newsletter_section_order')
        ->orderByDesc('usefulness_score')
        ->orderBy('section_rank')
        ->get();

    // group rows by section label and count queued/failed states
}
```

Pass a view model shaped like:

```php
[
    'groups' => [
        [
            'label' => 'Headlines',
            'items' => [...],
        ],
    ],
    'pending_count' => 1,
    'failed_count' => 0,
]
```

Filter the main v1 display to editorial rows only:

```php
$rows = $root->researchLinkSummaries()
    ->where('classification', 'editorial')
    ->orderBy('newsletter_section_order')
    ->orderByDesc('usefulness_score')
    ->orderBy('section_rank')
    ->get();
```

Compute `pending_count` and `failed_count` from all retained rows linked to the research thought, while excluding `noise` and `sponsor` rows from the rendered item list. Treat `queued` and `fetching` rows as pending for the page-level progress note.

- [ ] **Step 4: Add the Blade partial and include it from `research_show.blade.php`**

Render:

- section heading `Editorial link summaries`
- groups in `newsletter_section_order`
- items sorted by usefulness
- title, URL, summary, relation label, and why-it-matters text
- subdued `quality_notes` copy when a summarized row is low-confidence or partial
- compact pending note when `pending_count > 0`
- compact failed note when `failed_count > 0`

- [ ] **Step 5: Re-run the research rendering tests**

Run: `php artisan test tests/Feature/ResearchShowTest.php --filter=editorial_link_summaries`

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/IdeaController.php resources/views/idea/research_show.blade.php resources/views/idea/partials/research_editorial_link_summaries.blade.php tests/Feature/ResearchShowTest.php
git commit -m "feat: render grouped editorial link summaries on research page"
```

## Task 7: Final Verification

**Files:**

- Modify: none
- Test: `tests/Unit/Models/ThoughtLinkSummaryTest.php`
- Test: `tests/Unit/Services/LinkSummary/NewsletterEditorialLinkCandidateBuilderTest.php`
- Test: `tests/Unit/Services/LinkSummary/LinkSummaryGeneratorTest.php`
- Test: `tests/Unit/Services/EmailNewsletterResearchServiceTest.php`
- Test: `tests/Feature/ProcessThoughtLinkSummaryJobTest.php`
- Test: `tests/Feature/ProcessExtraEmailResearchJobTest.php`
- Test: `tests/Feature/EmailResearchControllerTest.php`
- Test: `tests/Feature/ResearchShowTest.php`

- [ ] **Step 1: Run the focused automated test set**

Run: `php artisan test tests/Unit/Models/ThoughtLinkSummaryTest.php tests/Unit/Services/LinkSummary/NewsletterEditorialLinkCandidateBuilderTest.php tests/Unit/Services/LinkSummary/LinkSummaryGeneratorTest.php tests/Unit/Services/EmailNewsletterResearchServiceTest.php tests/Feature/ProcessThoughtLinkSummaryJobTest.php tests/Feature/ProcessExtraEmailResearchJobTest.php tests/Feature/EmailResearchControllerTest.php tests/Feature/ResearchShowTest.php`

Expected: PASS.

- [ ] **Step 2: Run a broader research/email regression pass**

Run: `php artisan test --filter=Research`

Expected: PASS, including existing email research, research show, and preview behaviors.

- [ ] **Step 3: Manually verify the full flow**

Check:

- a newsletter email still creates its core research thought
- noise links do not appear in the main research presentation
- retained editorial links appear under the correct newsletter sections
- every retained editorial link eventually shows a summary row
- items are ordered by usefulness within each section
- re-triggering newsletter research clears stale summary rows and repopulates them
- queued or failed links show compact status notes rather than breaking the page
- sponsor and noise rows never appear in the main editorial summary block
- fetch-stage and summarize-stage failures remain distinguishable in stored records

- [ ] **Step 4: Commit final cleanup**

```bash
git add database/factories/ThoughtLinkSummaryFactory.php app/Http/Controllers/EmailResearchController.php app/Http/Controllers/IdeaController.php app/Jobs/ProcessExtraEmailResearch.php app/Jobs/ProcessThoughtLinkSummary.php app/Models/Thought.php app/Models/ThoughtLinkSummary.php app/Services/Email/EmailNewsletterResearchService.php app/Services/LinkSummary/NewsletterEditorialLinkCandidateBuilder.php app/Services/LinkSummary/LinkSummaryDispatchService.php app/Services/LinkSummary/LinkSummaryFetcher.php app/Services/LinkSummary/LinkSummaryGenerator.php app/Services/OpenRouterService.php resources/views/idea/research_show.blade.php resources/views/idea/partials/research_editorial_link_summaries.blade.php tests/Unit/Models/ThoughtLinkSummaryTest.php tests/Unit/Services/LinkSummary/NewsletterEditorialLinkCandidateBuilderTest.php tests/Unit/Services/LinkSummary/LinkSummaryGeneratorTest.php tests/Unit/Services/EmailNewsletterResearchServiceTest.php tests/Feature/ProcessThoughtLinkSummaryJobTest.php tests/Feature/ProcessExtraEmailResearchJobTest.php tests/Feature/EmailResearchControllerTest.php tests/Feature/ResearchShowTest.php
git commit -m "feat: add queued editorial link summaries for newsletters"
```
