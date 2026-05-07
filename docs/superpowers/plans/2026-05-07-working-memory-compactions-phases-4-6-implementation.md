# Working Memory Compactions (Phases 4–6 + Quality) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship the remaining compaction subtypes from the design (`compaction:weekly-digest`, `compaction:research-synth`), backfill historical compactions for existing scopes, and complete the cross-cutting quality + diagnostics work the Phase 1–3 plan deliberately deferred.

**Architecture:** Two new periodic jobs (`BuildScopeDigestJob`, `SynthesizeResearchCompactionJob`) reuse the existing `CompactionVersionWriter` and `LlmJsonDecoder`. Two new console commands (`working-memory:bootstrap`, `compactions:rebuild`) drive synchronous backfills. `WorkingMemoryOutputValidator` gains an `unused_compaction` soft-failure; `WorkingMemoryBuilderService` extends `build_diagnostics_json` with compaction coverage fields. A retention cleanup keeps compactions per scope under a configurable cap.

**Tech Stack:** Laravel 12, PHP 8.2+, Eloquent migrations and models, Pest tests, OpenRouter via existing `OpenRouterService::researchFromPrompt`.

**Spec:** [`docs/superpowers/specs/2026-05-07-working-memory-compactions-design.md`](../specs/2026-05-07-working-memory-compactions-design.md). This plan implements rollout phases 4–6 plus the design's "Implementation checklist" items that were not part of the Phase 1–3 plan: validator extensions (`unused_compaction`, per-compaction coverage), `build_diagnostics_json` fields, and per-scope retention.

---

## Scope check

This plan covers one cohesive subsystem: extending the working-memory compaction layer that landed in Phase 1–3. It is sized similarly to the Phase 1–3 plan and produces working software at each commit. Phases 4 and 5 are independent jobs and could ship separately, but the cross-cutting quality + diagnostics work (Phase A below) is a prerequisite for both, and the bootstrap command (Phase D) needs all subtypes present to be useful — keeping them in one plan keeps the rollout coherent.

## File structure (creates + touches)

| Path | Responsibility |
|---|---|
| `app/Services/WorkingMemory/WorkingMemoryOutputValidator.php` | Add `unused_compaction` soft-failure path. |
| `app/Services/WorkingMemory/WorkingMemoryBuilderService.php` | Merge compaction-coverage diagnostics into `build_diagnostics_json`. |
| `app/Services/WorkingMemory/Compactions/ScopeDigestPromptBuilder.php` | Build prompt for `compaction:weekly-digest`. |
| `app/Services/WorkingMemory/Compactions/ResearchSynthesisPromptBuilder.php` | Build prompt for `compaction:research-synth`. |
| `app/Services/WorkingMemory/Compactions/CompactionRetentionService.php` | Trim compactions per scope per subtype past the retention cap. |
| `app/Jobs/BuildScopeDigestJob.php` | Periodic per-scope weekly digest. |
| `app/Jobs/SynthesizeResearchCompactionJob.php` | Threshold-triggered research synthesis. |
| `app/Console/Commands/BuildWeeklyDigestsCommand.php` | `compactions:digest` — enqueue digest jobs for active scopes. |
| `app/Console/Commands/BuildResearchSynthesesCommand.php` | `compactions:research` — enqueue research syntheses for tripped thresholds. |
| `app/Console/Commands/WorkingMemoryBootstrapCommand.php` | `working-memory:bootstrap` — backfill historical compactions for a scope. |
| `app/Console/Commands/CompactionsRebuildCommand.php` | `compactions:rebuild` — manual recompute for a single subtype. |
| `routes/console.php` | Register the two new schedules. |
| `config/working_memory.php` | Add digest/research/retention/threshold config keys. |
| `tests/Unit/Services/WorkingMemory/WorkingMemoryOutputValidatorTest.php` | `unused_compaction` soft-failure. |
| `tests/Unit/Services/WorkingMemory/Compactions/ScopeDigestPromptBuilderTest.php` | Prompt shape. |
| `tests/Unit/Services/WorkingMemory/Compactions/ResearchSynthesisPromptBuilderTest.php` | Prompt shape. |
| `tests/Unit/Services/WorkingMemory/Compactions/CompactionRetentionServiceTest.php` | Retention behavior. |
| `tests/Feature/BuildScopeDigestJobTest.php` | Digest job end-to-end (mocked OpenRouter). |
| `tests/Feature/SynthesizeResearchCompactionJobTest.php` | Research job end-to-end. |
| `tests/Feature/BuildWeeklyDigestsCommandTest.php` | Command enumerates scopes and dispatches. |
| `tests/Feature/BuildResearchSynthesesCommandTest.php` | Command honors threshold. |
| `tests/Feature/WorkingMemoryBootstrapCommandTest.php` | Bootstrap dispatches all compaction jobs synchronously then a consolidated authoring pass. |
| `tests/Feature/CompactionsRebuildCommandTest.php` | Manual recompute for one subtype. |
| `tests/Unit/Services/WorkingMemory/WorkingMemoryBuilderServiceDiagnosticsTest.php` | Builder writes new diagnostic fields. |

---

## Phase A — Quality + diagnostics (cross-cutting)

### Task 1: Add compaction-coverage diagnostics to `build_diagnostics_json`

**Files:**
- Modify: `app/Services/WorkingMemory/WorkingMemoryBuilderService.php`
- Test: `tests/Unit/Services/WorkingMemory/WorkingMemoryBuilderServiceDiagnosticsTest.php`

The builder already persists `build_diagnostics_json` from the validator's diagnostics dict. We merge four new fields on top after a successful authoring pass: `compaction_inputs_count`, `compaction_subtypes_used`, `raw_thought_inputs_count`, `compaction_coverage_ratio`.

- [ ] **Step 1: Write failing test for diagnostics fields**

```php
<?php

namespace Tests\Unit\Services\WorkingMemory;

use App\Models\Thought;
use App\Models\User;
use App\Models\WorkingMemory;
use App\Models\WorkingMemoryVersion;
use App\Services\OpenRouterService;
use App\Services\WorkingMemory\WorkingMemoryBuilderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class WorkingMemoryBuilderServiceDiagnosticsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function build_diagnostics_includes_compaction_coverage_fields(): void
    {
        Queue::fake();
        config([
            'working_memory.authoring_enabled' => true,
            'features.working_memory_ai_authored' => true,
        ]);

        $user = User::factory()->create();

        // Pre-existing meeting compaction in the scope.
        $memory = WorkingMemory::create([
            'user_id' => $user->id,
            'scope_type' => 'project',
            'scope_key' => 'dezeen',
            'freshness_state' => 'stale',
        ]);
        $compaction = $memory->versions()->create([
            'build_type' => 'compaction:meeting',
            'summary_markdown' => "## Summary\nWeekly check-in.",
            'structured_sections_json' => [],
            'references_json' => [],
            'authoring_status' => 'validated',
            'confidence_score' => 0,
        ]);

        Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Need to ship DEZ-2819.',
            'metadata' => ['type' => 'idea', 'tags' => ['project:dezeen']],
            'source_metadata' => ['project' => 'dezeen'],
        ]);

        $compactionUrl = "/memory/project/dezeen/compactions/{$compaction->id}";

        $openRouter = Mockery::mock(OpenRouterService::class);
        $openRouter->shouldReceive('researchFromPrompt')->andReturn(json_encode([
            'summary_markdown' => "# WM\n## Current Focus\n- Ship DEZ-2819 [1].",
            'structured_sections' => array_fill_keys(
                ['Current Focus', 'Active Priorities', 'Recent Changes', 'Open Questions', 'Risks / Blockers', 'Next Actions', 'Latest Signals', 'Source Notes'],
                [['text' => 'Ship DEZ-2819 [1].', 'importance' => 1, 'fallback_mode' => 'direct',
                  'citations' => [['type' => 'compaction', 'url' => $compactionUrl, 'label' => 'compaction:meeting']]]]
            ),
            'references' => [['type' => 'compaction', 'url' => $compactionUrl, 'label' => 'compaction:meeting']],
        ]));
        $this->app->instance(OpenRouterService::class, $openRouter);

        /** @var WorkingMemoryBuilderService $builder */
        $builder = app(WorkingMemoryBuilderService::class);
        $version = $builder->buildIncremental($user->id, 'project', 'dezeen');

        $diagnostics = $version->build_diagnostics_json;
        $this->assertIsArray($diagnostics);
        $this->assertArrayHasKey('compaction_inputs_count', $diagnostics);
        $this->assertArrayHasKey('compaction_subtypes_used', $diagnostics);
        $this->assertArrayHasKey('raw_thought_inputs_count', $diagnostics);
        $this->assertArrayHasKey('compaction_coverage_ratio', $diagnostics);

        $this->assertSame(1, $diagnostics['compaction_inputs_count']);
        $this->assertContains('meeting', $diagnostics['compaction_subtypes_used']);
        $this->assertGreaterThanOrEqual(1, $diagnostics['raw_thought_inputs_count']);
        $this->assertGreaterThan(0.0, $diagnostics['compaction_coverage_ratio']);
        $this->assertLessThanOrEqual(1.0, $diagnostics['compaction_coverage_ratio']);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Unit/Services/WorkingMemory/WorkingMemoryBuilderServiceDiagnosticsTest.php`
Expected: FAIL — diagnostics keys not present.

- [ ] **Step 3: Implement diagnostics merge in `WorkingMemoryBuilderService`**

In `app/Services/WorkingMemory/WorkingMemoryBuilderService.php`, locate the block where `$buildDiagnostics` is assigned from the validator and the `$evidencePack` and `$authoredOutput` are in scope. Add a private helper and call it before `$version = $memory->versions()->create([...])`.

Add the helper method to the class:

