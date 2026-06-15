# Attention Pulse (Phases 1–3) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship `/pulse` operator awareness dashboard (Phase 1), extend Inbox with attention generators (Phase 2), and add durable `commitment_items` with extractors (Phase 3).

**Architecture:** Phase 1 adds `AttentionOverviewBuilder` (read-only queries over working memories, compactions, Jira thoughts) + Blade page + morning brief card. Phase 2 adds `InboxGenerator` implementations registered in `config/inbox.php`. Phase 3 adds `commitment_items` table, `CommitmentExtractor` writers on existing jobs, and switches Pulse commitments section to the new store.

**Tech Stack:** Laravel 12, Blade, Pest/PHPUnit feature tests, existing Working Memory / Inbox / Jira services.

**Spec:** [`docs/superpowers/specs/2026-06-15-attention-pulse-design.md`](../specs/2026-06-15-attention-pulse-design.md)

---

## File map

| File | Role |
|------|------|
| `config/features.php` | `attention_pulse` flag |
| `config/pulse.php` (new) | Thresholds and limits |
| `app/DataTransferObjects/AttentionItemData.php` (new) | Single pulse row |
| `app/DataTransferObjects/AttentionSectionData.php` (new) | Grouped section |
| `app/DataTransferObjects/AttentionOverviewData.php` (new) | Page payload |
| `app/Services/Attention/AttentionOverviewBuilder.php` (new) | Phase 1 aggregator |
| `app/Services/Attention/MemoryHealthAttentionQuery.php` (new) | Memory health rows |
| `app/Services/Attention/WorkingMemoryCommitmentsQuery.php` (new) | WM next actions / questions |
| `app/Services/Attention/MeetingActionItemsQuery.php` (new) | Compaction action items |
| `app/Services/Attention/JiraActivityQuery.php` (new) | Recent Jira issues |
| `app/Http/Controllers/PulseController.php` (new) | `show()` |
| `routes/web.php` | `GET /pulse` |
| `resources/views/pulse/show.blade.php` (new) | Page UI |
| `resources/views/pulse/partials/item_row.blade.php` (new) | Row partial |
| `app/Services/MorningBriefService.php` | Pulse card |
| `resources/views/layouts/idea.blade.php` | Nav link |
| `tests/Feature/PulseTest.php` (new) | Phase 1 feature tests |
| `tests/Unit/Services/Attention/*Test.php` (new) | Query unit tests |
| `app/Services/Inbox/Generators/*Generator.php` (new ×4) | Phase 2 |
| `tests/Unit/Services/Inbox/Generators/*Test.php` (new ×4) | Phase 2 |
| `database/migrations/*_create_commitment_items_table.php` (new) | Phase 3 |
| `app/Models/CommitmentItem.php` (new) | Phase 3 |
| `app/Services/Commitments/CommitmentExtractor.php` (new) | Phase 3 upsert |
| `app/Services/Commitments/CommitmentItemService.php` (new) | done/snooze |
| `app/Http/Controllers/CommitmentController.php` (new) | POST done/snooze |
| Job hooks in `SynthesizeMeetingCompactionJob`, `WorkingMemoryBuilderService`, `SyncUserJiraActivity` | Phase 3 writers |

---

## Phase 1 — Pulse page

### Task 1: Feature flag and config

**Files:**
- Create: `config/pulse.php`
- Modify: `config/features.php`
- Test: `tests/Unit/Config/FeaturesConfigTest.php`

- [ ] **Step 1: Add config file**

```php
// config/pulse.php — keys from spec (memory_stale_days, jira_days, etc.)
```

- [ ] **Step 2: Add `attention_pulse` to features.php** (`FEATURE_ATTENTION_PULSE`, default `false`)

- [ ] **Step 3: Extend FeaturesConfigTest** to assert bool config exists

- [ ] **Step 4: Commit** `feat(pulse): add feature flag and config`

---

### Task 2: Attention DTOs

**Files:**
- Create: `app/DataTransferObjects/AttentionItemData.php`
- Create: `app/DataTransferObjects/AttentionSectionData.php`
- Create: `app/DataTransferObjects/AttentionOverviewData.php`

- [ ] **Step 1: Write readonly DTOs** matching spec (`kind`, `severity`, `title`, `subtitle`, `href`, `meta`, `source_ref`)

