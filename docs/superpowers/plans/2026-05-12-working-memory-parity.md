# Working Memory Parity — Phase 1 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add an `upsert_working_memory` endpoint (MCP + REST) so externally-authored working memory markdown can be persisted as the canonical online working memory for a scope.

**Architecture:** A new `WorkingMemoryUpsertService` parses markdown into structured sections and persists a `WorkingMemoryVersion` with `build_type: 'external'`. The `WorkingMemoryAssembler` is updated to treat external versions as consolidated-equivalent when resolving the canonical version. Both the MCP JSON-RPC handler and a new REST route delegate to the same service.

**Tech Stack:** Laravel 12 (PHP 8.2+), Pest (testing), SQLite (dev DB)

---

### Task 1: WorkingMemoryUpsertService — Markdown Parser + Persistence

**Files:**
- Create: `app/Services/WorkingMemory/WorkingMemoryUpsertService.php`
- Test: `tests/Feature/WorkingMemoryUpsertServiceTest.php`

- [ ] **Step 1: Write the failing test — parses markdown into structured sections**

```php
// tests/Feature/WorkingMemoryUpsertServiceTest.php
<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\WorkingMemory\WorkingMemoryUpsertService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WorkingMemoryUpsertServiceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function test_upsert_persists_external_version_from_markdown(): void
    {
        $user = User::factory()->create();

        $markdown = <<<'MD'
        # Working Memory

        ## Current Focus

        - Ship the comments-page fix.
        - Prepare the WordPress upgrade.

        ## Active Priorities

        - Test the Evessio plugin in staging.
        - Lock the WordPress upgrade timing.

        ## Recent Changes

        - On 2026-05-08 the team scheduled the WordPress upgrade.

        ## Open Questions

        - When will the Evessio API key be available?

        ## Risks / Blockers

        - Evessio rollout is blocked on API-key access.

        ## Next Actions

        - Get the Evessio API key and run staging tests.

        ## Latest Signals

        - Weekly check-in 2026-05-08: comments service restored.

        ## Source Notes

        - Reviewed context/index.md before refreshing.
        MD;

        $service = app(WorkingMemoryUpsertService::class);
        $version = $service->upsert($user->id, 'project', 'dezeen', trim($markdown));

        $this->assertNotNull($version);
        $this->assertEquals('external', $version->build_type);
        $this->assertEquals('external', $version->authoring_status);
        $this->assertEquals(90.0, (float) $version->confidence_score);
        $this->assertStringContainsString('Ship the comments-page fix', $version->summary_markdown);

        $sections = $version->structured_sections_json;
        $this->assertIsArray($sections);
        $this->assertArrayHasKey('Current Focus', $sections);
        $this->assertArrayHasKey('Active Priorities', $sections);
        $this->assertArrayHasKey('Open Questions', $sections);
        $this->assertArrayHasKey('Risks / Blockers', $sections);
        $this->assertArrayHasKey('Next Actions', $sections);
        $this->assertArrayHasKey('Latest Signals', $sections);
        $this->assertArrayHasKey('Source Notes', $sections);

        $focusItems = $sections['Current Focus'];
        $this->assertCount(2, $focusItems);
        $this->assertEquals('Ship the comments-page fix.', $focusItems[0]['text']);

        $memory = $version->workingMemory;
        $this->assertEquals('project', $memory->scope_type);
        $this->assertEquals('dezeen', $memory->scope_key);
        $this->assertEquals($version->id, $memory->latest_version_id);
        $this->assertEquals('fresh', $memory->freshness_state);
        $this->assertNotNull($memory->last_refreshed_at);
    }

    #[Test]
    public function test_upsert_updates_existing_working_memory_record(): void
    {
        $user = User::factory()->create();
        $service = app(WorkingMemoryUpsertService::class);

        $markdown1 = "## Current Focus\n\n- First version.\n\n## Active Priorities\n\n- Priority A.";
        $version1 = $service->upsert($user->id, 'project', 'dezeen', $markdown1);

        $markdown2 = "## Current Focus\n\n- Second version.\n\n## Active Priorities\n\n- Priority B.";
        $version2 = $service->upsert($user->id, 'project', 'dezeen', $markdown2);

        $this->assertNotEquals($version1->id, $version2->id);

        $memory = $version2->workingMemory->fresh();
        $this->assertEquals($version2->id, $memory->latest_version_id);
    }

    #[Test]
    public function test_upsert_normalizes_scope_key_to_lowercase(): void
    {
        $user = User::factory()->create();
        $service = app(WorkingMemoryUpsertService::class);

        $markdown = "## Current Focus\n\n- Test item.";
        $version = $service->upsert($user->id, 'project', 'Dezeen', $markdown);

        $this->assertEquals('dezeen', $version->workingMemory->scope_key);
    }

    #[Test]
    public function test_upsert_rejects_empty_content(): void
    {
        $user = User::factory()->create();
        $service = app(WorkingMemoryUpsertService::class);

        $this->expectException(\InvalidArgumentException::class);
        $service->upsert($user->id, 'project', 'dezeen', '');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/WorkingMemoryUpsertServiceTest.php --filter=test_upsert_persists_external_version_from_markdown`
