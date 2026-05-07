# Working Memory Self-Contained MCP-First Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make IdeaTub the canonical scoped memory store and expose citation-rich, link-resolvable working memory payloads to downstream agents via MCP.

**Architecture:** Keep the existing working-memory pipeline (`evidence pack -> authored output -> validation -> persisted version`) and harden it around scope isolation, canonical link generation, and section-level audit references. Persist a richer contract (`section_references`) and enforce URL/citation validity so required bullets are always evidence-backed. Maintain last-known-good fallback behavior while making failures explicit via diagnostics.

**Tech Stack:** Laravel 12, PHP 8.2+, Eloquent models/migrations, existing WorkingMemory services, MCP controller surface, Pest/PHPUnit unit + feature tests.

---

## Scope check

This is one subsystem (working-memory assembly/authoring/validation/API contract). It is large but coherent, so one implementation plan with phased tasks is appropriate.

## File structure (creates + touches)

| Path | Responsibility |
|---|---|
| `database/migrations/2026_05_07_120000_add_section_references_to_working_memory_versions.php` | Persist section-level audit references per working-memory version. |
| `app/Models/WorkingMemoryVersion.php` | Add fillable/cast support for `section_references_json`. |
| `app/Services/WorkingMemory/WorkingMemoryEvidencePackBuilder.php` | Emit canonical internal source references (permalinks first) and section bundle material for section-level references. |
| `app/Services/WorkingMemory/WorkingMemoryAiAuthorService.php` | Preserve bullet-level citations and produce section-ready reference candidates consistently. |
| `app/Services/WorkingMemory/WorkingMemoryOutputValidator.php` | Enforce valid URLs for citations/references and guard required-section coverage in strict mode. |
| `app/Services/WorkingMemory/WorkingMemoryBuilderService.php` | Normalize and persist `section_references_json`; integrate validation diagnostics in final version contract. |
| `app/Services/WorkingMemory/WorkingMemoryAssembler.php` | Return `section_references` in canonical payload for API + MCP. |
| `app/Http/Controllers/Api/McpController.php` | Keep MCP tool schema/description aligned with enhanced working-memory contract. |
| `tests/Unit/Models/WorkingMemoryVersionTest.php` | Verify model cast/fillable for `section_references_json`. |
| `tests/Unit/Services/WorkingMemory/WorkingMemoryEvidencePackBuilderTest.php` | Verify scoped evidence references and canonical link strategy. |
| `tests/Unit/Services/WorkingMemory/WorkingMemoryOutputValidatorTest.php` | Verify URL validity + strict citation behavior. |
| `tests/Unit/Services/WorkingMemory/WorkingMemoryBuilderServiceTest.php` | Verify persistence and fallback behavior with `section_references`. |
| `tests/Feature/WorkingMemoryApiTest.php` | Verify REST working-memory payload includes `section_references`. |
| `tests/Feature/McpApiTest.php` | Verify MCP `get_working_memory` response includes `section_references` and structured contract fields. |
| `config/working_memory.php` | Keep strict citation threshold/required section policy explicit for canonical mode. |

---

### Task 1: Add persistence for section-level references

**Files:**
- Create: `database/migrations/2026_05_07_120000_add_section_references_to_working_memory_versions.php`
- Modify: `app/Models/WorkingMemoryVersion.php`
- Test: `tests/Unit/Models/WorkingMemoryVersionTest.php`

- [ ] **Step 1: Write failing model test for section references cast**

```php
#[Test]
public function section_references_json_is_cast_to_array(): void
{
    $version = WorkingMemoryVersion::factory()->make([
        'section_references_json' => [
            'Current Focus' => [
                ['type' => 'stream_filter', 'url' => '/stream?scope_type=project&scope_key=dezeen', 'label' => 'Current Focus evidence'],
            ],
        ],
    ]);

    $this->assertIsArray($version->section_references_json);
    $this->assertSame('Current Focus evidence', $version->section_references_json['Current Focus'][0]['label']);
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Unit/Models/WorkingMemoryVersionTest.php --filter=section_references_json_is_cast_to_array`  
Expected: FAIL because `section_references_json` is not fillable/cast yet.

- [ ] **Step 3: Add migration and model cast/fillable**

```php
// database/migrations/2026_05_07_120000_add_section_references_to_working_memory_versions.php
Schema::table('working_memory_versions', function (Blueprint $table): void {
    $table->json('section_references_json')->nullable()->after('references_json');
});
```

```php
// app/Models/WorkingMemoryVersion.php
protected $fillable = [
    // existing ...
    'section_references_json',
];

protected function casts(): array
{
    return [
        // existing ...
        'section_references_json' => 'array',
    ];
}
```

- [ ] **Step 4: Run targeted tests**

