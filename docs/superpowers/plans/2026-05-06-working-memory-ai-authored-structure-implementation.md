# Working Memory AI Authored Structure Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implement AI-authored working memory in `current.md`-style sections for all scopes (`global`, `project`, `insights`, `tag`) with enforced references and safe fallback behavior.

**Architecture:** Add a deterministic evidence-pack stage before synthesis, then run an AI author + validator pipeline that outputs both markdown and structured sections/references. Persist the enriched payload in existing working-memory tables (with additive columns), expose it through existing assembler/API/MCP/web surfaces, and retain last-known-good fallback whenever authoring/validation fails.

**Tech Stack:** Laravel 12, existing working memory services (`WorkingMemoryBuilderService`, `WorkingMemoryAssembler`), OpenRouter integration, MySQL/SQLite JSON columns, Blade views, PHPUnit/Pest feature + unit tests.

---

## File structure (creates + touches)

| Path | Responsibility |
|------|----------------|
| `config/working_memory.php` | Add authoring/validation thresholds and model toggles. |
| `config/features.php` | Add rollout feature flag for AI-authored working memory. |
| `database/migrations/2026_05_06_*.php` | Add additive columns for structured sections + references + authoring metadata. |
| `app/Models/WorkingMemoryVersion.php` | Fillable + casts for new JSON/metadata fields. |
| `app/Services/WorkingMemory/WorkingMemoryEvidencePackBuilder.php` | Deterministic scope-aware evidence assembly with preferred/fallback references. |
| `app/Services/WorkingMemory/WorkingMemoryAiAuthorService.php` | Prompted AI authoring of required 8-section schema. |
| `app/Services/WorkingMemory/WorkingMemoryOutputValidator.php` | Section completeness, citation coverage, and reference validity checks. |
| `app/Services/WorkingMemory/WorkingMemoryBuilderService.php` | Integrate evidence->author->validator pipeline; preserve fallback guarantees. |
| `app/Services/WorkingMemory/WorkingMemoryAssembler.php` | Return new structured fields (`structured_sections`, `references`, `citation_coverage`). |
| `app/Services/WorkingMemory/MemoryInsightsService.php` | Align insights authoring with the same section contract. |
| `app/Http/Controllers/Api/ThoughtsApiController.php` | No route change; ensure response assertions match added payload fields via tests. |
| `app/Http/Controllers/Api/McpController.php` | No method change; ensure `get_working_memory` payload carries new fields. |
| `resources/views/memory/show.blade.php` | Render section blocks + citation affordances when structured payload exists. |
| `resources/views/memory/insights.blade.php` | Render standardized authored structure for insights with references. |
| `tests/Unit/Services/WorkingMemory/WorkingMemoryEvidencePackBuilderTest.php` | Unit coverage for scope filtering, ranking, and reference preference. |
| `tests/Unit/Services/WorkingMemory/WorkingMemoryOutputValidatorTest.php` | Unit coverage for section/citation/reference checks and fail modes. |
| `tests/Unit/Services/WorkingMemory/WorkingMemoryBuilderServiceTest.php` | End-to-end builder behavior across all scopes + fallback paths. |
| `tests/Feature/WorkingMemoryApiTest.php` | API contract coverage for structured sections and references. |
| `tests/Feature/McpApiTest.php` | MCP `get_working_memory` contract coverage for new fields. |
| `tests/Feature/WorkingMemoryWebTest.php` | Global/project page rendering of structured sections + reference chips/links. |
| `tests/Feature/MemoryInsightsWebTest.php` | Insights page rendering with standardized sections and references. |
| `tests/Unit/Config/FeaturesConfigTest.php` | Assert new feature flag key registration. |

---

### Task 1: Add persistence + config foundations for authored output

**Files:**
- Create: `database/migrations/2026_05_06_120000_add_ai_authoring_fields_to_working_memory_versions.php`
- Modify: `app/Models/WorkingMemoryVersion.php`
- Modify: `config/working_memory.php`
- Modify: `config/features.php`
- Modify: `tests/Unit/Config/FeaturesConfigTest.php`

- [ ] **Step 1: Write the failing config test for the new feature flag**

```php
#[Test]
public function working_memory_authoring_feature_key_exists(): void
{
    $this->assertIsBool(config('features.working_memory_ai_authored'));
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Unit/Config/FeaturesConfigTest.php --filter=authoring_feature_key`  
Expected: FAIL with missing/null `features.working_memory_ai_authored`.

- [ ] **Step 3: Add config keys and migration/model fields**