Expected: FAIL — class `WorkingMemoryUpsertService` does not exist

- [ ] **Step 3: Implement WorkingMemoryUpsertService**

```php
// app/Services/WorkingMemory/WorkingMemoryUpsertService.php
<?php

namespace App\Services\WorkingMemory;

use App\Models\WorkingMemory;
use App\Models\WorkingMemoryVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WorkingMemoryUpsertService
{
    private const KNOWN_SECTIONS = [
        'Current Focus',
        'Active Priorities',
        'Recent Changes',
        'Open Questions',
        'Risks / Blockers',
        'Next Actions',
        'Latest Signals',
        'Source Notes',
    ];

    public function __construct(
        private readonly WorkingMemoryScopeNormalizer $scopeNormalizer,
    ) {}

    public function upsert(
        int $userId,
        string $scopeType,
        string $scopeKey,
        string $content,
        ?string $sourceLabel = null,
    ): WorkingMemoryVersion {
        $content = trim($content);
        if ($content === '') {
            throw new \InvalidArgumentException('content must not be empty.');
        }

        [$normalizedScopeType, $normalizedScopeKey] = $this->scopeNormalizer->normalize($scopeType, $scopeKey);

        $structuredSections = $this->parseMarkdownSections($content);
        $payload = $this->buildLegacyPayload($structuredSections);

        $sectionReferences = collect($structuredSections)
            ->mapWithKeys(fn (array $items, string $section): array => [
                $section => [[
                    'type' => 'stream_filter',
                    'url' => '/stream?' . http_build_query([
                        'scope_type' => $normalizedScopeType,
                        'scope_key' => $normalizedScopeKey,
                        'section' => $section,
                    ], '', '&', PHP_QUERY_RFC3986),
                    'label' => $section . ' evidence',
                ]],
            ])
            ->all();

        return DB::transaction(function () use (
            $userId,
            $normalizedScopeType,
            $normalizedScopeKey,
            $content,
            $structuredSections,
            $sectionReferences,
            $payload,
            $sourceLabel,
        ): WorkingMemoryVersion {
            $memory = WorkingMemory::query()->firstOrCreate(
                [
                    'user_id' => $userId,
                    'scope_type' => $normalizedScopeType,
                    'scope_key' => $normalizedScopeKey,
                ],
                [
                    'freshness_state' => 'stale',
                ]
            );

            $version = $memory->versions()->create([
                'build_type' => 'external',
                'summary_markdown' => $content,
                'key_concepts_json' => $payload['key_concepts'],
                'active_threads_json' => $payload['active_threads'],
                'open_questions_json' => $payload['open_questions'],
                'next_actions_json' => $payload['next_actions'],
                'structured_sections_json' => $structuredSections,
                'references_json' => [],
                'section_references_json' => $sectionReferences,
                'build_diagnostics_json' => [
                    'source_label' => $sourceLabel,
                    'parsed_section_count' => count($structuredSections),
                ],
                'citation_coverage' => null,
                'authoring_status' => 'external',
                'validation_error' => null,
                'confidence_score' => 90.0,
                'source_window_start' => now(),
                'source_window_end' => now(),
            ]);

            $memory->forceFill([
                'latest_version_id' => $version->id,
                'freshness_state' => 'fresh',
                'last_refreshed_at' => now(),
                'build_started_at' => null,
            ])->save();

            return $version->fresh(['workingMemory']);
        });
    }

    /**
     * @return array<string, array<int, array{id: string, text: string, importance: int, fallback_mode: string, citations: array<int, mixed>}>>
     */
    private function parseMarkdownSections(string $markdown): array
    {
        $sections = [];
        $currentSection = null;
        $currentLines = [];

        foreach (explode("\n", $markdown) as $line) {
            if (preg_match('/^##\s+(.+)$/', $line, $matches)) {
                if ($currentSection !== null) {
                    $sections[$currentSection] = $this->parseBullets($currentLines);
                }
                $heading = trim($matches[1]);
                if (in_array($heading, self::KNOWN_SECTIONS, true)) {
                    $currentSection = $heading;
                    $currentLines = [];
                } else {
                    $currentSection = null;
                    $currentLines = [];
                }

                continue;
            }

            if ($currentSection !== null) {
                $currentLines[] = $line;
            }
        }

        if ($currentSection !== null) {
            $sections[$currentSection] = $this->parseBullets($currentLines);
        }

        return $sections;
    }

    /**
     * @param  array<int, string>  $lines
     * @return array<int, array{id: string, text: string, importance: int, fallback_mode: string, citations: array<int, mixed>}>
     */
    private function parseBullets(array $lines): array
    {
        $items = [];
        $currentText = '';

        foreach ($lines as $line) {
            $trimmed = ltrim($line);
            if (str_starts_with($trimmed, '- ')) {
                if ($currentText !== '') {
                    $items[] = $this->bulletItem($currentText);
                }
                $currentText = trim(substr($trimmed, 2));
            } elseif ($currentText !== '' && $trimmed !== '') {
                $currentText .= ' ' . $trimmed;
            }
        }

        if ($currentText !== '') {
            $items[] = $this->bulletItem($currentText);
        }

        return $items;
    }

    /**
     * @return array{id: string, text: string, importance: int, fallback_mode: string, citations: array<int, mixed>}
     */
    private function bulletItem(string $text): array
    {
        return [
            'id' => (string) Str::uuid(),
            'text' => $text,
            'importance' => 0,
            'fallback_mode' => 'direct',
            'citations' => [],
        ];
    }

    /**
     * @param  array<string, array<int, array<string, mixed>>>  $sections
     * @return array{key_concepts: array<int, array{title: string}>, active_threads: array<int, array<string, string>>, open_questions: array<int, array<string, string>>, next_actions: array<int, array<string, string>>}
     */
    private function buildLegacyPayload(array $sections): array
    {
        $keyConcepts = collect($sections['Active Priorities'] ?? [])
            ->take(8)
            ->map(fn (array $item): array => ['title' => $item['text']])
            ->values()
            ->all();

        $activeThreads = collect($sections['Recent Changes'] ?? [])
            ->take(8)
            ->map(fn (array $item): array => ['title' => $item['text']])
            ->values()
            ->all();

        $openQuestions = collect($sections['Open Questions'] ?? [])
            ->take(8)
            ->map(fn (array $item): array => ['question' => $item['text']])
            ->values()
            ->all();

        $nextActions = collect($sections['Next Actions'] ?? [])
            ->take(8)
            ->map(fn (array $item): array => ['action' => $item['text']])
            ->values()
            ->all();

        return [
            'key_concepts' => $keyConcepts ?: [['title' => 'No key concepts identified yet']],
            'active_threads' => $activeThreads ?: [['title' => 'No active threads identified yet']],
            'open_questions' => $openQuestions ?: [['question' => 'What information is still missing?']],
            'next_actions' => $nextActions ?: [['action' => 'Capture more thoughts to improve memory coverage']],
        ];
    }
}
```

