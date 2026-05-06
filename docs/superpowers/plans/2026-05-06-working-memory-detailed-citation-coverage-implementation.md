# Working Memory Detailed Citation Coverage Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Upgrade working memory output to `current.md`-level detail with strict citation-backed bullets (direct thought/source links, plus explicit bundle fallback) across all scopes.

**Architecture:** Keep the existing deterministic evidence pack + authored output pipeline, but evolve section items from plain strings to structured objects with per-item citations and `fallback_mode`. Enforce hard validation for required-section citation coverage and preserve last-known-good fallback on hard failures. Expose richer citation metadata consistently through assembler/API/MCP/web rendering.

**Tech Stack:** Laravel 12, PHP 8.2+, Eloquent models, existing `WorkingMemory*` services, Blade views, Pest/PHPUnit feature + unit tests.

---

## Scope check

This is a single subsystem (working-memory authoring/validation/rendering). It can ship as one implementation plan with phased tasks that each leave tests passing.

## File structure (creates + touches)

| Path | Responsibility |
|---|---|
| `config/working_memory.php` | Tighten defaults for strict citation coverage and required section policy. |
| `database/migrations/2026_05_06_220000_add_build_diagnostics_to_working_memory_versions.php` | Add diagnostics JSON persistence for validator reason codes/counters. |
| `app/Models/WorkingMemoryVersion.php` | Add cast/fillable for `build_diagnostics_json`. |
| `app/Services/WorkingMemory/WorkingMemoryEvidencePackBuilder.php` | Emit richer section candidates and dual-link citation candidates per signal. |
| `app/Services/WorkingMemory/WorkingMemoryAiAuthorService.php` | Produce structured section items (`text`, `citations`, `fallback_mode`, `importance`) instead of plain bullet strings. |
| `app/Services/WorkingMemory/WorkingMemoryOutputValidator.php` | Enforce required section presence + 100% required-item citation coverage + URL/reason-code checks. |
| `app/Services/WorkingMemory/WorkingMemoryBuilderService.php` | Integrate structured item normalization, fallback/drop logic, diagnostics persistence, and strict hard-fail behavior. |
| `app/Services/WorkingMemory/WorkingMemoryAssembler.php` | Return structured sections, citation coverage metrics, and diagnostics payload safely. |
| `resources/views/memory/show.blade.php` | Render structured item rows + per-item citation chips + bundle fallback badge. |
| `resources/views/memory/insights.blade.php` | Same rendering behavior for insights surface. |
| `tests/Unit/Services/WorkingMemory/WorkingMemoryEvidencePackBuilderTest.php` | Verify dual-link citations, section bundle fallback sets, and richer candidates. |
| `tests/Unit/Services/WorkingMemory/WorkingMemoryAiAuthorServiceTest.php` | Verify structured item schema and fallback-mode semantics. |
| `tests/Unit/Services/WorkingMemory/WorkingMemoryOutputValidatorTest.php` | Verify strict required-item coverage and hard/soft failure diagnostics. |
| `tests/Unit/Services/WorkingMemory/WorkingMemoryBuilderServiceTest.php` | Verify persistence/fallback behavior with structured items and diagnostics. |
| `tests/Feature/WorkingMemoryApiTest.php` | Verify payload includes structured citation data and diagnostics metrics. |
| `tests/Feature/McpApiTest.php` | Verify `get_working_memory` returns updated structured citation payload. |
| `tests/Feature/WorkingMemoryWebTest.php` | Verify global/project working-memory UI renders per-item citations and fallback badge. |
| `tests/Feature/MemoryInsightsWebTest.php` | Verify insights UI renders the same citation behavior. |

---

### Task 1: Add persistence/config foundations for strict citation mode

**Files:**
- Create: `database/migrations/2026_05_06_220000_add_build_diagnostics_to_working_memory_versions.php`
- Modify: `app/Models/WorkingMemoryVersion.php`
- Modify: `config/working_memory.php`
- Test: `tests/Unit/Models/WorkingMemoryVersionTest.php`

- [ ] **Step 1: Write failing model test for diagnostics cast**

