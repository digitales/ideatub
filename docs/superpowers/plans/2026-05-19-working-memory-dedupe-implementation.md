# Working Memory Dedupe Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Stop duplicate working-memory Stream cards and `external` versions when Codex/Elixirr syncs unchanged content; supersede prior rows when substance changes; backfill and nightly-reconcile existing duplicates.

**Architecture:** Shared `WorkingMemoryContentFingerprint` + `WorkingMemoryDedupeFamilyResolver` feed two ingest paths (`WorkingMemorySnapshotDedupeService` for `capture_plan`, extended `WorkingMemoryUpsertService` for upsert). Supersede hides Stream thoughts and marks WM versions `superseded_at`. `working-memory:dedupe` backfills history; `RetryWorkingMemorySupersedeJob` repairs failed inline supersede.

**Tech Stack:** Laravel 12, PHP 8.2+, Pest, existing MCP (`McpController`) and REST (`ThoughtsApiController`) patterns

**Spec:** [2026-05-19-working-memory-dedupe-design.md](../specs/2026-05-19-working-memory-dedupe-design.md)

---

## File structure

| Path | Responsibility |
|------|----------------|
| `database/migrations/2026_05_19_*_working_memory_dedupe_columns.php` | `content_fingerprint`, supersede columns |
| `config/working_memory.php` | `dedupe_enabled`, `dedupe_nightly_days`, `dedupe_volatile_patterns` |
| `app/Services/WorkingMemory/WorkingMemoryContentFingerprint.php` | Normalize + SHA-256 |
| `app/Services/WorkingMemory/WorkingMemoryDedupeFamilyResolver.php` | WM detection + `wm:{scope}:{id}` key |
| `app/Services/WorkingMemory/WorkingMemorySnapshotSuperseder.php` | Hide thought, metadata, tag |
| `app/Services/WorkingMemory/WorkingMemoryVersionSuperseder.php` | Set `superseded_*` on versions |
| `app/Services/WorkingMemory/WorkingMemorySnapshotDedupeService.php` | `capture_plan` orchestration |
| `app/Services/WorkingMemory/WorkingMemoryUpsertService.php` | Extend `upsert()` with dedupe |
| `app/Services/WorkingMemory/WorkingMemoryAssembler.php` | `whereNull('superseded_at')` on authoritative query |
| `app/Jobs/RetryWorkingMemorySupersedeJob.php` | Idempotent repair |
| `app/Console/Commands/WorkingMemoryDedupeCommand.php` | Backfill + supersede |
| `app/Http/Controllers/Api/McpController.php` | Wire dedupe into `capturePlan`, `upsertWorkingMemory`; MCP schema |
| `app/Http/Controllers/Api/ThoughtsApiController.php` | REST upsert response fields |
| `app/Models/Thought.php` | Fillable/casts for `content_fingerprint` if needed |
| `app/Models/WorkingMemoryVersion.php` | Fillable/casts for new columns |
| `routes/console.php` | Schedule nightly dedupe |
| `tests/Unit/Services/WorkingMemory/WorkingMemoryContentFingerprintTest.php` | Normalization unit tests |
| `tests/Unit/Services/WorkingMemory/WorkingMemoryDedupeFamilyResolverTest.php` | Family key unit tests |
| `tests/Feature/WorkingMemorySnapshotDedupeTest.php` | MCP `capture_plan` dedupe |
| `tests/Feature/WorkingMemoryUpsertDedupeTest.php` | Upsert dedupe |
| `tests/Feature/WorkingMemoryDedupeCommandTest.php` | Artisan command |
| `tests/Feature/RetryWorkingMemorySupersedeJobTest.php` | Retry job |
| `tests/Feature/WorkingMemoryAssemblerSupersededTest.php` | Canonical ignores superseded |
| `docs/mcp-integration-guide.md` | Document params + responses |
| `resources/content/help/working-memory-corpus-sync.md` | Operator note |

---

## Phase 1 — Schema and fingerprint foundation

### Task 1: Migration for fingerprint and supersede columns