- [ ] **Step 4: Run all upsert tests to verify they pass**

Run: `php artisan test tests/Feature/WorkingMemoryUpsertServiceTest.php`
Expected: 4 tests PASS

- [ ] **Step 5: Commit**

```bash
git add app/Services/WorkingMemory/WorkingMemoryUpsertService.php tests/Feature/WorkingMemoryUpsertServiceTest.php
git commit -m "feat: add WorkingMemoryUpsertService for external working memory persistence"
```

---

### Task 2: Update Canonical Version Resolution in WorkingMemoryAssembler

**Files:**
- Modify: `app/Services/WorkingMemory/WorkingMemoryAssembler.php:393-446` (the `payloadFromPersistedMemory` and `resolveCanonicalVersion` methods)
- Test: `tests/Feature/WorkingMemoryUpsertServiceTest.php` (add integration test)

- [ ] **Step 1: Write the failing test — external version is returned by forScope**

Add to `tests/Feature/WorkingMemoryUpsertServiceTest.php`:

```php
#[Test]
public function test_forScope_returns_external_version_as_canonical(): void
{
    $user = User::factory()->create();
    $service = app(WorkingMemoryUpsertService::class);

    $markdown = <<<'MD'
    ## Current Focus

    - Ship the comments-page fix.

    ## Active Priorities

    - Test Evessio plugin.

    ## Recent Changes

    - WordPress upgrade scheduled.

    ## Open Questions

    - When is the API key available?

    ## Risks / Blockers

    - Evessio blocked on API key.

    ## Next Actions

    - Run staging tests.

    ## Latest Signals

    - Weekly check-in 2026-05-08.

    ## Source Notes

    - Reviewed context/index.md.
    MD;

    $service->upsert($user->id, 'project', 'dezeen', trim($markdown));

    $assembler = app(\App\Services\WorkingMemory\WorkingMemoryAssembler::class);
    $payload = $assembler->forScope($user->id, 'project', 'dezeen');

    $this->assertEquals('project', $payload['scope_type']);
    $this->assertEquals('dezeen', $payload['scope_key']);
    $this->assertEquals('external', $payload['baseline_build_type']);
    $this->assertStringContainsString('Ship the comments-page fix', $payload['summary_markdown']);
    $this->assertArrayHasKey('Current Focus', $payload['structured_sections']);
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/WorkingMemoryUpsertServiceTest.php --filter=test_forScope_returns_external_version_as_canonical`
Expected: FAIL — `resolveCanonicalVersion` only looks for `consolidated` build type, so the external version is not returned as canonical; it falls through to `latestVersion` but `baseline_build_type` assertion may still fail depending on whether it matches.