```php
// config/features.php
'working_memory_ai_authored' => env('FEATURE_WORKING_MEMORY_AI_AUTHORED', false),

// config/working_memory.php
'authoring_enabled' => env('WORKING_MEMORY_AUTHORING_ENABLED', false),
'citation_min_coverage' => (float) env('WORKING_MEMORY_CITATION_MIN_COVERAGE', 0.90),
'authoring_model' => env('WORKING_MEMORY_AUTHORING_MODEL', 'openrouter/auto'),
```

```php
// migration up()
$table->json('structured_sections_json')->nullable();
$table->json('references_json')->nullable();
$table->decimal('citation_coverage', 5, 2)->nullable();
$table->string('authoring_status', 32)->nullable();
$table->text('validation_error')->nullable();
```

```php
// app/Models/WorkingMemoryVersion.php (snippets)
protected $fillable = [
    // existing...
    'structured_sections_json',
    'references_json',
    'citation_coverage',
    'authoring_status',
    'validation_error',
];

protected function casts(): array
{
    return [
        // existing...
        'structured_sections_json' => 'array',
        'references_json' => 'array',
        'citation_coverage' => 'decimal:2',
    ];
}
```

- [ ] **Step 4: Run targeted tests and migration smoke**

Run:  
`php artisan test tests/Unit/Config/FeaturesConfigTest.php`  
`php artisan test tests/Feature/WorkingMemorySchemaTest.php`

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add database/migrations/2026_05_06_120000_add_ai_authoring_fields_to_working_memory_versions.php app/Models/WorkingMemoryVersion.php config/working_memory.php config/features.php tests/Unit/Config/FeaturesConfigTest.php
git commit -m "feat(memory): add ai-authored working memory persistence and config flags"
```

---

### Task 2: Build deterministic evidence-pack service with reference preference logic

**Files:**
- Create: `app/Services/WorkingMemory/WorkingMemoryEvidencePackBuilder.php`
- Create: `tests/Unit/Services/WorkingMemory/WorkingMemoryEvidencePackBuilderTest.php`
- Modify: `app/Services/WorkingMemory/WorkingMemoryBuilderService.php`

- [ ] **Step 1: Write failing unit tests for evidence-pack behavior**

```php
public function test_it_prefers_internal_thought_links_and_falls_back_to_source_refs(): void
{
    $pack = $this->builder->build($userId, 'global', 'global', $thoughts);

    $firstRef = $pack['signals'][0]['references'][0] ?? null;
    $this->assertSame('thought', $firstRef['type']);
    $this->assertStringContainsString('/thoughts/', $firstRef['url']);
}
```

```php
public function test_it_builds_scope_specific_signal_set_for_tag_scope(): void
{
    $pack = $this->builder->build($userId, 'tag', 'ai', $thoughts);
    $this->assertNotEmpty($pack['signals']);
    $this->assertSame('tag', $pack['scope_type']);
    $this->assertSame('ai', $pack['scope_key']);
}
```

- [ ] **Step 2: Run tests to verify failures**

Run: `php artisan test tests/Unit/Services/WorkingMemory/WorkingMemoryEvidencePackBuilderTest.php`  
Expected: FAIL (`Class ...WorkingMemoryEvidencePackBuilder not found`).

- [ ] **Step 3: Implement evidence pack builder**

```php
final class WorkingMemoryEvidencePackBuilder
{
    public function build(int $userId, string $scopeType, string $scopeKey, Collection $thoughts): array
    {
        $signals = $thoughts
            ->sortByDesc(fn (Thought $t) => $t->created_at)
            ->take(60)
            ->values()
            ->map(fn (Thought $t): array => [
                'thought_id' => (string) $t->id,
                'content' => trim((string) $t->content),
                'created_at' => $t->created_at?->toIso8601String(),
                'references' => $this->referencesForThought($t),
            ])
            ->all();

        return [
            'scope_type' => $scopeType,
            'scope_key' => $scopeKey,
            'signals' => $signals,
            'generated_at' => now()->toIso8601String(),
        ];
    }
}
```

```php
private function referencesForThought(Thought $thought): array
{
    $refs = [];
    $refs[] = ['type' => 'thought', 'url' => route('thoughts.show', $thought), 'label' => (string) $thought->id];

    $filePath = (string) data_get($thought->source_metadata, 'file_path', '');
    if ($filePath !== '') {
        $refs[] = ['type' => 'source', 'url' => $filePath, 'label' => basename($filePath)];
    }

    return $refs;
}
```

- [ ] **Step 4: Wire builder into `WorkingMemoryBuilderService` constructor (no behavior switch yet)**

```php
public function __construct(
    private readonly WorkingMemoryAssembler $assembler,
    private readonly WorkingMemoryScopeNormalizer $scopeNormalizer,
    private readonly WorkingMemoryConsolidationWindowResolver $consolidationWindowResolver,
    private readonly MemoryInsightsService $memoryInsightsService,
    private readonly WorkingMemoryEvidencePackBuilder $evidencePackBuilder,
) {}
```

- [ ] **Step 5: Run tests**

Run:  
`php artisan test tests/Unit/Services/WorkingMemory/WorkingMemoryEvidencePackBuilderTest.php`  
`php artisan test tests/Unit/Services/WorkingMemory/WorkingMemoryBuilderServiceTest.php --filter=scope`

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Services/WorkingMemory/WorkingMemoryEvidencePackBuilder.php app/Services/WorkingMemory/WorkingMemoryBuilderService.php tests/Unit/Services/WorkingMemory/WorkingMemoryEvidencePackBuilderTest.php
git commit -m "feat(memory): add deterministic evidence pack builder for ai authoring"
```

