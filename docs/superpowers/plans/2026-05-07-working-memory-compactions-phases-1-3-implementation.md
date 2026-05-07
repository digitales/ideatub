# Working Memory Compactions (Phases 1–3) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the deterministic working-memory authoring placeholder with model-backed narrative output, persist meeting captures as citable compaction versions, and extend `working_memory_inputs` so the canonical narrative can cite compaction permalinks.

**Architecture:** Compactions are first-class `working_memory_versions` rows with new `build_type` values (`compaction:meeting`, etc.). A nullable `source_version_id` is added to `working_memory_inputs` so the canonical version can record compaction lineage. `WorkingMemoryAiAuthorService` becomes a thin wrapper that builds a prompt and calls `OpenRouterService::researchFromPrompt` (the same path `MeetingWorkflowRunner` uses).

**Tech Stack:** Laravel 12, PHP 8.2+, Eloquent migrations and models, Pest tests, OpenRouter via existing `OpenRouterService`.

**Spec:** [`docs/superpowers/specs/2026-05-07-working-memory-compactions-design.md`](../specs/2026-05-07-working-memory-compactions-design.md). Phases 4–6 (digest compactions, research compactions, bootstrap command) are deliberately out of scope and will be separate plans.

---

## Scope check

This plan covers one subsystem (working-memory authoring + compaction lineage + meeting compaction job). It is large but coherent. Phases 4–6 are independent and shipped separately.

## File structure (creates + touches)

| Path | Responsibility |
|---|---|
| `database/migrations/2026_05_07_140000_extend_working_memory_inputs_for_compactions.php` | Make `thought_id` nullable, add `source_version_id` FK + unique index. |
| `app/Models/WorkingMemoryInput.php` | Nullable `thought_id`, new `sourceVersion()` relation, fillable update. |
| `app/Models/WorkingMemoryVersion.php` | Add `isCompaction()` and `compactionSubtype()` helpers. |
| `app/Services/WorkingMemory/Compactions/CompactionVersionWriter.php` | Persist a compaction `working_memory_versions` row plus `working_memory_inputs` for source thoughts. |
| `app/Services/WorkingMemory/Compactions/MeetingCompactionPromptBuilder.php` | Build prompt for `compaction:meeting`. |
| `app/Services/WorkingMemory/Composer/WorkingMemoryComposerPromptBuilder.php` | Build prompt for canonical narrative authoring. |
| `app/Services/WorkingMemory/WorkingMemoryAiAuthorService.php` | Replace deterministic body with model-backed composer; preserve return shape. |
| `app/Services/WorkingMemory/WorkingMemoryEvidencePackBuilder.php` | Load compaction versions for the scope and emit per-source-type promotion hints. |
| `app/Jobs/SynthesizeMeetingCompactionJob.php` | Synthesize a `compaction:meeting` version from a meeting thought. |
| `app/Observers/ThoughtObserver.php` | Dispatch `SynthesizeMeetingCompactionJob` when a meeting thought is created or updated. |
| `app/Http/Controllers/MemoryCompactionController.php` | Render `/memory/{scopeType}/{scopeKey}/compactions/{versionId}`. |
| `app/Http/Controllers/Api/McpController.php` | Add `get_compaction` MCP method. |
| `routes/web.php` | Add memory compaction route. |
| `config/working_memory.php` | Add composer/meeting model + temperature config keys. |
| `resources/views/memory/compactions/show.blade.php` | Server-rendered compaction view (existing memory page is server-rendered Blade). |
| `tests/Unit/Models/WorkingMemoryInputTest.php` | Schema invariants (nullable `thought_id`, `source_version_id` relation). |
| `tests/Unit/Models/WorkingMemoryVersionTest.php` | `isCompaction()` / `compactionSubtype()` behavior. |
| `tests/Unit/Services/WorkingMemory/Compactions/CompactionVersionWriterTest.php` | Persistence + input lineage. |
| `tests/Unit/Services/WorkingMemory/Compactions/MeetingCompactionPromptBuilderTest.php` | Prompt shape. |
| `tests/Unit/Services/WorkingMemory/Composer/WorkingMemoryComposerPromptBuilderTest.php` | Prompt shape. |
| `tests/Unit/Services/WorkingMemory/WorkingMemoryAiAuthorServiceTest.php` | Model-backed authoring (mocked OpenRouter). |
| `tests/Unit/Services/WorkingMemory/WorkingMemoryEvidencePackBuilderTest.php` | Compaction versions surface as evidence; promotion hints emitted. |
| `tests/Feature/SynthesizeMeetingCompactionJobTest.php` | End-to-end: meeting thought → compaction version persisted with prose. |
| `tests/Feature/ThoughtObserverTest.php` | Meeting thoughts dispatch the compaction job; non-meeting thoughts do not. |
| `tests/Feature/MemoryCompactionRouteTest.php` | Auth + render. |
| `tests/Feature/McpApiTest.php` | `get_compaction` payload. |

---

### Task 1: Extend `working_memory_inputs` for compaction lineage

**Files:**
- Create: `database/migrations/2026_05_07_140000_extend_working_memory_inputs_for_compactions.php`
- Modify: `app/Models/WorkingMemoryInput.php`
- Test: `tests/Unit/Models/WorkingMemoryInputTest.php`

- [ ] **Step 1: Write failing test for compaction-input lineage**

```php
<?php

namespace Tests\Unit\Models;

use App\Models\Thought;
use App\Models\User;
use App\Models\WorkingMemory;
use App\Models\WorkingMemoryInput;
use App\Models\WorkingMemoryVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class WorkingMemoryInputTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_can_link_to_a_compaction_version_instead_of_a_thought(): void
    {
        $user = User::factory()->create();
        $memory = WorkingMemory::factory()->create([
            'user_id' => $user->id,
            'scope_type' => 'project',
            'scope_key' => 'dezeen',
        ]);
        $compaction = WorkingMemoryVersion::factory()->create([
            'working_memory_id' => $memory->id,
            'build_type' => 'compaction:meeting',
        ]);
        $canonical = WorkingMemoryVersion::factory()->create([
            'working_memory_id' => $memory->id,
            'build_type' => 'consolidated',
        ]);

        $input = WorkingMemoryInput::create([
            'working_memory_version_id' => $canonical->id,
            'thought_id' => null,
            'source_version_id' => $compaction->id,
            'contribution_type' => 'compaction',
            'weight' => 1.0,
        ]);

        $this->assertNull($input->thought_id);
        $this->assertSame($compaction->id, $input->source_version_id);
        $this->assertSame($compaction->id, $input->sourceVersion->id);
    }

    #[Test]
    public function thought_inputs_still_work_unchanged(): void
    {
        $user = User::factory()->create();
        $thought = Thought::factory()->create(['user_id' => $user->id]);
        $memory = WorkingMemory::factory()->create(['user_id' => $user->id]);
        $version = WorkingMemoryVersion::factory()->create(['working_memory_id' => $memory->id]);

        $input = WorkingMemoryInput::create([
            'working_memory_version_id' => $version->id,
            'thought_id' => $thought->id,
            'source_version_id' => null,
            'contribution_type' => 'primary',
            'weight' => 1.0,
        ]);

        $this->assertSame($thought->id, $input->thought_id);
        $this->assertNull($input->source_version_id);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Unit/Models/WorkingMemoryInputTest.php`
Expected: FAIL — `source_version_id` column does not exist; `sourceVersion` relation undefined.