- [ ] **Step 3: Update resolveCanonicalVersion to include external builds**

In `app/Services/WorkingMemory/WorkingMemoryAssembler.php`, replace the `payloadFromPersistedMemory` method's consolidated query and the `resolveCanonicalVersion` method:

Replace this block (lines 395-405):

```php
        $latestConsolidated = $memory->versions()
            ->where('build_type', 'consolidated')
            ->orderByDesc('created_at')
            ->first();

        $latestIncremental = $memory->versions()
            ->where('build_type', 'incremental')
            ->orderByDesc('created_at')
            ->first();

        $canonical = $this->resolveCanonicalVersion($memory, $latestConsolidated);
```

With:

```php
        $latestAuthoritative = $memory->versions()
            ->whereIn('build_type', ['consolidated', 'external'])
            ->orderByDesc('created_at')
            ->first();

        $latestIncremental = $memory->versions()
            ->where('build_type', 'incremental')
            ->orderByDesc('created_at')
            ->first();

        $canonical = $this->resolveCanonicalVersion($memory, $latestAuthoritative);
```

The `resolveCanonicalVersion` method signature and body stay the same — it already handles the `null` case by falling back to `latestVersion`. The parameter name change from `$latestConsolidated` to `$latestAuthoritative` is internal only.

Also update the `resolveCanonicalVersion` signature and PHPDoc for clarity:

Replace (lines 434-446):

```php
    private function resolveCanonicalVersion(WorkingMemory $memory, ?WorkingMemoryVersion $latestConsolidated): WorkingMemoryVersion
    {
        if ($latestConsolidated !== null) {
            return $latestConsolidated;
        }

        $latest = $memory->latestVersion;
        if ($latest === null) {
            throw new RuntimeException('Working memory is missing a latest version.');
        }

        return $latest;
    }
```

With:

```php
    private function resolveCanonicalVersion(WorkingMemory $memory, ?WorkingMemoryVersion $latestAuthoritative): WorkingMemoryVersion
    {
        if ($latestAuthoritative !== null) {
            return $latestAuthoritative;
        }

        $latest = $memory->latestVersion;
        if ($latest === null) {
            throw new RuntimeException('Working memory is missing a latest version.');
        }

        return $latest;
    }
```

- [ ] **Step 4: Run the integration test and the full existing test suite**

Run: `php artisan test tests/Feature/WorkingMemoryUpsertServiceTest.php tests/Feature/WorkingMemoryApiTest.php`
Expected: All tests PASS (existing tests unaffected — they create `consolidated` versions which still match `whereIn(['consolidated', 'external'])`)

- [ ] **Step 5: Commit**

```bash
git add app/Services/WorkingMemory/WorkingMemoryAssembler.php tests/Feature/WorkingMemoryUpsertServiceTest.php
git commit -m "feat: treat external working memory versions as authoritative alongside consolidated"
```

