# Newsletter Body Analysis Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add async AI-generated analysis (summary, key points, positives/negatives, highlights) to newsletter research, rendered above the raw email body.

**Architecture:** A new `ProcessNewsletterBodyAnalysis` queued job fires after newsletter research creation, calls `OpenRouterService::analyzeNewsletter()`, stores results in a dedicated `newsletter_analyses` table, and the research view renders the analysis block from that record.

**Tech Stack:** Laravel queued jobs, Eloquent, OpenRouter API (via `Http::fake()` in tests), Blade templates, PHPUnit with `RefreshDatabase`.

---

## File map

| Action | Path |
|---|---|
| Create | `database/migrations/2026_04_05_000001_create_newsletter_analyses_table.php` |
| Create | `app/Models/NewsletterAnalysis.php` |
| Modify | `app/Services/OpenRouterService.php` |
| Create | `app/Services/NewsletterAnalysis/NewsletterAnalysisGenerator.php` |
| Create | `app/Jobs/ProcessNewsletterBodyAnalysis.php` |
| Modify | `app/Jobs/ProcessExtraEmailResearch.php` |
| Modify | `app/Http/Controllers/IdeaController.php` |
| Create | `resources/views/idea/partials/research_newsletter_analysis.blade.php` |
| Modify | `resources/views/idea/research_show.blade.php` |
| Create | `tests/Unit/Services/NewsletterAnalysis/NewsletterAnalysisGeneratorTest.php` |
| Modify | `tests/Unit/Services/OpenRouterServiceTest.php` |
| Create | `tests/Feature/ProcessNewsletterBodyAnalysisJobTest.php` |
| Modify | `tests/Feature/ProcessExtraEmailResearchJobTest.php` |
| Create | `tests/Feature/NewsletterAnalysisRenderingTest.php` |

---

### Task 1: Migration and Model

**Files:**
- Create: `database/migrations/2026_04_05_000001_create_newsletter_analyses_table.php`
- Create: `app/Models/NewsletterAnalysis.php`

- [ ] **Step 1: Create the migration**

```php
// database/migrations/2026_04_05_000001_create_newsletter_analyses_table.php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('newsletter_analyses', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('research_thought_id')->unique()->constrained('thoughts')->cascadeOnDelete();
            $table->foreignUuid('source_thought_id')->constrained('thoughts')->cascadeOnDelete();
            $table->string('stored_email_type', 64)->nullable();
            $table->unsignedBigInteger('stored_email_id')->nullable();
            $table->string('status', 32)->default('queued');
            $table->text('summary')->nullable();
            $table->json('key_points')->nullable();
            $table->json('positives_mentioned')->nullable();
            $table->json('negatives_mentioned')->nullable();
            $table->json('highlights')->nullable();
            $table->text('quality_notes')->nullable();
            $table->string('failure_reason', 255)->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('newsletter_analyses');
    }
};
```

- [ ] **Step 2: Create the model**

```php
// app/Models/NewsletterAnalysis.php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NewsletterAnalysis extends Model
{
    protected $fillable = [
        'research_thought_id',
        'source_thought_id',
        'stored_email_type',
        'stored_email_id',
        'status',
        'summary',
        'key_points',
        'positives_mentioned',
        'negatives_mentioned',
        'highlights',
        'quality_notes',
        'failure_reason',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'key_points' => 'array',
            'positives_mentioned' => 'array',
            'negatives_mentioned' => 'array',
            'highlights' => 'array',
            'completed_at' => 'datetime',
        ];
    }

    public function researchThought(): BelongsTo
    {
        return $this->belongsTo(Thought::class, 'research_thought_id');
    }

    public function sourceThought(): BelongsTo
    {
        return $this->belongsTo(Thought::class, 'source_thought_id');
    }
}
```

- [ ] **Step 3: Run the migration**

```bash
php artisan migrate
```

Expected: Migration runs without errors; `newsletter_analyses` table created.

- [ ] **Step 4: Commit**

```bash
git add database/migrations/2026_04_05_000001_create_newsletter_analyses_table.php app/Models/NewsletterAnalysis.php
git commit -m "feat(newsletter-analysis): add newsletter_analyses migration and model"
```

---

### Task 2: `OpenRouterService::analyzeNewsletter()`

**Files:**
- Modify: `app/Services/OpenRouterService.php`
- Modify: `tests/Unit/Services/OpenRouterServiceTest.php`

- [ ] **Step 1: Write the failing tests**

Add these two tests to the existing `tests/Unit/Services/OpenRouterServiceTest.php` class (inside the class, after the last existing `#[Test]` method):

