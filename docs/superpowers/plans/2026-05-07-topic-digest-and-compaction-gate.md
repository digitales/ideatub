# Topic-Digest Producer + Per-Compaction Validator Gate Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close the two unshipped surfaces of the working-memory compactions design: (A) an on-demand `compaction:topic-digest` producer; (B) a validator gate that runs `WorkingMemoryOutputValidator::validate()` on every compaction before persistence and aborts (or observes) on hard-fail.

**Architecture:** Task A mirrors the existing weekly-digest pipeline — prompt builder → queued job → console command — with topic filtering applied via tag matching. Task B injects `WorkingMemoryOutputValidator` into `CompactionVersionWriter` with a per-build_type required-sections map and a config-gated enforcement flag, so the gate ships in observation mode now and can be flipped to enforcement once compaction prompts are updated to produce citations.

**Tech Stack:** Laravel 12 (PHP 8.2+), Eloquent, Pest test runner, OpenRouter LLM gateway via `OpenRouterService::researchFromPrompt`, existing `LlmJsonDecoder`, existing `CompactionRetentionService`.

---

## Spec references

- Design: `docs/superpowers/specs/2026-05-07-working-memory-compactions-design.md`
  - Topic-digest build_type: line 57.
  - Topic-digest promotion sections: line 142 — `Active Priorities, Open Questions, Latest Signals`.
  - Per-compaction validator gate: line 218 — "Compaction versions themselves must pass the existing per-section citation coverage threshold; otherwise the compaction is discarded (not persisted) and a diagnostic is emitted."
- Sibling reference for Task A shape: `BuildScopeDigestJob`, `ScopeDigestPromptBuilder`, `BuildWeeklyDigestsCommand`.
- Sibling reference for Task B shape: `WorkingMemoryOutputValidator::validate()`, `CompactionVersionWriter::write()`.

## File map

**Created:**
- `app/Services/WorkingMemory/Compactions/TopicDigestPromptBuilder.php` — heredoc prompt for `compaction:topic-digest`.
- `app/Jobs/BuildTopicDigestJob.php` — queued job synthesizing one topic digest for one (user, scope, topic) tuple.
- `app/Console/Commands/BuildTopicDigestCommand.php` — `compactions:topic-digest` on-demand command.
- `tests/Unit/Services/WorkingMemory/Compactions/TopicDigestPromptBuilderTest.php`
- `tests/Feature/BuildTopicDigestJobTest.php`
- `tests/Feature/BuildTopicDigestCommandTest.php`

**Modified:**
- `app/Services/WorkingMemory/WorkingMemoryOutputValidator.php` — accept `?array $requiredSections` to override `requiredSectionKeys()`.
- `app/Services/WorkingMemory/Compactions/CompactionVersionWriter.php` — inject validator + per-subtype required-sections map; gate persistence behind config flag.
- `config/working_memory.php` — add `compaction_validation_enforced`.
- `tests/Unit/Services/WorkingMemory/WorkingMemoryOutputValidatorTest.php` — coverage for the new override param.
- `tests/Unit/Services/WorkingMemory/Compactions/CompactionVersionWriterTest.php` — coverage for gate observation + enforcement.

## Important constraint to acknowledge before Task B

All four current compaction prompt builders (`MeetingCompactionPromptBuilder`, `ScopeDigestPromptBuilder`, `ResearchSynthesisPromptBuilder`, and the new `TopicDigestPromptBuilder` from Task A) explicitly instruct the model to return `"citations": []` on items and `"references": []` at the top level (see e.g. `ScopeDigestPromptBuilder.php:56`). The existing `WorkingMemoryOutputValidator::validate()` hard-fails any item without citations via `reason_codes ⊇ {missing_citation}`, so a literal "always abort on hard-fail" gate would block **every** compaction with current prompts.

**Resolution:** Task B ships the gate in observation mode by default (`compaction_validation_enforced=false`). The validator runs, hard-fails are logged, but persistence proceeds. A separate follow-up plan (out of scope here) will update the four prompt builders to thread source-thought permalinks into `references[]` and instruct the model to cite them; once that lands, the operator flips the flag to `true` and the gate becomes enforcing.

---

## Task A: Topic-Digest Producer

### Task A1: TopicDigestPromptBuilder

**Files:**
- Create: `app/Services/WorkingMemory/Compactions/TopicDigestPromptBuilder.php`
- Create: `tests/Unit/Services/WorkingMemory/Compactions/TopicDigestPromptBuilderTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Services/WorkingMemory/Compactions/TopicDigestPromptBuilderTest.php`:

```php
<?php

namespace Tests\Unit\Services\WorkingMemory\Compactions;

use App\Models\Thought;
use App\Services\WorkingMemory\Compactions\TopicDigestPromptBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class TopicDigestPromptBuilderTest extends TestCase
{
    #[Test]
    public function it_renders_required_sections_topic_block_and_thought_blocks(): void
    {
        $thoughtA = new Thought([
            'content' => 'Pricing tier B regression observed in dezeen onboarding.',
            'created_at' => Carbon::parse('2026-05-05T08:00:00Z'),
        ]);
        $thoughtA->id = 't-1';

        $thoughtB = new Thought([
            'content' => 'Pricing tier C unaffected; flag rollback complete.',
            'created_at' => Carbon::parse('2026-05-06T10:30:00Z'),
        ]);
        $thoughtB->id = 't-2';

        $thoughts = new Collection([$thoughtA, $thoughtB]);

        $prompt = (new TopicDigestPromptBuilder)->build(
            scopeType: 'project',
            scopeKey: 'dezeen',
            topic: 'pricing',
            thoughts: $thoughts,
        );

        $this->assertStringContainsString('## Topic digest task', $prompt);
        $this->assertStringContainsString('Scope: project / dezeen', $prompt);
        $this->assertStringContainsString('Topic: pricing', $prompt);
        $this->assertStringContainsString('Active Priorities', $prompt);
        $this->assertStringContainsString('Open Questions', $prompt);
        $this->assertStringContainsString('Latest Signals', $prompt);
        $this->assertStringContainsString('thought:t-1', $prompt);
        $this->assertStringContainsString('thought:t-2', $prompt);
        $this->assertStringContainsString('[2026-05-05T08:00:00', $prompt);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter='TopicDigestPromptBuilderTest'`