```php
    /**
     * @param  array<string, mixed>|null  $diagnostics
     * @param  array<string, mixed>  $evidencePack
     * @param  array<string, mixed>  $authoredOutput
     * @return array<string, mixed>
     */
    private function mergeCompactionDiagnostics(
        ?array $diagnostics,
        array $evidencePack,
        array $authoredOutput,
    ): array {
        $compactions = is_array($evidencePack['compactions'] ?? null) ? $evidencePack['compactions'] : [];
        $signals = is_array($evidencePack['signals'] ?? null) ? $evidencePack['signals'] : [];

        $subtypes = [];
        foreach ($compactions as $compaction) {
            $subtype = (string) ($compaction['subtype'] ?? '');
            if ($subtype !== '' && ! in_array($subtype, $subtypes, true)) {
                $subtypes[] = $subtype;
            }
        }

        $sections = is_array($authoredOutput['structured_sections'] ?? null)
            ? $authoredOutput['structured_sections']
            : [];

        $totalCited = 0;
        $compactionCited = 0;
        foreach ($sections as $items) {
            if (! is_array($items)) {
                continue;
            }
            foreach ($items as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $citations = is_array($item['citations'] ?? null) ? $item['citations'] : [];
                if ($citations === []) {
                    continue;
                }
                $totalCited++;
                foreach ($citations as $citation) {
                    if (is_array($citation) && ($citation['type'] ?? null) === 'compaction') {
                        $compactionCited++;
                        break;
                    }
                }
            }
        }

        $coverageRatio = $totalCited > 0
            ? round($compactionCited / $totalCited, 4)
            : 0.0;

        return array_merge($diagnostics ?? [], [
            'compaction_inputs_count' => count($compactions),
            'compaction_subtypes_used' => $subtypes,
            'raw_thought_inputs_count' => count($signals),
            'compaction_coverage_ratio' => $coverageRatio,
        ]);
    }
```

Then, in the `build()` method, immediately after the validator call inside the `$validation['ok']` happy branch (i.e. before the existing `if (($validation['ok'] ?? false) === true) {` block ends), reassign `$buildDiagnostics`:

```php
                if (($validation['ok'] ?? false) === true) {
                    $summaryMarkdown = (string) ($authoredOutput['summary_markdown'] ?? '');
                    $payload = $this->payloadFromStructuredSections($structuredSections, $thoughts);
                    $authoringStatus = 'validated';
                    $buildDiagnostics = $this->mergeCompactionDiagnostics(
                        $buildDiagnostics,
                        $evidencePack,
                        $authoredOutput,
                    );
                } elseif (($validation['failure_type'] ?? null) === 'soft') {
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Unit/Services/WorkingMemory/WorkingMemoryBuilderServiceDiagnosticsTest.php`
Expected: PASS.

- [ ] **Step 5: Run regression slice**

Run: `php artisan test --filter='WorkingMemoryBuilderService|WorkingMemoryFreshness'`
Expected: All pass (deprecation noise OK).

- [ ] **Step 6: Commit**

```bash
git add app/Services/WorkingMemory/WorkingMemoryBuilderService.php tests/Unit/Services/WorkingMemory/WorkingMemoryBuilderServiceDiagnosticsTest.php
git commit -m "feat(working-memory): add compaction-coverage diagnostics to build_diagnostics_json

Records compaction_inputs_count, compaction_subtypes_used, raw_thought_inputs_count
and compaction_coverage_ratio on each canonical version so we can track ramp-up of
compaction usage and triage soft-fails."
```

---

### Task 2: `unused_compaction` soft-failure in `WorkingMemoryOutputValidator`

**Files:**
- Modify: `app/Services/WorkingMemory/WorkingMemoryOutputValidator.php`
- Test: `tests/Unit/Services/WorkingMemory/WorkingMemoryOutputValidatorTest.php`

When the evidence pack contains compactions but the canonical version cites zero `type: compaction` references, raise a soft-failure (`unused_compaction`). The composer should always pull at least one compaction citation when compactions exist; if it doesn't, fall back rather than promoting the questionable output as `validated`.

The validator currently does not see the evidence pack. We extend `validate()` with an optional third parameter — the count of compactions visible in the evidence — and add a soft-failure check.

- [ ] **Step 1: Write failing test**

Append to (or create) `tests/Unit/Services/WorkingMemory/WorkingMemoryOutputValidatorTest.php`:

```php
    #[\PHPUnit\Framework\Attributes\Test]
    public function it_soft_fails_when_compactions_exist_but_payload_does_not_cite_any(): void
    {
        $thoughtUrl = 'https://ideatub.test/thoughts/abc-123';
        $payload = [
            'summary_markdown' => '# WM',
            'structured_sections' => array_fill_keys(
                ['Current Focus', 'Active Priorities', 'Recent Changes', 'Open Questions', 'Risks / Blockers', 'Next Actions', 'Latest Signals', 'Source Notes'],
                [[
                    'text' => 'Ship DEZ-2819.',
                    'importance' => 1,
                    'fallback_mode' => 'direct',
                    'citations' => [['type' => 'thought', 'url' => $thoughtUrl, 'label' => 'abc-123']],
                ]]
            ),
            'references' => [['type' => 'thought', 'url' => $thoughtUrl, 'label' => 'abc-123']],
        ];

        $validator = new \App\Services\WorkingMemory\WorkingMemoryOutputValidator;

        $result = $validator->validate($payload, null, compactionCountInScope: 3);

        $this->assertFalse($result['ok']);
        $this->assertSame('soft', $result['failure_type']);
        $this->assertContains('unused_compaction', $result['diagnostics']['reason_codes']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_passes_when_compactions_exist_and_payload_cites_at_least_one(): void
    {
        $compactionUrl = '/memory/project/dezeen/compactions/v-1';
        $payload = [
            'summary_markdown' => '# WM',
            'structured_sections' => array_fill_keys(
                ['Current Focus', 'Active Priorities', 'Recent Changes', 'Open Questions', 'Risks / Blockers', 'Next Actions', 'Latest Signals', 'Source Notes'],
                [[
                    'text' => 'Ship DEZ-2819.',
                    'importance' => 1,
                    'fallback_mode' => 'direct',
                    'citations' => [['type' => 'compaction', 'url' => $compactionUrl, 'label' => 'compaction:meeting']],
                ]]
            ),
            'references' => [['type' => 'compaction', 'url' => $compactionUrl, 'label' => 'compaction:meeting']],
        ];

        $validator = new \App\Services\WorkingMemory\WorkingMemoryOutputValidator;

        $result = $validator->validate($payload, null, compactionCountInScope: 1);

        $this->assertTrue($result['ok']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_does_not_check_unused_compaction_when_no_compactions_exist(): void
    {
        $thoughtUrl = 'https://ideatub.test/thoughts/abc-123';
        $payload = [
            'summary_markdown' => '# WM',
            'structured_sections' => array_fill_keys(
                ['Current Focus', 'Active Priorities', 'Recent Changes', 'Open Questions', 'Risks / Blockers', 'Next Actions', 'Latest Signals', 'Source Notes'],
                [[
                    'text' => 'Ship DEZ-2819.',
                    'importance' => 1,
                    'fallback_mode' => 'direct',
                    'citations' => [['type' => 'thought', 'url' => $thoughtUrl, 'label' => 'abc-123']],
                ]]
            ),
            'references' => [['type' => 'thought', 'url' => $thoughtUrl, 'label' => 'abc-123']],
        ];

        $validator = new \App\Services\WorkingMemory\WorkingMemoryOutputValidator;

        $result = $validator->validate($payload, null, compactionCountInScope: 0);

        $this->assertTrue($result['ok']);
    }
```

If the test file does not exist, create it with a class wrapper:

```php
<?php

namespace Tests\Unit\Services\WorkingMemory;

use Tests\TestCase;

final class WorkingMemoryOutputValidatorTest extends TestCase
{
    // (paste the three test methods above here)
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter='WorkingMemoryOutputValidatorTest'`
Expected: FAIL — `validate()` does not accept a third argument; `unused_compaction` reason code missing.

- [ ] **Step 3: Extend `validate()` signature and add the soft-failure path**

In `app/Services/WorkingMemory/WorkingMemoryOutputValidator.php`, change the `validate()` signature:

```php
    public function validate(array $payload, ?float $minimumCoverage = null, int $compactionCountInScope = 0): array
```

Then, immediately before the existing `return [ 'ok' => true, ... ]` happy path at the end of `validate()`, add the unused-compaction guard:

```php
        if ($compactionCountInScope > 0 && ! $this->hasAnyCompactionCitation($sections)) {
            return [
                'ok' => false,
                'message' => 'Working memory must cite at least one compaction when compactions exist in scope.',
                'coveragePercent' => $coveragePercent,
                'failure_type' => 'soft',
                'diagnostics' => $this->diagnosticsPayload(
                    $requiredItems,
                    $citedItems,
                    ['unused_compaction']
                ),
            ];
        }
```

Add the helper at the bottom of the class:

```php
    /**
     * @param  array<string, mixed>  $sections
     */
    private function hasAnyCompactionCitation(array $sections): bool
    {
        foreach ($sections as $items) {
            if (! is_array($items)) {
                continue;
            }
            foreach ($items as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $citations = is_array($item['citations'] ?? null) ? $item['citations'] : [];
                foreach ($citations as $citation) {
                    if (is_array($citation) && ($citation['type'] ?? null) === 'compaction') {
                        return true;
                    }
                }
            }
        }

        return false;
    }
```

- [ ] **Step 4: Wire the new argument from `WorkingMemoryBuilderService`**

In `WorkingMemoryBuilderService::build()`, change the validator call to pass the compaction count from the evidence pack:

```php
                $validation = $this->outputValidator->validate(
                    $authoredOutput,
                    (float) config('working_memory.citation_min_coverage', 0.90),
                    count(is_array($evidencePack['compactions'] ?? null) ? $evidencePack['compactions'] : [])
                );
```

- [ ] **Step 5: Run tests**