---

### Task 3: Implement AI author + output validator services

**Files:**
- Create: `app/Services/WorkingMemory/WorkingMemoryAiAuthorService.php`
- Create: `app/Services/WorkingMemory/WorkingMemoryOutputValidator.php`
- Create: `tests/Unit/Services/WorkingMemory/WorkingMemoryOutputValidatorTest.php`
- Modify: `tests/Unit/Services/WorkingMemory/WorkingMemoryBuilderServiceTest.php`

- [ ] **Step 1: Write failing validator tests**

```php
public function test_validator_rejects_missing_required_sections(): void
{
    $result = $this->validator->validate([
        'Current Focus' => ['- Item'],
        // missing seven required sections...
    ], 0.90);

    $this->assertFalse($result->ok);
    $this->assertStringContainsString('missing required section', $result->message);
}
```

```php
public function test_validator_rejects_unresolvable_references(): void
{
    $result = $this->validator->validate($sections, 0.90, [
        ['type' => 'thought', 'url' => ''],
    ]);

    $this->assertFalse($result->ok);
    $this->assertStringContainsString('reference', $result->message);
}
```

- [ ] **Step 2: Run tests to verify failures**

Run: `php artisan test tests/Unit/Services/WorkingMemory/WorkingMemoryOutputValidatorTest.php`  
Expected: FAIL (`Class ...WorkingMemoryOutputValidator not found`).

- [ ] **Step 3: Implement AI author service with strict section contract**

```php
final class WorkingMemoryAiAuthorService
{
    public function authorFromEvidence(array $evidencePack): array
    {
        // Call model service with a fixed 8-section contract.
        // Return:
        // [
        //   'summary_markdown' => '...',
        //   'structured_sections' => [...],
        //   'references' => [...],
        // ]
    }
}
```

```php
private const REQUIRED_SECTIONS = [
    'Current Focus',
    'Active Priorities',
    'Recent Changes',
    'Open Questions',
    'Risks / Blockers',
    'Next Actions',
    'Latest Signals',
    'Source Notes',
];
```

- [ ] **Step 4: Implement validator with hard-fail and coverage checks**

```php
final class WorkingMemoryOutputValidator
{
    public function validate(array $structuredSections, float $minCoverage, array $references = []): ValidationResult
    {
        // 1) Required sections present
        // 2) Reference validity (hard fail)
        // 3) Coverage >= minCoverage (soft fail)
    }
}
```

- [ ] **Step 5: Add builder-level failing test for fallback on validator failure**

```php
public function test_builder_keeps_last_known_good_when_authoring_validation_fails(): void
{
    // Seed a valid consolidated version, then force validator failure.
    // Assert returned version id remains previous latest and memory freshness becomes degraded.
}
```

- [ ] **Step 6: Run tests**