Expected: FAIL with "Class TopicDigestPromptBuilder not found".

- [ ] **Step 3: Write minimal implementation**

Create `app/Services/WorkingMemory/Compactions/TopicDigestPromptBuilder.php`:

```php
<?php

namespace App\Services\WorkingMemory\Compactions;

use App\Models\Thought;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class TopicDigestPromptBuilder
{
    /**
     * @param  Collection<int, Thought>  $thoughts
     */
    public function build(
        string $scopeType,
        string $scopeKey,
        string $topic,
        Collection $thoughts,
    ): string {
        $maxChars = (int) config('working_memory.authoring_max_prompt_input_chars', 60000);
        $body = $this->renderThoughts($thoughts);

        $prompt = <<<TEXT
## Topic digest task

Produce a durable on-demand digest compaction over the captures tagged with this topic.

Scope: {$scopeType} / {$scopeKey}
Topic: {$topic}

## Tagged captures
{$body}

## Output contract

Return JSON only (no Markdown fences) with this shape:

{
  "summary_markdown": "<full markdown with the sections below as `## <section>`>",
  "structured_sections": {
    "Active Priorities": [{"text": "...", "importance": 1, "fallback_mode": "direct", "citations": []}],
    "Open Questions": [...],
    "Latest Signals": [...]
  },
  "references": []
}