Run:  
`php artisan test tests/Unit/Models/WorkingMemoryVersionTest.php`  
`php artisan test tests/Feature/WorkingMemorySchemaTest.php`

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add database/migrations/2026_05_07_120000_add_section_references_to_working_memory_versions.php app/Models/WorkingMemoryVersion.php tests/Unit/Models/WorkingMemoryVersionTest.php
git commit -m "feat(memory): persist section-level working memory references"
```

---

### Task 2: Harden evidence-pack references for self-contained canonical links

**Files:**
- Modify: `app/Services/WorkingMemory/WorkingMemoryEvidencePackBuilder.php`
- Modify: `tests/Unit/Services/WorkingMemory/WorkingMemoryEvidencePackBuilderTest.php`

- [ ] **Step 1: Write failing tests for canonical thought-first references**

```php
#[Test]
public function evidence_pack_signal_references_prioritize_internal_thought_permalink(): void
{
    $pack = app(WorkingMemoryEvidencePackBuilder::class)->build($user->id, 'project', 'dezeen', collect([$thought]));

    $refs = $pack['signals'][0]['references'] ?? [];
    $this->assertNotEmpty($refs);
    $this->assertSame('thought', $refs[0]['type']);
    $this->assertStringContainsString('/thoughts/', $refs[0]['url']);
}
```

```php
#[Test]
public function evidence_pack_builds_section_bundles_for_latest_signals_when_source_metadata_exists(): void
{
    $pack = app(WorkingMemoryEvidencePackBuilder::class)->build($user->id, 'project', 'dezeen', $thoughts);

    $this->assertArrayHasKey('section_bundles', $pack);
    $this->assertArrayHasKey('Latest Signals', $pack['section_bundles']);
    $this->assertNotEmpty($pack['section_bundles']['Latest Signals']);
}
```

- [ ] **Step 2: Run tests to verify failures**

Run: `php artisan test tests/Unit/Services/WorkingMemory/WorkingMemoryEvidencePackBuilderTest.php`  
Expected: FAIL on ordering/link expectations.

- [ ] **Step 3: Implement canonical link/reference behavior**

```php
// app/Services/WorkingMemory/WorkingMemoryEvidencePackBuilder.php
private function referencesForThought(Thought $thought): array
{
    $references = [];

    $internal = $this->internalThoughtReference($thought);
    if ($internal !== null) {
        $references[] = $internal; // canonical self-contained link
    }

    $fallback = $this->sourceFallbackReference($thought);
    if ($fallback !== null) {
        $references[] = $fallback;
    }

    return $references;
}
```

```php
private function sourceFallbackReference(Thought $thought): ?array
{
    $sourceMetadata = is_array($thought->source_metadata) ? $thought->source_metadata : [];
    $url = trim((string) data_get($sourceMetadata, 'source_doc_url', data_get($sourceMetadata, 'source_url', data_get($sourceMetadata, 'url', ''))));
    if ($url === '') {
        return null;
    }

    $label = trim((string) data_get($sourceMetadata, 'section_title', data_get($sourceMetadata, 'doc_type', 'Source')));

    return [
        'type' => 'source',
        'url' => $url,
        'label' => $label !== '' ? $label : $url,
    ];
}
```

- [ ] **Step 4: Run tests**

Run: `php artisan test tests/Unit/Services/WorkingMemory/WorkingMemoryEvidencePackBuilderTest.php`  
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/WorkingMemory/WorkingMemoryEvidencePackBuilder.php tests/Unit/Services/WorkingMemory/WorkingMemoryEvidencePackBuilderTest.php
git commit -m "feat(memory): harden evidence references for self-contained canonical links"
```

---

### Task 3: Persist and expose section-level references in builder + assembler

**Files:**
- Modify: `app/Services/WorkingMemory/WorkingMemoryBuilderService.php`
- Modify: `app/Services/WorkingMemory/WorkingMemoryAssembler.php`
- Modify: `tests/Unit/Services/WorkingMemory/WorkingMemoryBuilderServiceTest.php`
- Modify: `tests/Feature/WorkingMemoryApiTest.php`

- [ ] **Step 1: Write failing builder test for section reference persistence**

```php
#[Test]
public function build_consolidated_persists_section_references_json(): void
{
    $version = app(WorkingMemoryBuilderService::class)->buildConsolidated($user->id, 'project', 'dezeen');

    $this->assertIsArray($version->section_references_json);
    $this->assertArrayHasKey('Current Focus', $version->section_references_json);
}
```

- [ ] **Step 2: Run test to verify failure**

Run: `php artisan test tests/Unit/Services/WorkingMemory/WorkingMemoryBuilderServiceTest.php --filter=persists_section_references_json`  
Expected: FAIL because builder does not yet persist `section_references_json`.

- [ ] **Step 3: Add section reference normalization + persistence**