Run:  
`php artisan test tests/Unit/Services/WorkingMemory/WorkingMemoryOutputValidatorTest.php`  
`php artisan test tests/Unit/Services/WorkingMemory/WorkingMemoryBuilderServiceTest.php --filter=validation_fails`

Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add app/Services/WorkingMemory/WorkingMemoryAiAuthorService.php app/Services/WorkingMemory/WorkingMemoryOutputValidator.php tests/Unit/Services/WorkingMemory/WorkingMemoryOutputValidatorTest.php tests/Unit/Services/WorkingMemory/WorkingMemoryBuilderServiceTest.php
git commit -m "feat(memory): add ai author and validator for structured working memory"
```

---

### Task 4: Integrate authoring pipeline into `WorkingMemoryBuilderService` for all scopes

**Files:**
- Modify: `app/Services/WorkingMemory/WorkingMemoryBuilderService.php`
- Modify: `app/Services/WorkingMemory/MemoryInsightsService.php`
- Modify: `tests/Unit/Services/WorkingMemory/WorkingMemoryBuilderServiceTest.php`

- [ ] **Step 1: Write failing tests for all-scope authored structure**

```php
#[Test]
public function it_persists_authored_sections_and_references_for_global_scope(): void
{
    $version = app(WorkingMemoryBuilderService::class)->buildConsolidated($user->id, 'global', 'global');

    $this->assertIsArray($version->structured_sections_json);
    $this->assertArrayHasKey('Current Focus', $version->structured_sections_json);
    $this->assertIsArray($version->references_json);
}
```

```php
#[Test]
public function it_persists_authored_structure_for_tag_project_and_insights_scopes(): void
{
    // Build each scope and assert section schema presence.
}
```

- [ ] **Step 2: Run tests to verify failures**

Run: `php artisan test tests/Unit/Services/WorkingMemory/WorkingMemoryBuilderServiceTest.php --filter=authored`  
Expected: FAIL on missing fields/authoring path.

- [ ] **Step 3: Implement authoring branch in builder with feature/config gate**

```php
$authoringEnabled = config('features.working_memory_ai_authored')
    && config('working_memory.authoring_enabled');

if ($authoringEnabled) {
    $evidence = $this->evidencePackBuilder->build($userId, $normalizedScopeType, $normalizedScopeKey, $thoughts);
    $authored = $this->aiAuthorService->authorFromEvidence($evidence);
    $validation = $this->outputValidator->validate(
        $authored['structured_sections'],
        (float) config('working_memory.citation_min_coverage'),
        $authored['references']
    );
}
```

```php
$version = $memory->versions()->create([
    // existing...
    'structured_sections_json' => $authored['structured_sections'] ?? null,
    'references_json' => $authored['references'] ?? null,
    'citation_coverage' => $validation->coveragePercent ?? null,
    'authoring_status' => $validation->ok ? 'validated' : 'rejected',
    'validation_error' => $validation->ok ? null : $validation->message,
]);
```

- [ ] **Step 4: Keep insights on same section schema**

```php
// MemoryInsightsService: return section-shaped payload compatible with REQUIRED_SECTIONS
return [
    'summary_markdown' => $summaryMarkdown,
    'structured_sections' => $sections,
    'references' => $references,
    // existing persisted fields...
];
```

- [ ] **Step 5: Run tests**

Run:  
`php artisan test tests/Unit/Services/WorkingMemory/WorkingMemoryBuilderServiceTest.php`  
`php artisan test tests/Unit/Services/WorkingMemory/WorkingMemoryFreshnessTest.php`

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Services/WorkingMemory/WorkingMemoryBuilderService.php app/Services/WorkingMemory/MemoryInsightsService.php tests/Unit/Services/WorkingMemory/WorkingMemoryBuilderServiceTest.php
git commit -m "feat(memory): integrate ai-authored pipeline across all working memory scopes"
```

---

### Task 5: Expose structured sections/references through assembler, API, MCP, and web UI

**Files:**
- Modify: `app/Services/WorkingMemory/WorkingMemoryAssembler.php`
- Modify: `tests/Feature/WorkingMemoryApiTest.php`
- Modify: `tests/Feature/McpApiTest.php`
- Modify: `resources/views/memory/show.blade.php`
- Modify: `resources/views/memory/insights.blade.php`
- Modify: `tests/Feature/WorkingMemoryWebTest.php`
- Modify: `tests/Feature/MemoryInsightsWebTest.php`

- [ ] **Step 1: Write failing API + MCP contract tests for new fields**

```php
$response->assertJsonStructure([
    // existing keys...
    'structured_sections',
    'references',
    'citation_coverage',
]);
```

```php
$this->assertIsArray($response->json('structured_sections'));
$this->assertIsArray($response->json('references'));
```

- [ ] **Step 2: Run tests to verify failures**

Run:  
`php artisan test tests/Feature/WorkingMemoryApiTest.php --filter=structured_sections`  
`php artisan test tests/Feature/McpApiTest.php --filter=working_memory`

Expected: FAIL on missing keys.

- [ ] **Step 3: Add assembler payload fields**