- [ ] **Step 2: Add `AttentionOverviewData::totalCount()`** for morning brief

- [ ] **Step 3: Commit** `feat(pulse): add attention overview DTOs`

---

### Task 3: Memory health query

**Files:**
- Create: `app/Services/Attention/MemoryHealthAttentionQuery.php`
- Test: `tests/Unit/Services/Attention/MemoryHealthAttentionQueryTest.php`

- [ ] **Step 1: Write failing test** — fallback scope returns high severity row with correct `href`

- [ ] **Step 2: Implement query** using `WorkingMemory::forUser`, `WorkingMemoryScopeRowBadge`, project titles from `WorkingMemoryScopesIndexBuilder` patterns (inject or duplicate minimal project title resolver)

- [ ] **Step 3: Run test** — PASS

- [ ] **Step 4: Commit** `feat(pulse): memory health attention query`

---

### Task 4: Working memory commitments query

**Files:**
- Create: `app/Services/Attention/WorkingMemoryCommitmentsQuery.php`
- Test: `tests/Unit/Services/Attention/WorkingMemoryCommitmentsQueryTest.php`

- [ ] **Step 1: Write failing test** — project with validated `structured_sections` returns Next Actions rows

- [ ] **Step 2: Implement** — resolve canonical version via `WorkingMemoryAssembler::forScope()` or direct canonical version query; extract `Next Actions` + `Open Questions`; map to items with project memory `href`

- [ ] **Step 3: Run test** — PASS

- [ ] **Step 4: Commit** `feat(pulse): working memory commitments query`

---

### Task 5: Meeting action items query

**Files:**
- Create: `app/Services/Attention/MeetingActionItemsQuery.php`
- Test: `tests/Unit/Services/Attention/MeetingActionItemsQueryTest.php`

- [ ] **Step 1: Write failing test** — `compaction:meeting` version with Action Items returns rows with compaction detail URL

- [ ] **Step 2: Implement** — query `WorkingMemoryVersion` where `build_type like compaction:meeting`, parse `structured_sections_json['Action Items']`, build href via `MemoryCompactionController` route pattern

- [ ] **Step 3: Run test** — PASS

- [ ] **Step 4: Commit** `feat(pulse): meeting action items query`

---

### Task 6: Jira activity query

**Files:**
- Create: `app/Services/Attention/JiraActivityQuery.php`
- Test: `tests/Unit/Services/Attention/JiraActivityQueryTest.php`

- [ ] **Step 1: Write failing test** — recent jira thought returns issue row with external URL

- [ ] **Step 2: Implement** — reuse `Thought::matchingCanonicalSourceType('jira')`, order by `source_metadata->jira_updated_at`, dedupe by issue key, respect `pulse.jira_days`

- [ ] **Step 3: Run test** — PASS

- [ ] **Step 4: Commit** `feat(pulse): jira activity query`

---

### Task 7: AttentionOverviewBuilder

**Files:**
- Create: `app/Services/Attention/AttentionOverviewBuilder.php`
- Test: `tests/Unit/Services/Attention/AttentionOverviewBuilderTest.php`

- [ ] **Step 1: Write failing test** — `build($userId)` returns ordered sections, omits empty sections

- [ ] **Step 2: Wire four query services** with limits from config

- [ ] **Step 3: Run test** — PASS

- [ ] **Step 4: Commit** `feat(pulse): attention overview builder`

---

### Task 8: Pulse controller, route, view

**Files:**
- Create: `app/Http/Controllers/PulseController.php`
- Create: `resources/views/pulse/show.blade.php`
- Create: `resources/views/pulse/partials/item_row.blade.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/PulseTest.php`

- [ ] **Step 1: Write failing feature test** — guest redirected; feature off → 404; feature on → 200 with section headings

- [ ] **Step 2: Add route** in `auth` group with middleware checking `config('features.attention_pulse')`

- [ ] **Step 3: Implement controller** `show()` → `AttentionOverviewBuilder::build()`

- [ ] **Step 4: Blade view** — match Memory page styling (`max-w-3xl`, violet borders, severity badges)

- [ ] **Step 5: Run tests** — PASS

- [ ] **Step 6: Commit** `feat(pulse): pulse page route and view`

---

### Task 9: Morning brief + nav

**Files:**
- Modify: `app/Services/MorningBriefService.php`
- Modify: `resources/views/layouts/idea.blade.php`
- Test: `tests/Feature/MorningBriefTest.php`, `tests/Feature/PulseTest.php`