---

### Task 3: MCP Method — upsert_working_memory

**Files:**
- Modify: `app/Http/Controllers/Api/McpController.php`
- Test: `tests/Feature/McpUpsertWorkingMemoryTest.php`

- [ ] **Step 1: Write the failing test — MCP upsert_working_memory method**

```php
// tests/Feature/McpUpsertWorkingMemoryTest.php
<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserMcpKey;
use App\Models\WorkingMemory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class McpUpsertWorkingMemoryTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function test_upsert_working_memory_via_mcp_persists_external_version(): void
    {
        $user = User::factory()->create();
        $key = UserMcpKey::factory()->for($user)->create();

        $markdown = "## Current Focus\n\n- Ship the fix.\n\n## Active Priorities\n\n- Test staging.";

        $response = $this->postJson('/api/mcp', [
            'jsonrpc' => '2.0',
            'method' => 'upsert_working_memory',
            'params' => [
                'scope_type' => 'project',
                'scope_key' => 'dezeen',
                'content' => $markdown,
                'source_label' => 'elixirr-sync',
            ],
            'id' => 1,
        ], ['x-ideatub-key' => $key->key]);

        $response->assertOk();
        $response->assertJsonPath('jsonrpc', '2.0');
        $response->assertJsonPath('id', 1);
        $this->assertNull($response->json('error'));

        $result = $response->json('result');
        $this->assertEquals('external', $result['build_type']);
        $this->assertEquals('project', $result['scope_type']);
        $this->assertEquals('dezeen', $result['scope_key']);

        $memory = WorkingMemory::where('user_id', $user->id)
            ->where('scope_type', 'project')
            ->where('scope_key', 'dezeen')
            ->first();
        $this->assertNotNull($memory);
        $this->assertEquals('fresh', $memory->freshness_state);
    }

    #[Test]
    public function test_upsert_working_memory_requires_content(): void
    {
        $user = User::factory()->create();
        $key = UserMcpKey::factory()->for($user)->create();

        $response = $this->postJson('/api/mcp', [
            'jsonrpc' => '2.0',
            'method' => 'upsert_working_memory',
            'params' => [
                'scope_type' => 'project',
                'scope_key' => 'dezeen',
            ],
            'id' => 2,
        ], ['x-ideatub-key' => $key->key]);

        $response->assertOk();
        $this->assertNotNull($response->json('error'));
    }

    #[Test]
    public function test_upsert_working_memory_requires_auth(): void
    {
        $response = $this->postJson('/api/mcp', [
            'jsonrpc' => '2.0',
            'method' => 'upsert_working_memory',
            'params' => [
                'scope_type' => 'project',
                'scope_key' => 'dezeen',
                'content' => '## Current Focus\n\n- Test.',
            ],
            'id' => 3,
        ]);

        $response->assertUnauthorized();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/McpUpsertWorkingMemoryTest.php --filter=test_upsert_working_memory_via_mcp_persists_external_version`
Expected: FAIL — method `upsert_working_memory` not found

- [ ] **Step 3: Register the MCP method and implement the handler**

In `app/Http/Controllers/Api/McpController.php`, make three changes:

**3a.** Add `WorkingMemoryUpsertService` to the constructor:

Add to the `use` imports at the top:
```php
use App\Services\WorkingMemory\WorkingMemoryUpsertService;
```

Add to the constructor parameter list (after `WorkingMemoryAssembler`):
```php
    private WorkingMemoryUpsertService $workingMemoryUpsertService,
```

**3b.** Add `'upsert_working_memory'` to `mcpMethodNames()`:

In the `$base` array, add after `'get_compaction'`:
```php
            'upsert_working_memory',
```

**3c.** Add the dispatch case and handler method:

In the `dispatch()` match block, add after the `'get_compaction'` case:
```php
            'upsert_working_memory' => $this->upsertWorkingMemory($params),
```