Run: `php artisan test --filter='WorkingMemoryOutputValidatorTest|WorkingMemoryBuilderService|WorkingMemoryFreshness'`
Expected: PASS (the new tests plus the existing builder/freshness slice).

- [ ] **Step 6: Commit**

```bash
git add app/Services/WorkingMemory/WorkingMemoryOutputValidator.php app/Services/WorkingMemory/WorkingMemoryBuilderService.php tests/Unit/Services/WorkingMemory/WorkingMemoryOutputValidatorTest.php
git commit -m "feat(working-memory): soft-fail when compactions exist but payload cites none

Adds unused_compaction soft-failure to WorkingMemoryOutputValidator. Builder threads
the compaction count from the evidence pack so the validator can decide whether the
guard applies. Result: a canonical version that 'forgets' to cite available compactions
falls back instead of being promoted as validated."
```

---

## Phase B — Phase 4: Digest compactions

### Task 3: `ScopeDigestPromptBuilder`

**Files:**
- Create: `app/Services/WorkingMemory/Compactions/ScopeDigestPromptBuilder.php`
- Test: `tests/Unit/Services/WorkingMemory/Compactions/ScopeDigestPromptBuilderTest.php`

Builds a digest prompt over a list of recent thoughts in a scope window (typically the last 7 days). Returns a single string. Output contract is JSON with `summary_markdown`, `structured_sections` (Latest Signals / Active Priorities / Recent Changes), and `references`.

- [ ] **Step 1: Write failing test**

```php
<?php

namespace Tests\Unit\Services\WorkingMemory\Compactions;

use App\Models\Thought;
use App\Services\WorkingMemory\Compactions\ScopeDigestPromptBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ScopeDigestPromptBuilderTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_renders_required_sections_and_thought_blocks(): void
    {
        $thoughtA = new Thought([
            'id' => 't-1',
            'content' => 'Shipped DEZ-2819 hotfix.',
        ]);
        $thoughtA->created_at = Carbon::parse('2026-05-06T10:00:00Z');

        $thoughtB = new Thought([
            'id' => 't-2',
            'content' => 'Open question on observability budget.',
        ]);
        $thoughtB->created_at = Carbon::parse('2026-05-06T12:00:00Z');

        $builder = new ScopeDigestPromptBuilder;
        $prompt = $builder->build(
            scopeType: 'project',
            scopeKey: 'dezeen',
            windowStart: Carbon::parse('2026-04-30T00:00:00Z'),
            windowEnd: Carbon::parse('2026-05-07T00:00:00Z'),
            thoughts: new Collection([$thoughtA, $thoughtB]),
        );

        $this->assertStringContainsString('## Scope digest task', $prompt);
        $this->assertStringContainsString('Scope: project / dezeen', $prompt);
        $this->assertStringContainsString('Latest Signals', $prompt);
        $this->assertStringContainsString('Active Priorities', $prompt);
        $this->assertStringContainsString('Recent Changes', $prompt);
        $this->assertStringContainsString('Shipped DEZ-2819 hotfix.', $prompt);
        $this->assertStringContainsString('thought:t-1', $prompt);
        $this->assertStringContainsString('thought:t-2', $prompt);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Unit/Services/WorkingMemory/Compactions/ScopeDigestPromptBuilderTest.php`
Expected: FAIL — class does not exist.

- [ ] **Step 3: Create the prompt builder**

```php
<?php

namespace App\Services\WorkingMemory\Compactions;

use App\Models\Thought;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class ScopeDigestPromptBuilder
{
    /**
     * @param  Collection<int, Thought>  $thoughts
     */
    public function build(
        string $scopeType,
        string $scopeKey,
        Carbon $windowStart,
        Carbon $windowEnd,
        Collection $thoughts,
    ): string {
        $maxChars = (int) config('working_memory.authoring_max_prompt_input_chars', 60000);
        $body = $this->renderThoughts($thoughts);

        $prompt = <<<TEXT
## Scope digest task

Produce a durable digest compaction over the recent activity in this scope.

Scope: {$scopeType} / {$scopeKey}
Window: {$windowStart->toIso8601String()} → {$windowEnd->toIso8601String()}

## Recent thoughts
{$body}

## Output contract

Return JSON only (no Markdown fences) with this shape:

{
  "summary_markdown": "<full markdown with the sections below as `## <section>`>",
  "structured_sections": {
    "Latest Signals": [{"text": "...", "importance": 1, "fallback_mode": "direct", "citations": []}],
    "Active Priorities": [...],
    "Recent Changes": [...]
  },
  "references": []
}

Rules:
- The three required sections are: Latest Signals, Active Priorities, Recent Changes.
- Cluster related thoughts; do not repeat the same point in multiple sections.
- Latest Signals = newly observed information that may shape next decisions.
- Active Priorities = work currently in flight or claimed for next.
- Recent Changes = factual deltas that already happened.
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
            return '_No thoughts in window._';
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

Run: `php artisan test tests/Unit/Services/WorkingMemory/Compactions/ScopeDigestPromptBuilderTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/WorkingMemory/Compactions/ScopeDigestPromptBuilder.php tests/Unit/Services/WorkingMemory/Compactions/ScopeDigestPromptBuilderTest.php
git commit -m "feat(working-memory): add ScopeDigestPromptBuilder for compaction:weekly-digest"
```

---

### Task 4: `BuildScopeDigestJob`

**Files:**
- Create: `app/Jobs/BuildScopeDigestJob.php`
- Test: `tests/Feature/BuildScopeDigestJobTest.php`

Synthesizes a `compaction:weekly-digest` for one (user, scope_type, scope_key) over the last N days (default 7). Idempotent: skip if a digest already exists with `created_at >= windowEnd`.

Add config keys upfront:

```php
// config/working_memory.php (append inside the array, before the closing ];)
'digest_window_days' => (int) env('WORKING_MEMORY_DIGEST_WINDOW_DAYS', 7),
'digest_min_thoughts' => (int) env('WORKING_MEMORY_DIGEST_MIN_THOUGHTS', 3),
```

- [ ] **Step 1: Write failing job test**

```php
<?php

namespace Tests\Feature;

use App\Jobs\BuildScopeDigestJob;
use App\Models\Thought;
use App\Models\User;
use App\Models\WorkingMemoryVersion;
use App\Services\OpenRouterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class BuildScopeDigestJobTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_creates_a_weekly_digest_compaction_for_active_scope(): void
    {
        Queue::fake(); // suppress incidental observer dispatches
        Carbon::setTestNow(Carbon::parse('2026-05-07T10:00:00Z'));

        $openRouter = Mockery::mock(OpenRouterService::class);
        $openRouter->shouldReceive('researchFromPrompt')->once()->andReturn(json_encode([
            'summary_markdown' => "## Latest Signals\n- Observability budget under review.",
            'structured_sections' => [
                'Latest Signals' => [['text' => 'Observability budget under review.', 'importance' => 1, 'fallback_mode' => 'direct', 'citations' => []]],
                'Active Priorities' => [],
                'Recent Changes' => [],
            ],
            'references' => [],
        ]));
        $this->app->instance(OpenRouterService::class, $openRouter);

        $user = User::factory()->create();

        Thought::factory()->count(4)->create([
            'user_id' => $user->id,
            'metadata' => ['type' => 'idea', 'tags' => ['project:dezeen']],
            'source_metadata' => ['project' => 'dezeen'],
            'created_at' => Carbon::parse('2026-05-04T10:00:00Z'),
        ]);

        BuildScopeDigestJob::dispatchSync($user->id, 'project', 'dezeen');

        $version = WorkingMemoryVersion::query()
            ->where('build_type', 'compaction:weekly-digest')
            ->first();

        $this->assertNotNull($version);
        $this->assertStringContainsString('Observability budget', (string) $version->summary_markdown);
        $this->assertSame('project', $version->workingMemory->scope_type);
        $this->assertSame('dezeen', $version->workingMemory->scope_key);
    }

    #[Test]
    public function it_skips_when_below_minimum_thoughts(): void
    {
        Queue::fake();
        config(['working_memory.digest_min_thoughts' => 3]);
        Carbon::setTestNow(Carbon::parse('2026-05-07T10:00:00Z'));

        $openRouter = Mockery::mock(OpenRouterService::class);
        $openRouter->shouldNotReceive('researchFromPrompt');
        $this->app->instance(OpenRouterService::class, $openRouter);

        $user = User::factory()->create();
        Thought::factory()->count(2)->create([
            'user_id' => $user->id,
            'metadata' => ['type' => 'idea', 'tags' => ['project:dezeen']],
            'source_metadata' => ['project' => 'dezeen'],
            'created_at' => Carbon::parse('2026-05-04T10:00:00Z'),
        ]);

        BuildScopeDigestJob::dispatchSync($user->id, 'project', 'dezeen');

        $this->assertSame(0, WorkingMemoryVersion::query()->where('build_type', 'compaction:weekly-digest')->count());
    }

    #[Test]
    public function it_is_idempotent_when_a_recent_digest_already_exists(): void
    {
        Queue::fake();

        $openRouter = Mockery::mock(OpenRouterService::class);
        $openRouter->shouldNotReceive('researchFromPrompt');
        $this->app->instance(OpenRouterService::class, $openRouter);

        $user = User::factory()->create();

        // Create a pre-existing digest one day before "now" (Eloquent timestamps honor Carbon::setTestNow).
        Carbon::setTestNow(Carbon::parse('2026-05-06T10:00:00Z'));
        $memory = \App\Models\WorkingMemory::create([
            'user_id' => $user->id,
            'scope_type' => 'project',
            'scope_key' => 'dezeen',
            'freshness_state' => 'stale',
        ]);
        $memory->versions()->create([
            'build_type' => 'compaction:weekly-digest',
            'summary_markdown' => 'existing',
            'structured_sections_json' => [],
            'references_json' => [],
            'authoring_status' => 'validated',
            'confidence_score' => 0,
        ]);

        Thought::factory()->count(5)->create([
            'user_id' => $user->id,
            'metadata' => ['type' => 'idea', 'tags' => ['project:dezeen']],
            'source_metadata' => ['project' => 'dezeen'],
        ]);

        Carbon::setTestNow(Carbon::parse('2026-05-07T10:00:00Z'));
        BuildScopeDigestJob::dispatchSync($user->id, 'project', 'dezeen');

        $this->assertSame(1, WorkingMemoryVersion::query()->where('build_type', 'compaction:weekly-digest')->count());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Mockery::close();
        parent::tearDown();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/BuildScopeDigestJobTest.php`