```php
// app/Services/WorkingMemory/WorkingMemoryBuilderService.php
$sectionReferences = $this->buildSectionReferences(
    $structuredSections,
    $references,
    $normalizedScopeType,
    $normalizedScopeKey
);

$version = $memory->versions()->create([
    // existing...
    'structured_sections_json' => $structuredSections,
    'references_json' => $references,
    'section_references_json' => $sectionReferences,
]);
```

```php
private function buildSectionReferences(array $sections, array $references, string $scopeType, string $scopeKey): array
{
    $streamUrl = sprintf('/stream?scope_type=%s&scope_key=%s', urlencode($scopeType), urlencode($scopeKey));

    return collect($sections)->mapWithKeys(function (array $items, string $section) use ($references, $streamUrl): array {
        $label = $section.' evidence';
        $row = ['type' => 'stream_filter', 'url' => $streamUrl, 'label' => $label];

        return [trim($section) => [$row, ...array_slice($references, 0, 3)]];
    })->all();
}
```

- [ ] **Step 4: Expose `section_references` in assembler payload**

```php
// app/Services/WorkingMemory/WorkingMemoryAssembler.php
'section_references' => $canonical->section_references_json ?? [],
```

- [ ] **Step 5: Add failing API assertion then make it pass**

```php
// tests/Feature/WorkingMemoryApiTest.php
$response->assertJsonStructure([
    'section_references',
]);
$this->assertIsArray($response->json('section_references'));
```

- [ ] **Step 6: Run tests**

Run:  
`php artisan test tests/Unit/Services/WorkingMemory/WorkingMemoryBuilderServiceTest.php`  
`php artisan test tests/Feature/WorkingMemoryApiTest.php --filter=working_memory`

Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add app/Services/WorkingMemory/WorkingMemoryBuilderService.php app/Services/WorkingMemory/WorkingMemoryAssembler.php tests/Unit/Services/WorkingMemory/WorkingMemoryBuilderServiceTest.php tests/Feature/WorkingMemoryApiTest.php
git commit -m "feat(memory): persist and expose section-level working memory references"
```

---

### Task 4: Tighten validator rules for URL integrity and citation coverage

**Files:**
- Modify: `app/Services/WorkingMemory/WorkingMemoryOutputValidator.php`
- Modify: `config/working_memory.php`
- Modify: `tests/Unit/Services/WorkingMemory/WorkingMemoryOutputValidatorTest.php`

- [ ] **Step 1: Write failing tests for invalid URL rejection and strict coverage**

```php
#[Test]
public function invalid_reference_url_hard_fails_validation(): void
{
    $payload = $this->validStructuredPayload();
    $payload['references'][0]['url'] = 'javascript:alert(1)';

    $result = app(WorkingMemoryOutputValidator::class)->validate($payload, 1.0);

    $this->assertFalse($result['ok']);
    $this->assertSame('hard', $result['failure_type']);
    $this->assertContains('invalid_link', $result['diagnostics']['reason_codes']);
}
```

```php
#[Test]
public function required_item_without_citation_is_counted_and_rejected(): void
{
    $payload = $this->validStructuredPayload();
    $payload['structured_sections']['Next Actions'][0]['citations'] = [];

    $result = app(WorkingMemoryOutputValidator::class)->validate($payload, 1.0);

    $this->assertFalse($result['ok']);
    $this->assertSame('hard', $result['failure_type']);
    $this->assertContains('missing_citation', $result['diagnostics']['reason_codes']);
}
```

- [ ] **Step 2: Run tests to verify failures**

Run: `php artisan test tests/Unit/Services/WorkingMemory/WorkingMemoryOutputValidatorTest.php`  
Expected: FAIL until URL and coverage guards are fully enforced for structured items.

- [ ] **Step 3: Ensure supported URL and citation resolvability checks are strict**

```php
// app/Services/WorkingMemory/WorkingMemoryOutputValidator.php
if (! $this->isSupportedReferenceUrl($url)) {
    return $this->hardFail('References must include supported safe URL formats.', 0, 0, ['invalid_link']);
}
```

```php
if ($citations === []) {
    $reasonCodes[] = 'missing_citation';
    continue;
}
if (! $this->citationsAreResolvable($citations)) {
    $reasonCodes[] = 'invalid_link';
    continue;
}
```

- [ ] **Step 4: Confirm strict canonical defaults in config**

```php
// config/working_memory.php
'citation_min_coverage' => (float) env('WORKING_MEMORY_CITATION_MIN_COVERAGE', 1.00),
'citation_required_sections' => [
    'Current Focus',
    'Active Priorities',
    'Recent Changes',
    'Open Questions',
    'Risks / Blockers',
    'Next Actions',
    'Latest Signals',
    'Source Notes',
],
```

- [ ] **Step 5: Run tests**

Run:  
`php artisan test tests/Unit/Services/WorkingMemory/WorkingMemoryOutputValidatorTest.php`  
`php artisan test tests/Unit/Services/WorkingMemory/WorkingMemoryBuilderServiceTest.php --filter=hard`

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Services/WorkingMemory/WorkingMemoryOutputValidator.php config/working_memory.php tests/Unit/Services/WorkingMemory/WorkingMemoryOutputValidatorTest.php
git commit -m "feat(memory): enforce strict citation integrity and safe URL validation"
```