- [ ] **Step 1: Write failing test** — when pulse enabled and items exist, morning brief includes pulse card

- [ ] **Step 2: Inject `AttentionOverviewBuilder` into `MorningBriefService`** (or call via app container)

- [ ] **Step 3: Add nav link** when feature enabled (label: **Pulse**)

- [ ] **Step 4: Run tests** — PASS

- [ ] **Step 5: Commit** `feat(pulse): morning brief card and nav link`

---

### Phase 1 checkpoint

Run: `php artisan test --filter=Pulse`
Run: `php artisan test --filter=Attention`
Manual: enable `FEATURE_ATTENTION_PULSE=true`, visit `/pulse` with seeded fallback memory + Jira thought.

---

## Phase 2 — Inbox generators

### Task 10: WorkingMemoryFallbackGenerator

**Files:**
- Create: `app/Services/Inbox/Generators/WorkingMemoryFallbackGenerator.php`
- Test: `tests/Unit/Services/Inbox/Generators/WorkingMemoryFallbackGeneratorTest.php`
- Modify: `config/inbox.php`

- [ ] **Step 1: Failing unit test** — fallback memory produces one inbox payload with dedupe key

- [ ] **Step 2: Implement generator** (mirror `NeglectedIdeaInboxGenerator` shape)

- [ ] **Step 3: Register in config/inbox.php**

- [ ] **Step 4: Integration test** — `InboxGenerationService` creates item

- [ ] **Step 5: Commit** `feat(pulse): memory fallback inbox generator`

---

### Task 11: StaleProjectMemoryGenerator

**Files:**
- Create: `app/Services/Inbox/Generators/StaleProjectMemoryGenerator.php`
- Test: `tests/Unit/Services/Inbox/Generators/StaleProjectMemoryGeneratorTest.php`

- [ ] **Step 1–5:** Same pattern — project scope, `last_refreshed_at` > `pulse.memory_stale_days` OR `freshness_state` stale

- [ ] **Commit** `feat(pulse): stale project memory inbox generator`

---

### Task 12: MeetingActionInboxGenerator

**Files:**
- Create: `app/Services/Inbox/Generators/MeetingActionInboxGenerator.php`
- Test: `tests/Unit/Services/Inbox/Generators/MeetingActionInboxGeneratorTest.php`

- [ ] **Step 1: Reuse `MeetingActionItemsQuery`** inside generator; one inbox item per action (or batched per meeting — pick one in impl, document in test)

- [ ] **Step 2: Dedupe** `meeting_action:{version_id}:{text_hash}`

- [ ] **Step 3: Commit** `feat(pulse): meeting action inbox generator`

---

### Task 13: JiraFollowUpInboxGenerator

**Files:**
- Create: `app/Services/Inbox/Generators/JiraFollowUpInboxGenerator.php`
- Test: `tests/Unit/Services/Inbox/Generators/JiraFollowUpInboxGeneratorTest.php`

- [ ] **Step 1: Reuse `JiraActivityQuery`** with tighter window (3 days) and `jira_event_type` filter for status/comment changes

- [ ] **Step 2: Body includes issue key, summary, link**

- [ ] **Step 3: Commit** `feat(pulse): jira follow-up inbox generator`

---

### Task 14: Inbox scheduling docs

**Files:**
- Modify: `resources/content/help/working-memory-corpus-sync.md` or new `resources/content/help/attention-pulse.md`
- Modify: in-app help route if exists

- [ ] **Document** daily `inbox:generate` (or existing schedule command name — verify `routes/console.php`)

- [ ] **Commit** `docs(pulse): inbox generator cadence`

---

### Phase 2 checkpoint

Run: `php artisan test --filter=Inbox`
Manual: run inbox generation; confirm fallback memory creates triage item.

---

## Phase 3 — Commitments store

### Task 15: Migration and model

**Files:**
- Create: `database/migrations/2026_06_15_000000_create_commitment_items_table.php`
- Create: `app/Models/CommitmentItem.php`
- Test: `tests/Unit/Models/CommitmentItemTest.php`

- [ ] **Step 1: Migration** per spec schema + partial unique index on open dedupe

- [ ] **Step 2: Model** — scopes `open()`, `forUser()`, casts