Expected: FAIL — job does not exist.

- [ ] **Step 3: Implement the job**

```php
<?php

namespace App\Jobs;

use App\Models\Thought;
use App\Models\WorkingMemory;
use App\Models\WorkingMemoryVersion;
use App\Services\OpenRouterService;
use App\Services\WorkingMemory\Compactions\CompactionVersionWriter;
use App\Services\WorkingMemory\Compactions\ScopeDigestPromptBuilder;
use App\Support\Json\LlmJsonDecoder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

class BuildScopeDigestJob implements ShouldQueue
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
    ) {}

    public function handle(
        ScopeDigestPromptBuilder $promptBuilder,
        OpenRouterService $openRouter,
        CompactionVersionWriter $writer,
    ): void {
        if ($this->userId <= 0 || $this->scopeType === '' || $this->scopeKey === '') {
            return;
        }

        $now = Carbon::now();
        $windowDays = (int) config('working_memory.digest_window_days', 7);
        $minThoughts = (int) config('working_memory.digest_min_thoughts', 3);
        $windowStart = $now->copy()->subDays($windowDays);
        $windowEnd = $now->copy();

        if ($this->hasFreshDigest($windowStart)) {
            return;
        }

        $thoughts = $this->collectScopedThoughts($windowStart, $windowEnd);
        if ($thoughts->count() < $minThoughts) {
            return;
        }

        try {
            $prompt = $promptBuilder->build(
                $this->scopeType,
                $this->scopeKey,
                $windowStart,
                $windowEnd,
                $thoughts,
            );
            $model = (string) config('working_memory.authoring_digest_model', '');
            $temperature = config('working_memory.authoring_digest_temperature');
            $raw = $openRouter->researchFromPrompt(
                $prompt,
                $model !== '' ? $model : null,
                is_numeric($temperature) ? (float) $temperature : null,
            );

            $decoded = LlmJsonDecoder::decode($raw);
            if ($decoded === null) {
                Log::warning('BuildScopeDigestJob: model returned non-JSON output.', [
                    'user_id' => $this->userId,
                    'scope_type' => $this->scopeType,
                    'scope_key' => $this->scopeKey,
                ]);

                return;
            }

            $writer->write(
                userId: $this->userId,
                scopeType: $this->scopeType,
                scopeKey: $this->scopeKey,
                buildType: 'compaction:weekly-digest',
                summaryMarkdown: (string) ($decoded['summary_markdown'] ?? ''),
                structuredSections: is_array($decoded['structured_sections'] ?? null) ? $decoded['structured_sections'] : [],
                references: is_array($decoded['references'] ?? null) ? $decoded['references'] : [],
                sourceThoughtIds: $thoughts->pluck('id')->map(fn ($id): string => (string) $id)->all(),
            );
        } catch (Throwable $e) {
            Log::warning('BuildScopeDigestJob failed.', [
                'user_id' => $this->userId,
                'scope_type' => $this->scopeType,
                'scope_key' => $this->scopeKey,
                'message' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    private function hasFreshDigest(Carbon $windowStart): bool
    {
        $memory = WorkingMemory::query()
            ->where('user_id', $this->userId)
            ->where('scope_type', $this->scopeType)
            ->where('scope_key', $this->scopeKey)
            ->first();

        if ($memory === null) {
            return false;
        }

        return WorkingMemoryVersion::query()
            ->where('working_memory_id', $memory->id)
            ->where('build_type', 'compaction:weekly-digest')
            ->where('created_at', '>=', $windowStart)
            ->exists();
    }

    /**
     * @return \Illuminate\Support\Collection<int, Thought>
     */
    private function collectScopedThoughts(Carbon $windowStart, Carbon $windowEnd): \Illuminate\Support\Collection
    {
        $query = Thought::query()
            ->where('user_id', $this->userId)
            ->whereBetween('created_at', [$windowStart, $windowEnd])
            ->orderByDesc('created_at')
            ->limit(200);

        if ($this->scopeType === 'project') {
            $query->where(function ($q): void {
                $q->whereJsonContains('source_metadata->project', $this->scopeKey)
                    ->orWhereHas('projects', fn ($p) => $p->where('projects.id', $this->scopeKey));
            });
        } elseif ($this->scopeType === 'tag') {
            $query->whereJsonContains('metadata->tags', $this->scopeKey);
        }
        // global / insights: no narrowing.

        return $query->get();
    }
}
```

Add the optional digest model/temperature keys to `config/working_memory.php`:

```php
'authoring_digest_model' => env('WORKING_MEMORY_DIGEST_MODEL', env('OPENROUTER_METADATA_MODEL', 'openai/gpt-4o-mini')),
'authoring_digest_temperature' => (float) env('WORKING_MEMORY_DIGEST_TEMPERATURE', 0.2),
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/BuildScopeDigestJobTest.php`
Expected: PASS (all three tests).

- [ ] **Step 5: Commit**

```bash
git add app/Jobs/BuildScopeDigestJob.php config/working_memory.php tests/Feature/BuildScopeDigestJobTest.php
git commit -m "feat(working-memory): add BuildScopeDigestJob for compaction:weekly-digest

Idempotent per-scope digest job: skips when a digest already exists within window or
when the scope has fewer than working_memory.digest_min_thoughts in the last
working_memory.digest_window_days. Reuses CompactionVersionWriter and LlmJsonDecoder."
```

---

### Task 5: `compactions:digest` command + scheduler entry

**Files:**
- Create: `app/Console/Commands/BuildWeeklyDigestsCommand.php`
- Modify: `routes/console.php`
- Test: `tests/Feature/BuildWeeklyDigestsCommandTest.php`

The command enumerates `(user_id, scope_type, scope_key)` tuples that have at least one thought in the last `digest_window_days` and queues a `BuildScopeDigestJob` per tuple. Mirrors `WorkingMemoryConsolidateCommand`'s scope-discovery pattern.

- [ ] **Step 1: Write failing command test**

```php
<?php

namespace Tests\Feature;

use App\Jobs\BuildScopeDigestJob;
use App\Models\Thought;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class BuildWeeklyDigestsCommandTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_dispatches_a_digest_job_per_active_scope(): void
    {
        Queue::fake();
        Carbon::setTestNow(Carbon::parse('2026-05-07T10:00:00Z'));

        $user = User::factory()->create();
        Thought::factory()->count(3)->create([
            'user_id' => $user->id,
            'metadata' => ['type' => 'idea', 'tags' => ['project:dezeen']],
            'source_metadata' => ['project' => 'dezeen'],
            'created_at' => Carbon::parse('2026-05-04T10:00:00Z'),
        ]);

        $exit = $this->artisan('compactions:digest')->run();

        $this->assertSame(0, $exit);
        Queue::assertPushed(BuildScopeDigestJob::class, function (BuildScopeDigestJob $job) use ($user): bool {
            return $job->userId === $user->id
                && $job->scopeType === 'project'
                && $job->scopeKey === 'dezeen';
        });
    }

    #[Test]
    public function it_skips_when_no_active_scope(): void
    {
        Queue::fake();
        User::factory()->create();

        $exit = $this->artisan('compactions:digest')->run();

        $this->assertSame(0, $exit);
        Queue::assertNotPushed(BuildScopeDigestJob::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/BuildWeeklyDigestsCommandTest.php`
Expected: FAIL — command not registered.

- [ ] **Step 3: Implement the command**

```php
<?php

namespace App\Console\Commands;

use App\Jobs\BuildScopeDigestJob;
use App\Models\Thought;
use App\Services\WorkingMemory\WorkingMemoryScopeResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class BuildWeeklyDigestsCommand extends Command
{
    protected $signature = 'compactions:digest {--user=}';

    protected $description = 'Enqueue weekly digest compaction jobs for active scopes.';

    public function __construct(
        private readonly WorkingMemoryScopeResolver $scopeResolver,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $windowDays = (int) config('working_memory.digest_window_days', 7);
        $cutoff = Carbon::now()->subDays($windowDays);

        $userIdOption = $this->option('user');
        $query = Thought::query()->where('created_at', '>=', $cutoff);
        if (is_string($userIdOption) && $userIdOption !== '' && ctype_digit($userIdOption)) {
            $query->where('user_id', (int) $userIdOption);
        }

        $thoughts = $query->orderByDesc('created_at')->limit(2000)->get();

        $seen = [];
        foreach ($thoughts as $thought) {
            $userId = (int) $thought->user_id;
            if ($userId <= 0) {
                continue;
            }
            foreach ($this->scopeResolver->forThought($thought) as $scope) {
                $key = $userId.'|'.$scope['scope_type'].'|'.$scope['scope_key'];
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                BuildScopeDigestJob::dispatch($userId, $scope['scope_type'], $scope['scope_key']);
            }
        }

        $this->info(sprintf('Queued %d digest job(s).', count($seen)));

        return self::SUCCESS;
    }
}
```

Register the command in Laravel 12 — commands in `app/Console/Commands/` are auto-discovered, so no change to `bootstrap/app.php` is required.

- [ ] **Step 4: Add scheduler entry**

In `routes/console.php`, append:

```php
Schedule::command('compactions:digest')->hourly();
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test tests/Feature/BuildWeeklyDigestsCommandTest.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Console/Commands/BuildWeeklyDigestsCommand.php routes/console.php tests/Feature/BuildWeeklyDigestsCommandTest.php
git commit -m "feat(working-memory): add compactions:digest command + hourly schedule"
```

---

## Phase C — Phase 5: Research compactions

### Task 6: `ResearchSynthesisPromptBuilder`

**Files:**
- Create: `app/Services/WorkingMemory/Compactions/ResearchSynthesisPromptBuilder.php`
- Test: `tests/Unit/Services/WorkingMemory/Compactions/ResearchSynthesisPromptBuilderTest.php`

Synthesizes a research-grade compaction over a research-tagged thought cluster. Required sections: Open Questions, Risks / Blockers, Latest Signals, Source Notes.

- [ ] **Step 1: Write failing test**

```php
<?php

namespace Tests\Unit\Services\WorkingMemory\Compactions;

use App\Models\Thought;
use App\Services\WorkingMemory\Compactions\ResearchSynthesisPromptBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ResearchSynthesisPromptBuilderTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_renders_required_sections_and_research_blocks(): void
    {
        $thought = new Thought([
            'id' => 'r-1',
            'content' => 'Postgres MVCC bloat reaches 30% under workload X.',
        ]);
        $thought->created_at = Carbon::parse('2026-05-05T08:00:00Z');

        $builder = new ResearchSynthesisPromptBuilder;
        $prompt = $builder->build(
            scopeType: 'project',
            scopeKey: 'dezeen',
            thoughts: new Collection([$thought]),
        );

        $this->assertStringContainsString('## Research synthesis task', $prompt);
        $this->assertStringContainsString('Scope: project / dezeen', $prompt);
        $this->assertStringContainsString('Open Questions', $prompt);
        $this->assertStringContainsString('Risks / Blockers', $prompt);
        $this->assertStringContainsString('Latest Signals', $prompt);
        $this->assertStringContainsString('Source Notes', $prompt);
        $this->assertStringContainsString('Postgres MVCC bloat', $prompt);
        $this->assertStringContainsString('thought:r-1', $prompt);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Unit/Services/WorkingMemory/Compactions/ResearchSynthesisPromptBuilderTest.php`
Expected: FAIL — class does not exist.

- [ ] **Step 3: Create the prompt builder**

```php
<?php

namespace App\Services\WorkingMemory\Compactions;

use App\Models\Thought;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class ResearchSynthesisPromptBuilder
{
    /**
     * @param  Collection<int, Thought>  $thoughts
     */
    public function build(
        string $scopeType,
        string $scopeKey,
        Collection $thoughts,
    ): string {
        $maxChars = (int) config('working_memory.authoring_max_prompt_input_chars', 60000);
        $body = $this->renderResearch($thoughts);

        $prompt = <<<TEXT
## Research synthesis task

Synthesize the following research-tagged captures into a durable research compaction.

Scope: {$scopeType} / {$scopeKey}

## Research captures
{$body}

## Output contract

Return JSON only (no Markdown fences) with this shape:

{
  "summary_markdown": "<full markdown with the sections below as `## <section>`>",
  "structured_sections": {
    "Open Questions": [{"text": "...", "importance": 1, "fallback_mode": "direct", "citations": []}],
    "Risks / Blockers": [...],
    "Latest Signals": [...],
    "Source Notes": [...]
  },
  "references": []
}