Rules:
- The three required sections are: Active Priorities, Open Questions, Latest Signals.
- Cluster related captures; do not repeat the same point in multiple sections.
- Active Priorities = work currently in flight on this topic or claimed for next.
- Open Questions = unresolved decisions or contradictions raised by captures.
- Latest Signals = newly observed information that may shape next decisions on this topic.
- Citations array should be empty; the canonical composer will add permalinks.
TEXT;

        return Str::limit($prompt, $maxChars, '');
    }

    /**
     * @param  Collection<int, Thought>  $thoughts
     */
    private function renderThoughts(Collection $thoughts): string
    {
        if ($thoughts->isEmpty()) {
            return '_No tagged captures._';
        }

        $lines = [];
        foreach ($thoughts as $thought) {
            $id = (string) ($thought->id ?? 'unknown');
            $createdAt = $thought->created_at?->toIso8601String() ?? 'unknown';
            $content = trim((string) $thought->content);
            $lines[] = "- [{$createdAt}] thought:{$id}\n  {$content}";
        }

        return implode("\n", $lines);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter='TopicDigestPromptBuilderTest'`
Expected: PASS, 1 test, 9 assertions.

- [ ] **Step 5: Commit**

```bash
git add app/Services/WorkingMemory/Compactions/TopicDigestPromptBuilder.php tests/Unit/Services/WorkingMemory/Compactions/TopicDigestPromptBuilderTest.php
git commit -m "working-memory(compactions): add TopicDigestPromptBuilder for compaction:topic-digest"
```

---

### Task A2: BuildTopicDigestJob

**Files:**
- Create: `app/Jobs/BuildTopicDigestJob.php`
- Create: `tests/Feature/BuildTopicDigestJobTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/BuildTopicDigestJobTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Jobs\BuildTopicDigestJob;
use App\Models\Thought;
use App\Models\User;
use App\Models\WorkingMemoryVersion;
use App\Services\OpenRouterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class BuildTopicDigestJobTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_creates_a_topic_digest_compaction_when_topic_thoughts_exist(): void
    {
        Queue::fakeExcept([BuildTopicDigestJob::class]);

        $openRouter = Mockery::mock(OpenRouterService::class);
        $openRouter->shouldReceive('researchFromPrompt')->once()->andReturn(json_encode([
            'summary_markdown' => "## Active Priorities\n- Pricing rollback is in flight.",
            'structured_sections' => [
                'Active Priorities' => [['text' => 'Pricing rollback is in flight.', 'importance' => 1, 'fallback_mode' => 'direct', 'citations' => []]],
                'Open Questions' => [],
                'Latest Signals' => [],
            ],
            'references' => [],
        ]));
        $this->app->instance(OpenRouterService::class, $openRouter);

        $user = User::factory()->create();
        Thought::factory()->count(3)->create([
            'user_id' => $user->id,
            'metadata' => ['type' => 'idea', 'tags' => ['project:dezeen', 'pricing']],
            'source_metadata' => ['project' => 'dezeen'],
        ]);
        // Untagged thought — must be ignored by topic filter.
        Thought::factory()->create([
            'user_id' => $user->id,
            'metadata' => ['type' => 'idea', 'tags' => ['project:dezeen']],
            'source_metadata' => ['project' => 'dezeen'],
        ]);

        BuildTopicDigestJob::dispatchSync($user->id, 'project', 'dezeen', 'pricing');

        $version = WorkingMemoryVersion::query()
            ->where('build_type', 'compaction:topic-digest')
            ->first();

        $this->assertNotNull($version);
        $this->assertStringContainsString('Pricing rollback', (string) $version->summary_markdown);
        $this->assertSame('project', $version->workingMemory->scope_type);
        $this->assertSame('dezeen', $version->workingMemory->scope_key);
    }

    #[Test]
    public function it_skips_when_no_thoughts_carry_the_topic_tag(): void
    {
        Queue::fakeExcept([BuildTopicDigestJob::class]);

        $openRouter = Mockery::mock(OpenRouterService::class);
        $openRouter->shouldNotReceive('researchFromPrompt');
        $this->app->instance(OpenRouterService::class, $openRouter);

        $user = User::factory()->create();
        Thought::factory()->count(2)->create([
            'user_id' => $user->id,
            'metadata' => ['type' => 'idea', 'tags' => ['project:dezeen']],
            'source_metadata' => ['project' => 'dezeen'],
        ]);

        BuildTopicDigestJob::dispatchSync($user->id, 'project', 'dezeen', 'pricing');

        $this->assertSame(0, WorkingMemoryVersion::query()->where('build_type', 'compaction:topic-digest')->count());
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter='BuildTopicDigestJobTest'`
Expected: FAIL with "Class BuildTopicDigestJob not found".

- [ ] **Step 3: Write minimal implementation**

Create `app/Jobs/BuildTopicDigestJob.php`:

```php
<?php

namespace App\Jobs;

use App\Models\Thought;
use App\Services\OpenRouterService;
use App\Services\WorkingMemory\Compactions\CompactionVersionWriter;
use App\Services\WorkingMemory\Compactions\TopicDigestPromptBuilder;
use App\Support\Json\LlmJsonDecoder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class BuildTopicDigestJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(
        public int $userId,
        public string $scopeType,
        public string $scopeKey,
        public string $topic,
    ) {}

    public function handle(
        TopicDigestPromptBuilder $promptBuilder,
        OpenRouterService $openRouter,
        CompactionVersionWriter $writer,
    ): void {
        if ($this->userId <= 0 || $this->scopeType === '' || $this->scopeKey === '' || $this->topic === '') {
            return;
        }

        $thoughts = $this->collectTopicThoughts();
        if ($thoughts->isEmpty()) {
            return;
        }

        try {
            $prompt = $promptBuilder->build($this->scopeType, $this->scopeKey, $this->topic, $thoughts);
            $model = (string) config('working_memory.authoring_digest_model', '');
            $temperature = config('working_memory.authoring_digest_temperature');
            $raw = $openRouter->researchFromPrompt(
                $prompt,
                $model !== '' ? $model : null,
                is_numeric($temperature) ? (float) $temperature : null,
            );

            $decoded = LlmJsonDecoder::decode($raw);
            if ($decoded === null) {
                Log::warning('BuildTopicDigestJob: model returned non-JSON output.', [
                    'user_id' => $this->userId,
                    'scope_type' => $this->scopeType,
                    'scope_key' => $this->scopeKey,
                    'topic' => $this->topic,
                ]);

                return;
            }

            $writer->write(
                userId: $this->userId,
                scopeType: $this->scopeType,
                scopeKey: $this->scopeKey,
                buildType: 'compaction:topic-digest',
                summaryMarkdown: (string) ($decoded['summary_markdown'] ?? ''),
                structuredSections: is_array($decoded['structured_sections'] ?? null) ? $decoded['structured_sections'] : [],
                references: is_array($decoded['references'] ?? null) ? $decoded['references'] : [],
                sourceThoughtIds: $thoughts->pluck('id')->map(fn ($id): string => (string) $id)->all(),
            );
        } catch (Throwable $e) {
            Log::warning('BuildTopicDigestJob failed.', [
                'user_id' => $this->userId,
                'scope_type' => $this->scopeType,
                'scope_key' => $this->scopeKey,
                'topic' => $this->topic,
                'message' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * @return Collection<int, Thought>
     */
    private function collectTopicThoughts(): Collection
    {
        $query = Thought::query()
            ->where('user_id', $this->userId)
            ->whereJsonContains('metadata->tags', $this->topic)
            ->orderByDesc('created_at')
            ->limit(200);

        if ($this->scopeType === 'project') {
            $query->where(function ($q): void {
                $q->where('source_metadata->project', $this->scopeKey);
                if (Str::isUuid($this->scopeKey)) {
                    $q->orWhereHas('projects', fn ($p) => $p->where('projects.id', $this->scopeKey));
                }
            });
        } elseif ($this->scopeType === 'tag') {
            $query->whereJsonContains('metadata->tags', $this->scopeKey);
        }

        return $query->get();
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter='BuildTopicDigestJobTest'`
Expected: PASS, 2 tests, ~7 assertions.

- [ ] **Step 5: Commit**

```bash
git add app/Jobs/BuildTopicDigestJob.php tests/Feature/BuildTopicDigestJobTest.php
git commit -m "working-memory(compactions): add BuildTopicDigestJob for on-demand topic digests"
```

---

### Task A3: compactions:topic-digest console command

**Files:**
- Create: `app/Console/Commands/BuildTopicDigestCommand.php`
- Create: `tests/Feature/BuildTopicDigestCommandTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/BuildTopicDigestCommandTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Jobs\BuildTopicDigestJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class BuildTopicDigestCommandTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_dispatches_one_job_for_the_requested_scope_and_topic(): void
    {
        Queue::fake();

        $user = User::factory()->create();

        $exit = $this->artisan('compactions:topic-digest', [
            'scope_type' => 'project',
            'scope_key' => 'dezeen',
            'topic' => 'pricing',
            '--user' => (string) $user->id,
        ])->run();

        $this->assertSame(0, $exit);
        Queue::assertPushed(
            BuildTopicDigestJob::class,
            fn (BuildTopicDigestJob $job): bool => $job->userId === $user->id
                && $job->scopeType === 'project'
                && $job->scopeKey === 'dezeen'
                && $job->topic === 'pricing',
        );
    }

    #[Test]
    public function it_normalizes_topic_and_project_scope_key_to_lowercase(): void
    {
        Queue::fake();

        $user = User::factory()->create();

        $exit = $this->artisan('compactions:topic-digest', [
            'scope_type' => 'project',
            'scope_key' => 'Dezeen',
            'topic' => 'Pricing',
            '--user' => (string) $user->id,
        ])->run();

        $this->assertSame(0, $exit);
        Queue::assertPushed(
            BuildTopicDigestJob::class,
            fn (BuildTopicDigestJob $job): bool => $job->scopeKey === 'dezeen' && $job->topic === 'pricing',
        );
    }

    #[Test]
    public function it_requires_a_numeric_user_option(): void
    {
        $user = User::factory()->create();

        $missing = $this->artisan('compactions:topic-digest', [
            'scope_type' => 'project',
            'scope_key' => 'dezeen',
            'topic' => 'pricing',
        ])->run();
        $this->assertSame(1, $missing);

        $nonNumeric = $this->artisan('compactions:topic-digest', [
            'scope_type' => 'project',
            'scope_key' => 'dezeen',
            'topic' => 'pricing',
            '--user' => 'abc',
        ])->run();
        $this->assertSame(1, $nonNumeric);

        $missingUser = $this->artisan('compactions:topic-digest', [
            'scope_type' => 'project',
            'scope_key' => 'dezeen',
            'topic' => 'pricing',
            '--user' => (string) ($user->id + 9999),
        ])->run();
        $this->assertSame(1, $missingUser);
    }

    #[Test]
    public function it_rejects_invalid_scope_type(): void
    {
        $user = User::factory()->create();

        $exit = $this->artisan('compactions:topic-digest', [
            'scope_type' => 'bogus',
            'scope_key' => 'dezeen',
            'topic' => 'pricing',
            '--user' => (string) $user->id,
        ])->run();

        $this->assertSame(1, $exit);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter='BuildTopicDigestCommandTest'`
Expected: FAIL with "Command 'compactions:topic-digest' is not defined".

- [ ] **Step 3: Write minimal implementation**

Create `app/Console/Commands/BuildTopicDigestCommand.php`:

```php
<?php

namespace App\Console\Commands;

use App\Jobs\BuildTopicDigestJob;
use App\Models\User;
use Illuminate\Console\Command;
use InvalidArgumentException;

class BuildTopicDigestCommand extends Command
{
    protected $signature = 'compactions:topic-digest {scope_type} {scope_key} {topic} {--user=}';

    protected $description = 'Enqueue an on-demand topic-digest compaction for a (scope, topic) tuple.';

    public function handle(): int
    {
        try {
            $userId = $this->resolveUserId();
            [$scopeType, $scopeKey] = $this->resolveScope();
            $topic = $this->resolveTopic();
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        BuildTopicDigestJob::dispatch($userId, $scopeType, $scopeKey, $topic);
        $this->info(sprintf('Queued topic digest for %s/%s topic="%s".', $scopeType, $scopeKey, $topic));

        return self::SUCCESS;
    }

    private function resolveUserId(): int
    {
        $userIdOption = $this->option('user');
        if (! is_string($userIdOption) || trim($userIdOption) === '' || ! ctype_digit(trim($userIdOption))) {
            throw new InvalidArgumentException('--user is required and must be a numeric user id.');
        }

        $userId = (int) trim($userIdOption);
        if (! User::query()->whereKey($userId)->exists()) {
            throw new InvalidArgumentException("User {$userId} does not exist.");
        }

        return $userId;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function resolveScope(): array
    {
        $scopeType = (string) $this->argument('scope_type');
        $scopeKey = (string) $this->argument('scope_key');

        if (! in_array($scopeType, ['global', 'project', 'insights', 'tag'], true)) {
            throw new InvalidArgumentException('Invalid scope_type. Allowed: global, project, insights, tag.');
        }

        if (trim($scopeKey) === '') {
            throw new InvalidArgumentException('scope_key must not be empty.');
        }

        if (in_array($scopeType, ['global', 'insights'], true) && $scopeKey !== 'global') {
            throw new InvalidArgumentException("scope_key for {$scopeType} must be 'global'.");
        }

        return [$scopeType, $scopeType === 'project' || $scopeType === 'tag' ? strtolower(trim($scopeKey)) : $scopeKey];
    }

    private function resolveTopic(): string
    {
        $topic = strtolower(trim((string) $this->argument('topic')));
        if ($topic === '') {
            throw new InvalidArgumentException('topic must not be empty.');
        }

        return $topic;
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter='BuildTopicDigestCommandTest'`
Expected: PASS, 4 tests.

- [ ] **Step 5: Commit**

```bash
git add app/Console/Commands/BuildTopicDigestCommand.php tests/Feature/BuildTopicDigestCommandTest.php
git commit -m "working-memory(compactions): add compactions:topic-digest on-demand console command"
```

---

### Task A4: Task-A regression sweep

- [ ] **Step 1: Run the broad compactions slice**

Run:
```bash
php artisan test --filter='WorkingMemory|Compaction|MeetingCompaction|ResearchCompaction|ScopeDigest|TopicDigest|ResearchSynthesis|ThoughtObserver|McpApi|LlmJsonDecoder|OpenRouterService|Compactions:|MeetingPrimaryScopeResolver'
```
Expected: PASS, 0 failures, count >= 261 (255 prior + 6 new tests across A1-A3).

- [ ] **Step 2: No commit needed**

Verification only.

---

## Task B: Per-Compaction Validator Gate

### Task B1: Extend WorkingMemoryOutputValidator with `?array $requiredSections`

**Files:**
- Modify: `app/Services/WorkingMemory/WorkingMemoryOutputValidator.php`
- Modify: `tests/Unit/Services/WorkingMemory/WorkingMemoryOutputValidatorTest.php`

- [ ] **Step 1: Write the failing test**

Append to `tests/Unit/Services/WorkingMemory/WorkingMemoryOutputValidatorTest.php` (before the `validStructuredPayload()` helper):

```php
    #[Test]
    public function explicit_required_sections_override_config_driven_defaults(): void
    {
        $sourceUrl = 'https://example.com/ref-1';

        $payload = [
            'summary_markdown' => '# Topic digest',
            'structured_sections' => [
                'Active Priorities' => [[
                    'text' => 'Pricing rollback in flight',
                    'importance' => 1,
                    'fallback_mode' => 'direct',
                    'citations' => [['type' => 'source', 'url' => $sourceUrl, 'label' => 'Source']],
                ]],
                'Open Questions' => [[
                    'text' => 'Tier C exposure unconfirmed',
                    'importance' => 1,
                    'fallback_mode' => 'direct',
                    'citations' => [['type' => 'source', 'url' => $sourceUrl, 'label' => 'Source']],
                ]],
                'Latest Signals' => [[
                    'text' => 'Flag rollback completed',
                    'importance' => 1,
                    'fallback_mode' => 'direct',
                    'citations' => [['type' => 'source', 'url' => $sourceUrl, 'label' => 'Source']],
                ]],
            ],
            'references' => [['type' => 'source', 'url' => $sourceUrl, 'label' => 'Source']],
        ];

        $validator = new WorkingMemoryOutputValidator;

        // Without override: validator demands the canonical 8 sections, hard-fails.
        $defaultResult = $validator->validate($payload, 1.0);
        $this->assertFalse($defaultResult['ok']);
        $this->assertSame('hard', $defaultResult['failure_type']);

        // With explicit override: only the 3 topic-digest sections are required.
        $overriddenResult = $validator->validate(
            payload: $payload,
            minimumCoverage: 1.0,
            compactionCountInScope: 0,
            requiredSections: ['Active Priorities', 'Open Questions', 'Latest Signals'],
        );
        $this->assertTrue($overriddenResult['ok']);
        $this->assertSame(100.0, $overriddenResult['coveragePercent']);
    }

    #[Test]
    public function explicit_required_sections_falls_back_to_config_when_empty_array(): void
    {
        $payload = $this->validStructuredPayload();
        $validator = new WorkingMemoryOutputValidator;

        $result = $validator->validate(
            payload: $payload,
            minimumCoverage: 1.0,
            compactionCountInScope: 0,
            requiredSections: [],
        );

        $this->assertTrue($result['ok']);
        $this->assertSame(100.0, $result['coveragePercent']);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter='WorkingMemoryOutputValidatorTest::explicit_required_sections'`
Expected: FAIL — `validate()` does not accept a 4th argument.

- [ ] **Step 3: Implement override**

In `app/Services/WorkingMemory/WorkingMemoryOutputValidator.php`:

Update the `validate()` signature and PHPDoc. Replace this block:

```php
    /**
     * @return array{
     *     ok: bool,
     *     message: string|null,
     *     coveragePercent: float|null,
     *     failure_type: 'hard'|'soft'|null,
     *     diagnostics: array{
     *         required_items: int,
     *         cited_items: int,
     *         compaction_cited_items: int,
     *         reason_codes: array<int, string>
     *     }
     * }
     */
    public function validate(array $payload, ?float $minimumCoverage = null, int $compactionCountInScope = 0): array
    {
        $sections = $payload['structured_sections'] ?? null;
```

with:

```php
    /**
     * @param  array<int, string>|null  $requiredSections  When non-null and non-empty, overrides the
     *   config-driven required-section list. Empty arrays fall back to the config default so callers
     *   that want defaults can pass `[]` without special-casing.
     * @return array{
     *     ok: bool,
     *     message: string|null,
     *     coveragePercent: float|null,
     *     failure_type: 'hard'|'soft'|null,
     *     diagnostics: array{
     *         required_items: int,
     *         cited_items: int,
     *         compaction_cited_items: int,
     *         reason_codes: array<int, string>
     *     }
     * }
     */
    public function validate(
        array $payload,
        ?float $minimumCoverage = null,
        int $compactionCountInScope = 0,
        ?array $requiredSections = null,
    ): array {
        $sections = $payload['structured_sections'] ?? null;
```

Then update `requiredSectionKeys()` to accept the override. Replace:

```php
    /**
     * @return array<int, string>
     */
    private function requiredSectionKeys(): array
    {
        $configuredSections = config('working_memory.citation_required_sections', self::REQUIRED_SECTION_KEYS);
```

with:

```php
    /**
     * @param  array<int, string>|null  $override
     * @return array<int, string>
     */
    private function requiredSectionKeys(?array $override = null): array
    {
        if (is_array($override) && $override !== []) {
            $normalized = [];
            foreach ($override as $section) {
                if (! is_string($section)) {
                    continue;
                }
                $trimmed = trim($section);
                if ($trimmed !== '') {
                    $normalized[] = $trimmed;
                }
            }
            $unique = array_values(array_unique($normalized));
            if ($unique !== []) {
                return $unique;
            }
        }

        $configuredSections = config('working_memory.citation_required_sections', self::REQUIRED_SECTION_KEYS);
```

And in the body of `validate()`, change the call:

```php
        foreach ($this->requiredSectionKeys() as $requiredSection) {
```

to:

```php
        foreach ($this->requiredSectionKeys($requiredSections) as $requiredSection) {
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter='WorkingMemoryOutputValidatorTest'`
Expected: PASS, all existing tests + 2 new pass.

- [ ] **Step 5: Commit**

```bash
git add app/Services/WorkingMemory/WorkingMemoryOutputValidator.php tests/Unit/Services/WorkingMemory/WorkingMemoryOutputValidatorTest.php
git commit -m "working-memory(validator): accept explicit requiredSections override for compaction gating"
```

---

### Task B2: Add `compaction_validation_enforced` config flag

**Files:**
- Modify: `config/working_memory.php`

- [ ] **Step 1: Edit config**

Inside the array in `config/working_memory.php`, add a new key directly above the `compaction_retention` block. Replace:

```php
    'meeting_refresh_delay_seconds' => (int) env('WORKING_MEMORY_MEETING_REFRESH_DELAY_SECONDS', 60),

    'compaction_retention' => [
```

with:

```php
    'meeting_refresh_delay_seconds' => (int) env('WORKING_MEMORY_MEETING_REFRESH_DELAY_SECONDS', 60),

    /*
    |--------------------------------------------------------------------------
    | Per-compaction validator enforcement
    |--------------------------------------------------------------------------
    |
    | When `true`, CompactionVersionWriter aborts persistence (no version row,
    | no input rows) for any compaction whose payload returns a hard-failure
    | from WorkingMemoryOutputValidator. When `false` (default), the writer
    | still runs the validator but logs the diagnostic and persists anyway —
    | useful while compaction prompt builders are still emitting empty
    | citations arrays. Flip to `true` once prompts populate references[]
    | with source-thought permalinks.
    |
    */

    'compaction_validation_enforced' => (bool) env('WORKING_MEMORY_COMPACTION_VALIDATION_ENFORCED', false),

    'compaction_retention' => [
```

- [ ] **Step 2: No tests for pure config**

The flag is read in Task B3; coverage lives there.

- [ ] **Step 3: No commit yet**

Bundled with B3 commit.

---

### Task B3: Inject validator + per-subtype required-sections map into CompactionVersionWriter

**Files:**
- Modify: `app/Services/WorkingMemory/Compactions/CompactionVersionWriter.php`
- Modify: `tests/Unit/Services/WorkingMemory/Compactions/CompactionVersionWriterTest.php`

- [ ] **Step 1: Write the failing tests**

Append to `tests/Unit/Services/WorkingMemory/Compactions/CompactionVersionWriterTest.php`:

```php
    #[Test]
    public function it_persists_compactions_when_payload_validates(): void
    {
        config(['working_memory.compaction_validation_enforced' => true]);

        $user = User::factory()->create();
        $thought = Thought::factory()->create(['user_id' => $user->id]);
        $sourceUrl = 'https://example.com/source';

        $version = app(CompactionVersionWriter::class)->write(
            userId: $user->id,
            scopeType: 'project',
            scopeKey: 'dezeen',
            buildType: 'compaction:weekly-digest',
            summaryMarkdown: "## Latest Signals\n- Signal observed.",
            structuredSections: [
                'Latest Signals' => [['text' => 'Signal observed.', 'importance' => 1, 'fallback_mode' => 'direct',
                    'citations' => [['type' => 'source', 'url' => $sourceUrl, 'label' => 'Source']]]],
                'Active Priorities' => [['text' => 'Active priority captured.', 'importance' => 1, 'fallback_mode' => 'direct',
                    'citations' => [['type' => 'source', 'url' => $sourceUrl, 'label' => 'Source']]]],
                'Recent Changes' => [['text' => 'Change recorded.', 'importance' => 1, 'fallback_mode' => 'direct',
                    'citations' => [['type' => 'source', 'url' => $sourceUrl, 'label' => 'Source']]]],
            ],
            references: [['type' => 'source', 'url' => $sourceUrl, 'label' => 'Source']],
            sourceThoughtIds: [$thought->id],
        );

        $this->assertNotNull($version);
        $this->assertSame('compaction:weekly-digest', $version->build_type);
    }

    #[Test]
    public function it_aborts_persistence_when_enforcement_is_on_and_validator_hard_fails(): void
    {
        config(['working_memory.compaction_validation_enforced' => true]);
        \Illuminate\Support\Facades\Log::spy();

        $user = User::factory()->create();
        $thought = Thought::factory()->create(['user_id' => $user->id]);

        $version = app(CompactionVersionWriter::class)->write(
            userId: $user->id,
            scopeType: 'project',
            scopeKey: 'dezeen',
            buildType: 'compaction:weekly-digest',
            summaryMarkdown: "## Latest Signals\n- Signal observed.",
            // Intentionally empty citations + empty references — validator hard-fails on missing_citation.
            structuredSections: [
                'Latest Signals' => [['text' => 'Signal observed.', 'importance' => 1, 'fallback_mode' => 'direct', 'citations' => []]],
                'Active Priorities' => [['text' => 'Active priority captured.', 'importance' => 1, 'fallback_mode' => 'direct', 'citations' => []]],
                'Recent Changes' => [['text' => 'Change recorded.', 'importance' => 1, 'fallback_mode' => 'direct', 'citations' => []]],
            ],
            references: [],
            sourceThoughtIds: [$thought->id],
        );

        $this->assertNull($version);
        $this->assertSame(0, \App\Models\WorkingMemoryVersion::query()->count());
        \Illuminate\Support\Facades\Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message, $context = []) => str_contains((string) $message, 'CompactionVersionWriter'))
            ->atLeast()->once();
    }

    #[Test]
    public function it_persists_with_observation_log_when_enforcement_is_off_and_validator_hard_fails(): void
    {
        config(['working_memory.compaction_validation_enforced' => false]);
        \Illuminate\Support\Facades\Log::spy();

        $user = User::factory()->create();
        $thought = Thought::factory()->create(['user_id' => $user->id]);

        $version = app(CompactionVersionWriter::class)->write(
            userId: $user->id,
            scopeType: 'project',
            scopeKey: 'dezeen',
            buildType: 'compaction:weekly-digest',
            summaryMarkdown: "## Latest Signals\n- Signal observed.",
            structuredSections: [
                'Latest Signals' => [['text' => 'Signal observed.', 'importance' => 1, 'fallback_mode' => 'direct', 'citations' => []]],
                'Active Priorities' => [['text' => 'Active priority captured.', 'importance' => 1, 'fallback_mode' => 'direct', 'citations' => []]],
                'Recent Changes' => [['text' => 'Change recorded.', 'importance' => 1, 'fallback_mode' => 'direct', 'citations' => []]],
            ],
            references: [],
            sourceThoughtIds: [$thought->id],
        );

        $this->assertNotNull($version);
        $this->assertSame('compaction:weekly-digest', $version->build_type);
        \Illuminate\Support\Facades\Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message, $context = []) => str_contains((string) $message, 'CompactionVersionWriter'))
            ->atLeast()->once();
    }
```

Note: also update the existing `it_persists_a_compaction_version_with_thought_inputs` test signature: that test passes empty references and empty citations and expects success. It currently passes because no validator runs. Once Task B3 lands, that test still passes when `compaction_validation_enforced` is `false` (which is the default). Confirm by re-running it after Step 3.

- [ ] **Step 2: Run tests to verify the new ones fail**

Run: `php artisan test --filter='CompactionVersionWriterTest'`
Expected: FAIL — first new test fails because the existing writer signature does not accept a validator-aware path; second test fails because the writer always returns a non-null version; third test logs nothing.

- [ ] **Step 3: Implement gating in the writer**

Replace the entire body of `app/Services/WorkingMemory/Compactions/CompactionVersionWriter.php`:

```php
<?php

namespace App\Services\WorkingMemory\Compactions;

use App\Models\WorkingMemory;
use App\Models\WorkingMemoryVersion;
use App\Services\WorkingMemory\WorkingMemoryOutputValidator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class CompactionVersionWriter
{
    /**
     * Required sections per compaction subtype. Mirrors what each prompt
     * builder explicitly tells the model to produce. Used to scope the
     * validator's required-sections check so the canonical 8-section gate
     * does not hard-fail every compaction.
     *
     * @var array<string, array<int, string>>
     */
    private const REQUIRED_SECTIONS = [
        'compaction:meeting' => ['Summary', 'Decisions', 'Action Items', 'Risks / Blockers', 'Open Questions'],
        'compaction:weekly-digest' => ['Latest Signals', 'Active Priorities', 'Recent Changes'],
        'compaction:topic-digest' => ['Active Priorities', 'Open Questions', 'Latest Signals'],
        'compaction:research-synth' => ['Open Questions', 'Risks / Blockers', 'Latest Signals', 'Source Notes'],
    ];

    public function __construct(
        private readonly CompactionRetentionService $retention,
        private readonly WorkingMemoryOutputValidator $validator,
    ) {}

    /**
     * @param  array<string, array<int, array<string, mixed>>>  $structuredSections
     * @param  array<int, array{type: string, url: string, label: string}>  $references
     * @param  array<int, string>  $sourceThoughtIds
     */
    public function write(
        int $userId,
        string $scopeType,
        string $scopeKey,
        string $buildType,
        string $summaryMarkdown,
        array $structuredSections,
        array $references,
        array $sourceThoughtIds,
    ): ?WorkingMemoryVersion {
        if (! str_starts_with($buildType, 'compaction:')) {
            throw new InvalidArgumentException(
                "CompactionVersionWriter only accepts compaction:* build types, got: {$buildType}"
            );
        }

        $validation = $this->validator->validate(
            payload: [
                'summary_markdown' => $summaryMarkdown,
                'structured_sections' => $structuredSections,
                'references' => $references,
            ],
            minimumCoverage: null,
            compactionCountInScope: 0,
            requiredSections: self::REQUIRED_SECTIONS[$buildType] ?? null,
        );

        $isHardFail = ($validation['ok'] ?? false) === false
            && ($validation['failure_type'] ?? null) === 'hard';

        if ($isHardFail) {
            $context = [
                'user_id' => $userId,
                'scope_type' => $scopeType,
                'scope_key' => $scopeKey,
                'build_type' => $buildType,
                'reason_codes' => $validation['diagnostics']['reason_codes'] ?? [],
                'message' => $validation['message'] ?? null,
            ];

            if ((bool) config('working_memory.compaction_validation_enforced', false)) {
                Log::warning('CompactionVersionWriter aborted persistence (validator hard-fail).', $context);

                return null;
            }

            Log::warning('CompactionVersionWriter observed validator hard-fail (enforcement off).', $context);
        }

        return DB::transaction(function () use (
            $userId,
            $scopeType,
            $scopeKey,
            $buildType,
            $summaryMarkdown,
            $structuredSections,
            $references,
            $sourceThoughtIds,
        ): WorkingMemoryVersion {
            $memory = WorkingMemory::query()->firstOrCreate(
                [
                    'user_id' => $userId,
                    'scope_type' => $scopeType,
                    'scope_key' => $scopeKey,
                ],
                [
                    'freshness_state' => 'stale',
                ]
            );

            $version = $memory->versions()->create([
                'build_type' => $buildType,
                'summary_markdown' => $summaryMarkdown,
                'structured_sections_json' => $structuredSections,
                'references_json' => $references,
                'authoring_status' => 'validated',
                'confidence_score' => 0,
            ]);

            foreach (array_unique($sourceThoughtIds) as $thoughtId) {
                $version->inputs()->create([
                    'thought_id' => $thoughtId,
                    'source_version_id' => null,
                    'contribution_type' => 'compaction-source',
                    'weight' => 1.0,
                ]);
            }

            $this->retention->trim($memory, $buildType);

            return $version;
        });
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter='CompactionVersionWriterTest'`
Expected: PASS — both new tests pass; existing `it_persists_a_compaction_version_with_thought_inputs` still passes (default config `compaction_validation_enforced=false` makes hard-fails permissive).

- [ ] **Step 5: Run the broader compaction job slice**

Run:
```bash
php artisan test --filter='SynthesizeMeetingCompactionJobTest|BuildScopeDigestJobTest|SynthesizeResearchCompactionJobTest|BuildTopicDigestJobTest'
```
Expected: PASS — current LLM mocks return empty `citations: []` and `references: []`, but with `compaction_validation_enforced=false` (default) the writer logs the hard-fail and persists anyway, so existing assertions on `WorkingMemoryVersion` still hold.

- [ ] **Step 6: Commit**

```bash
git add config/working_memory.php app/Services/WorkingMemory/Compactions/CompactionVersionWriter.php tests/Unit/Services/WorkingMemory/Compactions/CompactionVersionWriterTest.php
git commit -m "working-memory(compactions): gate CompactionVersionWriter behind WorkingMemoryOutputValidator

Adds a per-compaction validator pass with subtype-aware required sections.
The gate logs hard-fail diagnostics on every write; when
working_memory.compaction_validation_enforced is true, hard-fails abort
persistence. Defaults to false so the gate ships in observation mode
until prompt builders are updated to populate references[] with source
thought permalinks."
```

---

### Task B4: Task-B regression sweep

- [ ] **Step 1: Run the broad slice**

Run:
```bash
php artisan test --filter='WorkingMemory|Compaction|MeetingCompaction|ResearchCompaction|ScopeDigest|TopicDigest|ResearchSynthesis|ThoughtObserver|McpApi|LlmJsonDecoder|OpenRouterService|Compactions:|MeetingPrimaryScopeResolver'
```
Expected: PASS, 0 failures, count >= 268 (261 after Task A + 7 new tests across B1-B3).

- [ ] **Step 2: Confirm scheduler and routes still resolve**

Run: `php artisan schedule:list`
Expected: existing entries unchanged. No new scheduled entry — topic-digest is on-demand only.

- [ ] **Step 3: No commit needed**

Verification only.

---

## Out-of-scope follow-up (not part of this plan)

To make the per-compaction gate enforcing in production, a follow-up is needed:

1. Update each compaction prompt builder (`MeetingCompactionPromptBuilder`, `ScopeDigestPromptBuilder`, `ResearchSynthesisPromptBuilder`, `TopicDigestPromptBuilder`) to include the source thought permalinks in the prompt and instruct the model to populate `references[]` with `{type: "thought", url: <permalink>, label: <thought_id>}` entries plus item-level `citations`.
2. Update each producer job's LLM-mock test fixtures so the model's mocked output includes those citations.
3. Set `WORKING_MEMORY_COMPACTION_VALIDATION_ENFORCED=true` in production env once the prompt rollout is verified across at least one full compaction cycle.

That follow-up is sized similarly to Task B and should land as its own plan.

---

## Self-Review

**Spec coverage:**
- Topic-digest build_type (design line 57) — Task A delivers prompt builder, job, command.
- Topic-digest promotion sections — encoded in prompt (Active Priorities, Open Questions, Latest Signals).
- Per-compaction validator gate (design line 218) — Task B delivers validator injection + per-subtype sections + observation/enforcement modes.
- The "discarded (not persisted)" semantics — Task B3 returns `null` and writes nothing when enforced + hard-fail.
- The "diagnostic is emitted" semantics — Task B3 logs `Log::warning` with reason codes on every hard-fail.

**Placeholder scan:** No "TBD", "TODO", "implement later", or vague handwaves. Every code block is concrete and complete.

**Type consistency:**
- `BuildTopicDigestJob` constructor: `(int $userId, string $scopeType, string $scopeKey, string $topic)` — matches test fixtures.
- `TopicDigestPromptBuilder::build` signature: `(string $scopeType, string $scopeKey, string $topic, Collection $thoughts)` — matches test fixture and job call site.
- `WorkingMemoryOutputValidator::validate` signature: `(array $payload, ?float $minimumCoverage, int $compactionCountInScope, ?array $requiredSections)` — matches both new validator tests and the writer call site.
- `CompactionVersionWriter::write` return type: `?WorkingMemoryVersion` — matches the abort-on-enforcement test (`assertNull`) and the persist tests (`assertNotNull`).
- Required-sections map keys match exactly what each prompt builder declares ("Risks / Blockers" includes the spaces).

---

## Execution Handoff

**Plan complete and saved to `docs/superpowers/plans/2026-05-07-topic-digest-and-compaction-gate.md`. Two execution options:**

**1. Subagent-Driven (recommended)** — Dispatch a fresh subagent per task, review between tasks, fast iteration.

**2. Inline Execution** — Execute tasks in this session using executing-plans, batch execution with checkpoints.

Which approach?
