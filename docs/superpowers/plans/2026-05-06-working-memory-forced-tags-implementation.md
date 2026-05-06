# Working Memory Forced Tags Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add user-managed forced tags so selected tags always maintain tag-scoped working memory and are included in refresh/consolidation processing even when below normal thresholds.

**Architecture:** Store forced tags in `UserPreference` as normalized tag keys. Extend working-memory scope normalization/resolution to support `scope_type=tag`, `scope_key=<normalized-tag>`. Ensure incremental and scheduled consolidation include forced tags. Expose settings UI for managing forced tags.

**Tech Stack:** Laravel 12, existing `UserPreference` model, working memory services/jobs/commands, Blade settings pages, Pest/PHPUnit.

---

## File structure (creates + touches)

| Path | Responsibility |
|------|----------------|
| `app/Models/UserPreference.php` | Add constant key for forced tags preference. |
| `app/Services/WorkingMemory/ForcedTagResolver.php` | Parse/normalize forced tags from preferences. |
| `app/Services/WorkingMemory/WorkingMemoryScopeNormalizer.php` | Add `tag` scope handling and validation. |
| `app/Services/WorkingMemory/WorkingMemoryScopeResolver.php` | Include tag scopes from thought tags + forced list where applicable. |
| `app/Services/WorkingMemory/WorkingMemoryBuilderService.php` | Select source thoughts for `tag` scope. |
| `app/Console/Commands/WorkingMemoryConsolidateCommand.php` | Include forced tags in user scope list for scheduled/manual consolidation. |
| `app/Http/Controllers/WorkingMemorySettingsController.php` | Read/update forced tags alongside consolidation window. |
| `resources/views/settings/working-memory.blade.php` | Add forced tags form control and helper text. |
| `app/Http/Controllers/Api/ThoughtsApiController.php` | Accept `scope_type=tag` in validation. |
| `app/Http/Controllers/Api/McpController.php` | Accept `scope_type=tag` in method schema + validator. |
| `tests/Unit/Services/WorkingMemory/ForcedTagResolverTest.php` | Normalization/dedup tests. |
| `tests/Unit/Services/WorkingMemory/WorkingMemoryScopeResolverTest.php` | Tag scope resolution tests. |
| `tests/Unit/Services/WorkingMemory/WorkingMemoryBuilderServiceTest.php` | Tag-scope build tests. |
| `tests/Feature/WorkingMemorySettingsTest.php` | Forced tags persistence tests. |
| `tests/Feature/WorkingMemoryConsolidationCommandTest.php` | Forced tags included in queued scopes. |
| `tests/Feature/WorkingMemoryApiTest.php` + `tests/Feature/McpApiTest.php` | `tag` scope validation/happy-path tests. |

---

### Task 1: Add forced tag preference + resolver service

**Files:**
- Modify: `app/Models/UserPreference.php`
- Create: `app/Services/WorkingMemory/ForcedTagResolver.php`
- Create: `tests/Unit/Services/WorkingMemory/ForcedTagResolverTest.php`

- [ ] Add `KEY_WORKING_MEMORY_FORCED_TAGS = 'working_memory_forced_tags'` constant.
- [ ] Implement resolver methods:
  - `forUserId(int $userId): array` returns normalized unique tags.
  - `normalizeTags(array|string|null $raw): array` handles CSV/newlines/JSON-like arrays.
- [ ] Add tests for trimming, lowercase normalization, deduplication, empty filtering.
- [ ] Run: `php artisan test tests/Unit/Services/WorkingMemory/ForcedTagResolverTest.php`
- [ ] Commit.

---

### Task 2: Add `tag` scope normalization and selection behavior

**Files:**
- Modify: `app/Services/WorkingMemory/WorkingMemoryScopeNormalizer.php`
- Modify: `app/Services/WorkingMemory/WorkingMemoryBuilderService.php`
- Modify: `tests/Unit/Services/WorkingMemory/WorkingMemoryBuilderServiceTest.php`

- [ ] Extend scope normalizer to allow `tag` scope and non-empty normalized `scope_key`.
- [ ] In builder `selectThoughts()`, add `tag` branch:
  - Include thoughts where normalized `metadata.tags[]` contains `scope_key`.
  - Consolidated build obeys consolidation window; incremental uses existing recent-window behavior.