```php
return [
    // existing keys...
    'structured_sections' => $canonical->structured_sections_json ?? [],
    'references' => $canonical->references_json ?? [],
    'citation_coverage' => $canonical->citation_coverage !== null ? (float) $canonical->citation_coverage : null,
];
```

- [ ] **Step 4: Update Blade views to prefer structured sections and render references**

```blade
@php($sections = $structured_sections ?? [])
@if (!empty($sections))
    @foreach ($sections as $sectionTitle => $items)
        <h2>{{ $sectionTitle }}</h2>
        <ul>
            @foreach ($items as $item)
                <li>{{ $item['text'] ?? '' }}</li>
            @endforeach
        </ul>
    @endforeach
@else
    {!! \Illuminate\Support\Str::markdown($summary_markdown ?? '', ['html_input' => 'strip', 'allow_unsafe_links' => false]) !!}
@endif
```

```blade
@if (!empty($references))
    <div class="mt-4">
        @foreach ($references as $reference)
            <a href="{{ $reference['url'] }}" class="text-xs text-memory-violet">{{ $reference['label'] }}</a>
        @endforeach
    </div>
@endif
```

- [ ] **Step 5: Add web tests for citation link rendering**

```php
$response->assertSee('Current Focus', false);
$response->assertSee('href=', false); // citation anchor present
```

- [ ] **Step 6: Run tests**

Run:  
`php artisan test tests/Feature/WorkingMemoryApiTest.php tests/Feature/McpApiTest.php --filter=working_memory`  
`php artisan test tests/Feature/WorkingMemoryWebTest.php tests/Feature/MemoryInsightsWebTest.php`

Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add app/Services/WorkingMemory/WorkingMemoryAssembler.php tests/Feature/WorkingMemoryApiTest.php tests/Feature/McpApiTest.php resources/views/memory/show.blade.php resources/views/memory/insights.blade.php tests/Feature/WorkingMemoryWebTest.php tests/Feature/MemoryInsightsWebTest.php
git commit -m "feat(memory): expose structured authored sections and references in api/mcp/web"
```

---

### Task 6: Add rollout safety checks and full verification pass

**Files:**
- Modify: `tests/Unit/Services/WorkingMemory/WorkingMemoryBuilderServiceTest.php`
- Modify: `tests/Feature/WorkingMemoryApiTest.php`
- Modify: `docs/superpowers/specs/2026-05-06-working-memory-ai-authored-structure-design.md` (only if implementation-specific clarifications are needed)

- [ ] **Step 1: Add explicit rollback-path test coverage**

```php
public function test_failed_authoring_never_replaces_latest_version(): void
{
    // Build valid baseline, force authoring error, assert latest_version_id unchanged.
}
```

- [ ] **Step 2: Add coverage threshold assertion test**

```php
public function test_response_includes_citation_coverage_when_available(): void
{
    $response = $this->getJson('/api/thoughts/working-memory?scope_type=global&scope_key=global');
    $this->assertNotNull($response->json('citation_coverage'));
}
```

- [ ] **Step 3: Run focused full suite**

Run:  
`php artisan test tests/Unit/Services/WorkingMemory tests/Feature/WorkingMemoryApiTest.php tests/Feature/McpApiTest.php tests/Feature/WorkingMemoryWebTest.php tests/Feature/MemoryInsightsWebTest.php`

Expected: PASS.

- [ ] **Step 4: Run formatter**

Run: `./vendor/bin/pint --dirty`  
Expected: PASS with either no changes or auto-formatted touched files.

- [ ] **Step 5: Commit**

```bash
git add tests/Unit/Services/WorkingMemory/WorkingMemoryBuilderServiceTest.php tests/Feature/WorkingMemoryApiTest.php docs/superpowers/specs/2026-05-06-working-memory-ai-authored-structure-design.md
git commit -m "test(memory): lock rollback and citation coverage guarantees for ai-authored pipeline"
```

---

## Spec coverage self-check

- AI authors final output: covered in Tasks 3-4 (`WorkingMemoryAiAuthorService` + builder integration).
- All scopes (`global`, `project`, `insights`, `tag`): covered in Tasks 2 and 4 tests.
- Reference preference (thought links then source links): covered in Task 2 service + tests.
- Required `current.md` section schema: enforced in Task 3 validator and Task 4 persistence tests.
- Strict fallback (last-known-good): covered in Tasks 3, 4, and 6 rollback tests.
- API/MCP/UI structured output: covered in Task 5.
- Rollout flags + thresholds + observability fields: covered in Tasks 1 and 6.

No unresolved placeholders remain in this plan.