- [ ] **Step 3: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('working_memory_inputs', function (Blueprint $table): void {
            $table->uuid('source_version_id')->nullable()->after('thought_id');
        });

        Schema::table('working_memory_inputs', function (Blueprint $table): void {
            $table->foreign('source_version_id', 'working_memory_inputs_source_version_fk')
                ->references('id')
                ->on('working_memory_versions')
                ->nullOnDelete();

            $table->unique(
                ['working_memory_version_id', 'source_version_id'],
                'working_memory_inputs_version_source_unique'
            );
        });

        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE working_memory_inputs ALTER COLUMN thought_id DROP NOT NULL');
            DB::statement(
                'ALTER TABLE working_memory_inputs ADD CONSTRAINT working_memory_inputs_input_target_chk '
                .'CHECK ((thought_id IS NOT NULL)::int + (source_version_id IS NOT NULL)::int = 1)'
            );
        } elseif ($driver === 'mysql' || $driver === 'mariadb') {
            DB::statement('ALTER TABLE working_memory_inputs MODIFY thought_id CHAR(36) NULL');
            DB::statement(
                'ALTER TABLE working_memory_inputs ADD CONSTRAINT working_memory_inputs_input_target_chk '
                .'CHECK ((thought_id IS NOT NULL) <> (source_version_id IS NOT NULL))'
            );
        } elseif ($driver === 'sqlite') {
            // SQLite cannot alter NOT NULL or add CHECK in place; rebuild the table.
            DB::statement('PRAGMA foreign_keys = OFF');
            DB::statement('CREATE TABLE working_memory_inputs__new (
                id TEXT PRIMARY KEY NOT NULL,
                working_memory_version_id TEXT NOT NULL,
                thought_id TEXT NULL,
                source_version_id TEXT NULL,
                contribution_type VARCHAR(32) NOT NULL,
                weight NUMERIC(5,2) NOT NULL DEFAULT 0,
                created_at DATETIME NULL,
                updated_at DATETIME NULL,
                FOREIGN KEY (working_memory_version_id) REFERENCES working_memory_versions(id) ON DELETE CASCADE,
                FOREIGN KEY (thought_id) REFERENCES thoughts(id) ON DELETE CASCADE,
                FOREIGN KEY (source_version_id) REFERENCES working_memory_versions(id) ON DELETE SET NULL,
                CHECK ((thought_id IS NOT NULL) <> (source_version_id IS NOT NULL))
            )');
            DB::statement('INSERT INTO working_memory_inputs__new
                (id, working_memory_version_id, thought_id, source_version_id, contribution_type, weight, created_at, updated_at)
                SELECT id, working_memory_version_id, thought_id, NULL, contribution_type, weight, created_at, updated_at
                FROM working_memory_inputs');
            DB::statement('DROP TABLE working_memory_inputs');
            DB::statement('ALTER TABLE working_memory_inputs__new RENAME TO working_memory_inputs');
            DB::statement('CREATE UNIQUE INDEX working_memory_inputs_version_thought_unique ON working_memory_inputs (working_memory_version_id, thought_id)');
            DB::statement('CREATE UNIQUE INDEX working_memory_inputs_version_source_unique ON working_memory_inputs (working_memory_version_id, source_version_id)');
            DB::statement('CREATE INDEX working_memory_inputs_version_type_idx ON working_memory_inputs (working_memory_version_id, contribution_type)');
            DB::statement('CREATE INDEX working_memory_inputs_thought_id_index ON working_memory_inputs (thought_id)');
            DB::statement('PRAGMA foreign_keys = ON');
        }
    }

    public function down(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE working_memory_inputs DROP CONSTRAINT IF EXISTS working_memory_inputs_input_target_chk');
            DB::statement('ALTER TABLE working_memory_inputs ALTER COLUMN thought_id SET NOT NULL');
        } elseif ($driver === 'mysql' || $driver === 'mariadb') {
            DB::statement('ALTER TABLE working_memory_inputs DROP CHECK working_memory_inputs_input_target_chk');
            DB::statement('ALTER TABLE working_memory_inputs MODIFY thought_id CHAR(36) NOT NULL');
        }

        Schema::table('working_memory_inputs', function (Blueprint $table): void {
            $table->dropUnique('working_memory_inputs_version_source_unique');
            $table->dropForeign('working_memory_inputs_source_version_fk');
            $table->dropColumn('source_version_id');
        });
    }
};
```

- [ ] **Step 4: Update `WorkingMemoryInput` model**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkingMemoryInput extends Model
{
    use HasFactory;
    use HasUuids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'working_memory_version_id',
        'thought_id',
        'source_version_id',
        'contribution_type',
        'weight',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'weight' => 'decimal:2',
        ];
    }

    public function workingMemoryVersion(): BelongsTo
    {
        return $this->belongsTo(WorkingMemoryVersion::class);
    }

    public function thought(): BelongsTo
    {
        return $this->belongsTo(Thought::class);
    }

    public function sourceVersion(): BelongsTo
    {
        return $this->belongsTo(WorkingMemoryVersion::class, 'source_version_id');
    }
}
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test tests/Unit/Models/WorkingMemoryInputTest.php`
Expected: PASS (both methods).

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_05_07_140000_extend_working_memory_inputs_for_compactions.php \
        app/Models/WorkingMemoryInput.php \
        tests/Unit/Models/WorkingMemoryInputTest.php
git commit -m "feat(working-memory): extend inputs schema for compaction lineage"
```

---

### Task 2: Add compaction helpers to `WorkingMemoryVersion`

**Files:**
- Modify: `app/Models/WorkingMemoryVersion.php`
- Test: `tests/Unit/Models/WorkingMemoryVersionTest.php`

- [ ] **Step 1: Write failing test for compaction helpers**

Append to `tests/Unit/Models/WorkingMemoryVersionTest.php`:

```php
#[Test]
public function is_compaction_returns_true_for_compaction_build_types(): void
{
    $compaction = WorkingMemoryVersion::factory()->make(['build_type' => 'compaction:meeting']);
    $consolidated = WorkingMemoryVersion::factory()->make(['build_type' => 'consolidated']);

    $this->assertTrue($compaction->isCompaction());
    $this->assertSame('meeting', $compaction->compactionSubtype());

    $this->assertFalse($consolidated->isCompaction());
    $this->assertNull($consolidated->compactionSubtype());
}