Add the handler method after `getWorkingMemory()`:
```php
    /**
     * upsert_working_memory: Persist externally-authored working memory markdown for a scope.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function upsertWorkingMemory(array $params): array
    {
        $input = $params;
        foreach (['scope_type', 'scope_key', 'content', 'source_label'] as $key) {
            if (isset($input[$key]) && is_string($input[$key])) {
                $input[$key] = trim($input[$key]);
            }
        }

        $v = Validator::make($input, [
            'scope_type' => 'required|string|in:global,project,insights,tag',
            'scope_key' => 'required|string|max:191',
            'content' => 'required|string|min:1',
            'source_label' => 'nullable|string|max:191',
        ]);
        if ($v->fails()) {
            throw new \InvalidArgumentException($v->errors()->first());
        }

        /** @var array{scope_type: string, scope_key: string, content: string, source_label: ?string} $validated */
        $validated = $v->validated();

        $version = $this->workingMemoryUpsertService->upsert(
            (int) auth()->id(),
            $validated['scope_type'],
            $validated['scope_key'],
            $validated['content'],
            $validated['source_label'] ?? null,
        );

        return [
            'build_type' => $version->build_type,
            'version_id' => (string) $version->id,
            'scope_type' => $version->workingMemory->scope_type,
            'scope_key' => $version->workingMemory->scope_key,
            'freshness_state' => $version->workingMemory->freshness_state,
        ];
    }
```

- [ ] **Step 4: Run all MCP upsert tests**

Run: `php artisan test tests/Feature/McpUpsertWorkingMemoryTest.php`
Expected: 3 tests PASS

- [ ] **Step 5: Run existing MCP tests to check for regressions**

Run: `php artisan test tests/Feature/McpApiTest.php`
Expected: All existing tests PASS

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Api/McpController.php tests/Feature/McpUpsertWorkingMemoryTest.php
git commit -m "feat: add upsert_working_memory MCP method"
```

---

### Task 4: REST Endpoint — POST /api/thoughts/working-memory/upsert

**Files:**
- Modify: `app/Http/Controllers/Api/ThoughtsApiController.php`
- Modify: `routes/api.php`
- Test: `tests/Feature/WorkingMemoryApiTest.php` (add REST upsert tests)

- [ ] **Step 1: Write the failing test — REST upsert endpoint**

Add to `tests/Feature/WorkingMemoryApiTest.php`:

```php
#[Test]
public function test_upsert_working_memory_via_rest_persists_external_version(): void
{
    $user = User::factory()->create();
    $token = 'test-access-token';

    $this->mock(OAuthMcpJwtService::class, function ($mock) use ($user, $token): void {
        $mock->shouldReceive('verifyAccessToken')
            ->once()
            ->with($token)
            ->andReturn([
                'user_id' => $user->id,
                'aud' => config('oauth-mcp.resource_api'),
            ]);
    });

    $markdown = "## Current Focus\n\n- Ship the fix.\n\n## Active Priorities\n\n- Test staging.";

    $response = $this->withHeader('Authorization', 'Bearer ' . $token)
        ->postJson('/api/thoughts/working-memory/upsert', [
            'scope_type' => 'project',
            'scope_key' => 'dezeen',
            'content' => $markdown,
            'source_label' => 'elixirr-sync',
        ]);

    $response->assertOk();
    $response->assertJsonPath('build_type', 'external');
    $response->assertJsonPath('scope_type', 'project');
    $response->assertJsonPath('scope_key', 'dezeen');
}

#[Test]
public function test_upsert_working_memory_rest_requires_auth(): void
{
    $response = $this->postJson('/api/thoughts/working-memory/upsert', [
        'scope_type' => 'project',
        'scope_key' => 'dezeen',
        'content' => '## Current Focus\n\n- Test.',
    ]);

    $response->assertUnauthorized();
}