- [ ] Add tests:
  - `buildConsolidated(..., 'tag', 'ai')` includes only thoughts tagged `ai`.
  - Invalid empty tag key rejected.
- [ ] Run: `php artisan test tests/Unit/Services/WorkingMemory/WorkingMemoryBuilderServiceTest.php`
- [ ] Commit.

---

### Task 3: Scope resolver + consolidation include forced tags

**Files:**
- Modify: `app/Services/WorkingMemory/WorkingMemoryScopeResolver.php`
- Modify: `app/Console/Commands/WorkingMemoryConsolidateCommand.php`
- Modify: `tests/Unit/Services/WorkingMemory/WorkingMemoryScopeResolverTest.php`
- Modify: `tests/Feature/WorkingMemoryConsolidationCommandTest.php`

- [ ] Inject `ForcedTagResolver` into scope resolver.
- [ ] For thought events:
  - Include `tag` scopes for normalized thought tags.
  - Include forced tags that match thought tags (ensures forced tags are never missed on thought updates).
- [ ] In consolidation command:
  - Add forced tag scopes for each user during scope discovery.
  - Allow `--scope_type=tag`.
- [ ] Update tests for expected queued jobs and scope arrays.
- [ ] Run:
  - `php artisan test tests/Unit/Services/WorkingMemory/WorkingMemoryScopeResolverTest.php`
  - `php artisan test tests/Feature/WorkingMemoryConsolidationCommandTest.php`
- [ ] Commit.

---

### Task 4: Settings UI for forced tags

**Files:**
- Modify: `app/Http/Controllers/WorkingMemorySettingsController.php`
- Modify: `resources/views/settings/working-memory.blade.php`
- Modify: `tests/Feature/WorkingMemorySettingsTest.php`

- [ ] Add nullable `working_memory_forced_tags` input (textarea/chips text input).
- [ ] Save normalized tags to `UserPreference::KEY_WORKING_MEMORY_FORCED_TAGS`.
- [ ] Clear preference when input empty.
- [ ] Display currently saved forced tags and input helper text.
- [ ] Extend tests for save/clear behavior.
- [ ] Run: `php artisan test tests/Feature/WorkingMemorySettingsTest.php`
- [ ] Commit.

---

### Task 5: API + MCP support for `tag` scope

**Files:**
- Modify: `app/Http/Controllers/Api/ThoughtsApiController.php`
- Modify: `app/Http/Controllers/Api/McpController.php`
- Modify: `tests/Feature/WorkingMemoryApiTest.php`
- Modify: `tests/Feature/McpApiTest.php`

- [ ] Extend validators/enums from `global|project|insights` to `global|project|insights|tag`.
- [ ] Add happy-path test for `scope_type=tag` and matching tagged thought.
- [ ] Keep existing validation behavior for missing/invalid inputs.
- [ ] Run:
  - `php artisan test tests/Feature/WorkingMemoryApiTest.php`
  - `php artisan test tests/Feature/McpApiTest.php --filter=working_memory`
- [ ] Commit.

---

### Task 6: Focused regression run + docs touch-up

**Files:**
- Modify: `README.md` (scope examples include `tag`)
- Modify: `docs/mcp-integration-guide.md` (scope_type includes `tag`)

- [ ] Run focused suite:
  - `php artisan test tests/Unit/Services/WorkingMemory tests/Feature/WorkingMemoryApiTest.php tests/Feature/WorkingMemoryConsolidationCommandTest.php tests/Feature/WorkingMemorySettingsTest.php tests/Feature/McpApiTest.php --filter=working_memory`
- [ ] Run formatter: `./vendor/bin/pint --dirty`
- [ ] Commit docs and any format updates.

---

## Notes / constraints

- Forced tags are a **priority include**, not a replacement for thresholding:
  - Natural tag selection rules still apply.
  - Forced list guarantees inclusion in scheduled processing and eligible event-driven updates.
- Do not delete historical `working_memory_versions` when unforcing a tag.
- Keep `scope_key` normalization consistent (trim + lowercase) across settings, scope normalizer, API, MCP, and command logic.