#[Test]
public function compaction_subtype_returns_full_subtype_after_colon(): void
{
    $weekly = WorkingMemoryVersion::factory()->make(['build_type' => 'compaction:weekly-digest']);

    $this->assertSame('weekly-digest', $weekly->compactionSubtype());
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Unit/Models/WorkingMemoryVersionTest.php --filter=compaction`
Expected: FAIL — methods undefined.

- [ ] **Step 3: Add helpers to the model**

In `app/Models/WorkingMemoryVersion.php` add:

```php
public function isCompaction(): bool
{
    return str_starts_with((string) $this->build_type, 'compaction:');
}

public function compactionSubtype(): ?string
{
    if (! $this->isCompaction()) {
        return null;
    }

    return substr((string) $this->build_type, strlen('compaction:'));
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test tests/Unit/Models/WorkingMemoryVersionTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Models/WorkingMemoryVersion.php tests/Unit/Models/WorkingMemoryVersionTest.php
git commit -m "feat(working-memory): add compaction helpers on version model"
```

---

### Task 3: Add `working_memory.php` config keys for composer + compaction models

**Files:**
- Modify: `config/working_memory.php`

- [ ] **Step 1: Add config keys for AI models**

Add (or merge) into `config/working_memory.php`:

```php
'authoring' => [
    'enabled' => filter_var(env('WORKING_MEMORY_AUTHORING_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
    'composer_model' => env('WORKING_MEMORY_COMPOSER_MODEL', env('OPENROUTER_METADATA_MODEL', 'openai/gpt-4o-mini')),
    'composer_temperature' => (float) env('WORKING_MEMORY_COMPOSER_TEMPERATURE', 0.2),
    'meeting_compaction_model' => env('WORKING_MEMORY_MEETING_COMPACTION_MODEL', env('OPENROUTER_METADATA_MODEL', 'openai/gpt-4o-mini')),
    'meeting_compaction_temperature' => (float) env('WORKING_MEMORY_MEETING_COMPACTION_TEMPERATURE', 0.2),
    'max_prompt_input_chars' => (int) env('WORKING_MEMORY_MAX_PROMPT_INPUT_CHARS', 60000),
],
```

If `config/working_memory.php` does not yet have an `authoring` key, add it as a top-level key. If `authoring.enabled` already exists, merge — do not duplicate.

- [ ] **Step 2: Verify config loads cleanly**

Run: `php artisan config:clear && php artisan config:cache`
Expected: no errors.

Run: `php -r "require 'vendor/autoload.php'; \$a = require 'config/working_memory.php'; var_dump(\$a['authoring']);"`
Expected: array prints showing the new keys.

- [ ] **Step 3: Commit**

```bash
git add config/working_memory.php
git commit -m "chore(working-memory): add composer and meeting compaction model config"
```

---

### Task 4: Build `WorkingMemoryComposerPromptBuilder`

**Files:**
- Create: `app/Services/WorkingMemory/Composer/WorkingMemoryComposerPromptBuilder.php`
- Test: `tests/Unit/Services/WorkingMemory/Composer/WorkingMemoryComposerPromptBuilderTest.php`

- [ ] **Step 1: Write failing prompt builder test**

```php
<?php

namespace Tests\Unit\Services\WorkingMemory\Composer;

use App\Services\WorkingMemory\Composer\WorkingMemoryComposerPromptBuilder;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class WorkingMemoryComposerPromptBuilderTest extends TestCase
{
    #[Test]
    public function it_includes_required_sections_scope_and_signal_blocks(): void
    {
        $builder = new WorkingMemoryComposerPromptBuilder();

        $prompt = $builder->build([
            'scope_type' => 'project',
            'scope_key' => 'dezeen',
            'generated_at' => '2026-05-07T10:00:00Z',
            'signals' => [
                [
                    'thought_id' => 'thought-1',
                    'content' => 'DEZ-2819: comments-endpoint.php missing from committed plugin tree.',
                    'created_at' => '2026-05-02T09:00:00Z',
                    'references' => [
                        ['type' => 'thought', 'url' => '/thoughts/thought-1', 'label' => 'DEZ-2819 bug scan'],
                    ],
                ],
            ],
            'compactions' => [
                [
                    'version_id' => 'compaction-1',
                    'subtype' => 'meeting',
                    'summary_markdown' => "## Summary\nWeekly check-in agreed PHP upgrade scope.",
                    'created_at' => '2026-05-01T15:00:00Z',
                    'references' => [
                        ['type' => 'compaction', 'url' => '/memory/project/dezeen/compactions/compaction-1', 'label' => 'Weekly check-in 2026-05-01'],
                    ],
                ],
            ],
        ]);

        $this->assertStringContainsString('## Working memory composition task', $prompt);
        $this->assertStringContainsString('Scope: project / dezeen', $prompt);
        $this->assertStringContainsString('Current Focus', $prompt);
        $this->assertStringContainsString('Active Priorities', $prompt);
        $this->assertStringContainsString('Recent Changes', $prompt);
        $this->assertStringContainsString('Open Questions', $prompt);
        $this->assertStringContainsString('Risks / Blockers', $prompt);
        $this->assertStringContainsString('Next Actions', $prompt);
        $this->assertStringContainsString('Latest Signals', $prompt);
        $this->assertStringContainsString('Source Notes', $prompt);
        $this->assertStringContainsString('compaction:meeting', $prompt);
        $this->assertStringContainsString('DEZ-2819', $prompt);
        $this->assertStringContainsString('Return JSON', $prompt);
    }

    #[Test]
    public function it_truncates_input_to_configured_max_chars(): void
    {
        config(['working_memory.authoring_max_prompt_input_chars' => 500]);
        $builder = new WorkingMemoryComposerPromptBuilder();

        $longContent = str_repeat('A', 5000);
        $prompt = $builder->build([
            'scope_type' => 'global',
            'scope_key' => 'global',
            'generated_at' => '2026-05-07T10:00:00Z',
            'signals' => [
                ['thought_id' => 't', 'content' => $longContent, 'created_at' => '2026-05-07T09:00:00Z', 'references' => []],
            ],
            'compactions' => [],
        ]);

        $this->assertLessThan(2000, mb_strlen($prompt));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Unit/Services/WorkingMemory/Composer/WorkingMemoryComposerPromptBuilderTest.php`
Expected: FAIL — class does not exist.

- [ ] **Step 3: Implement the prompt builder**

```php
<?php

namespace App\Services\WorkingMemory\Composer;

use Illuminate\Support\Str;

class WorkingMemoryComposerPromptBuilder
{
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

    private const PROMOTION_RULES = <<<TEXT
Promotion guidance per source type:
- compaction:meeting → Recent Changes, Next Actions, Risks / Blockers, Open Questions
- compaction:weekly-digest → Latest Signals, Active Priorities, Recent Changes
- compaction:topic-digest → Active Priorities, Open Questions, Latest Signals
- compaction:research-synth → Open Questions, Risks / Blockers, Latest Signals
- raw thought (recent) → Latest Signals, Open Questions
- raw thought with risk/block/issue/delay/incident keywords → Risks / Blockers, Next Actions
TEXT;

    /**
     * @param  array{
     *     scope_type: string,
     *     scope_key: string,
     *     generated_at: string,
     *     signals: array<int, array{
     *         thought_id: string|null,
     *         content: string,
     *         created_at: string|null,
     *         references: array<int, array{type: string, url: string, label: string}>
     *     }>,
     *     compactions: array<int, array{
     *         version_id: string,
     *         subtype: string,
     *         summary_markdown: string,
     *         created_at: string,
     *         references: array<int, array{type: string, url: string, label: string}>
     *     }>
     * }  $evidencePack
     */
    public function build(array $evidencePack): string
    {
        $maxChars = (int) config('working_memory.authoring_max_prompt_input_chars', 60000);
        $sections = implode(', ', self::REQUIRED_SECTIONS);

        $compactionBlock = $this->renderCompactions($evidencePack['compactions'] ?? []);
        $signalBlock = $this->renderSignals($evidencePack['signals'] ?? []);

        $payload = <<<TEXT
## Working memory composition task

You are composing a decision-grade working-memory snapshot for IdeaTub.

Scope: {$evidencePack['scope_type']} / {$evidencePack['scope_key']}
Generated at: {$evidencePack['generated_at']}

Required sections (in this order): {$sections}

{$this->promotionRules()}

## Compactions (preferred evidence)
{$compactionBlock}

## Recent raw thoughts
{$signalBlock}

## Output contract

Return JSON with this exact shape (no Markdown fences):

{
  "summary_markdown": "<full markdown rendering with all required sections as `## <section>` headings, narrative bullet prose under each>",
  "structured_sections": {
    "Current Focus": [
      {
        "text": "<single bullet, narrative prose>",
        "importance": 1,
        "fallback_mode": "direct",
        "citations": [
          {"type": "thought|compaction|source", "url": "<exact url from evidence>", "label": "<exact label from evidence>"}
        ]
      }
    ],
    ...
  },
  "references": [
    {"type": "thought|compaction|source", "url": "<exact url>", "label": "<exact label>"}
  ]
}

Rules:
- Every bullet in a required section MUST have at least one citation taken verbatim from the evidence above.
- Do not invent URLs or labels. Use only the URLs and labels provided in compaction or signal references.
- Prefer compaction citations when both a compaction and its source thought are available.
- Source Notes should list each unique cited reference once.
- Write in concise decision-grade prose, not stub bullets. Aim for 1–3 sentence bullets that name people, IDs, dates, and concrete state.
TEXT;

        return Str::limit($payload, $maxChars, '');
    }

    private function promotionRules(): string
    {
        return self::PROMOTION_RULES;
    }

    /**
     * @param  array<int, array{
     *     version_id: string, subtype: string, summary_markdown: string,
     *     created_at: string, references: array<int, array{type: string, url: string, label: string}>
     * }>  $compactions
     */
    private function renderCompactions(array $compactions): string
    {
        if ($compactions === []) {
            return '_No compactions available for this window._';
        }

        $blocks = [];
        foreach ($compactions as $compaction) {
            $references = $this->renderReferences($compaction['references'] ?? []);
            $blocks[] = "### compaction:{$compaction['subtype']} — {$compaction['version_id']} ({$compaction['created_at']})\n"
                ."References: {$references}\n\n"
                .trim($compaction['summary_markdown']);
        }

        return implode("\n\n---\n\n", $blocks);
    }

    /**
     * @param  array<int, array{
     *     thought_id: string|null, content: string, created_at: string|null,
     *     references: array<int, array{type: string, url: string, label: string}>
     * }>  $signals
     */
    private function renderSignals(array $signals): string
    {
        if ($signals === []) {
            return '_No raw thoughts in this window._';
        }

        $lines = [];
        foreach ($signals as $signal) {
            $thoughtId = $signal['thought_id'] ?? 'unknown';
            $createdAt = $signal['created_at'] ?? 'unknown';
            $references = $this->renderReferences($signal['references'] ?? []);
            $content = trim($signal['content']);
            $lines[] = "- [{$createdAt}] thought:{$thoughtId} (refs: {$references})\n  {$content}";
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<int, array{type: string, url: string, label: string}>  $references
     */
    private function renderReferences(array $references): string
    {
        if ($references === []) {
            return 'none';
        }

        $parts = [];
        foreach ($references as $reference) {
            $parts[] = "[{$reference['type']}] {$reference['label']} ({$reference['url']})";
        }

        return implode('; ', $parts);
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test tests/Unit/Services/WorkingMemory/Composer/WorkingMemoryComposerPromptBuilderTest.php`
Expected: PASS (both methods).

- [ ] **Step 5: Commit**

```bash
git add app/Services/WorkingMemory/Composer/WorkingMemoryComposerPromptBuilder.php \
        tests/Unit/Services/WorkingMemory/Composer/WorkingMemoryComposerPromptBuilderTest.php
git commit -m "feat(working-memory): add composer prompt builder for narrative authoring"
```

---

### Task 5: Replace `WorkingMemoryAiAuthorService` with model-backed composer

**Files:**
- Modify: `app/Services/WorkingMemory/WorkingMemoryAiAuthorService.php`
- Test: `tests/Unit/Services/WorkingMemory/WorkingMemoryAiAuthorServiceTest.php` (rewrite)

- [ ] **Step 1: Write failing test for model-backed authoring**

Replace existing tests at `tests/Unit/Services/WorkingMemory/WorkingMemoryAiAuthorServiceTest.php` with:

```php
<?php

namespace Tests\Unit\Services\WorkingMemory;

use App\Services\OpenRouterService;
use App\Services\WorkingMemory\Composer\WorkingMemoryComposerPromptBuilder;
use App\Services\WorkingMemory\WorkingMemoryAiAuthorService;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class WorkingMemoryAiAuthorServiceTest extends TestCase
{
    #[Test]
    public function it_calls_openrouter_with_composer_prompt_and_returns_parsed_json(): void
    {
        $promptBuilder = new WorkingMemoryComposerPromptBuilder();
        $openRouter = Mockery::mock(OpenRouterService::class);
        $openRouter->shouldReceive('researchFromPrompt')
            ->once()
            ->with(Mockery::on(fn ($prompt) => str_contains((string) $prompt, '## Working memory composition task')))
            ->andReturn(json_encode([
                'summary_markdown' => "# Working memory\n## Current Focus\n- Ship DEZ-2819 fix.",
                'structured_sections' => [
                    'Current Focus' => [[
                        'text' => 'Ship DEZ-2819 fix.',
                        'importance' => 1,
                        'fallback_mode' => 'direct',
                        'citations' => [['type' => 'thought', 'url' => '/thoughts/t1', 'label' => 'DEZ-2819']],
                    ]],
                ],
                'references' => [['type' => 'thought', 'url' => '/thoughts/t1', 'label' => 'DEZ-2819']],
            ]));

        $service = new WorkingMemoryAiAuthorService($promptBuilder, $openRouter);

        $result = $service->authorFromEvidence([
            'scope_type' => 'project',
            'scope_key' => 'dezeen',
            'generated_at' => '2026-05-07T10:00:00Z',
            'signals' => [[
                'thought_id' => 't1',
                'content' => 'Need to ship DEZ-2819.',
                'created_at' => '2026-05-07T09:00:00Z',
                'references' => [['type' => 'thought', 'url' => '/thoughts/t1', 'label' => 'DEZ-2819']],
            ]],
            'compactions' => [],
            'section_candidates' => [],
            'section_bundles' => [],
        ]);

        $this->assertArrayHasKey('summary_markdown', $result);
        $this->assertArrayHasKey('structured_sections', $result);
        $this->assertArrayHasKey('references', $result);
        $this->assertSame('Ship DEZ-2819 fix.', $result['structured_sections']['Current Focus'][0]['text']);
    }

    #[Test]
    public function it_falls_back_to_empty_sections_when_model_returns_invalid_json(): void
    {
        $promptBuilder = new WorkingMemoryComposerPromptBuilder();
        $openRouter = Mockery::mock(OpenRouterService::class);
        $openRouter->shouldReceive('researchFromPrompt')->andReturn('not json');

        $service = new WorkingMemoryAiAuthorService($promptBuilder, $openRouter);

        $result = $service->authorFromEvidence([
            'scope_type' => 'project',
            'scope_key' => 'dezeen',
            'generated_at' => '2026-05-07T10:00:00Z',
            'signals' => [],
            'compactions' => [],
            'section_candidates' => [],
            'section_bundles' => [],
        ]);

        $this->assertSame('', trim($result['summary_markdown']) === '' ? '' : '');
        $this->assertSame([], $result['references']);
        $this->assertArrayHasKey('Current Focus', $result['structured_sections']);
        $this->assertSame([], $result['structured_sections']['Current Focus']);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Unit/Services/WorkingMemory/WorkingMemoryAiAuthorServiceTest.php`
Expected: FAIL — service constructor does not accept `WorkingMemoryComposerPromptBuilder` and `OpenRouterService`.

- [ ] **Step 3: Replace service body with model-backed authoring**

Rewrite `app/Services/WorkingMemory/WorkingMemoryAiAuthorService.php`:

```php
<?php

namespace App\Services\WorkingMemory;

use App\Services\OpenRouterService;
use App\Services\WorkingMemory\Composer\WorkingMemoryComposerPromptBuilder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

final class WorkingMemoryAiAuthorService
{
    /**
     * @var array<int, string>
     */
    private const REQUIRED_SECTION_KEYS = [
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
        private WorkingMemoryComposerPromptBuilder $promptBuilder,
        private OpenRouterService $openRouter,
    ) {}

    /**
     * @param  array<string, mixed>  $evidencePack
     * @return array{
     *     summary_markdown: string,
     *     structured_sections: array<string, array<int, array{
     *         id: string,
     *         text: string,
     *         importance: int,
     *         fallback_mode: 'direct'|'section_bundle',
     *         citations: array<int, array{type: string, url: string, label: string}>
     *     }>>,
     *     references: array<int, array{type: string, url: string, label: string}>
     * }
     */
    public function authorFromEvidence(array $evidencePack): array
    {
        try {
            $prompt = $this->promptBuilder->build($evidencePack);
            $raw = $this->openRouter->researchFromPrompt($prompt);
            $decoded = $this->decodeJson($raw);

            if ($decoded === null) {
                Log::warning('WorkingMemoryAiAuthorService: model returned non-JSON output.', [
                    'scope_type' => $evidencePack['scope_type'] ?? null,
                    'scope_key' => $evidencePack['scope_key'] ?? null,
                    'preview' => Str::limit((string) $raw, 400),
                ]);

                return $this->emptyOutput();
            }

            return $this->normalizeOutput($decoded);
        } catch (Throwable $e) {
            Log::warning('WorkingMemoryAiAuthorService: authoring failed.', [
                'message' => $e->getMessage(),
            ]);

            return $this->emptyOutput();
        }
    }

    private function decodeJson(string $raw): ?array
    {
        $trimmed = trim($raw);
        if ($trimmed === '') {
            return null;
        }

        // Strip optional markdown fences if the model produced them anyway.
        if (str_starts_with($trimmed, '```')) {
            $trimmed = preg_replace('/^```(?:json)?\s*/', '', $trimmed) ?? $trimmed;
            $trimmed = preg_replace('/```\s*$/', '', $trimmed) ?? $trimmed;
        }

        try {
            $decoded = json_decode($trimmed, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  array<string, mixed>  $decoded
     * @return array{
     *     summary_markdown: string,
     *     structured_sections: array<string, array<int, array{
     *         id: string, text: string, importance: int,
     *         fallback_mode: 'direct'|'section_bundle',
     *         citations: array<int, array{type: string, url: string, label: string}>
     *     }>>,
     *     references: array<int, array{type: string, url: string, label: string}>
     * }
     */
    private function normalizeOutput(array $decoded): array
    {
        $summaryMarkdown = is_string($decoded['summary_markdown'] ?? null)
            ? trim($decoded['summary_markdown'])
            : '';

        $rawSections = is_array($decoded['structured_sections'] ?? null)
            ? $decoded['structured_sections']
            : [];

        $structured = [];
        foreach (self::REQUIRED_SECTION_KEYS as $section) {
            $items = [];
            $rawItems = is_array($rawSections[$section] ?? null) ? $rawSections[$section] : [];
            foreach ($rawItems as $rawItem) {
                if (! is_array($rawItem)) {
                    continue;
                }
                $text = trim((string) ($rawItem['text'] ?? ''));
                if ($text === '') {
                    continue;
                }
                $citations = $this->normalizeCitations($rawItem['citations'] ?? []);
                $items[] = [
                    'id' => (string) Str::uuid(),
                    'text' => $text,
                    'importance' => (int) ($rawItem['importance'] ?? 1),
                    'fallback_mode' => $this->normalizeFallbackMode($rawItem['fallback_mode'] ?? 'direct'),
                    'citations' => $citations,
                ];
            }
            $structured[$section] = $items;
        }

        $references = $this->normalizeCitations($decoded['references'] ?? []);

        return [
            'summary_markdown' => $summaryMarkdown,
            'structured_sections' => $structured,
            'references' => $references,
        ];
    }

    /**
     * @param  mixed  $citations
     * @return array<int, array{type: string, url: string, label: string}>
     */
    private function normalizeCitations(mixed $citations): array
    {
        if (! is_array($citations)) {
            return [];
        }

        $rows = [];
        foreach ($citations as $row) {
            if (! is_array($row)) {
                continue;
            }
            $url = trim((string) ($row['url'] ?? ''));
            $label = trim((string) ($row['label'] ?? ''));
            $type = trim((string) ($row['type'] ?? 'source'));
            if ($url === '' || $label === '') {
                continue;
            }
            $rows[] = [
                'type' => $type !== '' ? $type : 'source',
                'url' => $url,
                'label' => $label,
            ];
        }

        return $rows;
    }

    /**
     * @return 'direct'|'section_bundle'
     */
    private function normalizeFallbackMode(mixed $value): string
    {
        return $value === 'section_bundle' ? 'section_bundle' : 'direct';
    }

    /**
     * @return array{summary_markdown: string, structured_sections: array<string, array<int, array<string, mixed>>>, references: array<int, mixed>}
     */
    private function emptyOutput(): array
    {
        $sections = [];
        foreach (self::REQUIRED_SECTION_KEYS as $section) {
            $sections[$section] = [];
        }

        return [
            'summary_markdown' => '',
            'structured_sections' => $sections,
            'references' => [],
        ];
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test tests/Unit/Services/WorkingMemory/WorkingMemoryAiAuthorServiceTest.php`
Expected: PASS (both methods).

- [ ] **Step 5: Run the full working-memory test suite to catch breakage**

Run: `php artisan test --filter=WorkingMemory`
Expected: PASS. If any previously-passing tests fail because they assumed the deterministic placeholder, update them to mock `OpenRouterService` and assert the new contract.

- [ ] **Step 6: Commit**

```bash
git add app/Services/WorkingMemory/WorkingMemoryAiAuthorService.php \
        tests/Unit/Services/WorkingMemory/WorkingMemoryAiAuthorServiceTest.php
git commit -m "feat(working-memory): replace deterministic authoring with model-backed composer"
```

---

### Task 6: Extend `WorkingMemoryEvidencePackBuilder` to surface compactions

**Files:**
- Modify: `app/Services/WorkingMemory/WorkingMemoryEvidencePackBuilder.php`
- Test: `tests/Unit/Services/WorkingMemory/WorkingMemoryEvidencePackBuilderTest.php`

- [ ] **Step 1: Write failing test for compaction inclusion**

Append to `tests/Unit/Services/WorkingMemory/WorkingMemoryEvidencePackBuilderTest.php`:

```php
#[Test]
public function it_includes_compactions_for_the_scope_in_the_evidence_pack(): void
{
    $user = User::factory()->create();
    $memory = WorkingMemory::factory()->create([
        'user_id' => $user->id,
        'scope_type' => 'project',
        'scope_key' => 'dezeen',
    ]);
    $compaction = WorkingMemoryVersion::factory()->create([
        'working_memory_id' => $memory->id,
        'build_type' => 'compaction:meeting',
        'summary_markdown' => '## Summary\nWeekly check-in agreed PHP upgrade scope.',
        'references_json' => [['type' => 'thought', 'url' => '/thoughts/t9', 'label' => 'standup notes']],
    ]);
    $thought = Thought::factory()->create(['user_id' => $user->id]);

    $builder = app(WorkingMemoryEvidencePackBuilder::class);
    $pack = $builder->build($user->id, 'project', 'dezeen', collect([$thought]));

    $this->assertArrayHasKey('compactions', $pack);
    $this->assertCount(1, $pack['compactions']);
    $this->assertSame('meeting', $pack['compactions'][0]['subtype']);
    $this->assertSame($compaction->id, $pack['compactions'][0]['version_id']);
    $this->assertStringContainsString('PHP upgrade', $pack['compactions'][0]['summary_markdown']);
    $this->assertSame('/memory/project/dezeen/compactions/'.$compaction->id, $pack['compactions'][0]['references'][0]['url'] ?? null);
}
```

(The route URL assertion forces compaction permalinks to be normalized inside the evidence pack so the composer prompt and the canonical authored version cite the same URL.)

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Unit/Services/WorkingMemory/WorkingMemoryEvidencePackBuilderTest.php --filter=compactions`
Expected: FAIL — `compactions` key missing.

- [ ] **Step 3: Extend the evidence pack builder**

Add (or modify) at `app/Services/WorkingMemory/WorkingMemoryEvidencePackBuilder.php`:

- Update the public `build()` return shape doc to add `compactions` and `compaction_references_per_version`.
- Inside `build()`, after computing `$signals`, fetch compaction versions:

```php
$compactions = $this->loadCompactions($userId, $normalizedScopeType, $normalizedScopeKey);
```

- Add the method:

```php
/**
 * @return array<int, array{
 *     version_id: string, subtype: string, summary_markdown: string,
 *     created_at: string,
 *     references: array<int, array{type: string, url: string, label: string}>
 * }>
 */
private function loadCompactions(int $userId, string $scopeType, string $scopeKey): array
{
    $memory = \App\Models\WorkingMemory::query()
        ->where('user_id', $userId)
        ->where('scope_type', $scopeType)
        ->where('scope_key', $scopeKey)
        ->first();

    if ($memory === null) {
        return [];
    }

    return $memory->versions()
        ->where('build_type', 'like', 'compaction:%')
        ->orderByDesc('created_at')
        ->limit(20)
        ->get()
        ->map(function (\App\Models\WorkingMemoryVersion $version) use ($scopeType, $scopeKey): array {
            $permalink = sprintf('/memory/%s/%s/compactions/%s', $scopeType, $scopeKey, $version->id);
            $references = is_array($version->references_json) ? $version->references_json : [];
            $compactionRef = [
                'type' => 'compaction',
                'url' => $permalink,
                'label' => sprintf('compaction:%s %s', $version->compactionSubtype() ?? 'unknown', $version->created_at?->toDateString() ?? ''),
            ];

            // Prepend the canonical compaction permalink so the composer cites it directly,
            // followed by the references the compaction itself surfaces.
            $references = array_merge([$compactionRef], $references);

            return [
                'version_id' => $version->id,
                'subtype' => $version->compactionSubtype() ?? 'unknown',
                'summary_markdown' => (string) $version->summary_markdown,
                'created_at' => $version->created_at?->toIso8601String() ?? '',
                'references' => $references,
            ];
        })
        ->all();
}
```

- Include `'compactions' => $compactions` in the array returned by `build()`.

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test tests/Unit/Services/WorkingMemory/WorkingMemoryEvidencePackBuilderTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/WorkingMemory/WorkingMemoryEvidencePackBuilder.php \
        tests/Unit/Services/WorkingMemory/WorkingMemoryEvidencePackBuilderTest.php
git commit -m "feat(working-memory): surface compaction versions in evidence pack"
```

---

### Task 7: Build `MeetingCompactionPromptBuilder`

**Files:**
- Create: `app/Services/WorkingMemory/Compactions/MeetingCompactionPromptBuilder.php`
- Test: `tests/Unit/Services/WorkingMemory/Compactions/MeetingCompactionPromptBuilderTest.php`

- [ ] **Step 1: Write failing prompt builder test**

```php
<?php

namespace Tests\Unit\Services\WorkingMemory\Compactions;

use App\Models\Thought;
use App\Services\WorkingMemory\Compactions\MeetingCompactionPromptBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class MeetingCompactionPromptBuilderTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_renders_the_meeting_thought_content_and_required_sections(): void
    {
        $thought = Thought::factory()->create([
            'content' => 'Standup 2026-05-07. Decided to ship DEZ-2819 hotfix Friday.',
            'metadata' => ['type' => 'meeting', 'tags' => ['client:dezeen']],
        ]);

        $builder = new MeetingCompactionPromptBuilder();
        $prompt = $builder->build($thought);

        $this->assertStringContainsString('## Meeting compaction task', $prompt);
        $this->assertStringContainsString('Summary', $prompt);
        $this->assertStringContainsString('Decisions', $prompt);
        $this->assertStringContainsString('Action Items', $prompt);
        $this->assertStringContainsString('Risks / Blockers', $prompt);
        $this->assertStringContainsString('Open Questions', $prompt);
        $this->assertStringContainsString('Standup 2026-05-07', $prompt);
        $this->assertStringContainsString('Return JSON', $prompt);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Unit/Services/WorkingMemory/Compactions/MeetingCompactionPromptBuilderTest.php`
Expected: FAIL — class does not exist.

- [ ] **Step 3: Implement the prompt builder**

```php
<?php

namespace App\Services\WorkingMemory\Compactions;

use App\Models\Thought;
use Illuminate\Support\Str;

class MeetingCompactionPromptBuilder
{
    public function build(Thought $meeting): string
    {
        $maxChars = (int) config('working_memory.authoring_max_prompt_input_chars', 60000);
        $tags = collect(data_get($meeting->metadata, 'tags', []))
            ->map(fn ($t): string => trim((string) $t))
            ->filter()
            ->implode(', ');
        $createdAt = $meeting->created_at?->toIso8601String() ?? 'unknown';
        $body = trim((string) $meeting->content);

        $prompt = <<<TEXT
## Meeting compaction task

Synthesize the following meeting capture into a durable compaction note.

Meeting thought id: {$meeting->id}
Captured at: {$createdAt}
Tags: {$tags}

## Raw capture

{$body}

## Output contract

Return JSON only (no Markdown fences) with this shape:

{
  "summary_markdown": "<full markdown with the sections below as `## <section>`>",
  "structured_sections": {
    "Summary": [{"text": "...", "importance": 1, "fallback_mode": "direct", "citations": []}],
    "Decisions": [...],
    "Action Items": [...],
    "Risks / Blockers": [...],
    "Open Questions": [...]
  },
  "references": []
}

Rules:
- The five required sections are: Summary, Decisions, Action Items, Risks / Blockers, Open Questions.
- Action Items should name owners when the capture supports it. Do not invent owners.
- Decisions should include only confirmed decisions; tentative items belong under Open Questions.
- Keep prose concrete (dates, IDs, names). Do not pad.
- Citations array should be empty for now — a follow-up step will populate them from related thoughts.
TEXT;

        return Str::limit($prompt, $maxChars, '');
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test tests/Unit/Services/WorkingMemory/Compactions/MeetingCompactionPromptBuilderTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/WorkingMemory/Compactions/MeetingCompactionPromptBuilder.php \
        tests/Unit/Services/WorkingMemory/Compactions/MeetingCompactionPromptBuilderTest.php
git commit -m "feat(working-memory): add meeting compaction prompt builder"
```

---

### Task 8: Build `CompactionVersionWriter`

**Files:**
- Create: `app/Services/WorkingMemory/Compactions/CompactionVersionWriter.php`
- Test: `tests/Unit/Services/WorkingMemory/Compactions/CompactionVersionWriterTest.php`

- [ ] **Step 1: Write failing test for compaction persistence**

```php
<?php

namespace Tests\Unit\Services\WorkingMemory\Compactions;

use App\Models\Thought;
use App\Models\User;
use App\Models\WorkingMemoryInput;
use App\Models\WorkingMemoryVersion;
use App\Services\WorkingMemory\Compactions\CompactionVersionWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class CompactionVersionWriterTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_persists_a_compaction_version_with_thought_inputs(): void
    {
        $user = User::factory()->create();
        $thought = Thought::factory()->create(['user_id' => $user->id]);

        $writer = app(CompactionVersionWriter::class);
        $version = $writer->write(
            userId: $user->id,
            scopeType: 'project',
            scopeKey: 'dezeen',
            buildType: 'compaction:meeting',
            summaryMarkdown: "## Summary\nDecided to ship DEZ-2819.",
            structuredSections: [
                'Summary' => [
                    [
                        'id' => 'fixed-id-1',
                        'text' => 'Decided to ship DEZ-2819.',
                        'importance' => 1,
                        'fallback_mode' => 'direct',
                        'citations' => [],
                    ],
                ],
            ],
            references: [],
            sourceThoughtIds: [$thought->id],
        );

        $this->assertSame('compaction:meeting', $version->build_type);
        $this->assertSame('project', $version->workingMemory->scope_type);
        $this->assertSame('dezeen', $version->workingMemory->scope_key);
        $this->assertSame($user->id, $version->workingMemory->user_id);
        $this->assertNotSame($version->id, $version->workingMemory->latest_version_id, 'Compactions must not become latest_version_id');

        $input = WorkingMemoryInput::query()
            ->where('working_memory_version_id', $version->id)
            ->where('thought_id', $thought->id)
            ->first();
        $this->assertNotNull($input);
        $this->assertSame('compaction-source', $input->contribution_type);
    }

    #[Test]
    public function it_rejects_non_compaction_build_types(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        app(CompactionVersionWriter::class)->write(
            userId: 1,
            scopeType: 'project',
            scopeKey: 'dezeen',
            buildType: 'consolidated',
            summaryMarkdown: '',
            structuredSections: [],
            references: [],
            sourceThoughtIds: [],
        );
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Unit/Services/WorkingMemory/Compactions/CompactionVersionWriterTest.php`
Expected: FAIL — class does not exist.

- [ ] **Step 3: Implement the writer**

```php
<?php

namespace App\Services\WorkingMemory\Compactions;

use App\Models\WorkingMemory;
use App\Models\WorkingMemoryVersion;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class CompactionVersionWriter
{
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
    ): WorkingMemoryVersion {
        if (! str_starts_with($buildType, 'compaction:')) {
            throw new InvalidArgumentException(
                "CompactionVersionWriter only accepts compaction:* build types, got: {$buildType}"
            );
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

            // Compactions must NEVER become latest_version_id; only consolidated/incremental do.
            return $version;
        });
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test tests/Unit/Services/WorkingMemory/Compactions/CompactionVersionWriterTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/WorkingMemory/Compactions/CompactionVersionWriter.php \
        tests/Unit/Services/WorkingMemory/Compactions/CompactionVersionWriterTest.php
git commit -m "feat(working-memory): add compaction version writer"
```

---

### Task 9: `SynthesizeMeetingCompactionJob`

**Files:**
- Create: `app/Jobs/SynthesizeMeetingCompactionJob.php`
- Test: `tests/Feature/SynthesizeMeetingCompactionJobTest.php`

- [ ] **Step 1: Write failing job test**

```php
<?php

namespace Tests\Feature;

use App\Jobs\SynthesizeMeetingCompactionJob;
use App\Models\Thought;
use App\Models\User;
use App\Models\WorkingMemoryVersion;
use App\Services\OpenRouterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SynthesizeMeetingCompactionJobTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_creates_a_compaction_version_for_a_meeting_thought(): void
    {
        $user = User::factory()->create();
        $meeting = Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Weekly check-in 2026-05-07. Decided to ship DEZ-2819 fix.',
            'metadata' => ['type' => 'meeting', 'tags' => ['scope:project', 'project:dezeen']],
        ]);

        $openRouter = Mockery::mock(OpenRouterService::class);
        $openRouter->shouldReceive('researchFromPrompt')->once()->andReturn(json_encode([
            'summary_markdown' => "## Summary\nWeekly check-in agreed PHP upgrade scope.\n## Decisions\n- Ship DEZ-2819.",
            'structured_sections' => [
                'Summary' => [['text' => 'Weekly check-in agreed PHP upgrade scope.', 'importance' => 1, 'fallback_mode' => 'direct', 'citations' => []]],
                'Decisions' => [['text' => 'Ship DEZ-2819.', 'importance' => 1, 'fallback_mode' => 'direct', 'citations' => []]],
                'Action Items' => [],
                'Risks / Blockers' => [],
                'Open Questions' => [],
            ],
            'references' => [],
        ]));
        $this->app->instance(OpenRouterService::class, $openRouter);

        SynthesizeMeetingCompactionJob::dispatchSync($meeting->id);

        $version = WorkingMemoryVersion::query()
            ->where('build_type', 'compaction:meeting')
            ->first();

        $this->assertNotNull($version);
        $this->assertStringContainsString('PHP upgrade scope', (string) $version->summary_markdown);
        $this->assertSame('project', $version->workingMemory->scope_type);
        $this->assertSame('dezeen', $version->workingMemory->scope_key);
    }

    #[Test]
    public function it_skips_non_meeting_thoughts(): void
    {
        $user = User::factory()->create();
        $thought = Thought::factory()->create([
            'user_id' => $user->id,
            'metadata' => ['type' => 'idea'],
        ]);

        $openRouter = Mockery::mock(OpenRouterService::class);
        $openRouter->shouldNotReceive('researchFromPrompt');
        $this->app->instance(OpenRouterService::class, $openRouter);

        SynthesizeMeetingCompactionJob::dispatchSync($thought->id);

        $this->assertSame(0, WorkingMemoryVersion::query()->where('build_type', 'compaction:meeting')->count());
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/SynthesizeMeetingCompactionJobTest.php`
Expected: FAIL — job does not exist.

- [ ] **Step 3: Implement the job**

```php
<?php

namespace App\Jobs;

use App\Models\Thought;
use App\Services\OpenRouterService;
use App\Services\WorkingMemory\Compactions\CompactionVersionWriter;
use App\Services\WorkingMemory\Compactions\MeetingCompactionPromptBuilder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class SynthesizeMeetingCompactionJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(public string $thoughtId) {}

    public function handle(
        MeetingCompactionPromptBuilder $promptBuilder,
        OpenRouterService $openRouter,
        CompactionVersionWriter $writer,
    ): void {
        $meeting = Thought::query()->find($this->thoughtId);
        if ($meeting === null) {
            return;
        }

        $type = data_get($meeting->metadata, 'type');
        if ($type !== 'meeting') {
            return;
        }

        $userId = (int) $meeting->user_id;
        if ($userId <= 0) {
            return;
        }

        [$scopeType, $scopeKey] = $this->resolveScope($meeting);

        try {
            $prompt = $promptBuilder->build($meeting);
            $raw = $openRouter->researchFromPrompt($prompt);
            $decoded = $this->decodeJson($raw);

            if ($decoded === null) {
                Log::warning('SynthesizeMeetingCompactionJob: model returned non-JSON output.', [
                    'thought_id' => $meeting->id,
                ]);

                return;
            }

            $writer->write(
                userId: $userId,
                scopeType: $scopeType,
                scopeKey: $scopeKey,
                buildType: 'compaction:meeting',
                summaryMarkdown: (string) ($decoded['summary_markdown'] ?? ''),
                structuredSections: is_array($decoded['structured_sections'] ?? null) ? $decoded['structured_sections'] : [],
                references: is_array($decoded['references'] ?? null) ? $decoded['references'] : [],
                sourceThoughtIds: [$meeting->id],
            );
        } catch (Throwable $e) {
            Log::warning('SynthesizeMeetingCompactionJob failed.', [
                'thought_id' => $meeting->id,
                'message' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function resolveScope(Thought $meeting): array
    {
        $tags = collect(data_get($meeting->metadata, 'tags', []))
            ->map(fn ($t): string => trim((string) $t))
            ->filter()
            ->values();

        $project = $tags->first(fn (string $t): bool => str_starts_with($t, 'project:'));
        if ($project !== null) {
            return ['project', substr($project, strlen('project:'))];
        }

        $client = $tags->first(fn (string $t): bool => str_starts_with($t, 'client:'));
        if ($client !== null) {
            return ['project', substr($client, strlen('client:'))];
        }

        return ['global', 'global'];
    }

    private function decodeJson(string $raw): ?array
    {
        $trimmed = trim($raw);
        if ($trimmed === '') {
            return null;
        }
        if (str_starts_with($trimmed, '```')) {
            $trimmed = preg_replace('/^```(?:json)?\s*/', '', $trimmed) ?? $trimmed;
            $trimmed = preg_replace('/```\s*$/', '', $trimmed) ?? $trimmed;
        }
        try {
            $decoded = json_decode($trimmed, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test tests/Feature/SynthesizeMeetingCompactionJobTest.php`
Expected: PASS (both methods).

- [ ] **Step 5: Commit**

```bash
git add app/Jobs/SynthesizeMeetingCompactionJob.php tests/Feature/SynthesizeMeetingCompactionJobTest.php
git commit -m "feat(working-memory): synthesize meeting compaction job"
```

---

### Task 10: Wire `ThoughtObserver` to dispatch the meeting compaction job

**Files:**
- Modify: `app/Observers/ThoughtObserver.php`
- Test: `tests/Feature/ThoughtObserverTest.php`

- [ ] **Step 1: Write failing observer test**

```php
<?php

namespace Tests\Feature;

use App\Jobs\SynthesizeMeetingCompactionJob;
use App\Models\Thought;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ThoughtObserverTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_dispatches_meeting_compaction_for_meeting_thoughts(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        Thought::factory()->create([
            'user_id' => $user->id,
            'metadata' => ['type' => 'meeting'],
        ]);

        Queue::assertPushed(SynthesizeMeetingCompactionJob::class);
    }

    #[Test]
    public function it_does_not_dispatch_meeting_compaction_for_non_meeting_thoughts(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        Thought::factory()->create([
            'user_id' => $user->id,
            'metadata' => ['type' => 'idea'],
        ]);

        Queue::assertNotPushed(SynthesizeMeetingCompactionJob::class);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/ThoughtObserverTest.php`
Expected: FAIL — observer does not dispatch the job.

- [ ] **Step 3: Update the observer**

Replace `app/Observers/ThoughtObserver.php`:

```php
<?php

namespace App\Observers;

use App\Jobs\RefreshWorkingMemoryIncremental;
use App\Jobs\SynthesizeMeetingCompactionJob;
use App\Models\Thought;

class ThoughtObserver
{
    public function created(Thought $thought): void
    {
        if ($thought->user_id === null) {
            return;
        }

        if ($this->isMeetingThought($thought)) {
            SynthesizeMeetingCompactionJob::dispatch($thought->id);
        }

        RefreshWorkingMemoryIncremental::dispatch($thought->id);
    }

    public function updated(Thought $thought): void
    {
        if ($thought->user_id === null) {
            return;
        }

        if (! $thought->wasChanged([
            'content',
            'metadata',
            'source',
            'source_metadata',
            'parent_id',
            'is_visible_in_stream',
            'visibility_reason',
        ])) {
            return;
        }

        if ($this->isMeetingThought($thought) && $thought->wasChanged(['content', 'metadata'])) {
            SynthesizeMeetingCompactionJob::dispatch($thought->id);
        }

        RefreshWorkingMemoryIncremental::dispatch($thought->id);
    }

    private function isMeetingThought(Thought $thought): bool
    {
        return data_get($thought->metadata, 'type') === 'meeting';
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test tests/Feature/ThoughtObserverTest.php`
Expected: PASS (both methods).

- [ ] **Step 5: Commit**

```bash
git add app/Observers/ThoughtObserver.php tests/Feature/ThoughtObserverTest.php
git commit -m "feat(working-memory): dispatch meeting compaction job on meeting thoughts"
```

---

### Task 11: Compaction permalink route

**Files:**
- Create: `app/Http/Controllers/MemoryCompactionController.php`
- Create: `resources/views/memory/compactions/show.blade.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/MemoryCompactionRouteTest.php`

- [ ] **Step 1: Write failing route test**

```php
<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WorkingMemory;
use App\Models\WorkingMemoryVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class MemoryCompactionRouteTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_shows_a_compaction_to_its_owner(): void
    {
        $user = User::factory()->create();
        $memory = WorkingMemory::factory()->create([
            'user_id' => $user->id,
            'scope_type' => 'project',
            'scope_key' => 'dezeen',
        ]);
        $version = WorkingMemoryVersion::factory()->create([
            'working_memory_id' => $memory->id,
            'build_type' => 'compaction:meeting',
            'summary_markdown' => "## Summary\nWeekly check-in.",
        ]);

        $this->actingAs($user)
            ->get("/memory/project/dezeen/compactions/{$version->id}")
            ->assertOk()
            ->assertSeeText('Weekly check-in')
            ->assertSeeText('compaction:meeting');
    }

    #[Test]
    public function it_returns_404_for_other_users_compactions(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $memory = WorkingMemory::factory()->create(['user_id' => $owner->id]);
        $version = WorkingMemoryVersion::factory()->create([
            'working_memory_id' => $memory->id,
            'build_type' => 'compaction:meeting',
        ]);

        $this->actingAs($intruder)
            ->get("/memory/{$memory->scope_type}/{$memory->scope_key}/compactions/{$version->id}")
            ->assertNotFound();
    }

    #[Test]
    public function it_returns_404_for_non_compaction_versions(): void
    {
        $user = User::factory()->create();
        $memory = WorkingMemory::factory()->create([
            'user_id' => $user->id,
            'scope_type' => 'project',
            'scope_key' => 'dezeen',
        ]);
        $version = WorkingMemoryVersion::factory()->create([
            'working_memory_id' => $memory->id,
            'build_type' => 'consolidated',
        ]);

        $this->actingAs($user)
            ->get("/memory/project/dezeen/compactions/{$version->id}")
            ->assertNotFound();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/MemoryCompactionRouteTest.php`
Expected: FAIL — route does not exist.

- [ ] **Step 3: Implement the controller**

```php
<?php

namespace App\Http\Controllers;

use App\Models\WorkingMemoryVersion;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MemoryCompactionController extends Controller
{
    public function show(Request $request, string $scopeType, string $scopeKey, string $versionId): View
    {
        $userId = (int) $request->user()->id;

        $version = WorkingMemoryVersion::query()
            ->whereHas('workingMemory', function ($query) use ($userId, $scopeType, $scopeKey): void {
                $query->where('user_id', $userId)
                    ->where('scope_type', $scopeType)
                    ->where('scope_key', $scopeKey);
            })
            ->where('id', $versionId)
            ->where('build_type', 'like', 'compaction:%')
            ->with('inputs.thought')
            ->firstOrFail();

        return view('memory.compactions.show', [
            'version' => $version,
            'scopeType' => $scopeType,
            'scopeKey' => $scopeKey,
        ]);
    }
}
```

- [ ] **Step 4: Add the route**

In `routes/web.php`, inside the existing authenticated memory route group (or a clearly comparable group; if the existing memory routes use `Route::middleware('auth')->group(...)`, add inside that), add:

```php
Route::get(
    '/memory/{scopeType}/{scopeKey}/compactions/{versionId}',
    [\App\Http\Controllers\MemoryCompactionController::class, 'show']
)->name('memory.compactions.show');
```

- [ ] **Step 5: Implement the view**

Create `resources/views/memory/compactions/show.blade.php`:

```blade
@extends('layouts.app')

@section('title', 'Compaction')

@section('content')
<div class="memory-compaction">
    <header>
        <a href="/memory/{{ $scopeType }}/{{ $scopeKey }}">← Back to working memory</a>
        <h1>{{ $version->build_type }}</h1>
        <p class="meta">
            Created {{ optional($version->created_at)->toIso8601String() }}
            · Authoring status: {{ $version->authoring_status }}
        </p>
    </header>

    <article>
        {!! \Illuminate\Support\Str::markdown((string) $version->summary_markdown) !!}
    </article>

    <section class="references">
        <h2>References</h2>
        <ul>
            @foreach (($version->references_json ?? []) as $reference)
                <li>
                    <a href="{{ $reference['url'] }}">{{ $reference['label'] }}</a>
                    <span class="ref-type">[{{ $reference['type'] }}]</span>
                </li>
            @endforeach
        </ul>
    </section>

    <section class="source-thoughts">
        <h2>Source thoughts</h2>
        <ul>
            @foreach ($version->inputs as $input)
                @if ($input->thought)
                    <li><a href="/thoughts/{{ $input->thought->id }}">{{ \Illuminate\Support\Str::limit($input->thought->content, 120) }}</a></li>
                @endif
            @endforeach
        </ul>
    </section>
</div>
@endsection
```

If `layouts.app` does not exist, use whichever layout the existing memory pages already extend (check `resources/views/memory/show.blade.php` if present, or the layout used by `MemoryController`). Do not invent a new layout.

- [ ] **Step 6: Run tests to verify they pass**

Run: `php artisan test tests/Feature/MemoryCompactionRouteTest.php`
Expected: PASS (all three methods).

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/MemoryCompactionController.php \
        resources/views/memory/compactions/show.blade.php \
        routes/web.php \
        tests/Feature/MemoryCompactionRouteTest.php
git commit -m "feat(working-memory): add compaction permalink route"
```

---

### Task 12: MCP `get_compaction` tool

**Files:**
- Modify: `app/Http/Controllers/Api/McpController.php`
- Test: `tests/Feature/McpApiTest.php`

- [ ] **Step 1: Write failing MCP test**

Append to `tests/Feature/McpApiTest.php` (use the existing test setup helpers in that file for auth + payload shape — match the style of the existing `get_working_memory` tests):

```php
#[Test]
public function get_compaction_returns_payload_for_owner(): void
{
    $user = $this->authenticateForMcp(); // existing helper used by other tests in this file
    $memory = \App\Models\WorkingMemory::factory()->create([
        'user_id' => $user->id,
        'scope_type' => 'project',
        'scope_key' => 'dezeen',
    ]);
    $version = \App\Models\WorkingMemoryVersion::factory()->create([
        'working_memory_id' => $memory->id,
        'build_type' => 'compaction:meeting',
        'summary_markdown' => "## Summary\nWeekly check-in.",
        'references_json' => [['type' => 'thought', 'url' => '/thoughts/t1', 'label' => 'standup']],
    ]);

    $response = $this->postJson('/api/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/call',
        'params' => [
            'name' => 'get_compaction',
            'arguments' => [
                'scope_type' => 'project',
                'scope_key' => 'dezeen',
                'version_id' => $version->id,
            ],
        ],
    ])->assertOk();

    $body = $response->json();
    $payload = $body['result']['content'][0]['text'] ?? null;
    $this->assertNotNull($payload);
    $decoded = json_decode($payload, true);
    $this->assertSame('compaction:meeting', $decoded['build_type']);
    $this->assertSame('project', $decoded['scope_type']);
    $this->assertSame('dezeen', $decoded['scope_key']);
    $this->assertStringContainsString('Weekly check-in', $decoded['summary_markdown']);
}
```

If `authenticateForMcp` does not exist by that name in the file, copy the MCP-auth setup the nearest `get_working_memory` test uses verbatim — do not invent helpers.

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/McpApiTest.php --filter=get_compaction`
Expected: FAIL — method `get_compaction` not handled.

- [ ] **Step 3: Add the MCP method**

In `app/Http/Controllers/Api/McpController.php`:

1. Locate the `tools/list` handler (around line 108 where `'capture_meeting'` appears) and add `'get_compaction'` to the tool name registry.

2. Add the tool descriptor in the same array where existing tools are described:

```php
[
    'name' => 'get_compaction',
    'description' => 'Read a single working-memory compaction version by id (build_type starts with `compaction:`). Returns markdown + structured sections + references for that compaction.',
    'inputSchema' => [
        'type' => 'object',
        'required' => ['scope_type', 'scope_key', 'version_id'],
        'properties' => [
            'scope_type' => ['type' => 'string'],
            'scope_key' => ['type' => 'string'],
            'version_id' => ['type' => 'string'],
        ],
    ],
],
```

3. In the `match` block dispatching tool calls (around line 768 where `'capture_meeting'` lives), add:

```php
'get_compaction' => $this->getCompaction($params),
```

4. Add the private method:

```php
/**
 * @param  array<string, mixed>  $params
 * @return array<string, mixed>
 */
private function getCompaction(array $params): array
{
    $scopeType = trim((string) ($params['scope_type'] ?? ''));
    $scopeKey = trim((string) ($params['scope_key'] ?? ''));
    $versionId = trim((string) ($params['version_id'] ?? ''));

    if ($scopeType === '' || $scopeKey === '' || $versionId === '') {
        throw new \InvalidArgumentException('scope_type, scope_key, and version_id are required.');
    }

    $userId = (int) auth()->id();

    $version = \App\Models\WorkingMemoryVersion::query()
        ->whereHas('workingMemory', function ($query) use ($userId, $scopeType, $scopeKey): void {
            $query->where('user_id', $userId)
                ->where('scope_type', $scopeType)
                ->where('scope_key', $scopeKey);
        })
        ->where('id', $versionId)
        ->where('build_type', 'like', 'compaction:%')
        ->first();

    if ($version === null) {
        throw new \InvalidArgumentException('Compaction not found.');
    }

    return [
        'version_id' => $version->id,
        'scope_type' => $scopeType,
        'scope_key' => $scopeKey,
        'build_type' => $version->build_type,
        'summary_markdown' => (string) $version->summary_markdown,
        'structured_sections' => $version->structured_sections_json ?? [],
        'references' => $version->references_json ?? [],
        'authoring_status' => (string) $version->authoring_status,
        'created_at' => $version->created_at?->toIso8601String(),
    ];
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test tests/Feature/McpApiTest.php --filter=get_compaction`
Expected: PASS.

Run: `php artisan test tests/Feature/McpApiTest.php`
Expected: full file PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Api/McpController.php tests/Feature/McpApiTest.php
git commit -m "feat(working-memory): add get_compaction MCP tool"
```

---

### Task 13: Verify end-to-end with full test suite + manual check

**Files:** none.

- [ ] **Step 1: Run the full working-memory + meeting + MCP test slice**

Run: `php artisan test --filter='WorkingMemory|Compaction|MeetingCompaction|McpApi|ThoughtObserver'`
Expected: PASS for all matched tests.

- [ ] **Step 2: Run the full suite as a regression sanity check**

Run: `php artisan test`
Expected: PASS. If anything outside the working-memory/compaction/meeting surface fails because of the schema change or observer change, fix it inline (likely candidates: factories that hard-code `thought_id` non-null, observer-dependent tests).

- [ ] **Step 3: Manual smoke check (local)**

```bash
php artisan migrate
php artisan tinker
# In tinker:
# $u = \App\Models\User::factory()->create();
# auth()->login($u);
# $t = \App\Models\Thought::factory()->create([
#     'user_id' => $u->id,
#     'content' => 'Standup 2026-05-07. Decided X. Action: Y owns Z by Friday.',
#     'metadata' => ['type' => 'meeting', 'tags' => ['project:dezeen']],
# ]);
# php artisan queue:work --once
```

Then visit `/memory/project/dezeen/compactions/<id>` (look up the `<id>` in the `working_memory_versions` table) and confirm the rendered prose matches the expected meeting-compaction shape.

- [ ] **Step 4: Final commit only if any inline fixes were made above**

```bash
git status
# If anything was fixed in step 2 or 3, stage and commit with a message describing the fix.
```

---

## Self-review

**1. Spec coverage:**

- Compactions live in `working_memory_versions` with `compaction:*` build types — covered (Tasks 5, 8).
- `working_memory_inputs` schema change with CHECK constraint — covered (Task 1).
- Compaction permalinks (`/memory/.../compactions/{id}`) — covered (Task 11).
- MCP `get_compaction` tool — covered (Task 12).
- Model-backed composer using `OpenRouterService::researchFromPrompt` — covered (Task 5, with prompt builder in Task 4).
- Evidence pack surfaces compactions to the composer — covered (Task 6).
- Meeting compaction job triggered on meeting thought capture — covered (Tasks 7, 9, 10).
- Per-source-type promotion rules in the prompt — covered (Task 4).
- Phases 4–6 (digests, research, bootstrap) and the `compaction_coverage_ratio` validator gate — explicitly out of scope for this plan; will be handled in follow-up plans.

**2. Placeholder scan:** No "TBD"/"TODO"/"add validation"/"similar to". Each test has full code; each implementation step has full code. Layout fallback in Task 11 step 5 instructs the implementer to use the existing memory layout rather than invent one — this is concrete, not a placeholder.

**3. Type consistency:** `WorkingMemoryComposerPromptBuilder::build(array $evidencePack): string` matches the call in `WorkingMemoryAiAuthorService::authorFromEvidence`. `MeetingCompactionPromptBuilder::build(Thought $meeting): string` matches the call in `SynthesizeMeetingCompactionJob::handle`. `CompactionVersionWriter::write(...)` signature matches the call in the same job. `compactionSubtype()` is consistent across model + evidence pack builder + view.