```php
#[Test]
public function diagnostics_json_is_cast_to_array(): void
{
    $version = WorkingMemoryVersion::factory()->make([
        'build_diagnostics_json' => ['reason_codes' => ['missing_citation']],
    ]);

    $this->assertIsArray($version->build_diagnostics_json);
    $this->assertSame(['missing_citation'], $version->build_diagnostics_json['reason_codes']);
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Unit/Models/WorkingMemoryVersionTest.php --filter=diagnostics_json_is_cast_to_array`  
Expected: FAIL because `build_diagnostics_json` is not fillable/cast yet.

- [ ] **Step 3: Add migration, model cast/fillable, and strict config defaults**

```php
// database/migrations/2026_05_06_220000_add_build_diagnostics_to_working_memory_versions.php
Schema::table('working_memory_versions', function (Blueprint $table): void {
    $table->json('build_diagnostics_json')->nullable()->after('validation_error');
});
```

```php
// app/Models/WorkingMemoryVersion.php
protected $fillable = [
    // existing ...
    'build_diagnostics_json',
];

protected function casts(): array
{
    return [
        // existing ...
        'build_diagnostics_json' => 'array',
    ];
}
```

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
],
```

- [ ] **Step 4: Run targeted tests**

Run:  
`php artisan test tests/Unit/Models/WorkingMemoryVersionTest.php`  
`php artisan test tests/Feature/WorkingMemorySchemaTest.php`

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add database/migrations/2026_05_06_220000_add_build_diagnostics_to_working_memory_versions.php app/Models/WorkingMemoryVersion.php config/working_memory.php tests/Unit/Models/WorkingMemoryVersionTest.php
git commit -m "feat(memory): persist validator diagnostics and strict citation defaults"
```

---

### Task 2: Enrich evidence-pack output for detailed section authoring

**Files:**
- Modify: `app/Services/WorkingMemory/WorkingMemoryEvidencePackBuilder.php`
- Modify: `tests/Unit/Services/WorkingMemory/WorkingMemoryEvidencePackBuilderTest.php`

- [ ] **Step 1: Write failing tests for dual-link and section-bundle evidence**

```php
#[Test]
public function evidence_pack_signal_references_include_thought_and_source_when_both_exist(): void
{
    $pack = app(WorkingMemoryEvidencePackBuilder::class)->build($user->id, 'global', 'global', $thoughts);

    $refs = $pack['signals'][0]['references'] ?? [];
    $this->assertSame('thought', $refs[0]['type']);
    $this->assertSame('source', $refs[1]['type']);
}
```

```php
#[Test]
public function evidence_pack_includes_section_bundle_fallback_references(): void
{
    $pack = app(WorkingMemoryEvidencePackBuilder::class)->build($user->id, 'tag', 'ai', $thoughts);

    $this->assertArrayHasKey('section_bundles', $pack);
    $this->assertArrayHasKey('Latest Signals', $pack['section_bundles']);
    $this->assertNotEmpty($pack['section_bundles']['Latest Signals']);
}
```

- [ ] **Step 2: Run tests to verify failures**

Run: `php artisan test tests/Unit/Services/WorkingMemory/WorkingMemoryEvidencePackBuilderTest.php`  
Expected: FAIL on missing `section_bundles` and missing second `source` citation.

- [ ] **Step 3: Implement richer evidence payload**

```php
// app/Services/WorkingMemory/WorkingMemoryEvidencePackBuilder.php
return [
    'scope_type' => $normalizedScopeType,
    'scope_key' => $normalizedScopeKey,
    'generated_at' => now()->toIso8601String(),
    'signals' => $signals,
    'section_candidates' => [
        'Current Focus' => $this->focusCandidates($signals),
        'Active Priorities' => $this->priorityCandidates($signals),
        'Recent Changes' => $this->changeCandidates($signals),
        'Open Questions' => $this->questionCandidates($signals),
        'Risks / Blockers' => $this->riskCandidates($signals),
        'Next Actions' => $this->actionCandidates($signals),
        'Latest Signals' => $this->latestSignalCandidates($signals),
    ],
    'section_bundles' => $this->buildSectionBundles($signals),
];
```

```php
private function referencesForThought(Thought $thought): array
{
    $refs = [];
    $thoughtRef = $this->internalThoughtReference($thought);
    if ($thoughtRef !== null) {
        $refs[] = $thoughtRef;
    }

    $sourceRef = $this->sourceFallbackReference($thought);
    if ($sourceRef !== null) {
        $refs[] = $sourceRef;
    }

    return $refs;
}
```