Rules:
- The four required sections are: Open Questions, Risks / Blockers, Latest Signals, Source Notes.
- Promote contradictions and confidence gaps into Open Questions or Risks / Blockers.
- Latest Signals = newly observed evidence shaping next decisions.
- Source Notes = one bullet per cited capture, briefest possible.
- Citations array should be empty; the canonical composer adds permalinks.
TEXT;

        return Str::limit($prompt, $maxChars, '');
    }

    /**
     * @param  Collection<int, Thought>  $thoughts
     */
    private function renderResearch(Collection $thoughts): string
    {
        if ($thoughts->isEmpty()) {
            return '_No research captures._';
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

Run: `php artisan test tests/Unit/Services/WorkingMemory/Compactions/ResearchSynthesisPromptBuilderTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/WorkingMemory/Compactions/ResearchSynthesisPromptBuilder.php tests/Unit/Services/WorkingMemory/Compactions/ResearchSynthesisPromptBuilderTest.php
git commit -m "feat(working-memory): add ResearchSynthesisPromptBuilder for compaction:research-synth"
```

---

### Task 7: `SynthesizeResearchCompactionJob`

**Files:**
- Create: `app/Jobs/SynthesizeResearchCompactionJob.php`
- Test: `tests/Feature/SynthesizeResearchCompactionJobTest.php`

Synthesizes `compaction:research-synth` for one (user, scope_type, scope_key) when the scope's research-tagged thought count hits the threshold (configurable, default 8) and no recent research compaction exists.

Add config keys (append to `config/working_memory.php`):

```php
'research_synth_min_thoughts' => (int) env('WORKING_MEMORY_RESEARCH_SYNTH_MIN_THOUGHTS', 8),
'research_synth_freshness_hours' => (int) env('WORKING_MEMORY_RESEARCH_SYNTH_FRESHNESS_HOURS', 168),
'authoring_research_model' => env('WORKING_MEMORY_RESEARCH_MODEL', env('OPENROUTER_METADATA_MODEL', 'openai/gpt-4o-mini')),
'authoring_research_temperature' => (float) env('WORKING_MEMORY_RESEARCH_TEMPERATURE', 0.2),
```

- [ ] **Step 1: Write failing job test**

```php
<?php

namespace Tests\Feature;

use App\Jobs\SynthesizeResearchCompactionJob;
use App\Models\Thought;
use App\Models\User;
use App\Models\WorkingMemoryVersion;
use App\Services\OpenRouterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SynthesizeResearchCompactionJobTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_creates_a_research_compaction_when_threshold_met(): void
    {
        Queue::fake();
        Carbon::setTestNow(Carbon::parse('2026-05-07T10:00:00Z'));
        config(['working_memory.research_synth_min_thoughts' => 3]);

        $openRouter = Mockery::mock(OpenRouterService::class);
        $openRouter->shouldReceive('researchFromPrompt')->once()->andReturn(json_encode([
            'summary_markdown' => "## Open Questions\n- Does workload X exhibit MVCC bloat?",
            'structured_sections' => [
                'Open Questions' => [['text' => 'Does workload X exhibit MVCC bloat?', 'importance' => 1, 'fallback_mode' => 'direct', 'citations' => []]],
                'Risks / Blockers' => [],
                'Latest Signals' => [],
                'Source Notes' => [],
            ],
            'references' => [],
        ]));
        $this->app->instance(OpenRouterService::class, $openRouter);

        $user = User::factory()->create();
        Thought::factory()->count(3)->create([
            'user_id' => $user->id,
            'metadata' => ['type' => 'research', 'tags' => ['research', 'project:dezeen']],
            'source_metadata' => ['project' => 'dezeen'],
            'created_at' => Carbon::parse('2026-05-05T10:00:00Z'),
        ]);

        SynthesizeResearchCompactionJob::dispatchSync($user->id, 'project', 'dezeen');

        $version = WorkingMemoryVersion::query()
            ->where('build_type', 'compaction:research-synth')
            ->first();

        $this->assertNotNull($version);
        $this->assertStringContainsString('MVCC bloat', (string) $version->summary_markdown);
    }

    #[Test]
    public function it_skips_below_threshold(): void
    {
        Queue::fake();
        config(['working_memory.research_synth_min_thoughts' => 5]);

        $openRouter = Mockery::mock(OpenRouterService::class);
        $openRouter->shouldNotReceive('researchFromPrompt');
        $this->app->instance(OpenRouterService::class, $openRouter);

        $user = User::factory()->create();
        Thought::factory()->count(2)->create([
            'user_id' => $user->id,
            'metadata' => ['type' => 'research', 'tags' => ['research', 'project:dezeen']],
            'source_metadata' => ['project' => 'dezeen'],
        ]);

        SynthesizeResearchCompactionJob::dispatchSync($user->id, 'project', 'dezeen');

        $this->assertSame(0, WorkingMemoryVersion::query()->where('build_type', 'compaction:research-synth')->count());
    }

    #[Test]
    public function it_skips_when_recent_research_compaction_exists(): void
    {
        Queue::fake();
        config([
            'working_memory.research_synth_min_thoughts' => 1,
            'working_memory.research_synth_freshness_hours' => 168,
        ]);

        $openRouter = Mockery::mock(OpenRouterService::class);
        $openRouter->shouldNotReceive('researchFromPrompt');
        $this->app->instance(OpenRouterService::class, $openRouter);

        $user = User::factory()->create();

        // Create the pre-existing compaction at "yesterday" so it falls inside the 168h freshness window.
        Carbon::setTestNow(Carbon::parse('2026-05-06T10:00:00Z'));
        $memory = \App\Models\WorkingMemory::create([
            'user_id' => $user->id,
            'scope_type' => 'project',
            'scope_key' => 'dezeen',
            'freshness_state' => 'stale',
        ]);
        $memory->versions()->create([
            'build_type' => 'compaction:research-synth',
            'summary_markdown' => 'existing',
            'structured_sections_json' => [],
            'references_json' => [],
            'authoring_status' => 'validated',
            'confidence_score' => 0,
        ]);

        Thought::factory()->count(5)->create([
            'user_id' => $user->id,
            'metadata' => ['type' => 'research', 'tags' => ['research', 'project:dezeen']],
            'source_metadata' => ['project' => 'dezeen'],
        ]);

        Carbon::setTestNow(Carbon::parse('2026-05-07T10:00:00Z'));
        SynthesizeResearchCompactionJob::dispatchSync($user->id, 'project', 'dezeen');

        $this->assertSame(1, WorkingMemoryVersion::query()->where('build_type', 'compaction:research-synth')->count());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Mockery::close();
        parent::tearDown();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/SynthesizeResearchCompactionJobTest.php`
Expected: FAIL — job does not exist.

- [ ] **Step 3: Implement the job**

```php
<?php

namespace App\Jobs;

use App\Models\Thought;
use App\Models\WorkingMemory;
use App\Models\WorkingMemoryVersion;
use App\Services\OpenRouterService;
use App\Services\WorkingMemory\Compactions\CompactionVersionWriter;
use App\Services\WorkingMemory\Compactions\ResearchSynthesisPromptBuilder;
use App\Services\WorkingMemory\MemoryInsightsService;
use App\Support\Json\LlmJsonDecoder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

class SynthesizeResearchCompactionJob implements ShouldQueue
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
    ) {}

    public function handle(
        ResearchSynthesisPromptBuilder $promptBuilder,
        OpenRouterService $openRouter,
        CompactionVersionWriter $writer,
        MemoryInsightsService $insights,
    ): void {
        if ($this->userId <= 0 || $this->scopeType === '' || $this->scopeKey === '') {
            return;
        }

        if ($this->hasFreshResearchCompaction()) {
            return;
        }

        $thoughts = $this->collectResearchThoughts($insights);
        $minThoughts = (int) config('working_memory.research_synth_min_thoughts', 8);
        if ($thoughts->count() < $minThoughts) {
            return;
        }

        try {
            $prompt = $promptBuilder->build($this->scopeType, $this->scopeKey, $thoughts);
            $model = (string) config('working_memory.authoring_research_model', '');
            $temperature = config('working_memory.authoring_research_temperature');
            $raw = $openRouter->researchFromPrompt(
                $prompt,
                $model !== '' ? $model : null,
                is_numeric($temperature) ? (float) $temperature : null,
            );

            $decoded = LlmJsonDecoder::decode($raw);
            if ($decoded === null) {
                Log::warning('SynthesizeResearchCompactionJob: model returned non-JSON output.', [
                    'user_id' => $this->userId,
                    'scope_type' => $this->scopeType,
                    'scope_key' => $this->scopeKey,
                ]);

                return;
            }

            $writer->write(
                userId: $this->userId,
                scopeType: $this->scopeType,
                scopeKey: $this->scopeKey,
                buildType: 'compaction:research-synth',
                summaryMarkdown: (string) ($decoded['summary_markdown'] ?? ''),
                structuredSections: is_array($decoded['structured_sections'] ?? null) ? $decoded['structured_sections'] : [],
                references: is_array($decoded['references'] ?? null) ? $decoded['references'] : [],
                sourceThoughtIds: $thoughts->pluck('id')->map(fn ($id): string => (string) $id)->all(),
            );
        } catch (Throwable $e) {
            Log::warning('SynthesizeResearchCompactionJob failed.', [
                'user_id' => $this->userId,
                'scope_type' => $this->scopeType,
                'scope_key' => $this->scopeKey,
                'message' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    private function hasFreshResearchCompaction(): bool
    {
        $hours = (int) config('working_memory.research_synth_freshness_hours', 168);
        $cutoff = Carbon::now()->subHours($hours);

        $memory = WorkingMemory::query()
            ->where('user_id', $this->userId)
            ->where('scope_type', $this->scopeType)
            ->where('scope_key', $this->scopeKey)
            ->first();

        if ($memory === null) {
            return false;
        }

        return WorkingMemoryVersion::query()
            ->where('working_memory_id', $memory->id)
            ->where('build_type', 'compaction:research-synth')
            ->where('created_at', '>=', $cutoff)
            ->exists();
    }

    /**
     * @return \Illuminate\Support\Collection<int, Thought>
     */
    private function collectResearchThoughts(MemoryInsightsService $insights): \Illuminate\Support\Collection
    {
        $query = Thought::query()
            ->where('user_id', $this->userId)
            ->orderByDesc('created_at')
            ->limit(200);

        if ($this->scopeType === 'project') {
            $query->where(function ($q): void {
                $q->whereJsonContains('source_metadata->project', $this->scopeKey)
                    ->orWhereHas('projects', fn ($p) => $p->where('projects.id', $this->scopeKey));
            });
        } elseif ($this->scopeType === 'tag') {
            $query->whereJsonContains('metadata->tags', $this->scopeKey);
        }

        return $query->get()
            ->filter(fn (Thought $t): bool => $insights->isResearchThought($t))
            ->values();
    }
}
```

- [ ] **Step 4: Run tests**

Run: `php artisan test tests/Feature/SynthesizeResearchCompactionJobTest.php`
Expected: PASS (all three tests).

- [ ] **Step 5: Commit**

```bash
git add app/Jobs/SynthesizeResearchCompactionJob.php config/working_memory.php tests/Feature/SynthesizeResearchCompactionJobTest.php
git commit -m "feat(working-memory): add SynthesizeResearchCompactionJob with threshold trigger

Job synthesizes compaction:research-synth when a scope accumulates
working_memory.research_synth_min_thoughts research-tagged thoughts and no fresh
research compaction exists within working_memory.research_synth_freshness_hours."
```

---

### Task 8: `compactions:research` command + scheduler entry

**Files:**
- Create: `app/Console/Commands/BuildResearchSynthesesCommand.php`
- Modify: `routes/console.php`
- Test: `tests/Feature/BuildResearchSynthesesCommandTest.php`

- [ ] **Step 1: Write failing test**

```php
<?php

namespace Tests\Feature;

use App\Jobs\SynthesizeResearchCompactionJob;
use App\Models\Thought;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class BuildResearchSynthesesCommandTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_dispatches_research_jobs_for_scopes_at_or_above_threshold(): void
    {
        Queue::fake();
        config(['working_memory.research_synth_min_thoughts' => 2]);

        $user = User::factory()->create();
        Thought::factory()->count(2)->create([
            'user_id' => $user->id,
            'metadata' => ['type' => 'research', 'tags' => ['research', 'project:dezeen']],
            'source_metadata' => ['project' => 'dezeen'],
        ]);

        $exit = $this->artisan('compactions:research')->run();

        $this->assertSame(0, $exit);
        Queue::assertPushed(SynthesizeResearchCompactionJob::class, function (SynthesizeResearchCompactionJob $job) use ($user): bool {
            return $job->userId === $user->id
                && $job->scopeType === 'project'
                && $job->scopeKey === 'dezeen';
        });
    }

    #[Test]
    public function it_skips_scopes_below_threshold(): void
    {
        Queue::fake();
        config(['working_memory.research_synth_min_thoughts' => 5]);

        $user = User::factory()->create();
        Thought::factory()->count(2)->create([
            'user_id' => $user->id,
            'metadata' => ['type' => 'research', 'tags' => ['research', 'project:dezeen']],
            'source_metadata' => ['project' => 'dezeen'],
        ]);

        $this->artisan('compactions:research')->run();

        Queue::assertNotPushed(SynthesizeResearchCompactionJob::class);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/BuildResearchSynthesesCommandTest.php`
Expected: FAIL — command not registered.

- [ ] **Step 3: Implement the command**

```php
<?php

namespace App\Console\Commands;

use App\Jobs\SynthesizeResearchCompactionJob;
use App\Models\Thought;
use App\Services\WorkingMemory\MemoryInsightsService;
use App\Services\WorkingMemory\WorkingMemoryScopeResolver;
use Illuminate\Console\Command;

class BuildResearchSynthesesCommand extends Command
{
    protected $signature = 'compactions:research {--user=}';

    protected $description = 'Enqueue research synthesis compaction jobs for scopes at or above threshold.';

    public function __construct(
        private readonly WorkingMemoryScopeResolver $scopeResolver,
        private readonly MemoryInsightsService $insights,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $minThoughts = (int) config('working_memory.research_synth_min_thoughts', 8);

        $userIdOption = $this->option('user');
        $query = Thought::query();
        if (is_string($userIdOption) && $userIdOption !== '' && ctype_digit($userIdOption)) {
            $query->where('user_id', (int) $userIdOption);
        }

        $thoughts = $query->orderByDesc('created_at')->limit(5000)->get()
            ->filter(fn (Thought $t): bool => $this->insights->isResearchThought($t))
            ->values();

        $countsByScope = [];
        foreach ($thoughts as $thought) {
            $userId = (int) $thought->user_id;
            if ($userId <= 0) {
                continue;
            }
            foreach ($this->scopeResolver->forThought($thought) as $scope) {
                $key = $userId.'|'.$scope['scope_type'].'|'.$scope['scope_key'];
                $countsByScope[$key] = ($countsByScope[$key] ?? 0) + 1;
            }
        }

        $dispatched = 0;
        foreach ($countsByScope as $key => $count) {
            if ($count < $minThoughts) {
                continue;
            }
            [$userId, $scopeType, $scopeKey] = explode('|', $key, 3);
            SynthesizeResearchCompactionJob::dispatch((int) $userId, $scopeType, $scopeKey);
            $dispatched++;
        }

        $this->info(sprintf('Queued %d research synthesis job(s).', $dispatched));

        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: Add scheduler entry**

In `routes/console.php`, append:

```php
Schedule::command('compactions:research')->dailyAt('04:15');
```

- [ ] **Step 5: Run tests**

Run: `php artisan test tests/Feature/BuildResearchSynthesesCommandTest.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Console/Commands/BuildResearchSynthesesCommand.php routes/console.php tests/Feature/BuildResearchSynthesesCommandTest.php
git commit -m "feat(working-memory): add compactions:research command + daily schedule"
```

---

## Phase D — Phase 6: Bootstrap

### Task 9: `working-memory:bootstrap` command

**Files:**
- Create: `app/Console/Commands/WorkingMemoryBootstrapCommand.php`
- Test: `tests/Feature/WorkingMemoryBootstrapCommandTest.php`

For one (user, scope_type, scope_key), backfill historical compactions: dispatch `SynthesizeMeetingCompactionJob` for every meeting thought in scope, dispatch `BuildScopeDigestJob` once, and dispatch `SynthesizeResearchCompactionJob` once. Then dispatch `ConsolidateWorkingMemory` so the canonical version cites the freshly-built compactions.

All dispatches are synchronous (`dispatchSync`) so the operator sees errors immediately.

- [ ] **Step 1: Write failing command test**

```php
<?php

namespace Tests\Feature;

use App\Jobs\BuildScopeDigestJob;
use App\Jobs\ConsolidateWorkingMemory;
use App\Jobs\SynthesizeMeetingCompactionJob;
use App\Jobs\SynthesizeResearchCompactionJob;
use App\Models\Thought;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class WorkingMemoryBootstrapCommandTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_dispatches_compaction_jobs_synchronously_then_consolidates(): void
    {
        Bus::fake();

        $user = User::factory()->create();

        // Two meetings + assorted scope thoughts.
        Thought::factory()->count(2)->create([
            'user_id' => $user->id,
            'metadata' => ['type' => 'meeting', 'tags' => ['project:dezeen']],
            'source_metadata' => ['project' => 'dezeen'],
        ]);

        $exit = $this->artisan('working-memory:bootstrap', [
            'scope_type' => 'project',
            'scope_key' => 'dezeen',
            '--user' => (string) $user->id,
        ])->run();

        $this->assertSame(0, $exit);
        Bus::assertDispatchedSync(SynthesizeMeetingCompactionJob::class);
        Bus::assertDispatchedSync(BuildScopeDigestJob::class);
        Bus::assertDispatchedSync(SynthesizeResearchCompactionJob::class);
        Bus::assertDispatched(ConsolidateWorkingMemory::class);
    }

    #[Test]
    public function it_requires_a_user_option(): void
    {
        $exit = $this->artisan('working-memory:bootstrap', [
            'scope_type' => 'project',
            'scope_key' => 'dezeen',
        ])->run();

        $this->assertSame(1, $exit);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/WorkingMemoryBootstrapCommandTest.php`
Expected: FAIL — command not registered.

- [ ] **Step 3: Implement the command**

```php
<?php

namespace App\Console\Commands;

use App\Jobs\BuildScopeDigestJob;
use App\Jobs\ConsolidateWorkingMemory;
use App\Jobs\SynthesizeMeetingCompactionJob;
use App\Jobs\SynthesizeResearchCompactionJob;
use App\Models\Thought;
use App\Models\User;
use Illuminate\Console\Command;
use InvalidArgumentException;

class WorkingMemoryBootstrapCommand extends Command
{
    protected $signature = 'working-memory:bootstrap {scope_type} {scope_key} {--user=}';

    protected $description = 'Backfill all compactions for a scope, then trigger a consolidated authoring pass.';

    public function handle(): int
    {
        try {
            $userId = $this->resolveUserId();
            [$scopeType, $scopeKey] = $this->resolveScope();
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $meetings = Thought::query()
            ->where('user_id', $userId)
            ->whereJsonContains('metadata->type', 'meeting')
            ->orderBy('created_at')
            ->get();

        $this->info(sprintf('Bootstrapping %d meeting compaction(s) for %s/%s...', $meetings->count(), $scopeType, $scopeKey));
        foreach ($meetings as $meeting) {
            SynthesizeMeetingCompactionJob::dispatchSync((string) $meeting->id);
        }

        $this->info('Building scope digest...');
        BuildScopeDigestJob::dispatchSync($userId, $scopeType, $scopeKey);

        $this->info('Building research synthesis...');
        SynthesizeResearchCompactionJob::dispatchSync($userId, $scopeType, $scopeKey);

        $this->info('Triggering consolidated authoring...');
        ConsolidateWorkingMemory::dispatch($userId, $scopeType, $scopeKey);

        $this->info('Bootstrap complete.');

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
}
```

- [ ] **Step 4: Run tests**

Run: `php artisan test tests/Feature/WorkingMemoryBootstrapCommandTest.php`
Expected: PASS (both tests).

- [ ] **Step 5: Commit**

```bash
git add app/Console/Commands/WorkingMemoryBootstrapCommand.php tests/Feature/WorkingMemoryBootstrapCommandTest.php
git commit -m "feat(working-memory): add working-memory:bootstrap command for historical compaction backfill"
```

---

### Task 10: `compactions:rebuild` command

**Files:**
- Create: `app/Console/Commands/CompactionsRebuildCommand.php`
- Test: `tests/Feature/CompactionsRebuildCommandTest.php`

Manual recompute for a single subtype: takes a subtype filter (`meeting`, `weekly-digest`, `research-synth`) and dispatches just that job. Useful when prompt-builder changes need a forced re-run.

- [ ] **Step 1: Write failing test**

```php
<?php

namespace Tests\Feature;

use App\Jobs\BuildScopeDigestJob;
use App\Jobs\SynthesizeResearchCompactionJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class CompactionsRebuildCommandTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_dispatches_only_the_requested_subtype(): void
    {
        Bus::fake();

        $user = User::factory()->create();

        $this->artisan('compactions:rebuild', [
            'scope_type' => 'project',
            'scope_key' => 'dezeen',
            '--user' => (string) $user->id,
            '--type' => 'weekly-digest',
        ])->assertExitCode(0);

        Bus::assertDispatchedSync(BuildScopeDigestJob::class);
        Bus::assertNotDispatched(SynthesizeResearchCompactionJob::class);
    }

    #[Test]
    public function it_rejects_unknown_subtypes(): void
    {
        $user = User::factory()->create();

        $exit = $this->artisan('compactions:rebuild', [
            'scope_type' => 'project',
            'scope_key' => 'dezeen',
            '--user' => (string) $user->id,
            '--type' => 'bogus',
        ])->run();

        $this->assertSame(1, $exit);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/CompactionsRebuildCommandTest.php`
Expected: FAIL — command not registered.

- [ ] **Step 3: Implement the command**

```php
<?php

namespace App\Console\Commands;

use App\Jobs\BuildScopeDigestJob;
use App\Jobs\SynthesizeMeetingCompactionJob;
use App\Jobs\SynthesizeResearchCompactionJob;
use App\Models\Thought;
use App\Models\User;
use Illuminate\Console\Command;
use InvalidArgumentException;

class CompactionsRebuildCommand extends Command
{
    protected $signature = 'compactions:rebuild {scope_type} {scope_key} {--user=} {--type=}';

    protected $description = 'Manually rebuild a single compaction subtype for a scope.';

    private const ALLOWED_TYPES = ['meeting', 'weekly-digest', 'research-synth'];

    public function handle(): int
    {
        try {
            $userId = $this->resolveUserId();
            [$scopeType, $scopeKey] = $this->resolveScope();
            $type = $this->resolveType();
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        switch ($type) {
            case 'meeting':
                $meetings = Thought::query()
                    ->where('user_id', $userId)
                    ->whereJsonContains('metadata->type', 'meeting')
                    ->orderBy('created_at')
                    ->get();
                foreach ($meetings as $meeting) {
                    SynthesizeMeetingCompactionJob::dispatchSync((string) $meeting->id);
                }
                $this->info(sprintf('Rebuilt %d meeting compaction(s).', $meetings->count()));
                break;

            case 'weekly-digest':
                BuildScopeDigestJob::dispatchSync($userId, $scopeType, $scopeKey);
                $this->info('Rebuilt weekly digest.');
                break;

            case 'research-synth':
                SynthesizeResearchCompactionJob::dispatchSync($userId, $scopeType, $scopeKey);
                $this->info('Rebuilt research synthesis.');
                break;
        }

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
            throw new InvalidArgumentException('Invalid scope_type.');
        }
        if (trim($scopeKey) === '') {
            throw new InvalidArgumentException('scope_key must not be empty.');
        }

        return [$scopeType, $scopeType === 'project' || $scopeType === 'tag' ? strtolower(trim($scopeKey)) : $scopeKey];
    }

    private function resolveType(): string
    {
        $type = (string) ($this->option('type') ?? '');
        if (! in_array($type, self::ALLOWED_TYPES, true)) {
            throw new InvalidArgumentException(
                'Invalid --type. Allowed values: '.implode(', ', self::ALLOWED_TYPES)
            );
        }

        return $type;
    }
}
```

- [ ] **Step 4: Run tests**

Run: `php artisan test tests/Feature/CompactionsRebuildCommandTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Console/Commands/CompactionsRebuildCommand.php tests/Feature/CompactionsRebuildCommandTest.php
git commit -m "feat(working-memory): add compactions:rebuild command for single-subtype recompute"
```

---

## Phase E — Retention + ops hygiene

### Task 11: `CompactionRetentionService` + cleanup hook

**Files:**
- Create: `app/Services/WorkingMemory/Compactions/CompactionRetentionService.php`
- Modify: `app/Services/WorkingMemory/Compactions/CompactionVersionWriter.php`
- Test: `tests/Unit/Services/WorkingMemory/Compactions/CompactionRetentionServiceTest.php`

Per-scope retention: keep at most N compactions per subtype. The writer calls the retention service after every successful write so unbounded growth is impossible. Caps are config-driven.

Add to `config/working_memory.php`:

```php
'compaction_retention' => [
    'meeting' => (int) env('WORKING_MEMORY_RETAIN_MEETING', 50),
    'weekly-digest' => (int) env('WORKING_MEMORY_RETAIN_WEEKLY_DIGEST', 12),
    'topic-digest' => (int) env('WORKING_MEMORY_RETAIN_TOPIC_DIGEST', 24),
    'research-synth' => (int) env('WORKING_MEMORY_RETAIN_RESEARCH_SYNTH', 12),
],
```

- [ ] **Step 1: Write failing retention test**

```php
<?php

namespace Tests\Unit\Services\WorkingMemory\Compactions;

use App\Models\WorkingMemory;
use App\Models\WorkingMemoryVersion;
use App\Models\User;
use App\Services\WorkingMemory\Compactions\CompactionRetentionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class CompactionRetentionServiceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_trims_compactions_per_subtype_to_configured_cap(): void
    {
        config(['working_memory.compaction_retention.meeting' => 3]);

        $user = User::factory()->create();
        $memory = WorkingMemory::create([
            'user_id' => $user->id,
            'scope_type' => 'project',
            'scope_key' => 'dezeen',
            'freshness_state' => 'stale',
        ]);

        // Five meeting compactions; sequence with Carbon::setTestNow because created_at is not in $fillable.
        for ($i = 0; $i < 5; $i++) {
            Carbon::setTestNow(Carbon::parse('2026-05-01T00:00:00Z')->addHours($i));
            $memory->versions()->create([
                'build_type' => 'compaction:meeting',
                'summary_markdown' => "v{$i}",
                'structured_sections_json' => [],
                'references_json' => [],
                'authoring_status' => 'validated',
                'confidence_score' => 0,
            ]);
        }
        Carbon::setTestNow();

        $service = new CompactionRetentionService;
        $service->trim($memory, 'compaction:meeting');

        $surviving = WorkingMemoryVersion::query()
            ->where('working_memory_id', $memory->id)
            ->where('build_type', 'compaction:meeting')
            ->orderBy('created_at')
            ->get();

        $this->assertCount(3, $surviving);
        $this->assertSame(['v2', 'v3', 'v4'], $surviving->pluck('summary_markdown')->all());
    }

    #[Test]
    public function it_does_not_touch_other_subtypes_or_canonical_versions(): void
    {
        config(['working_memory.compaction_retention.meeting' => 1]);

        $user = User::factory()->create();
        $memory = WorkingMemory::create([
            'user_id' => $user->id,
            'scope_type' => 'project',
            'scope_key' => 'dezeen',
            'freshness_state' => 'stale',
        ]);

        Carbon::setTestNow(Carbon::parse('2026-05-01T00:00:00Z'));
        $memory->versions()->create([
            'build_type' => 'compaction:meeting',
            'summary_markdown' => 'old',
            'structured_sections_json' => [],
            'references_json' => [],
            'authoring_status' => 'validated',
            'confidence_score' => 0,
        ]);

        Carbon::setTestNow(Carbon::parse('2026-05-02T00:00:00Z'));
        $memory->versions()->create([
            'build_type' => 'compaction:meeting',
            'summary_markdown' => 'new',
            'structured_sections_json' => [],
            'references_json' => [],
            'authoring_status' => 'validated',
            'confidence_score' => 0,
        ]);
        $memory->versions()->create([
            'build_type' => 'compaction:weekly-digest',
            'summary_markdown' => 'digest',
            'structured_sections_json' => [],
            'references_json' => [],
            'authoring_status' => 'validated',
            'confidence_score' => 0,
        ]);
        $memory->versions()->create([
            'build_type' => 'consolidated',
            'summary_markdown' => 'canonical',
            'structured_sections_json' => [],
            'references_json' => [],
            'authoring_status' => 'validated',
            'confidence_score' => 0,
        ]);
        Carbon::setTestNow();

        $service = new CompactionRetentionService;
        $service->trim($memory, 'compaction:meeting');

        $this->assertSame(1, WorkingMemoryVersion::query()->where('build_type', 'compaction:meeting')->count());
        $this->assertSame(1, WorkingMemoryVersion::query()->where('build_type', 'compaction:weekly-digest')->count());
        $this->assertSame(1, WorkingMemoryVersion::query()->where('build_type', 'consolidated')->count());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Unit/Services/WorkingMemory/Compactions/CompactionRetentionServiceTest.php`
Expected: FAIL — class does not exist.

- [ ] **Step 3: Implement `CompactionRetentionService`**

```php
<?php

namespace App\Services\WorkingMemory\Compactions;

use App\Models\WorkingMemory;
use App\Models\WorkingMemoryVersion;

class CompactionRetentionService
{
    public function trim(WorkingMemory $memory, string $buildType): void
    {
        if (! str_starts_with($buildType, 'compaction:')) {
            return;
        }

        $subtype = substr($buildType, strlen('compaction:'));
        $caps = config('working_memory.compaction_retention', []);
        $cap = is_array($caps) && isset($caps[$subtype]) && is_int($caps[$subtype]) ? $caps[$subtype] : null;

        if ($cap === null || $cap <= 0) {
            return;
        }

        $count = WorkingMemoryVersion::query()
            ->where('working_memory_id', $memory->id)
            ->where('build_type', $buildType)
            ->count();

        if ($count <= $cap) {
            return;
        }

        $excess = $count - $cap;
        WorkingMemoryVersion::query()
            ->where('working_memory_id', $memory->id)
            ->where('build_type', $buildType)
            ->orderBy('created_at')
            ->limit($excess)
            ->get()
            ->each(fn (WorkingMemoryVersion $v) => $v->delete());
    }
}
```

- [ ] **Step 4: Wire retention into `CompactionVersionWriter`**

In `app/Services/WorkingMemory/Compactions/CompactionVersionWriter.php`, inject the retention service and call it at the end of the transaction. Replace the existing constructor (or add one) and the return at end of `write()`:

```php
    public function __construct(
        private readonly CompactionRetentionService $retention,
    ) {}
```

Then immediately before `return $version;` inside the closure:

```php
            $this->retention->trim($memory, $buildType);

            return $version;
```

- [ ] **Step 5: Run tests**

Run: `php artisan test tests/Unit/Services/WorkingMemory/Compactions/CompactionRetentionServiceTest.php`
Expected: PASS (both tests).

Run: `php artisan test --filter='CompactionVersionWriter|SynthesizeMeetingCompactionJob|BuildScopeDigestJob|SynthesizeResearchCompactionJob'`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Services/WorkingMemory/Compactions/CompactionRetentionService.php app/Services/WorkingMemory/Compactions/CompactionVersionWriter.php config/working_memory.php tests/Unit/Services/WorkingMemory/Compactions/CompactionRetentionServiceTest.php
git commit -m "feat(working-memory): retention cap on compactions per subtype

CompactionVersionWriter now invokes CompactionRetentionService after every successful
write. Per-subtype caps live in working_memory.compaction_retention; oldest rows are
hard-deleted past the cap. Canonical versions and other subtypes are untouched."
```

---

## Phase F — End-to-end verification

### Task 12: Full regression sweep

**Files:** none (verification only).

- [ ] **Step 1: Run the working-memory test slice**

```bash
php artisan test --filter='WorkingMemory|Compaction|MeetingCompaction|ResearchCompaction|ScopeDigest|ResearchSynthesis|ThoughtObserver|McpApi|LlmJsonDecoder|OpenRouterService|Compactions:'
```

Expected: All pass. Deprecation warnings (PDO, PHP 8.5) and risky test flags carrying over from prior runs are acceptable; new failures are not.

- [ ] **Step 2: Run a manual smoke test against a development user**

```bash
php artisan working-memory:bootstrap project dezeen --user=1
php artisan compactions:digest --user=1
php artisan compactions:research --user=1
php artisan compactions:rebuild project dezeen --user=1 --type=meeting
```

Expected: each command exits 0; new `working_memory_versions` rows visible with `build_type` in (`compaction:meeting`, `compaction:weekly-digest`, `compaction:research-synth`, `consolidated`); canonical version's `build_diagnostics_json` contains the four new fields.

- [ ] **Step 3: Verify scheduler entries**

```bash
php artisan schedule:list
```

Expected: `compactions:digest` (hourly) and `compactions:research` (dailyAt 04:15) appear alongside the existing schedule rows.

- [ ] **Step 4: Final commit if any incidental fixes were needed**

If the regression run surfaced anything that needed fixing, commit with a focused message. Otherwise this task produces no commits.

---

## Self-review notes

Spec coverage check (against the design's Phase 4–6 plus Implementation Checklist items not in the Phase 1–3 plan):

- Phase 4 — Digest compactions: Tasks 3 (prompt), 4 (job), 5 (command + schedule). ✓
- Phase 5 — Research compactions: Tasks 6 (prompt), 7 (job with threshold), 8 (command + schedule). ✓
- Phase 6 — Bootstrap: Task 9 (`working-memory:bootstrap`), Task 10 (`compactions:rebuild`). ✓
- Validator extension `unused_compaction`: Task 2. ✓
- Diagnostic fields in `build_diagnostics_json`: Task 1. ✓
- Per-scope retention policy: Task 11. ✓
- Spec items NOT included in this plan and deferred to follow-up:
  - Per-compaction citation-coverage check (compactions discarded if below threshold). The current `CompactionVersionWriter` writes whatever the job produces; adding pre-write validation requires extending the writer signature and isn't load-bearing for the rollout. Suggest a small follow-up plan after Phase 4–5 ship and we have data on real coverage rates.
  - `compaction:topic-digest` subtype. The spec lists it but defers all triggers to "on-demand" — there is no scheduler entry or threshold defined. Add when a UI or MCP tool surfaces the on-demand call.
  - Metrics emission (success rate, mean coverage ratio). The diagnostics fields land first; an instrumentation pass over those fields can ship after we see real data.

Type and naming consistency: `BuildScopeDigestJob`, `SynthesizeResearchCompactionJob`, `ScopeDigestPromptBuilder`, `ResearchSynthesisPromptBuilder`, `CompactionRetentionService` are referenced consistently in the file structure table, the task headers, and the test code. Config keys all live under `working_memory.*` flat namespace, matching the convention established by Phase 1–3. Job constructor signatures match: `(int $userId, string $scopeType, string $scopeKey)` for `BuildScopeDigestJob` and `SynthesizeResearchCompactionJob`; `(string $thoughtId)` for `SynthesizeMeetingCompactionJob` (existing).

Placeholder scan: every step contains the actual code, command, or assertion an engineer needs. No "TBD", no "implement appropriate handling", no "similar to Task N" without the code.