```php
#[Test]
public function analyze_newsletter_returns_structured_analysis(): void
{
    Config::set('services.openrouter.api_key', 'test-api-key');
    Config::set('services.openrouter.metadata_model', 'openai/gpt-4o-mini');

    $json = json_encode([
        'summary' => 'A weekly roundup covering AI tooling and developer productivity.',
        'key_points' => ['OpenAI released GPT-5', 'Cursor raised Series B'],
        'positives_mentioned' => ['Bullish on AI coding assistants', 'Well-structured overview'],
        'negatives_mentioned' => ['Sceptical of AGI timelines', 'Lacks citations for key claims'],
        'highlights' => ['Subscriber count now 50k'],
        'quality_notes' => null,
    ], JSON_THROW_ON_ERROR);

    Http::fake([
        'https://openrouter.ai/api/v1/chat/completions' => Http::response([
            'choices' => [['message' => ['content' => $json]]],
        ], 200),
    ]);

    $result = $this->service->analyzeNewsletter(
        subject: 'AI Weekly #42',
        body: str_repeat('Substantive newsletter body paragraph. ', 30),
    );

    $this->assertSame('A weekly roundup covering AI tooling and developer productivity.', $result['summary']);
    $this->assertSame(['OpenAI released GPT-5', 'Cursor raised Series B'], $result['key_points']);
    $this->assertSame(['Bullish on AI coding assistants', 'Well-structured overview'], $result['positives_mentioned']);
    $this->assertSame(['Sceptical of AGI timelines', 'Lacks citations for key claims'], $result['negatives_mentioned']);
    $this->assertSame(['Subscriber count now 50k'], $result['highlights']);
    $this->assertNull($result['quality_notes']);

    Http::assertSent(function ($request) {
        $messages = $request->data()['messages'] ?? [];
        $user = collect($messages)->firstWhere('role', 'user');
        $system = collect($messages)->firstWhere('role', 'system');

        return $request->url() === 'https://openrouter.ai/api/v1/chat/completions'
            && str_contains((string) ($user['content'] ?? ''), 'AI Weekly #42')
            && str_contains((string) ($system['content'] ?? ''), 'positives_mentioned');
    });
}

#[Test]
public function analyze_newsletter_appends_truncation_note_to_quality_notes_when_body_exceeds_limit(): void
{
    Config::set('services.openrouter.api_key', 'test-api-key');
    Config::set('services.openrouter.metadata_model', 'openai/gpt-4o-mini');

    $json = json_encode([
        'summary' => 'Summary of truncated body.',
        'key_points' => [],
        'positives_mentioned' => [],
        'negatives_mentioned' => [],
        'highlights' => [],
        'quality_notes' => null,
    ], JSON_THROW_ON_ERROR);

    Http::fake([
        'https://openrouter.ai/api/v1/chat/completions' => Http::response([
            'choices' => [['message' => ['content' => $json]]],
        ], 200),
    ]);

    // Body that exceeds the 8,000-character truncation limit
    $longBody = str_repeat('x', 9_000);

    $result = $this->service->analyzeNewsletter(subject: 'Long newsletter', body: $longBody);

    $this->assertNotNull($result['quality_notes']);
    $this->assertStringContainsString('truncated', $result['quality_notes']);

    Http::assertSent(function ($request) {
        $messages = $request->data()['messages'] ?? [];
        $user = collect($messages)->firstWhere('role', 'user');
        $body = (string) ($user['content'] ?? '');

        // Truncated body should be shorter than the original 9,000 chars
        return mb_strlen($body) < 9_200;
    });
}
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
php artisan test tests/Unit/Services/OpenRouterServiceTest.php --filter="analyze_newsletter"
```

Expected: FAIL with "Call to undefined method ... analyzeNewsletter"

- [ ] **Step 3: Add `analyzeNewsletter()` to `OpenRouterService`**

Add this method to `app/Services/OpenRouterService.php` after the `summarizeLink()` method (before the closing `}`):

```php
/**
 * Analyse a newsletter email body and return a structured summary.
 *
 * @return array{
 *     summary: string,
 *     key_points: list<string>,
 *     positives_mentioned: list<string>,
 *     negatives_mentioned: list<string>,
 *     highlights: list<string>,
 *     quality_notes: ?string
 * }
 *
 * @throws RequestException On HTTP errors
 * @throws \RuntimeException If OPENROUTER_API_KEY is not set or JSON is invalid
 */
public function analyzeNewsletter(string $subject, string $body): array
{
    $apiKey = config('services.openrouter.api_key');
    if (empty($apiKey)) {
        throw new \RuntimeException('OPENROUTER_API_KEY is not set.');
    }

    $model = config('services.openrouter.metadata_model', 'openai/gpt-4o-mini');

    $maxChars = 8_000;
    $truncated = mb_strlen($body) > $maxChars;
    if ($truncated) {
        $truncatedBody = mb_substr($body, 0, $maxChars);
        $lastParagraph = mb_strrpos($truncatedBody, "\n\n");
        if ($lastParagraph !== false && $lastParagraph > (int) ($maxChars * 0.8)) {
            $truncatedBody = mb_substr($truncatedBody, 0, $lastParagraph);
        }
    } else {
        $truncatedBody = $body;
    }

    $systemPrompt = <<<'PROMPT'
You analyse newsletter emails for an editor. Reply with only a single JSON object (no markdown fences, no explanation) with these keys:
- "summary" (string): a 2–4 sentence neutral overview of what this newsletter covers, its topics, framing, and scope
- "key_points" (array of strings): the main claims, findings, or stories the author highlights — one string per point
- "positives_mentioned" (array of strings): capture both (a) things the newsletter author is positive about, bullish on, or praising (e.g. "Bullish on X", "Praises Y approach") AND (b) quality strengths of the newsletter itself (e.g. "Well-sourced claims", "Clear structure") — one string per item
- "negatives_mentioned" (array of strings): capture both (a) things the author is critical or sceptical about (e.g. "Critical of Z policy", "Sceptical of Y trend") AND (b) quality weaknesses of the newsletter itself (e.g. "Surface-level on X", "Lacks evidence for claim Y") — one string per item
- "highlights" (array of strings): anything else pertinent — notable data points, surprising assertions, calls to action, recurring themes — that does not fit the above fields; use an empty array if nothing stands out
- "quality_notes" (string or null): caveats only — thin content, truncated body, unclear authorship, marketing-heavy; null if none

Write in British English. Factual, neutral, analytical tone. No padding.
PROMPT;

    $userContent = 'Subject: '.trim($subject)."\n\nNewsletter body:\n".trim($truncatedBody);

    $response = Http::withToken($apiKey)
        ->timeout(60)
        ->post(self::CHAT_URL, [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userContent],
            ],
            'max_tokens' => 1024,
        ]);

    $response->throw();

    $content = $response->json('choices.0.message.content');
    if ($content === null || $content === '') {
        throw new \RuntimeException('OpenRouter newsletter analysis response missing choices[0].message.content.');
    }

    $content = trim((string) $content);
    if (str_starts_with($content, '```')) {
        $content = preg_replace('/^```(?:json)?\s*|\s*```$/s', '', $content) ?? $content;
        $content = trim($content);
    }

    $decoded = json_decode($content, true);
    if (! is_array($decoded)) {
        throw new \RuntimeException('OpenRouter newsletter analysis response was not valid JSON.');
    }

    $quality = $decoded['quality_notes'] ?? null;
    $quality = ($quality !== null && $quality !== '') ? (is_string($quality) ? trim($quality) : null) : null;

    if ($truncated) {
        $truncationNote = 'Newsletter body was truncated before analysis; some content may be missing.';
        $quality = $quality !== null ? $truncationNote.' '.$quality : $truncationNote;
    }

    $toStringArray = static function (mixed $value): array {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map('strval', $value)));
    };

    return [
        'summary' => is_string($decoded['summary'] ?? null) ? trim($decoded['summary']) : '',
        'key_points' => $toStringArray($decoded['key_points'] ?? null),
        'positives_mentioned' => $toStringArray($decoded['positives_mentioned'] ?? null),
        'negatives_mentioned' => $toStringArray($decoded['negatives_mentioned'] ?? null),
        'highlights' => $toStringArray($decoded['highlights'] ?? null),
        'quality_notes' => $quality,
    ];
}
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
php artisan test tests/Unit/Services/OpenRouterServiceTest.php --filter="analyze_newsletter"
```

Expected: 2 PASSED

- [ ] **Step 5: Commit**

```bash
git add app/Services/OpenRouterService.php tests/Unit/Services/OpenRouterServiceTest.php
git commit -m "feat(newsletter-analysis): add OpenRouterService::analyzeNewsletter()"
```

---

### Task 3: `NewsletterAnalysisGenerator` service

**Files:**
- Create: `app/Services/NewsletterAnalysis/NewsletterAnalysisGenerator.php`
- Create: `tests/Unit/Services/NewsletterAnalysis/NewsletterAnalysisGeneratorTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Services/NewsletterAnalysis/NewsletterAnalysisGeneratorTest.php`:

```php
<?php

