# Working memory thread links (A + C) implementation plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Persist optional `thought_id` and `url` on `active_threads_json`, `open_questions_json`, and `next_actions_json`; derive links from composer citations (C) and heuristic thought pools (A); expose enriched rows via API and markdown fallback UI.

**Architecture:** Add a small **citation resolver** that picks a primary thought link from structured bullet citations (mirrors spec precedence). **WorkingMemoryBuilderService** builds legacy JSON lists from structured sections using bullet text + resolver output; heuristic paths update **WorkingMemoryAssembler** and **MemoryInsightsService**. **WorkingMemoryAssembler** augments markdown rendering for legacy summary lines and optionally **enriches** persisted rows with derived `url` on read when only `thought_id` is stored. No DB migration (JSON columns already untyped arrays).

**Tech stack:** Laravel 12, PHP 8.2+, PHPUnit/Pest tests, Blade (`memory.show` / `insights` markdown fallback).

**Spec:** [`docs/superpowers/specs/2026-05-08-working-memory-thread-links-design.md`](../specs/2026-05-08-working-memory-thread-links-design.md)

---

## File structure

| File | Responsibility |
| --- | --- |
| `app/Services/WorkingMemory/WorkingMemoryLegacyRowCitationResolver.php` (new) | Pure functions: given normalized citations, return `?array{thought_id: string, url?: string}` per spec precedence |
| `app/Services/WorkingMemory/WorkingMemoryAssembler.php` | Heuristic payload rows with `thought_id`; partitioned pools; markdown bullets as links; optional read-time URL enrichment |
| `app/Services/WorkingMemory/WorkingMemoryBuilderService.php` | Composer path: map `Recent Changes` / `Open Questions` / `Next Actions` bullets to legacy JSON with citations |
| `app/Services/WorkingMemory/MemoryInsightsService.php` | Attach `thought_id` (and optional `url`) to insights `active_threads` |
| `tests/Unit/Services/WorkingMemory/WorkingMemoryLegacyRowCitationResolverTest.php` (new) | Resolver coverage |
| `tests/Unit/Services/WorkingMemory/WorkingMemoryAssemblerTest.php` | Extend or create if missing for heuristic rows + markdown |
| `tests/Unit/Services/WorkingMemory/WorkingMemoryBuilderServiceTest.php` | Assert composer build persists linked legacy rows when citations present |
| `tests/Feature/WorkingMemoryApiTest.php` | Optional keys on list items don’t break JSON structure |

---

### Task 1: `WorkingMemoryLegacyRowCitationResolver`

**Files:**
- Create: `app/Services/WorkingMemory/WorkingMemoryLegacyRowCitationResolver.php`
- Create: `tests/Unit/Services/WorkingMemory/WorkingMemoryLegacyRowCitationResolverTest.php`

**Behavior:** Input: `array<int, array<string, mixed>>` (raw citation rows). Output: `null` or `array{thought_id: string, url?: string}`.

**Precedence (match spec):**

1. First citation with `type === 'thought'` and non-empty `thought_id` (accept UUID string).
2. Else first citation with `type === 'thought'` and `url` containing path `/thoughts/{uuid}` — extract UUID with a strict UUID v4 regex or Laravel’s UUID validation after extracting the last path segment.
3. Else first citation (any type) with `url` whose path is `/thoughts/{uuid}`.

If `thought_id` is found but `url` empty, leave `url` absent (caller may derive). Strip whitespace on URLs; reject `javascript:` and parent-directory path segments (mirror `WorkingMemoryBuilderService::isSupportedSectionReferenceUrl` logic in spirit — reuse if extracted to shared helper later; YAGNI: duplicate minimal path check inside resolver or inject callback).

- [ ] **Step 1: Write failing tests**

```php
<?php

namespace Tests\Unit\Services\WorkingMemory;

use App\Services\WorkingMemory\WorkingMemoryLegacyRowCitationResolver;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WorkingMemoryLegacyRowCitationResolverTest extends TestCase
{
    #[Test]
    public function it_returns_null_for_empty_citations(): void
    {
        $resolver = new WorkingMemoryLegacyRowCitationResolver;

        $this->assertNull($resolver->resolvePrimaryThought([]));
    }

    #[Test]
    public function it_prefers_thought_type_with_thought_id(): void
    {
        $resolver = new WorkingMemoryLegacyRowCitationResolver;

        $result = $resolver->resolvePrimaryThought([
            [
                'type' => 'thought',
                'thought_id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
                'url' => '/thoughts/aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
                'label' => 'Note',
            ],
        ]);

        $this->assertSame('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', $result['thought_id']);
        $this->assertSame('/thoughts/aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', $result['url']);
    }

    #[Test]
    public function it_extracts_uuid_from_thought_url_when_thought_id_missing(): void
    {
        $resolver = new WorkingMemoryLegacyRowCitationResolver;

        $result = $resolver->resolvePrimaryThought([
            [
                'type' => 'thought',
                'url' => '/thoughts/bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
                'label' => 'Note',
            ],
        ]);

        $this->assertSame('bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', $result['thought_id']);
    }
}
```