- [ ] **Step 3: Commit** `feat(pulse): commitment_items schema`

---

### Task 16: CommitmentExtractor

**Files:**
- Create: `app/Services/Commitments/CommitmentExtractor.php`
- Test: `tests/Unit/Services/Commitments/CommitmentExtractorTest.php`

- [ ] **Step 1: `fromMeetingCompaction(WorkingMemoryVersion $version)`** — upsert open items for each Action Item bullet

- [ ] **Step 2: `fromWorkingMemoryVersion(WorkingMemoryVersion $version)`** — next actions + open questions when validated/external

- [ ] **Step 3: `fromJiraEvent(array $event, User $user)`** — optional active issues

- [ ] **Step 4: Dedupe** — close + reopen only when `dedupe_key` changes

- [ ] **Step 5: Commit** `feat(pulse): commitment extractor`

---

### Task 17: Job hooks

**Files:**
- Modify: `app/Jobs/SynthesizeMeetingCompactionJob.php`
- Modify: `app/Services/WorkingMemory/WorkingMemoryBuilderService.php`
- Modify: `app/Jobs/SyncUserJiraActivity.php`
- Test: `tests/Feature/CommitmentExtractionTest.php`

- [ ] **Step 1: Feature test** — meeting compaction job creates `commitment_items` rows

- [ ] **Step 2: Hook extractor** after version persist in each path

- [ ] **Step 3: Run tests** — PASS

- [ ] **Step 4: Commit** `feat(pulse): wire commitment extraction to jobs`

---

### Task 18: Commitment actions + Pulse read path

**Files:**
- Create: `app/Services/Commitments/CommitmentItemService.php`
- Create: `app/Http/Controllers/CommitmentController.php`
- Create: `app/Services/Attention/OpenCommitmentsQuery.php`
- Modify: `app/Services/Attention/AttentionOverviewBuilder.php`
- Modify: `routes/web.php`, `resources/views/pulse/show.blade.php`
- Test: `tests/Feature/CommitmentActionsTest.php`

- [ ] **Step 1: POST routes** `commitments/{item}/done`, `snooze` (mirror inbox patterns)

- [ ] **Step 2: Replace Phase 1 WM/meeting commitment sections** with `OpenCommitmentsQuery` when phase 3 flag or always after phase 3 ships

- [ ] **Step 3: Pulse UI** — done/snooze buttons on commitment rows

- [ ] **Step 4: Commit** `feat(pulse): commitment triage and pulse integration`

---

### Task 19: MCP `get_attention_overview` (optional)

**Files:**
- Modify: `app/Http/Controllers/Api/McpController.php`
- Test: `tests/Feature/McpAttentionOverviewTest.php`

- [ ] **Expose Pulse DTO as JSON** for agents

- [ ] **Commit** `feat(pulse): mcp get_attention_overview`

---

### Phase 3 checkpoint

Run full test suite for Pulse + Commitments.
Backfill: optional artisan `commitments:backfill` from recent compactions (document in plan footer if timeboxed).

---

## Verification commands

```bash
php artisan test --filter=Pulse
php artisan test --filter=Attention
php artisan test --filter=Commitment
php artisan test --filter=WorkingMemoryFallbackGenerator
```

## Post-ship operator checklist

1. Set `FEATURE_ATTENTION_PULSE=true` on production.
2. Connect Jira; run sync.
3. Confirm meeting compactions exist for recent `capture_meeting` thoughts.
4. Visit `/pulse` after morning inbox generation.
5. Review memory fallback inbox items → run `elixirr-sync` or consolidate as appropriate.

## Dependencies and risks

| Risk | Mitigation |
|------|------------|
| Pulse query cost on large corpora | Limit rows; eager-load projects; no full thought scans |
| Duplicate inbox noise | Strict dedupe keys; respect `max_new_items_per_user_per_run` |
| Commitment drift from source | Dedupe on source version id + text hash; reopen on new version |
| Feature creep into task manager | Keep done/snooze only; no projects/assignees UI |

## Estimated effort

| Phase | Engineering time |
|-------|------------------|
| Phase 1 | 3–4 days |
| Phase 2 | 2–3 days |
| Phase 3 | 5–7 days |
| **Total** | **~2–3 weeks** part-time |

---

**Next step after plan approval:** Implement Phase 1 Task 1 in a feature branch (`feature/attention-pulse-phase-1`).