---

### Task 5: Align MCP contract and tests with canonical memory payload

**Files:**
- Modify: `app/Http/Controllers/Api/McpController.php`
- Modify: `tests/Feature/McpApiTest.php`
- Modify: `tests/Feature/WorkingMemoryApiTest.php` (if additional payload shape assertion needed)

- [ ] **Step 1: Write failing MCP payload assertion for `section_references`**

```php
// tests/Feature/McpApiTest.php
$response->assertStatus(200)->assertJsonStructure([
    'result' => [
        'summary_markdown',
        'structured_sections',
        'references',
        'section_references',
        'citation_coverage',
        'build_diagnostics',
    ],
]);
$this->assertIsArray($response->json('result.section_references'));
```

- [ ] **Step 2: Run test to verify failure**

Run: `php artisan test tests/Feature/McpApiTest.php --filter=get_working_memory_returns_scoped_payload`  
Expected: FAIL before MCP response structure and schema description are updated.

- [ ] **Step 3: Update MCP tool description/schema copy for enhanced contract**

```php
// app/Http/Controllers/Api/McpController.php tools/list
[
    'name' => 'get_working_memory',
    'description' => 'Return scoped working memory snapshot with structured sections, citations, section references, freshness, and confidence.',
    'inputSchema' => [
        'type' => 'object',
        'properties' => [
            'scope_type' => ['type' => 'string', 'enum' => ['global', 'project', 'insights', 'tag']],
            'scope_key' => ['type' => 'string'],
        ],
        'required' => ['scope_type', 'scope_key'],
    ],
],
```

- [ ] **Step 4: Run tests**

Run:  
`php artisan test tests/Feature/McpApiTest.php --filter=get_working_memory`  
`php artisan test tests/Feature/WorkingMemoryApiTest.php --filter=working_memory`

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Api/McpController.php tests/Feature/McpApiTest.php tests/Feature/WorkingMemoryApiTest.php
git commit -m "feat(memory): align MCP contract with canonical section reference payload"
```

---

### Task 6: Full verification and rollout safety checks

**Files:**
- Modify: `tests/Feature/WorkingMemoryConsolidationCommandTest.php` (only if command assertions need updated payload checks)
- Modify: `tests/Feature/WorkingMemoryRefreshFeatureTest.php` (only if refresh payload checks need updated fields)

- [ ] **Step 1: Add/adjust refresh + consolidate tests for new payload field**

```php
// Example assertion pattern
$payload = app(WorkingMemoryAssembler::class)->forScope($user->id, 'project', 'dezeen');
$this->assertArrayHasKey('section_references', $payload);
$this->assertIsArray($payload['section_references']);
```

- [ ] **Step 2: Run focused working-memory suite**

Run:  
`php artisan test tests/Unit/Services/WorkingMemory`  
`php artisan test tests/Feature/WorkingMemoryApiTest.php tests/Feature/McpApiTest.php tests/Feature/WorkingMemoryRefreshFeatureTest.php tests/Feature/WorkingMemoryConsolidationCommandTest.php`

Expected: PASS.

- [ ] **Step 3: Run formatter**

Run: `./vendor/bin/pint --dirty`  
Expected: PASS (or auto-format only in touched files).

- [ ] **Step 4: Commit**

```bash
git add tests/Feature/WorkingMemoryConsolidationCommandTest.php tests/Feature/WorkingMemoryRefreshFeatureTest.php
git commit -m "test(memory): verify section reference contract across refresh and consolidation flows"
```

---

## Spec coverage self-check

- IdeaTub as self-contained canonical source: covered in Tasks 2-4 (canonical references + strict validation) and Task 5 (MCP contract).
- Dual outputs and scope isolation posture: covered by retaining scoped build behavior and adding tests around scoped payload shape (Tasks 3, 5, 6).
- Per-bullet citations + usable links: covered by Tasks 2 and 4.
- Section-level stream/audit links (`section_references`): covered by Tasks 1 and 3.
- MCP as downstream agent source of truth: covered by Task 5.
- Reliability/fallback and diagnostics posture: preserved and verified in Tasks 3, 4, and 6.

No placeholders remain; each task specifies files, test-first steps, concrete code shape, verification commands, and commit boundaries.