#[Test]
public function test_upsert_working_memory_rest_validates_content(): void
{
    $user = User::factory()->create();
    $token = 'test-access-token';

    $this->mock(OAuthMcpJwtService::class, function ($mock) use ($user, $token): void {
        $mock->shouldReceive('verifyAccessToken')
            ->once()
            ->with($token)
            ->andReturn([
                'user_id' => $user->id,
                'aud' => config('oauth-mcp.resource_api'),
            ]);
    });

    $response = $this->withHeader('Authorization', 'Bearer ' . $token)
        ->postJson('/api/thoughts/working-memory/upsert', [
            'scope_type' => 'project',
            'scope_key' => 'dezeen',
        ]);

    $response->assertUnprocessable();
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/WorkingMemoryApiTest.php --filter=test_upsert_working_memory_via_rest`
Expected: FAIL — 404 (route not registered)

- [ ] **Step 3: Add the route and controller method**

**3a.** In `routes/api.php`, add inside the `auth.oauth.bearer` middleware group, after the existing `working-memory` GET route:

```php
    Route::post('/working-memory/upsert', [ThoughtsApiController::class, 'upsertWorkingMemory']);
```

**3b.** In `app/Http/Controllers/Api/ThoughtsApiController.php`, add the `use` import:

```php
use App\Services\WorkingMemory\WorkingMemoryUpsertService;
```

Add `WorkingMemoryUpsertService` to the constructor (check existing constructor pattern — if it uses DI in constructor, add there; if methods resolve from container, use `app()`).

Add the controller method after the existing `workingMemory()` method:

```php
    public function upsertWorkingMemory(Request $request): JsonResponse
    {
        $input = $request->only(['scope_type', 'scope_key', 'content', 'source_label']);
        foreach (['scope_type', 'scope_key', 'content', 'source_label'] as $key) {
            if (isset($input[$key]) && is_string($input[$key])) {
                $input[$key] = trim($input[$key]);
            }
        }

        $v = Validator::make($input, [
            'scope_type' => 'required|string|in:global,project,insights,tag',
            'scope_key' => 'required|string|max:191',
            'content' => 'required|string|min:1',
            'source_label' => 'nullable|string|max:191',
        ]);
        if ($v->fails()) {
            return response()->json(['error' => 'validation_error', 'message' => $v->errors()->first()], 422);
        }

        /** @var array{scope_type: string, scope_key: string, content: string, source_label: ?string} $validated */
        $validated = $v->validated();

        try {
            $version = app(WorkingMemoryUpsertService::class)->upsert(
                (int) auth()->id(),
                $validated['scope_type'],
                $validated['scope_key'],
                $validated['content'],
                $validated['source_label'] ?? null,
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => 'validation_error', 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'build_type' => $version->build_type,
            'version_id' => (string) $version->id,
            'scope_type' => $version->workingMemory->scope_type,
            'scope_key' => $version->workingMemory->scope_key,
            'freshness_state' => $version->workingMemory->freshness_state,
        ]);
    }
```

- [ ] **Step 4: Run all upsert REST tests**

Run: `php artisan test tests/Feature/WorkingMemoryApiTest.php --filter=test_upsert_working_memory`
Expected: 3 new tests PASS

- [ ] **Step 5: Run the full working memory API test suite**

Run: `php artisan test tests/Feature/WorkingMemoryApiTest.php`
Expected: All tests PASS (existing + new)

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Api/ThoughtsApiController.php routes/api.php tests/Feature/WorkingMemoryApiTest.php
git commit -m "feat: add REST endpoint POST /api/thoughts/working-memory/upsert"
```

---

### Task 5: MCP Tool Descriptor for upsert_working_memory

**Files:**
- Reference: Check how other MCP tool descriptors are published (e.g. `get_working_memory` tool descriptor)
- The MCP tool descriptor JSON lives in the IdeaTub MCP server's tool listing

- [ ] **Step 1: Check how existing tool descriptors are generated**

Run: `php artisan test tests/Feature/McpApiTest.php --filter=tools` to see if there's a tools/list test, or check the MCP `tools/list` handler in `McpController.php`.

Look for how `respondToolsList` builds the tool definitions.

- [ ] **Step 2: Add the upsert_working_memory tool descriptor**

Find the `respondToolsList` method (or equivalent) in `McpController.php` that returns tool schemas. Add the new tool:

```php
[
    'name' => 'upsert_working_memory',
    'description' => 'Persist externally-authored working memory markdown as the canonical online working memory for a scope. The markdown should contain ## headings for: Current Focus, Active Priorities, Recent Changes, Open Questions, Risks / Blockers, Next Actions, Latest Signals, Source Notes.',
    'inputSchema' => [
        'type' => 'object',
        'properties' => [
            'scope_type' => [
                'type' => 'string',
                'description' => 'Scope type: global, project, insights, or tag.',
                'enum' => ['global', 'project', 'insights', 'tag'],
            ],
            'scope_key' => [
                'type' => 'string',
                'description' => 'Scope identifier (e.g. "dezeen" for project scope, "global" for global scope).',
            ],
            'content' => [
                'type' => 'string',
                'description' => 'Full working memory markdown content with ## section headings.',
            ],
            'source_label' => [
                'type' => 'string',
                'description' => 'Optional origin identifier (e.g. "elixirr-sync").',
            ],
        ],
        'required' => ['scope_type', 'scope_key', 'content'],
    ],
],
```

- [ ] **Step 3: Run MCP tools/list test to verify the new tool appears**

Run: `php artisan test tests/Feature/McpApiTest.php`
Expected: All tests PASS, new tool visible in tools/list

- [ ] **Step 4: Commit**

```bash
git add app/Http/Controllers/Api/McpController.php
git commit -m "feat: add upsert_working_memory tool descriptor to MCP tools/list"
```

---

### Task 6: End-to-End Integration Test

**Files:**
- Test: `tests/Feature/WorkingMemoryUpsertServiceTest.php` (add e2e test)

- [ ] **Step 1: Write e2e test — upsert then read via get_working_memory**

Add to `tests/Feature/WorkingMemoryUpsertServiceTest.php`:

```php
#[Test]
public function test_upserted_memory_is_returned_by_get_working_memory_mcp(): void
{
    $user = User::factory()->create();
    $key = \App\Models\UserMcpKey::factory()->for($user)->create();

    $markdown = <<<'MD'
    ## Current Focus

    - Deliver the comments-page release.

    ## Active Priorities

    - Test Evessio plugin in staging.

    ## Recent Changes

    - WordPress upgrade scheduled for 2026-05-13.

    ## Open Questions

    - When will the API key be available?

    ## Risks / Blockers

    - Evessio blocked on API key access.

    ## Next Actions

    - Get the API key and run staging tests.

    ## Latest Signals

    - Weekly check-in confirmed comments service restored.

    ## Source Notes

    - Reviewed weekly check-in 2026-05-08.
    MD;

    // Upsert
    $upsertResponse = $this->postJson('/api/mcp', [
        'jsonrpc' => '2.0',
        'method' => 'upsert_working_memory',
        'params' => [
            'scope_type' => 'project',
            'scope_key' => 'dezeen',
            'content' => trim($markdown),
            'source_label' => 'elixirr-sync',
        ],
        'id' => 1,
    ], ['x-ideatub-key' => $key->key]);
    $upsertResponse->assertOk();

    // Read back via get_working_memory
    $readResponse = $this->postJson('/api/mcp', [
        'jsonrpc' => '2.0',
        'method' => 'get_working_memory',
        'params' => [
            'scope_type' => 'project',
            'scope_key' => 'dezeen',
        ],
        'id' => 2,
    ], ['x-ideatub-key' => $key->key]);
    $readResponse->assertOk();

    $resultText = $readResponse->json('result.content.0.text')
        ?? $readResponse->json('result.summary_markdown')
        ?? json_encode($readResponse->json('result'));

    $this->assertStringContainsString('Deliver the comments-page release', $resultText);
    $this->assertStringContainsString('external', $resultText);
}
```

- [ ] **Step 2: Run the e2e test**

Run: `php artisan test tests/Feature/WorkingMemoryUpsertServiceTest.php --filter=test_upserted_memory_is_returned_by_get_working_memory_mcp`
Expected: PASS

- [ ] **Step 3: Run the full test suite to check for regressions**

Run: `php artisan test`
Expected: All tests PASS

- [ ] **Step 4: Commit**

```bash
git add tests/Feature/WorkingMemoryUpsertServiceTest.php
git commit -m "test: add e2e test for upsert then get_working_memory round-trip"
```

---

### Task 7: Final Verification and Cleanup

- [ ] **Step 1: Run Laravel Pint for code formatting**

Run: `./vendor/bin/pint`
Expected: Files formatted (if any changes needed)

- [ ] **Step 2: Run the full test suite one final time**

Run: `php artisan test`
Expected: All tests PASS

- [ ] **Step 3: Commit any formatting changes**

```bash
git add -A
git commit -m "style: apply Laravel Pint formatting"
```

(Skip this commit if Pint made no changes.)

- [ ] **Step 4: Verify the implementation matches the spec**

Check against `docs/superpowers/specs/2026-05-12-working-memory-parity-design.md` Phase 1:
- [x] `WorkingMemoryUpsertService` — markdown parsing, section extraction, version persistence
- [x] `McpController` — `upsert_working_memory` MCP method handler + tool descriptor
- [x] `ThoughtsApiController` — `POST /api/thoughts/working-memory/upsert` route
- [x] `routes/api.php` — route registered
- [x] `WorkingMemoryAssembler` — `resolveCanonicalVersion()` includes `external` build type
- [x] Tests — unit, integration, e2e, regression