namespace Tests\Unit\Services\NewsletterAnalysis;

use App\Services\NewsletterAnalysis\NewsletterAnalysisGenerator;
use App\Services\OpenRouterService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class NewsletterAnalysisGeneratorTest extends TestCase
{
    #[Test]
    public function generate_delegates_to_openrouter_analyze_newsletter(): void
    {
        Config::set('services.openrouter.api_key', 'test-key');
        Config::set('services.openrouter.metadata_model', 'openai/gpt-4o-mini');

        $json = json_encode([
            'summary' => 'The newsletter covers fintech regulation.',
            'key_points' => ['FCA proposes new rules'],
            'positives_mentioned' => ['Clear writing', 'Praises open banking'],
            'negatives_mentioned' => ['Critical of big banks'],
            'highlights' => ['200k subscribers milestone'],
            'quality_notes' => null,
        ], JSON_THROW_ON_ERROR);

        Http::fake([
            'https://openrouter.ai/api/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => $json]]],
            ], 200),
        ]);

        $generator = new NewsletterAnalysisGenerator(new OpenRouterService);

        $result = $generator->generate(
            subject: 'Fintech Weekly #10',
            body: str_repeat('Fintech newsletter body. ', 20),
        );

        $this->assertSame('The newsletter covers fintech regulation.', $result['summary']);
        $this->assertSame(['FCA proposes new rules'], $result['key_points']);
        $this->assertSame(['Clear writing', 'Praises open banking'], $result['positives_mentioned']);
        $this->assertSame(['Critical of big banks'], $result['negatives_mentioned']);
        $this->assertSame(['200k subscribers milestone'], $result['highlights']);
        $this->assertNull($result['quality_notes']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
php artisan test tests/Unit/Services/NewsletterAnalysis/NewsletterAnalysisGeneratorTest.php
```

Expected: FAIL with "Class ... not found"

- [ ] **Step 3: Create the service**

```php
// app/Services/NewsletterAnalysis/NewsletterAnalysisGenerator.php
<?php

namespace App\Services\NewsletterAnalysis;

use App\Services\OpenRouterService;

class NewsletterAnalysisGenerator
{
    public function __construct(
        private readonly OpenRouterService $openRouter,
    ) {}

    /**
     * @return array{
     *     summary: string,
     *     key_points: list<string>,
     *     positives_mentioned: list<string>,
     *     negatives_mentioned: list<string>,
     *     highlights: list<string>,
     *     quality_notes: ?string
     * }
     */
    public function generate(string $subject, string $body): array
    {
        return $this->openRouter->analyzeNewsletter($subject, $body);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

```bash
php artisan test tests/Unit/Services/NewsletterAnalysis/NewsletterAnalysisGeneratorTest.php
```

Expected: 1 PASSED

- [ ] **Step 5: Commit**

```bash
git add app/Services/NewsletterAnalysis/NewsletterAnalysisGenerator.php tests/Unit/Services/NewsletterAnalysis/NewsletterAnalysisGeneratorTest.php
git commit -m "feat(newsletter-analysis): add NewsletterAnalysisGenerator service"
```

---

### Task 4: `ProcessNewsletterBodyAnalysis` job

**Files:**
- Create: `app/Jobs/ProcessNewsletterBodyAnalysis.php`
- Create: `tests/Feature/ProcessNewsletterBodyAnalysisJobTest.php`

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/ProcessNewsletterBodyAnalysisJobTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Jobs\ProcessNewsletterBodyAnalysis;
use App\Models\ImportedEmail;
use App\Models\MailAccount;
use App\Models\NewsletterAnalysis;
use App\Models\Thought;
use App\Models\User;
use App\Services\NewsletterAnalysis\NewsletterAnalysisGenerator;
use App\Services\OpenRouterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProcessNewsletterBodyAnalysisJobTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function fakeGeneratorResult(): array
    {
        return [
            'summary' => 'A fintech newsletter covering payments and regulation.',
            'key_points' => ['FCA proposes new rules', 'Stripe expands to Africa'],
            'positives_mentioned' => ['Bullish on open banking'],
            'negatives_mentioned' => ['Critical of incumbents'],
            'highlights' => ['100k subscriber milestone'],
            'quality_notes' => null,
        ];
    }

    #[Test]
    public function constructor_rejects_both_email_identifier_types(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Exactly one of importedEmailId or capturedInboundEmailId must be set.');

        new ProcessNewsletterBodyAnalysis(
            researchThoughtId: 'res-uuid',
            sourceThoughtId: 'src-uuid',
            importedEmailId: 1,
            capturedInboundEmailId: 2,
        );
    }

    #[Test]
    public function constructor_rejects_neither_email_identifier_type(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Exactly one of importedEmailId or capturedInboundEmailId must be set.');

        new ProcessNewsletterBodyAnalysis(
            researchThoughtId: 'res-uuid',
            sourceThoughtId: 'src-uuid',
        );
    }

    #[Test]
    public function job_creates_completed_analysis_for_valid_email(): void
    {
        $user = User::factory()->create();
        $account = MailAccount::factory()->create(['user_id' => $user->id]);
        $imported = ImportedEmail::query()->create([
            'user_id' => $user->id,
            'mail_account_id' => $account->id,
            'provider' => 'fastmail',
            'provider_message_id' => 'analysis-test-1',
            'direction' => 'received',
            'subject' => 'Fintech Weekly',
            'body_text' => str_repeat('Fintech newsletter body paragraph. ', 30),
            'from_json' => [['email' => 'news@fintech.com', 'name' => 'Fintech']],
            'processing_status' => 'research_completed',
        ]);

        $emailThought = Thought::factory()->create(['user_id' => $user->id, 'source' => 'email']);
        $researchThought = Thought::factory()->create(['user_id' => $user->id, 'source' => 'research']);

        $generator = Mockery::mock(NewsletterAnalysisGenerator::class);
        $generator->shouldReceive('generate')
            ->once()
            ->with('Fintech Weekly', Mockery::type('string'))
            ->andReturn($this->fakeGeneratorResult());
        $this->app->instance(NewsletterAnalysisGenerator::class, $generator);

        $job = new ProcessNewsletterBodyAnalysis(
            researchThoughtId: (string) $researchThought->id,
            sourceThoughtId: (string) $emailThought->id,
            importedEmailId: $imported->id,
        );
        $job->handle(app(NewsletterAnalysisGenerator::class));

        $analysis = NewsletterAnalysis::query()
            ->where('research_thought_id', $researchThought->id)
            ->first();

        $this->assertNotNull($analysis);
        $this->assertSame('completed', $analysis->status);
        $this->assertSame('A fintech newsletter covering payments and regulation.', $analysis->summary);
        $this->assertSame(['FCA proposes new rules', 'Stripe expands to Africa'], $analysis->key_points);
        $this->assertSame(['Bullish on open banking'], $analysis->positives_mentioned);
        $this->assertSame(['Critical of incumbents'], $analysis->negatives_mentioned);
        $this->assertSame(['100k subscriber milestone'], $analysis->highlights);
        $this->assertNull($analysis->quality_notes);
        $this->assertNotNull($analysis->completed_at);
        $this->assertSame('imported_email', $analysis->stored_email_type);
        $this->assertSame($imported->id, (int) $analysis->stored_email_id);
    }

    #[Test]
    public function job_marks_analysis_failed_when_body_is_too_short(): void
    {
        $user = User::factory()->create();
        $account = MailAccount::factory()->create(['user_id' => $user->id]);
        $imported = ImportedEmail::query()->create([
            'user_id' => $user->id,
            'mail_account_id' => $account->id,
            'provider' => 'fastmail',
            'provider_message_id' => 'analysis-short-body',
            'direction' => 'received',
            'subject' => 'Short',
            'body_text' => 'Hi.',
            'from_json' => [['email' => 'x@x.com']],
            'processing_status' => 'research_completed',
        ]);

        $emailThought = Thought::factory()->create(['user_id' => $user->id]);
        $researchThought = Thought::factory()->create(['user_id' => $user->id]);

        $generator = Mockery::mock(NewsletterAnalysisGenerator::class);
        $generator->shouldReceive('generate')->never();
        $this->app->instance(NewsletterAnalysisGenerator::class, $generator);

        $job = new ProcessNewsletterBodyAnalysis(
            researchThoughtId: (string) $researchThought->id,
            sourceThoughtId: (string) $emailThought->id,
            importedEmailId: $imported->id,
        );
        $job->handle(app(NewsletterAnalysisGenerator::class));

        $analysis = NewsletterAnalysis::query()
            ->where('research_thought_id', $researchThought->id)
            ->first();

        $this->assertNotNull($analysis);
        $this->assertSame('failed', $analysis->status);
        $this->assertSame('body_too_short', $analysis->failure_reason);
    }

    #[Test]
    public function job_skips_when_completed_analysis_already_exists(): void
    {
        $user = User::factory()->create();
        $account = MailAccount::factory()->create(['user_id' => $user->id]);
        $imported = ImportedEmail::query()->create([
            'user_id' => $user->id,
            'mail_account_id' => $account->id,
            'provider' => 'fastmail',
            'provider_message_id' => 'analysis-idempotent',
            'direction' => 'received',
            'subject' => 'Existing',
            'body_text' => str_repeat('Body content. ', 20),
            'from_json' => [['email' => 'x@x.com']],
            'processing_status' => 'research_completed',
        ]);

        $emailThought = Thought::factory()->create(['user_id' => $user->id]);
        $researchThought = Thought::factory()->create(['user_id' => $user->id]);

        NewsletterAnalysis::query()->create([
            'research_thought_id' => $researchThought->id,
            'source_thought_id' => $emailThought->id,
            'stored_email_type' => 'imported_email',
            'stored_email_id' => $imported->id,
            'status' => 'completed',
            'summary' => 'Existing summary.',
            'key_points' => ['Existing point'],
            'positives_mentioned' => [],
            'negatives_mentioned' => [],
            'highlights' => [],
            'completed_at' => now(),
        ]);

        $generator = Mockery::mock(NewsletterAnalysisGenerator::class);
        $generator->shouldReceive('generate')->never();
        $this->app->instance(NewsletterAnalysisGenerator::class, $generator);

        $job = new ProcessNewsletterBodyAnalysis(
            researchThoughtId: (string) $researchThought->id,
            sourceThoughtId: (string) $emailThought->id,
            importedEmailId: $imported->id,
        );
        $job->handle(app(NewsletterAnalysisGenerator::class));

        $analysis = NewsletterAnalysis::query()
            ->where('research_thought_id', $researchThought->id)
            ->first();

        // Existing row unchanged
        $this->assertSame('Existing summary.', $analysis->summary);
        $this->assertSame('completed', $analysis->status);
    }

    #[Test]
    public function job_marks_analysis_failed_when_generator_throws_on_final_attempt(): void
    {
        $user = User::factory()->create();
        $account = MailAccount::factory()->create(['user_id' => $user->id]);
        $imported = ImportedEmail::query()->create([
            'user_id' => $user->id,
            'mail_account_id' => $account->id,
            'provider' => 'fastmail',
            'provider_message_id' => 'analysis-gen-fail',
            'direction' => 'received',
            'subject' => 'Newsletter',
            'body_text' => str_repeat('Body content. ', 30),
            'from_json' => [['email' => 'x@x.com']],
            'processing_status' => 'research_completed',
        ]);

        $emailThought = Thought::factory()->create(['user_id' => $user->id]);
        $researchThought = Thought::factory()->create(['user_id' => $user->id]);

        $generator = Mockery::mock(NewsletterAnalysisGenerator::class);
        $generator->shouldReceive('generate')
            ->once()
            ->andThrow(new \RuntimeException('API error'));
        $this->app->instance(NewsletterAnalysisGenerator::class, $generator);

        // Set tries = 1 so attempts() (which returns 1 outside the queue runner) equals tries,
        // triggering the failure-record branch instead of a rethrow.
        $job = new ProcessNewsletterBodyAnalysis(
            researchThoughtId: (string) $researchThought->id,
            sourceThoughtId: (string) $emailThought->id,
            importedEmailId: $imported->id,
        );
        $job->tries = 1;

        $job->handle(app(NewsletterAnalysisGenerator::class));

        $analysis = NewsletterAnalysis::query()
            ->where('research_thought_id', $researchThought->id)
            ->first();

        $this->assertNotNull($analysis);
        $this->assertSame('failed', $analysis->status);
        $this->assertStringContainsString('API error', (string) $analysis->failure_reason);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
php artisan test tests/Feature/ProcessNewsletterBodyAnalysisJobTest.php
```

Expected: FAIL with "Class ... not found"

- [ ] **Step 3: Create the job**

```php
// app/Jobs/ProcessNewsletterBodyAnalysis.php
<?php

namespace App\Jobs;

use App\Models\CapturedInboundEmail;
use App\Models\ImportedEmail;
use App\Models\NewsletterAnalysis;
use App\Services\NewsletterAnalysis\NewsletterAnalysisGenerator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;

class ProcessNewsletterBodyAnalysis implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    public int $timeout = 120;

    public function __construct(
        public readonly string $researchThoughtId,
        public readonly string $sourceThoughtId,
        public readonly ?int $importedEmailId = null,
        public readonly ?int $capturedInboundEmailId = null,
    ) {
        if (($importedEmailId === null) === ($capturedInboundEmailId === null)) {
            throw new InvalidArgumentException(
                'Exactly one of importedEmailId or capturedInboundEmailId must be set.'
            );
        }
    }

    public function handle(NewsletterAnalysisGenerator $generator): void
    {
        $lock = Cache::lock(
            'process-newsletter-body-analysis:'.$this->researchThoughtId,
            $this->timeout + 30
        );

        if (! $lock->get()) {
            $this->release($this->backoff);

            return;
        }

        try {
            $this->handleLocked($generator);
        } finally {
            $lock->release();
        }
    }

    private function handleLocked(NewsletterAnalysisGenerator $generator): void
    {
        $analysis = NewsletterAnalysis::query()->firstOrNew([
            'research_thought_id' => $this->researchThoughtId,
        ]);

        if ($analysis->exists && $analysis->status === 'completed') {
            return;
        }

        $stored = $this->resolveStoredEmail();
        if ($stored === null) {
            Log::warning('ProcessNewsletterBodyAnalysis: stored email not found.', [
                'imported_email_id' => $this->importedEmailId,
                'captured_inbound_email_id' => $this->capturedInboundEmailId,
            ]);

            return;
        }

        $body = trim((string) ($stored->body_text ?? ''));
        $subject = trim((string) ($stored->subject ?? ''));
        [$storedType, $storedId] = $this->storedEmailIdentity($stored);

        if (mb_strlen($body) < 50) {
            $analysis->fill([
                'source_thought_id' => $this->sourceThoughtId,
                'stored_email_type' => $storedType,
                'stored_email_id' => $storedId,
                'status' => 'failed',
                'failure_reason' => 'body_too_short',
            ]);
            $analysis->save();

            return;
        }

        $analysis->fill([
            'source_thought_id' => $this->sourceThoughtId,
            'stored_email_type' => $storedType,
            'stored_email_id' => $storedId,
            'status' => 'processing',
        ]);
        $analysis->save();

        try {
            $result = $generator->generate($subject, $body);
        } catch (\Throwable $e) {
            if ($this->attempts() < $this->tries) {
                throw $e;
            }

            $analysis->update([
                'status' => 'failed',
                'failure_reason' => Str::limit($e->getMessage(), 255),
            ]);

            return;
        }

        $analysis->update([
            'status' => 'completed',
            'summary' => $result['summary'],
            'key_points' => $result['key_points'],
            'positives_mentioned' => $result['positives_mentioned'],
            'negatives_mentioned' => $result['negatives_mentioned'],
            'highlights' => $result['highlights'],
            'quality_notes' => $result['quality_notes'],
            'failure_reason' => null,
            'completed_at' => now(),
        ]);
    }

    private function resolveStoredEmail(): ImportedEmail|CapturedInboundEmail|null
    {
        if ($this->importedEmailId !== null) {
            return ImportedEmail::query()->find($this->importedEmailId);
        }

        return CapturedInboundEmail::query()->find($this->capturedInboundEmailId);
    }

    /**
     * @return array{0: string, 1: int}
     */
    private function storedEmailIdentity(ImportedEmail|CapturedInboundEmail $stored): array
    {
        if ($stored instanceof CapturedInboundEmail) {
            return ['captured_inbound_email', (int) $stored->id];
        }

        return ['imported_email', (int) $stored->id];
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
php artisan test tests/Feature/ProcessNewsletterBodyAnalysisJobTest.php
```

Expected: 4 PASSED (the Mockery-based generator-throws test may need adjustment — if it fails, replace with a direct `Http::fake` that returns an error response and remove the Mockery partial mock approach; see note below)

> **Note on the generator-throws test:** If mocking `ProcessNewsletterBodyAnalysis` as a partial mock causes issues, replace that test with a simpler approach: bind a custom `NewsletterAnalysisGenerator` that throws, and verify the row is saved as failed only when `$this->attempts() >= $this->tries`. The simplest approach is to call `$job->handle()` directly and trust that the `catch` branch saves the failure — using a generator mock that always throws and setting `tries = 1` on the job:
>
> ```php
> $job = new ProcessNewsletterBodyAnalysis(...);
> $job->tries = 1; // Force final attempt
> ```
>
> Since `attempts()` defaults to 1 in tests (not using the queue runner), this naturally hits the failure branch.

- [ ] **Step 5: Commit**

```bash
git add app/Jobs/ProcessNewsletterBodyAnalysis.php tests/Feature/ProcessNewsletterBodyAnalysisJobTest.php
git commit -m "feat(newsletter-analysis): add ProcessNewsletterBodyAnalysis job"
```

---

### Task 5: Dispatch from `ProcessExtraEmailResearch`

**Files:**
- Modify: `app/Jobs/ProcessExtraEmailResearch.php`
- Modify: `tests/Feature/ProcessExtraEmailResearchJobTest.php`

- [ ] **Step 1: Write the failing test**

Add this test to `tests/Feature/ProcessExtraEmailResearchJobTest.php` (inside the class, after existing tests):

```php
#[Test]
public function job_dispatches_newsletter_body_analysis_after_creating_research(): void
{
    config(['app.name' => 'JobTestApp']);
    $this->bindOpenRouterMocks();
    Queue::fake();

    $user = User::factory()->create();
    $account = MailAccount::factory()->create(['user_id' => $user->id]);
    $imported = ImportedEmail::query()->create([
        'user_id' => $user->id,
        'mail_account_id' => $account->id,
        'provider' => 'fastmail',
        'provider_message_id' => 'analysis-dispatch-test',
        'direction' => 'received',
        'subject' => 'Analysis dispatch test',
        'body_text' => str_repeat('Substantive newsletter body paragraph. ', 30),
        'from_json' => [['email' => 'news@example.com', 'name' => 'News']],
        'processing_status' => 'research_queued',
        'rule_action' => 'extra_process',
    ]);

    $emailThought = Thought::factory()->create([
        'user_id' => $user->id,
        'source' => 'email',
        'source_metadata' => [
            'imported_email_id' => $imported->id,
            'sender_rule_action' => 'extra_process',
        ],
    ]);
    $imported->thought_id = $emailThought->id;
    $imported->save();

    $yt = Mockery::mock(\App\Services\Email\YouTubeTranscriptService::class);
    $yt->shouldReceive('fetchForUrl')->never();
    $this->app->instance(\App\Services\Email\YouTubeTranscriptService::class, $yt);

    $job = new ProcessExtraEmailResearch(importedEmailId: $imported->id);
    $job->handle(
        app(\App\Services\Email\EmailNewsletterResearchService::class),
        app(\App\Services\Email\EmailLinkExtractor::class),
    );

    Queue::assertPushed(\App\Jobs\ProcessNewsletterBodyAnalysis::class, function ($job) use ($imported) {
        return $job->importedEmailId === $imported->id;
    });
}
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
php artisan test tests/Feature/ProcessExtraEmailResearchJobTest.php --filter="dispatches_newsletter_body_analysis"
```

Expected: FAIL — `ProcessNewsletterBodyAnalysis` is not dispatched yet.

- [ ] **Step 3: Add the dispatch to `ProcessExtraEmailResearch::handleLocked()`**

In `app/Jobs/ProcessExtraEmailResearch.php`, add `use App\Jobs\ProcessNewsletterBodyAnalysis;` to the imports (after the existing `use App\Jobs\...` line at the top if present, or after the namespace block).

Then find the **existing-research-thought path** (around line 107–123 — the block ending with `return;` after `queueNewsletterEditorialLinks`). Add the dispatch directly after `queueNewsletterEditorialLinks(...)`:

```php
// Existing code (keep as-is):
app(LinkSummaryDispatchService::class)->queueNewsletterEditorialLinks(
    $thought,
    $existingResearchThought,
    $stored,
    trim((string) ($stored->body_text ?? '')),
    $links
);

// Add this immediately after:
ProcessNewsletterBodyAnalysis::dispatch(
    researchThoughtId: (string) $existingResearchThought->id,
    sourceThoughtId: (string) $thought->id,
    importedEmailId: $this->importedEmailId,
    capturedInboundEmailId: $this->capturedInboundEmailId,
);

return;
```

Then find the **new-research-thought path** (the `if ($researchThought instanceof Thought)` block, around line 161–169). Add the dispatch after `queueNewsletterEditorialLinks(...)` there too:

```php
// Existing code (keep as-is):
app(LinkSummaryDispatchService::class)->queueNewsletterEditorialLinks(
    $thought,
    $researchThought,
    $stored,
    trim((string) ($stored->body_text ?? '')),
    $links
);

// Add this immediately after:
ProcessNewsletterBodyAnalysis::dispatch(
    researchThoughtId: (string) $researchThought->id,
    sourceThoughtId: (string) $thought->id,
    importedEmailId: $this->importedEmailId,
    capturedInboundEmailId: $this->capturedInboundEmailId,
);
```

- [ ] **Step 4: Run the test to verify it passes**

```bash
php artisan test tests/Feature/ProcessExtraEmailResearchJobTest.php --filter="dispatches_newsletter_body_analysis"
```

Expected: 1 PASSED

- [ ] **Step 5: Run full test file to check for regressions**

```bash
php artisan test tests/Feature/ProcessExtraEmailResearchJobTest.php
```

Expected: All PASSED

- [ ] **Step 6: Commit**

```bash
git add app/Jobs/ProcessExtraEmailResearch.php tests/Feature/ProcessExtraEmailResearchJobTest.php
git commit -m "feat(newsletter-analysis): dispatch ProcessNewsletterBodyAnalysis from ProcessExtraEmailResearch"
```

---

### Task 6: Controller view model, partial view, and rendering

**Files:**
- Modify: `app/Http/Controllers/IdeaController.php`
- Create: `resources/views/idea/partials/research_newsletter_analysis.blade.php`
- Modify: `resources/views/idea/research_show.blade.php`
- Create: `tests/Feature/NewsletterAnalysisRenderingTest.php`

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/NewsletterAnalysisRenderingTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\NewsletterAnalysis;
use App\Models\Thought;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class NewsletterAnalysisRenderingTest extends TestCase
{
    use RefreshDatabase;

    private function makeResearchThought(User $user): Thought
    {
        return Thought::factory()->create([
            'user_id' => $user->id,
            'source' => 'research',
            'source_metadata' => ['doc_type' => 'research'],
            'metadata' => ['type' => 'research'],
        ]);
    }

    #[Test]
    public function research_show_renders_analysis_sections_when_completed(): void
    {
        $user = User::factory()->create();
        $researchThought = $this->makeResearchThought($user);
        $emailThought = Thought::factory()->create(['user_id' => $user->id]);

        NewsletterAnalysis::query()->create([
            'research_thought_id' => $researchThought->id,
            'source_thought_id' => $emailThought->id,
            'stored_email_type' => 'imported_email',
            'stored_email_id' => 1,
            'status' => 'completed',
            'summary' => 'This is the newsletter summary.',
            'key_points' => ['Point one', 'Point two'],
            'positives_mentioned' => ['Positive thing'],
            'negatives_mentioned' => ['Negative thing'],
            'highlights' => ['Notable highlight'],
            'quality_notes' => null,
            'completed_at' => now(),
        ]);

        $response = $this->actingAs($user)
            ->get(route('idea.research.show', $researchThought));

        $response->assertOk();
        $response->assertSee('This is the newsletter summary.');
        $response->assertSee('Point one');
        $response->assertSee('Point two');
        $response->assertSee('Positive thing');
        $response->assertSee('Negative thing');
        $response->assertSee('Notable highlight');
    }

    #[Test]
    public function research_show_renders_pending_note_when_analysis_is_processing(): void
    {
        $user = User::factory()->create();
        $researchThought = $this->makeResearchThought($user);
        $emailThought = Thought::factory()->create(['user_id' => $user->id]);

        NewsletterAnalysis::query()->create([
            'research_thought_id' => $researchThought->id,
            'source_thought_id' => $emailThought->id,
            'stored_email_type' => 'imported_email',
            'stored_email_id' => 1,
            'status' => 'processing',
        ]);

        $response = $this->actingAs($user)
            ->get(route('idea.research.show', $researchThought));

        $response->assertOk();
        $response->assertSee('Newsletter analysis processing');
    }

    #[Test]
    public function research_show_renders_failure_note_when_analysis_failed(): void
    {
        $user = User::factory()->create();
        $researchThought = $this->makeResearchThought($user);
        $emailThought = Thought::factory()->create(['user_id' => $user->id]);

        NewsletterAnalysis::query()->create([
            'research_thought_id' => $researchThought->id,
            'source_thought_id' => $emailThought->id,
            'stored_email_type' => 'imported_email',
            'stored_email_id' => 1,
            'status' => 'failed',
            'failure_reason' => 'body_too_short',
        ]);

        $response = $this->actingAs($user)
            ->get(route('idea.research.show', $researchThought));

        $response->assertOk();
        $response->assertSee('Newsletter analysis could not be completed');
    }

    #[Test]
    public function research_show_renders_nothing_for_analysis_when_no_record_exists(): void
    {
        $user = User::factory()->create();
        $researchThought = $this->makeResearchThought($user);

        $response = $this->actingAs($user)
            ->get(route('idea.research.show', $researchThought));

        $response->assertOk();
        $response->assertDontSee('Newsletter analysis processing');
        $response->assertDontSee('Newsletter analysis could not be completed');
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
php artisan test tests/Feature/NewsletterAnalysisRenderingTest.php
```

Expected: FAIL — the view does not include the analysis partial yet.

- [ ] **Step 3: Add view model builder to `IdeaController`**

In `app/Http/Controllers/IdeaController.php`, add the `NewsletterAnalysis` import near the top of the file with the other model imports:

```php
use App\Models\NewsletterAnalysis;
```

Then in the `showResearch()` method (around line 1270, after `$editorialLinkSummaries = ...`), add:

```php
$newsletterAnalysis = $this->buildNewsletterAnalysisViewModel($thought);
```

In the `return view(...)` call (around line 1276), add the variable to the array:

```php
return view('idea.research_show', [
    'root' => $thought,
    'pageTitle' => $pageTitle,
    'root_html' => $rootHtml,
    'sections' => $sectionsWithHtml,
    'relatedEmail' => $relatedEmail,
    'linkedVideo' => $linkedVideo,
    'editorialLinkSummaries' => $editorialLinkSummaries,
    'newsletterAnalysis' => $newsletterAnalysis,   // add this line
]);
```

Then add this private method to `IdeaController`, after `buildResearchEditorialLinkSummaryViewModel()`:

```php
private function buildNewsletterAnalysisViewModel(Thought $researchThought): ?array
{
    $analysis = NewsletterAnalysis::query()
        ->where('research_thought_id', $researchThought->id)
        ->first();

    if ($analysis === null) {
        return null;
    }

    return [
        'status' => (string) $analysis->status,
        'summary' => $analysis->summary,
        'key_points' => $analysis->key_points ?? [],
        'positives_mentioned' => $analysis->positives_mentioned ?? [],
        'negatives_mentioned' => $analysis->negatives_mentioned ?? [],
        'highlights' => $analysis->highlights ?? [],
        'quality_notes' => $analysis->quality_notes,
    ];
}
```

- [ ] **Step 4: Create the Blade partial**

```blade
{{-- resources/views/idea/partials/research_newsletter_analysis.blade.php --}}
@if ($newsletterAnalysis)
    @if ($newsletterAnalysis['status'] === 'completed')
        <div class="mb-6 rounded-xl border border-memory-violet/20 bg-memory-violet/[0.04] p-4 md:p-5">
            <h2 class="text-[13px] md:text-[14px] font-semibold text-deep-indigo tracking-tight mb-4">Newsletter analysis</h2>

            @if ($newsletterAnalysis['summary'])
                <div class="mb-4">
                    <h3 class="text-[11px] font-semibold tracking-[0.08em] uppercase text-memory-violet/85 mb-2">Summary</h3>
                    <p class="text-[13px] text-slate-brand leading-relaxed">{{ $newsletterAnalysis['summary'] }}</p>
                </div>
            @endif

            @if (! empty($newsletterAnalysis['key_points']))
                <div class="mb-4">
                    <h3 class="text-[11px] font-semibold tracking-[0.08em] uppercase text-memory-violet/85 mb-2">Key points</h3>
                    <ul class="list-disc list-inside space-y-1">
                        @foreach ($newsletterAnalysis['key_points'] as $point)
                            <li class="text-[13px] text-slate-brand leading-relaxed">{{ $point }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @if (! empty($newsletterAnalysis['positives_mentioned']))
                <div class="mb-4">
                    <h3 class="text-[11px] font-semibold tracking-[0.08em] uppercase text-memory-violet/85 mb-2">Positives mentioned</h3>
                    <ul class="list-disc list-inside space-y-1">
                        @foreach ($newsletterAnalysis['positives_mentioned'] as $positive)
                            <li class="text-[13px] text-slate-brand leading-relaxed">{{ $positive }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @if (! empty($newsletterAnalysis['negatives_mentioned']))
                <div class="mb-4">
                    <h3 class="text-[11px] font-semibold tracking-[0.08em] uppercase text-memory-violet/85 mb-2">Negatives mentioned</h3>
                    <ul class="list-disc list-inside space-y-1">
                        @foreach ($newsletterAnalysis['negatives_mentioned'] as $negative)
                            <li class="text-[13px] text-slate-brand leading-relaxed">{{ $negative }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @if (! empty($newsletterAnalysis['highlights']))
                <div class="mb-4">
                    <h3 class="text-[11px] font-semibold tracking-[0.08em] uppercase text-memory-violet/85 mb-2">Highlights</h3>
                    <ul class="list-disc list-inside space-y-1">
                        @foreach ($newsletterAnalysis['highlights'] as $highlight)
                            <li class="text-[13px] text-slate-brand leading-relaxed">{{ $highlight }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @if ($newsletterAnalysis['quality_notes'])
                <p class="mt-2 text-[11px] text-slate-brand/60 leading-relaxed">{{ $newsletterAnalysis['quality_notes'] }}</p>
            @endif
        </div>
    @elseif ($newsletterAnalysis['status'] === 'queued' || $newsletterAnalysis['status'] === 'processing')
        <p class="mb-4 text-[12px] text-slate-brand/70 italic">Newsletter analysis processing…</p>
    @elseif ($newsletterAnalysis['status'] === 'failed')
        <p class="mb-4 text-[12px] text-slate-brand/60 italic">Newsletter analysis could not be completed.</p>
    @endif
@endif
```

- [ ] **Step 5: Update `research_show.blade.php` to include the new partial**

In `resources/views/idea/research_show.blade.php`, find this line:

```blade
@include('idea.partials.research_editorial_link_summaries', ['editorialLinkSummaries' => $editorialLinkSummaries])
```

Replace it with:

```blade
@include('idea.partials.research_newsletter_analysis', ['newsletterAnalysis' => $newsletterAnalysis ?? null])
@include('idea.partials.research_editorial_link_summaries', ['editorialLinkSummaries' => $editorialLinkSummaries])
```

- [ ] **Step 6: Run rendering tests to verify they pass**

```bash
php artisan test tests/Feature/NewsletterAnalysisRenderingTest.php
```

Expected: 4 PASSED

- [ ] **Step 7: Run all related tests for regressions**

```bash
php artisan test tests/Feature/ProcessExtraEmailResearchJobTest.php tests/Feature/ProcessNewsletterBodyAnalysisJobTest.php tests/Feature/NewsletterAnalysisRenderingTest.php tests/Unit/Services/OpenRouterServiceTest.php tests/Unit/Services/NewsletterAnalysis/
```

Expected: All PASSED

- [ ] **Step 8: Commit**

```bash
git add app/Http/Controllers/IdeaController.php resources/views/idea/partials/research_newsletter_analysis.blade.php resources/views/idea/research_show.blade.php tests/Feature/NewsletterAnalysisRenderingTest.php
git commit -m "feat(newsletter-analysis): render analysis block on research show page"
```