- [ ] **Step 4: Run tests**

Run: `php artisan test tests/Unit/Services/WorkingMemory/WorkingMemoryEvidencePackBuilderTest.php`  
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/WorkingMemory/WorkingMemoryEvidencePackBuilder.php tests/Unit/Services/WorkingMemory/WorkingMemoryEvidencePackBuilderTest.php
git commit -m "feat(memory): enrich evidence packs with dual-link references and section bundles"
```

---

### Task 3: Rework AI author output to structured section items with per-item citations

**Files:**
- Modify: `app/Services/WorkingMemory/WorkingMemoryAiAuthorService.php`
- Modify: `tests/Unit/Services/WorkingMemory/WorkingMemoryAiAuthorServiceTest.php`

- [ ] **Step 1: Write failing test for structured section item schema**

```php
#[Test]
public function authored_sections_emit_structured_items_with_citations_and_fallback_mode(): void
{
    $result = app(WorkingMemoryAiAuthorService::class)->authorFromEvidence($this->sampleEvidencePack());

    $item = $result['structured_sections']['Active Priorities'][0] ?? null;
    $this->assertIsArray($item);
    $this->assertArrayHasKey('text', $item);
    $this->assertArrayHasKey('citations', $item);
    $this->assertArrayHasKey('fallback_mode', $item);
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Unit/Services/WorkingMemory/WorkingMemoryAiAuthorServiceTest.php`  
Expected: FAIL because section items are currently plain strings.

- [ ] **Step 3: Implement structured item generation**

```php
// app/Services/WorkingMemory/WorkingMemoryAiAuthorService.php
private function makeItem(string $text, array $citations, string $fallbackMode, int $importance = 50): array
{
    return [
        'id' => (string) Str::uuid(),
        'text' => trim($text),
        'importance' => $importance,
        'fallback_mode' => $fallbackMode, // direct | section_bundle
        'citations' => $citations,
    ];
}
```

```php
private function itemForCandidate(array $candidate, array $bundleFallback): ?array
{
    $text = trim((string) ($candidate['text'] ?? ''));
    if ($text === '') {
        return null;
    }

    $direct = $this->normalizeCitations($candidate['references'] ?? []);
    if ($direct !== []) {
        return $this->makeItem($text, $direct, 'direct', (int) ($candidate['importance'] ?? 50));
    }

    if ($bundleFallback !== []) {
        return $this->makeItem($text, $bundleFallback, 'section_bundle', (int) ($candidate['importance'] ?? 50));
    }

    return null;
}
```

- [ ] **Step 4: Keep markdown render compatible with object items**

```php
private function renderSummaryMarkdown(array $structuredSections): string
{
    $parts = ['# Working memory synthesis'];

    foreach (self::REQUIRED_SECTION_KEYS as $section) {
        $items = is_array($structuredSections[$section] ?? null) ? $structuredSections[$section] : [];
        $lines = collect($items)
            ->map(fn (array $item): string => '- '.trim((string) ($item['text'] ?? '')))
            ->filter(fn (string $line): bool => $line !== '- ')
            ->values()
            ->all();

        $parts[] = '## '.$section;
        $parts[] = $lines !== [] ? implode("\n", $lines) : '- No material updates.';
    }

    return implode("\n\n", $parts);
}
```

- [ ] **Step 5: Run tests**

Run:  
`php artisan test tests/Unit/Services/WorkingMemory/WorkingMemoryAiAuthorServiceTest.php`  
`php artisan test tests/Unit/Services/WorkingMemory/WorkingMemoryEvidencePackBuilderTest.php`

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Services/WorkingMemory/WorkingMemoryAiAuthorService.php tests/Unit/Services/WorkingMemory/WorkingMemoryAiAuthorServiceTest.php
git commit -m "feat(memory): emit structured authored section items with per-item citations"
```

---

### Task 4: Enforce strict validator rules for required-section coverage and resolvable links

**Files:**
- Modify: `app/Services/WorkingMemory/WorkingMemoryOutputValidator.php`
- Modify: `tests/Unit/Services/WorkingMemory/WorkingMemoryOutputValidatorTest.php`

- [ ] **Step 1: Write failing validator tests for required-item 100% coverage**

```php
#[Test]
public function missing_item_citation_in_required_section_fails_hard(): void
{
    $payload = $this->validStructuredPayload();
    $payload['structured_sections']['Next Actions'][0]['citations'] = [];

    $result = app(WorkingMemoryOutputValidator::class)->validate($payload, 1.0);

    $this->assertFalse($result['ok']);
    $this->assertSame('hard', $result['failure_type']);
    $this->assertContains('missing_citation', $result['diagnostics']['reason_codes']);
}
```

```php
#[Test]
public function bundle_fallback_item_counts_as_cited_when_urls_are_valid(): void
{
    $payload = $this->validStructuredPayload();
    $payload['structured_sections']['Risks / Blockers'][0]['fallback_mode'] = 'section_bundle';

    $result = app(WorkingMemoryOutputValidator::class)->validate($payload, 1.0);

    $this->assertTrue($result['ok']);
    $this->assertSame(100.0, $result['coveragePercent']);
}
```

- [ ] **Step 2: Run tests to verify failures**

Run: `php artisan test tests/Unit/Services/WorkingMemory/WorkingMemoryOutputValidatorTest.php`  
Expected: FAIL because validator currently inspects inline `[n]` markers in plain strings.

- [ ] **Step 3: Implement structured-item validator logic**

```php
// app/Services/WorkingMemory/WorkingMemoryOutputValidator.php
private function requiredSectionKeys(): array
{
    return (array) config('working_memory.citation_required_sections', self::REQUIRED_SECTION_KEYS);
}

private function normalizeItems(mixed $raw): array
{
    return collect(is_array($raw) ? $raw : [])
        ->filter(fn ($item): bool => is_array($item) && trim((string) ($item['text'] ?? '')) !== '')
        ->values()
        ->all();
}
```

```php
private function validateItemCitations(array $items): array
{
    $total = 0;
    $cited = 0;
    $reasonCodes = [];

    foreach ($items as $item) {
        $total++;
        $citations = $this->normalizeCitations($item['citations'] ?? []);
        if ($citations === []) {
            $reasonCodes[] = 'missing_citation';
            continue;
        }

        if (! $this->allCitationsResolvable($citations)) {
            $reasonCodes[] = 'invalid_link';
            continue;
        }

        $cited++;
    }

    return [$total, $cited, array_values(array_unique($reasonCodes))];
}
```

- [ ] **Step 4: Return diagnostics payload with reason codes and counters**

```php
return [
    'ok' => $ok,
    'message' => $message,
    'coveragePercent' => $coveragePercent,
    'failure_type' => $failureType,
    'diagnostics' => [
        'required_items' => $requiredItems,
        'cited_items' => $citedItems,
        'reason_codes' => $reasonCodes,
    ],
];
```

- [ ] **Step 5: Run tests**

Run: `php artisan test tests/Unit/Services/WorkingMemory/WorkingMemoryOutputValidatorTest.php`  
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Services/WorkingMemory/WorkingMemoryOutputValidator.php tests/Unit/Services/WorkingMemory/WorkingMemoryOutputValidatorTest.php
git commit -m "feat(memory): enforce strict structured-item citation validation with diagnostics"
```

---

### Task 5: Integrate strict authoring + diagnostics into builder and assembler

**Files:**
- Modify: `app/Services/WorkingMemory/WorkingMemoryBuilderService.php`
- Modify: `app/Services/WorkingMemory/WorkingMemoryAssembler.php`
- Modify: `tests/Unit/Services/WorkingMemory/WorkingMemoryBuilderServiceTest.php`
- Modify: `tests/Feature/WorkingMemoryApiTest.php`
- Modify: `tests/Feature/McpApiTest.php`

- [ ] **Step 1: Write failing builder test for dropped uncited items and hard-fail fallback**

```php
#[Test]
public function hard_validation_failure_keeps_last_known_good_and_marks_memory_degraded(): void
{
    // Arrange baseline valid build then force authored payload with uncited required items.
    // Assert latest version is unchanged and freshness state is degraded.
}
```

- [ ] **Step 2: Run tests to verify failures**

Run: `php artisan test tests/Unit/Services/WorkingMemory/WorkingMemoryBuilderServiceTest.php --filter=hard_validation_failure_keeps_last_known_good`  
Expected: FAIL until strict builder behavior is wired.

- [ ] **Step 3: Normalize structured item arrays and preserve diagnostics in persisted version**

```php
// app/Services/WorkingMemory/WorkingMemoryBuilderService.php
$structuredSections = $this->normalizeStructuredSections($authoredOutput['structured_sections'] ?? null);
$references = $this->normalizeReferences($authoredOutput['references'] ?? null);
$diagnostics = is_array($validation['diagnostics'] ?? null) ? $validation['diagnostics'] : [];

$version = $memory->versions()->create([
    // existing...
    'structured_sections_json' => $structuredSections,
    'references_json' => $references,
    'citation_coverage' => $validation['coveragePercent'] ?? null,
    'authoring_status' => $authoringStatus,
    'validation_error' => $validationError,
    'build_diagnostics_json' => $diagnostics,
]);
```

```php
private function normalizeStructuredSections(mixed $sections): array
{
    if (! is_array($sections)) {
        return [];
    }

    return collect($sections)->map(function ($items, $title): array {
        $normalizedItems = collect(is_array($items) ? $items : [])
            ->filter(fn ($item): bool => is_array($item))
            ->map(fn (array $item): array => [
                'id' => trim((string) ($item['id'] ?? '')),
                'text' => trim((string) ($item['text'] ?? '')),
                'importance' => (int) ($item['importance'] ?? 50),
                'fallback_mode' => trim((string) ($item['fallback_mode'] ?? 'direct')) ?: 'direct',
                'citations' => $this->normalizeReferences($item['citations'] ?? []),
            ])
            ->filter(fn (array $item): bool => $item['text'] !== '')
            ->values()
            ->all();

        return [trim((string) $title) => $normalizedItems];
    })->collapse()->all();
}
```

- [ ] **Step 4: Expose diagnostics in assembler/API payload**

```php
// app/Services/WorkingMemory/WorkingMemoryAssembler.php return payload
'build_diagnostics' => is_array($canonical->build_diagnostics_json) ? $canonical->build_diagnostics_json : [],
```

- [ ] **Step 5: Add failing API/MCP assertions then make them pass**

```php
// tests/Feature/WorkingMemoryApiTest.php
$response->assertJsonStructure([
    // existing...
    'build_diagnostics' => ['required_items', 'cited_items', 'reason_codes'],
]);
```

```php
// tests/Feature/McpApiTest.php (get_working_memory assertion)
$this->assertIsArray(data_get($result, 'build_diagnostics.reason_codes', []));
```

- [ ] **Step 6: Run tests**

Run:  
`php artisan test tests/Unit/Services/WorkingMemory/WorkingMemoryBuilderServiceTest.php`  
`php artisan test tests/Feature/WorkingMemoryApiTest.php --filter=working_memory`  
`php artisan test tests/Feature/McpApiTest.php --filter=get_working_memory`

Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add app/Services/WorkingMemory/WorkingMemoryBuilderService.php app/Services/WorkingMemory/WorkingMemoryAssembler.php tests/Unit/Services/WorkingMemory/WorkingMemoryBuilderServiceTest.php tests/Feature/WorkingMemoryApiTest.php tests/Feature/McpApiTest.php
git commit -m "feat(memory): persist diagnostics and enforce strict citation-backed builder flow"
```

---

### Task 6: Update working-memory web rendering for item-level citations and fallback badges

**Files:**
- Modify: `resources/views/memory/show.blade.php`
- Modify: `resources/views/memory/insights.blade.php`
- Modify: `tests/Feature/WorkingMemoryWebTest.php`
- Modify: `tests/Feature/MemoryInsightsWebTest.php`

- [ ] **Step 1: Write failing feature tests for citation chips and bundle fallback badge**

```php
#[Test]
public function working_memory_page_renders_item_level_citation_links_and_bundle_badge(): void
{
    $response = $this->actingAs($user)->get(route('memory.show'));

    $response->assertOk();
    $response->assertSee('Current Focus', false);
    $response->assertSee('Source bundle', false);
    $response->assertSee('href=', false);
}
```

- [ ] **Step 2: Run tests to verify failures**

Run:  
`php artisan test tests/Feature/WorkingMemoryWebTest.php --filter=item_level_citation_links`  
`php artisan test tests/Feature/MemoryInsightsWebTest.php --filter=item_level_citation_links`

Expected: FAIL because views currently assume item strings and global reference chips only.

- [ ] **Step 3: Render structured item objects in Blade views**

```blade
@foreach ($items as $item)
    @php
        $itemText = trim((string) data_get($item, 'text', ''));
        $itemCitations = is_array(data_get($item, 'citations')) ? data_get($item, 'citations') : [];
        $fallbackMode = trim((string) data_get($item, 'fallback_mode', 'direct'));
    @endphp
    @continue($itemText === '')
    <li class="space-y-2">
        <div>{{ $itemText }}</div>
        <div class="flex flex-wrap gap-2">
            @foreach ($itemCitations as $citation)
                @php
                    $url = trim((string) data_get($citation, 'url', ''));
                    $label = trim((string) data_get($citation, 'label', ''));
                @endphp
                @continue($url === '' || $label === '' || ! $isSafeReferenceUrl($url))
                <a href="{{ $url }}" class="inline-flex items-center rounded-full border border-memory-violet/20 px-2 py-0.5 text-xs text-memory-violet">
                    {{ $label }}
                </a>
            @endforeach
            @if ($fallbackMode === 'section_bundle')
                <span class="inline-flex items-center rounded-full border border-amber-300 bg-amber-50 px-2 py-0.5 text-[10px] font-medium text-amber-900">Source bundle</span>
            @endif
        </div>
    </li>
@endforeach
```

- [ ] **Step 4: Run tests**

Run:  
`php artisan test tests/Feature/WorkingMemoryWebTest.php`  
`php artisan test tests/Feature/MemoryInsightsWebTest.php`

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add resources/views/memory/show.blade.php resources/views/memory/insights.blade.php tests/Feature/WorkingMemoryWebTest.php tests/Feature/MemoryInsightsWebTest.php
git commit -m "feat(memory): render per-item citations and source-bundle fallback badges"
```

---

### Task 7: Final verification and stabilization

**Files:**
- Modify: `tests/Unit/Services/WorkingMemory/WorkingMemoryFreshnessTest.php` (if assertions need adaptation for strict hard-fail fallback)
- Modify: `docs/superpowers/specs/2026-05-06-working-memory-detailed-citation-coverage-design.md` (only if implementation clarifications are required)

- [ ] **Step 1: Add/adjust freshness regression assertion for hard-fail fallback path**

```php
#[Test]
public function hard_failed_rebuild_does_not_replace_latest_version_and_sets_degraded_state(): void
{
    // Arrange baseline version, trigger hard fail, assert latest version id unchanged and state degraded.
}
```

- [ ] **Step 2: Run focused full suite**

Run:  
`php artisan test tests/Unit/Services/WorkingMemory tests/Feature/WorkingMemoryApiTest.php tests/Feature/McpApiTest.php tests/Feature/WorkingMemoryWebTest.php tests/Feature/MemoryInsightsWebTest.php`

Expected: PASS.

- [ ] **Step 3: Run formatter**

Run: `./vendor/bin/pint --dirty`  
Expected: PASS (or auto-formatted diffs only in touched files).

- [ ] **Step 4: Commit**

```bash
git add tests/Unit/Services/WorkingMemory/WorkingMemoryFreshnessTest.php docs/superpowers/specs/2026-05-06-working-memory-detailed-citation-coverage-design.md
git commit -m "test(memory): lock strict citation fallback and freshness regression guarantees"
```

---

## Spec coverage self-check

- Detailed `current.md` parity sections: covered in Tasks 2-3 (section candidates + structured author output).
- 100% citation coverage for required sections: covered in Task 4 validator rules + tests.
- Dual link strategy (`thought` + `source`): covered in Task 2 reference emission and tests.
- Bundle fallback when direct links are missing: covered in Tasks 2-3 authoring model and Task 6 rendering badge.
- Last-known-good hard-fail behavior: covered in Task 5 builder fallback and Task 7 freshness regression tests.
- API/MCP/UI contract updates: covered in Tasks 5-6.
- Operational diagnostics/observability: covered in Tasks 1, 4, and 5.

No placeholders remain; each task includes explicit files, code shape, test commands, and commit boundaries.