- [ ] **Step 2: Run tests — expect failures**

Run: `php artisan test tests/Unit/Services/WorkingMemory/WorkingMemoryLegacyRowCitationResolverTest.php`

Expected: FAIL (class missing).

- [ ] **Step 3: Implement resolver**

Create `WorkingMemoryLegacyRowCitationResolver` with public method `resolvePrimaryThought(array $citations): ?array` returning `['thought_id' => string, 'url' => ?string]` (omit `url` key when empty).

- [ ] **Step 4: Run tests — expect pass**

Run: `php artisan test tests/Unit/Services/WorkingMemory/WorkingMemoryLegacyRowCitationResolverTest.php`

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/WorkingMemory/WorkingMemoryLegacyRowCitationResolver.php tests/Unit/Services/WorkingMemory/WorkingMemoryLegacyRowCitationResolverTest.php
git commit -m "feat(working-memory): add citation resolver for legacy row thought links"
```

---

### Task 2: Composer path — legacy lists from structured sections

**Files:**
- Modify: `app/Services/WorkingMemory/WorkingMemoryBuilderService.php` (`payloadFromStructuredSections`, constructor injection)
- Modify: `tests/Unit/Services/WorkingMemory/WorkingMemoryBuilderServiceTest.php`

**Behavior:**

- Inject `WorkingMemoryLegacyRowCitationResolver`.
- Replace text-only mapping with iteration over structured bullet arrays:
  - For each section (`Recent Changes`, `Open Questions`, `Next Actions`), walk entries (same shape as `sectionTextsForPayload`: string or `['text' => ..., 'citations' => ...]`).
  - Text field: existing trim/limit behavior for the display string (`title` / `question` / `action`).
  - Call resolver on `citations`; merge `thought_id` and `url` into the row when non-null.
  - Dedupe by display string within each section (first bullet wins for link), preserving current `take(8)` limits.

- [ ] **Step 1: Add focused unit/integration test** (extend builder test file): build consolidated WM with mocked composer returning structured sections where `Recent Changes` has one bullet with citations referencing a thought URL; assert `active_threads_json[0]` contains `thought_id` or `url`.

Use existing test patterns in `WorkingMemoryBuilderServiceTest` (`bindValidatedComposerOpenRouter`, factories). If mocking composer output is heavy, call `payloadFromStructuredSections` via reflection in a unit test with fake `$sections` + thought collection — faster and isolated:

```php
$sections = [
    'Recent Changes' => [[
        'text' => 'Example thread text',
        'citations' => [[
            'type' => 'thought',
            'url' => '/thoughts/'.Thought::factory()->make()->id,
            'label' => 'Src',
        ]],
    ]],
    // minimal keys for other sections required by method or pass empty arrays
];
```

Instantiate service from container, use `ReflectionMethod` on private `payloadFromStructuredSections` (pattern already used in this test class).

- [ ] **Step 2: Implement mapping** in `WorkingMemoryBuilderService`.

- [ ] **Step 3: Run targeted tests**

Run: `php artisan test tests/Unit/Services/WorkingMemory/WorkingMemoryBuilderServiceTest.php`

Expected: PASS including new case.

- [ ] **Step 4: Commit**

```bash
git add app/Services/WorkingMemory/WorkingMemoryBuilderService.php tests/Unit/Services/WorkingMemory/WorkingMemoryBuilderServiceTest.php
git commit -m "feat(working-memory): derive legacy list links from composer structured sections"
```

---

### Task 3: Heuristic assembler + partitioned pools

**Files:**
- Modify: `app/Services/WorkingMemory/WorkingMemoryAssembler.php`

**Partitioning rule (from spec):**

1. Sort thoughts by `created_at` descending (stable).
2. **Open questions:** First N thoughts whose content contains `?` (after trim), unique by thought id, max 5.
3. **Active threads:** From remaining thoughts (exclude ids used in open questions), take content-derived titles, unique title, max 5, each row includes `thought_id`.
4. **Next actions:** From thoughts not in open questions (same exclusion as threads pool), max 5 — **exclude** thoughts already chosen for active threads to reduce duplicate titles across sections (same meeting appearing under threads and actions).

Adjust placeholder rows (`No active threads…`) to remain title-only without ids.

Update PHPDoc array shapes for `assemblePayload`, `renderSummary`, `forScope` return arrays to include optional `thought_id` and `url`.

- [ ] **Step 1: Unit tests** for `assemblePayload` (new file `tests/Unit/Services/WorkingMemory/WorkingMemoryAssemblerTest.php` if absent): create 3–4 thoughts with distinct contents; assert question thought appears only in `open_questions` with `thought_id`; assert no duplicate `thought_id` across sections for overlapping content scenarios.

- [ ] **Step 2: Implement** refactoring `assemblePayload`.

- [ ] **Step 3: Run tests**

Run: `php artisan test tests/Unit/Services/WorkingMemory/WorkingMemoryAssemblerTest.php`

- [ ] **Step 4: Commit**

```bash
git add app/Services/WorkingMemory/WorkingMemoryAssembler.php tests/Unit/Services/WorkingMemory/WorkingMemoryAssemblerTest.php
git commit -m "feat(working-memory): attach thought ids to heuristic legacy lists with partitioning"
```

---

### Task 4: Insights synthesis rows

**Files:**
- Modify: `app/Services/WorkingMemory/MemoryInsightsService.php` (`synthesizePersistable`)
- Modify: `tests/` — locate or add `MemoryInsightsServiceTest` if present; else feature test via insights WM build

When building `$activeThreads` from sorted thoughts, push:

```php
['title' => $title, 'thought_id' => (string) $thought->id]
```

Optional: derive `url` with `URL::route('thoughts.show', ['thought' => $thought->id])` here or only in assembler enrichment — pick one place to avoid duplication (prefer single enrichment in Task 5).

- [ ] **Step 1: Test + implementation + commit**

---

### Task 5: Markdown rendering + read-time URL enrichment

**Files:**
- Modify: `app/Services/WorkingMemory/WorkingMemoryAssembler.php`

**Markdown:** Replace naive `- text` bullets with markdown links when `url` or derivable `thought_id`:

- Add private `legacyListItemMarkdown(string $displayText, array $row): string` used by extended `renderBullets`-style helpers per section (`title`, `question`, `action` keys).
- Format: `- [` . escaped_label . `](` . url . `)` using bracket escaping for display text (replace `\`, `[`, `]` as needed for CommonMark — minimally escape `]` and `[` in label).

**Read-time enrichment:** Add private method `enrichLegacyRowsWithUrls(array $rows, string $labelKey): array` mapping each row: if `url` missing and `thought_id` present, set `url` to `route('thoughts.show', ['thought' => $row['thought_id']])` (requires valid UUID). Apply to `active_threads`, `open_questions`, `next_actions` in `forScope()` before returning payload so API and Blade receive URLs without storing duplicates on older rows.

- [ ] **Step 1: Unit test** markdown output contains `[` link syntax for a fake row with `url`.

- [ ] **Step 2: Unit test** enrichment adds `url` when only `thought_id` set.

- [ ] **Step 3: Implement**

- [ ] **Step 4: Run** `php artisan test tests/Unit/Services/WorkingMemory/WorkingMemoryAssemblerTest.php`

- [ ] **Step 5: Commit**

```bash
git add app/Services/WorkingMemory/WorkingMemoryAssembler.php tests/Unit/Services/WorkingMemory/WorkingMemoryAssemblerTest.php
git commit -m "feat(working-memory): markdown links and API enrichment for legacy rows"
```

---

### Task 6: API regression + docs touch-up

**Files:**
- Modify: `tests/Feature/WorkingMemoryApiTest.php` — after building WM with a thought fixture, assert at least one `active_threads` item is an array that **may** contain optional keys `thought_id`, `url` (use `assertArrayHasKey` only when fixture guarantees link).

- Modify: `docs/superpowers/specs/2026-05-08-working-memory-thread-links-design.md` — set **Status** to `Approved — implemented` when done.

- [ ] **Step 1: Run full WM-related tests**

Run: `php artisan test --filter=WorkingMemory`

Expected: all green.

- [ ] **Step 2: Commit**

```bash
git add tests/Feature/WorkingMemoryApiTest.php docs/superpowers/specs/2026-05-08-working-memory-thread-links-design.md
git commit -m "test(working-memory): API coverage for linked legacy rows; spec status"
```

---

## Spec coverage self-review

| Spec requirement | Task |
| --- | --- |
| (C) Primary citation resolution on composer bullets | Task 1–2 |
| (A) Heuristic thought ids + partitioning | Task 3 |
| (A) Insights `thought_id` on threads | Task 4 |
| Optional `url` / consumer precedence | Task 5 |
| API passes optional keys | Task 5–6 |
| Markdown fallback UI links | Task 5 |
| Backward compatibility (plain rows) | Tasks 3–5 (placeholders unchanged) |

No placeholder steps; gaps covered.

---

## Execution handoff

Plan complete and saved to `docs/superpowers/plans/2026-05-08-working-memory-thread-links-implementation.md`. Two execution options:

**1. Subagent-driven (recommended)** — dispatch a fresh subagent per task, review between tasks.

**2. Inline execution** — execute tasks in this session using executing-plans with checkpoints.

Which approach do you want?