**Files:**
- Create: `database/migrations/2026_05_19_100000_add_working_memory_dedupe_columns.php`

- [ ] **Step 1: Add migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('thoughts', function (Blueprint $table): void {
            if (! Schema::hasColumn('thoughts', 'content_fingerprint')) {
                $table->char('content_fingerprint', 64)->nullable()->after('content_sha256');
                $table->index(['user_id', 'content_fingerprint'], 'thoughts_user_content_fingerprint_idx');
            }
        });

        Schema::table('working_memory_versions', function (Blueprint $table): void {
            if (! Schema::hasColumn('working_memory_versions', 'content_fingerprint')) {
                $table->char('content_fingerprint', 64)->nullable()->after('authoring_status');
                $table->index(
                    ['working_memory_id', 'content_fingerprint'],
                    'wm_versions_memory_fingerprint_idx'
                );
            }
            if (! Schema::hasColumn('working_memory_versions', 'superseded_at')) {
                $table->timestamp('superseded_at')->nullable();
                $table->uuid('superseded_by_version_id')->nullable();
                $table->foreign('superseded_by_version_id')
                    ->references('id')
                    ->on('working_memory_versions')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('working_memory_versions', function (Blueprint $table): void {
            if (Schema::hasColumn('working_memory_versions', 'superseded_by_version_id')) {
                $table->dropForeign(['superseded_by_version_id']);
            }
            foreach (['superseded_at', 'superseded_by_version_id', 'content_fingerprint'] as $col) {
                if (Schema::hasColumn('working_memory_versions', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
        Schema::table('thoughts', function (Blueprint $table): void {
            if (Schema::hasColumn('thoughts', 'content_fingerprint')) {
                $table->dropIndex('thoughts_user_content_fingerprint_idx');
                $table->dropColumn('content_fingerprint');
            }
        });
    }
};
```

- [ ] **Step 2: Run migration**

Run: `php artisan migrate`
Expected: migration OK

- [ ] **Step 3: Update models**

In `app/Models/Thought.php` add `content_fingerprint` to `$fillable` (if using fillable guard) or ensure not in `$guarded`.

In `app/Models/WorkingMemoryVersion.php` add to `$fillable`:

```php
'content_fingerprint',
'superseded_at',
'superseded_by_version_id',
```

Add cast: `'superseded_at' => 'datetime'`.

- [ ] **Step 4: Commit**

```bash
git add database/migrations/2026_05_19_100000_add_working_memory_dedupe_columns.php app/Models/Thought.php app/Models/WorkingMemoryVersion.php
git commit -m "feat(working-memory): add fingerprint and supersede columns"
```

---

### Task 2: Config keys

**Files:**
- Modify: `config/working_memory.php`

- [ ] **Step 1: Add dedupe config**

After `external_protect_days`:

```php
'dedupe_enabled' => filter_var(env('WORKING_MEMORY_DEDUPE_ENABLED', true), FILTER_VALIDATE_BOOL),
'dedupe_nightly_days' => (int) env('WORKING_MEMORY_DEDUPE_NIGHTLY_DAYS', 30),
'dedupe_volatile_patterns' => [
    '/^#+\s*working memory\s*$/i',
    '/^last updated:/i',
    '/^scope:/i',
    '/^\(?.*refreshed at.*\)?\s*$/i',
],
```

- [ ] **Step 2: Commit**

```bash
git add config/working_memory.php
git commit -m "feat(working-memory): add dedupe config"
```

---

### Task 3: `WorkingMemoryContentFingerprint` (TDD)

**Files:**
- Create: `app/Services/WorkingMemory/WorkingMemoryContentFingerprint.php`
- Create: `tests/Unit/Services/WorkingMemory/WorkingMemoryContentFingerprintTest.php`

- [ ] **Step 1: Write failing unit tests**

```php
<?php

namespace Tests\Unit\Services\WorkingMemory;

use App\Services\WorkingMemory\WorkingMemoryContentFingerprint;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WorkingMemoryContentFingerprintTest extends TestCase
{
    #[Test]
    public function it_strips_volatile_lines_by_default(): void
    {
        $a = <<<'MD'
# Working Memory
Last Updated: 2026-05-19 (refreshed at 2026-05-19T01:00:00Z)
Scope: Client-level live state

## Current Focus
- Same bullet
MD;
        $b = <<<'MD'
# Working Memory
Last Updated: 2026-05-19 (refreshed at 2026-05-19T02:00:00Z)
Scope: Client-level live state

## Current Focus
- Same bullet
MD;

        $fp = app(WorkingMemoryContentFingerprint::class);

        $this->assertSame($fp->hash($a), $fp->hash($b));
    }

    #[Test]
    public function strict_mode_treats_volatile_lines_as_significant(): void
    {
        $a = "Last Updated: 2026-05-19\n\n## Focus\n- x";
        $b = "Last Updated: 2026-05-20\n\n## Focus\n- x";

        $fp = app(WorkingMemoryContentFingerprint::class);

        $this->assertNotSame($fp->hash($a, strict: true), $fp->hash($b, strict: true));
    }

    #[Test]
    public function it_normalizes_whitespace_and_markdown(): void
    {
        $fp = app(WorkingMemoryContentFingerprint::class);
        $this->assertSame(
            $fp->hash("##  Current Focus\n\n-  Item"),
            $fp->hash("## Current Focus\n- Item")
        );
    }
}
```

- [ ] **Step 2: Run tests (expect FAIL)**

Run: `php artisan test tests/Unit/Services/WorkingMemory/WorkingMemoryContentFingerprintTest.php`
Expected: FAIL — class not found

- [ ] **Step 3: Implement fingerprint service**

```php
<?php

namespace App\Services\WorkingMemory;

class WorkingMemoryContentFingerprint
{
    public function hash(string $markdown, bool $strict = false): string
    {
        $normalized = $this->normalize($markdown, $strict);
        if ($normalized === '') {
            throw new \InvalidArgumentException('Working memory content is empty after normalization.');
        }

        return hash('sha256', $normalized);
    }

    public function normalize(string $markdown, bool $strict = false): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $markdown);

        if (! $strict) {
            $patterns = config('working_memory.dedupe_volatile_patterns', []);
            $lines = [];
            foreach (explode("\n", $text) as $line) {
                $trimmed = trim($line);
                $skip = false;
                foreach ($patterns as $pattern) {
                    if (@preg_match($pattern, $trimmed) === 1) {
                        $skip = true;
                        break;
                    }
                }
                if (! $skip) {
                    $lines[] = $line;
                }
            }
            $text = implode("\n", $lines);
        }

        $text = preg_replace('/^#+\s*/m', '', $text) ?? $text;
        $text = preg_replace('/[*_`]/', '', $text) ?? $text;
        $text = mb_strtolower($text);
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }
}
```

- [ ] **Step 4: Run tests (expect PASS)**

Run: `php artisan test tests/Unit/Services/WorkingMemory/WorkingMemoryContentFingerprintTest.php`

- [ ] **Step 5: Commit**

```bash
git add app/Services/WorkingMemory/WorkingMemoryContentFingerprint.php tests/Unit/Services/WorkingMemory/WorkingMemoryContentFingerprintTest.php
git commit -m "feat(working-memory): add content fingerprint normalizer"
```

---

### Task 4: `WorkingMemoryDedupeFamilyResolver` (TDD)

**Files:**
- Create: `app/Services/WorkingMemory/WorkingMemoryDedupeFamilyResolver.php`
- Create: `tests/Unit/Services/WorkingMemory/WorkingMemoryDedupeFamilyResolverTest.php`

- [ ] **Step 1: Write failing tests**

```php
<?php

namespace Tests\Unit\Services\WorkingMemory;

use App\Services\WorkingMemory\WorkingMemoryDedupeFamilyResolver;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WorkingMemoryDedupeFamilyResolverTest extends TestCase
{
    #[Test]
    public function it_detects_wm_capture_from_working_memory_tag(): void
    {
        $resolver = app(WorkingMemoryDedupeFamilyResolver::class);
        $this->assertTrue($resolver->isWorkingMemoryCapture(
            planSlug: 'client-working-memory-2026-05-19',
            extraTags: ['working-memory', 'client:dezeen'],
            project: 'dezeen',
        ));
    }

    #[Test]
    public function it_builds_client_family_from_client_tag(): void
    {
        $resolver = app(WorkingMemoryDedupeFamilyResolver::class);
        $this->assertSame(
            'wm:client:dezeen',
            $resolver->resolveForCapture(
                planSlug: 'client-working-memory-2026-05-19',
                extraTags: ['working-memory', 'client:dezeen', 'scope:client'],
                project: 'dezeen',
            )
        );
    }

    #[Test]
    public function it_builds_project_family_from_project_metadata(): void
    {
        $resolver = app(WorkingMemoryDedupeFamilyResolver::class);
        $this->assertSame(
            'wm:project:dezeen/my-app',
            $resolver->resolveForCapture(
                planSlug: 'project-working-memory-2026-05-19',
                extraTags: ['working-memory', 'project:my-app', 'client:dezeen'],
                project: 'dezeen/my-app',
            )
        );
    }

    #[Test]
    public function it_builds_upsert_family_from_scope(): void
    {
        $resolver = app(WorkingMemoryDedupeFamilyResolver::class);
        $this->assertSame(
            'wm:project:019e0705-5591-73e9-be2e-0fb9c86b269a',
            $resolver->resolveForUpsert('project', '019E0705-5591-73E9-BE2E-0FB9C86B269A')
        );
    }
}
```

- [ ] **Step 2: Run tests (expect FAIL)**

- [ ] **Step 3: Implement resolver**

Key logic:

- `isWorkingMemoryCapture`: `in_array('working-memory', $tags)` OR `plan_slug` matches `/^(client|project)-working-memory/i`
- `resolveForCapture`: if `project` contains `/` → `wm:project:{lowercase project}`; elseif tag `client:{x}` → `wm:client:{x}`; elseif `scope:project` + `project:{p}` → `wm:project:{client}/{p}`; fallback `wm:plan:{plan_slug without date suffix}` using `preg_replace('/-\d{4}-\d{2}-\d{2}$/', '', $planSlug)`
- `resolveForUpsert`: `wm:{scope_type}:{strtolower scope_key}`

- [ ] **Step 4: Run tests (expect PASS)**

- [ ] **Step 5: Commit**

---

## Phase 2 — Supersede primitives

### Task 5: `WorkingMemorySnapshotSuperseder`

**Files:**
- Create: `app/Services/WorkingMemory/WorkingMemorySnapshotSuperseder.php`

- [ ] **Step 1: Implement supersede for thoughts**

```php
public function supersede(Thought $prior, Thought $winner): void
{
    $metadata = is_array($prior->source_metadata) ? $prior->source_metadata : [];
    $wm = is_array($metadata['working_memory'] ?? null) ? $metadata['working_memory'] : [];
    $wm['is_current'] = false;
    $wm['superseded_at'] = now()->toIso8601String();
    $wm['superseded_by_thought_id'] = (string) $winner->id;
    $metadata['working_memory'] = $wm;

    $tags = collect(data_get($prior->metadata, 'tags', []))
        ->map(fn ($t) => (string) $t)
        ->push('working-memory:superseded')
        ->unique()
        ->values()
        ->all();

    $prior->forceFill([
        'is_visible_in_stream' => false,
        'source_metadata' => $metadata,
        'metadata' => array_merge(is_array($prior->metadata) ? $prior->metadata : [], ['tags' => $tags]),
    ])->save();
}
```

- [ ] **Step 2: Commit**

---

### Task 6: `WorkingMemoryVersionSuperseder`

**Files:**
- Create: `app/Services/WorkingMemory/WorkingMemoryVersionSuperseder.php`

- [ ] **Step 1: Implement**

```php
public function supersedeAllExcept(WorkingMemory $memory, WorkingMemoryVersion $winner): int
{
    return $memory->versions()
        ->where('build_type', 'external')
        ->whereNull('superseded_at')
        ->whereKeyNot($winner->id)
        ->update([
            'superseded_at' => now(),
            'superseded_by_version_id' => $winner->id,
        ]);
}
```

- [ ] **Step 2: Commit**

---

## Phase 3 — Snapshot dedupe (`capture_plan`)

### Task 7: `WorkingMemorySnapshotDedupeService` + feature tests

**Files:**
- Create: `app/Services/WorkingMemory/WorkingMemorySnapshotDedupeService.php`
- Create: `tests/Feature/WorkingMemorySnapshotDedupeTest.php`
- Modify: `app/Http/Controllers/Api/McpController.php` (`capturePlan`, `buildCapturePlanLikeInputSchema`)

- [ ] **Step 1: Write failing feature test (MCP capture_plan)**

Use existing `McpApiTest` patterns: POST JSON-RPC `capture_plan` twice with same WM payload (include volatile `Last Updated` line change), assert second response `deduplicated: true` and `Thought::where(...)->visibleInStream()->count() === 1`.

Sample payload tags:

```php
'tags' => ['working-memory', 'client:dezeen', 'scope:client'],
'plan_slug' => 'client-working-memory-2026-05-19',
'project' => 'dezeen',
```

- [ ] **Step 2: Implement `WorkingMemorySnapshotDedupeService::capture()`**

Flow:

1. If `! config('working_memory.dedupe_enabled')` → delegate to existing `ThoughtCaptureService::create()` only.
2. If not WM capture → same.
3. Compute `$fingerprint`, `$family`.
4. Find current thought: `Thought::query()->where('user_id', $userId)->where('content_fingerprint', $fingerprint)` is wrong for changed-ts-same-body — find by family: `where('source_metadata->working_memory->dedupe_family', $family)` AND `where('source_metadata->working_memory->is_current', true)` AND `visibleInStream()`.
5. If current exists and `content_fingerprint` matches → return `DedupeCaptureResult(existing, deduplicated: true)`.
6. Else create via `ThoughtCaptureService`, set `content_fingerprint` column + merge `source_metadata.working_memory` block, then supersede other currents in family (query all `is_current` except winner), dispatch `RetryWorkingMemorySupersedeJob` on exception.

- [ ] **Step 3: Wire `McpController::capturePlan`**

Before `$this->captureService->create(...)`, when WM-managed:

```php
$strict = ! empty($params['strict_content_hash']);
if ($this->dedupeFamilyResolver->isWorkingMemoryCapture($planSlug, $extraTags, $project)) {
    return $this->snapshotDedupeService->captureViaMcp(...)->toArray();
}
```

Add validation key `'strict_content_hash' => 'sometimes|boolean'`.

Extend MCP `buildCapturePlanLikeInputSchema` with `strict_content_hash`.

- [ ] **Step 4: Second feature test — content change supersedes**

First capture focus bullet A; second capture bullet B; assert first thought `is_visible_in_stream === false`, second visible, response `superseded_thought_id` set.

- [ ] **Step 5: Run tests**

Run: `php artisan test tests/Feature/WorkingMemorySnapshotDedupeTest.php`

- [ ] **Step 6: Commit**

---

## Phase 4 — Upsert dedupe

### Task 8: Extend `WorkingMemoryUpsertService`

**Files:**
- Modify: `app/Services/WorkingMemory/WorkingMemoryUpsertService.php`
- Create: `tests/Feature/WorkingMemoryUpsertDedupeTest.php`
- Modify: `app/Http/Controllers/Api/McpController.php` (`upsertWorkingMemory`)
- Modify: `app/Http/Controllers/Api/ThoughtsApiController.php` (`upsertWorkingMemory`)

- [ ] **Step 1: Write failing upsert dedupe test**

```php
$v1 = $service->upsert($user->id, 'project', $uuid, $markdown, 'elixirr-sync');
$v2 = $service->upsert($user->id, 'project', $uuid, $markdown, 'elixirr-sync');
$this->assertSame($v1->id, $v2->id);
$this->assertSame(1, WorkingMemoryVersion::where('working_memory_id', $v1->working_memory_id)->where('build_type', 'external')->count());
```

- [ ] **Step 2: Implement in `upsert()`**

Inject `WorkingMemoryContentFingerprint`, `WorkingMemoryDedupeFamilyResolver`, `WorkingMemoryVersionSuperseder`.

At start of transaction after parse:

```php
$fingerprint = $this->fingerprint->hash($trimmed, $strict);
$family = $this->familyResolver->resolveForUpsert($normalizedScopeType, $normalizedScopeKey);

$latestExternal = $memory->versions()
    ->where('build_type', 'external')
    ->whereNull('superseded_at')
    ->orderByDesc('created_at')
    ->first();

if ($latestExternal && $latestExternal->content_fingerprint === $fingerprint) {
    return $latestExternal; // caller adds deduplicated flag
}
```

On create, set `content_fingerprint`, merge `build_diagnostics_json` with `dedupe_family`, call `$this->versionSuperseder->supersedeAllExcept($memory, $version)`.

Add optional `bool $strictContentHash = false` parameter to `upsert()`.

- [ ] **Step 3: Extend MCP/REST responses**

```php
'deduplicated' => $wasDuplicate,
'content_fingerprint' => $fingerprint,
'dedupe_family' => $family,
'superseded_version_id' => $supersededId,
```

- [ ] **Step 4: Run tests**

Run: `php artisan test tests/Feature/WorkingMemoryUpsertDedupeTest.php tests/Feature/WorkingMemoryUpsertServiceTest.php`

- [ ] **Step 5: Commit**

---

### Task 9: Assembler ignores superseded versions

**Files:**
- Modify: `app/Services/WorkingMemory/WorkingMemoryAssembler.php`
- Create: `tests/Feature/WorkingMemoryAssemblerSupersededTest.php`

- [ ] **Step 1: Write failing test**

Create two `external` versions; mark newer one's fingerprint duplicate but mark older as authoritative by superseding the newer. Canonical payload must use non-superseded version.

- [ ] **Step 2: Add `whereNull('superseded_at')` to authoritative query** (line ~401 in `payloadFromPersistedMemory`)

- [ ] **Step 3: Run test + commit**

---

## Phase 5 — Backfill, schedule, retry job

### Task 10: `WorkingMemoryDedupeCommand`

**Files:**
- Create: `app/Console/Commands/WorkingMemoryDedupeCommand.php`
- Create: `tests/Feature/WorkingMemoryDedupeCommandTest.php`

- [ ] **Step 1: Write failing command test**

Seed 3 WM thoughts same family + fingerprint (vary only Last Updated line); run `working-memory:dedupe --dry-run`; assert output mentions 2 would supersede. Run without dry-run; assert 1 visible.

- [ ] **Step 2: Implement command**

Signature: `working-memory:dedupe {--days=30} {--dry-run} {--user=}`

Logic per spec § Backfill:

- Query thoughts: `where('created_at', '>=', now()->subDays($days))`, user filter optional, WM tag or plan_slug LIKE `%-working-memory-%`
- Backfill `content_fingerprint` using fingerprint service when null
- Group by resolved `dedupe_family` (re-resolve from metadata/tags if missing)
- Per family per fingerprint cluster: keep newest `created_at`, supersede others via `WorkingMemorySnapshotSuperseder`
- Mirror for `external` versions per `WorkingMemory`
- Reconcile `latest_version_id`

- [ ] **Step 3: Schedule in `routes/console.php`**

```php
Schedule::command('working-memory:dedupe --days=30')
    ->dailyAt('03:15')
    ->when(fn () => config('working_memory.dedupe_enabled', true));
```

- [ ] **Step 4: Run tests + commit**

---

### Task 11: `RetryWorkingMemorySupersedeJob`

**Files:**
- Create: `app/Jobs/RetryWorkingMemorySupersedeJob.php`
- Create: `tests/Feature/RetryWorkingMemorySupersedeJobTest.php`

- [ ] **Step 1: Write failing test**

Create two visible WM currents in same family; dispatch job with winner id; assert only one remains visible.

- [ ] **Step 2: Implement job**

Constructor: `int $userId`, `string $dedupeFamily`, `?string $winnerThoughtId`, `?string $winnerVersionId`

Handler: query all thoughts with matching `source_metadata.working_memory.dedupe_family` and `is_current` true; supersede all except winner. Same for external versions if `winnerVersionId` set.

- [ ] **Step 3: Dispatch from snapshot dedupe catch block**

- [ ] **Step 4: Run tests + commit**

---

## Phase 6 — UI label and docs

### Task 12: Version history “Superseded” label (optional, small)

**Files:**
- Modify: `resources/views/memory/history.blade.php` (or partial used by version list)
- Modify: `app/Services/WorkingMemory/WorkingMemoryVersionCatalog.php` if payload needs `superseded_at`

- [ ] **Step 1: Show badge when `superseded_at` not null**

- [ ] **Step 2: Commit**

---

### Task 13: Documentation

**Files:**
- Modify: `docs/mcp-integration-guide.md`
- Modify: `resources/content/help/working-memory-corpus-sync.md`

- [ ] **Step 1: Document `strict_content_hash`, `deduplicated`, dedupe_family in MCP guide** (upsert + capture_plan sections)

- [ ] **Step 2: Add corpus-sync note:** server dedupe + `working-memory:dedupe` + nightly schedule

- [ ] **Step 3: Update spec status to Approved** in `docs/superpowers/specs/2026-05-19-working-memory-dedupe-design.md`

- [ ] **Step 4: Commit**

---

## Phase 7 — Full regression

### Task 14: Run full WM test suite

- [ ] **Step 1: Run targeted tests**

```bash
php artisan test \
  tests/Unit/Services/WorkingMemory/WorkingMemoryContentFingerprintTest.php \
  tests/Unit/Services/WorkingMemory/WorkingMemoryDedupeFamilyResolverTest.php \
  tests/Feature/WorkingMemorySnapshotDedupeTest.php \
  tests/Feature/WorkingMemoryUpsertDedupeTest.php \
  tests/Feature/WorkingMemoryDedupeCommandTest.php \
  tests/Feature/RetryWorkingMemorySupersedeJobTest.php \
  tests/Feature/WorkingMemoryAssemblerSupersededTest.php \
  tests/Feature/McpUpsertWorkingMemoryTest.php \
  tests/Feature/WorkingMemoryUpsertServiceTest.php
```

- [ ] **Step 2: Run Pint**

```bash
vendor/bin/pint --dirty
```

- [ ] **Step 3: Manual smoke (optional)**

1. MCP `capture_plan` twice with same Dezeen WM body → one Stream card.
2. Change one bullet → two thoughts, one hidden.
3. `php artisan working-memory:dedupe --dry-run` on seeded duplicates.

---

## Spec coverage checklist

| Spec requirement | Task |
|------------------|------|
| Shared fingerprint | 3 |
| Configurable strict hash | 3, 7, 8 |
| Dedupe family key | 4 |
| Migration columns | 1 |
| capture_plan dedupe + supersede | 7 |
| upsert dedupe + supersede | 8 |
| Assembler canonical | 9 |
| Backfill command | 10 |
| Nightly schedule | 10 |
| Retry job | 11 |
| MCP/REST response fields | 7, 8 |
| Docs | 13 |
| Tests per spec | 3–11, 14 |

## Out of repo

- Elixirr `elixirr-sync` skill: document `no_chunking: true` and server dedupe backstop (operator can update skill separately).

---

## Execution handoff

Plan complete and saved to `docs/superpowers/plans/2026-05-19-working-memory-dedupe-implementation.md`.

**Two execution options:**

1. **Subagent-driven (recommended)** — fresh subagent per task, review between tasks  
2. **Inline execution** — implement task-by-task in this session with checkpoints  

Which approach do you want?
