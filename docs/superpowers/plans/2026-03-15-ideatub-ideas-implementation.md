# Ideatub Ideas, “Ideas to revisit”, and AI Research — Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implement Ideas (thoughts with `type: idea`, completed, logged_date), the “Ideas to revisit” web page and MCP list, and on-demand AI research stored as linked thoughts.

**Architecture:** Ideas and research are thoughts with metadata (`metadata.type`, `metadata.completed`, `metadata.logged_date` for ideas; `metadata.type = 'research'`, `metadata.idea_id` for research). No new tables for thoughts. User preferences for “Ideas to revisit” (limit, min_age_days) stored in a new `user_preferences` key-value table. Selection for “Ideas to revisit” is computed on load (no stored digest). Research uses existing OpenRouter chat completions; research notes are stored as separate thoughts linked by `metadata.idea_id`.

**Tech Stack:** Laravel 12, PHP 8.2+, Blade, existing OpenRouterService (embed + chat), Pest.

**Spec:** `docs/superpowers/specs/2025-03-15-ideatub-ideas-design.md`

**Mark idea as complete:** Ideas have `metadata.completed` (boolean, default `false`). The user marks an idea complete from the **Ideas list** (and optionally from idea detail): one control that **toggles** between complete and incomplete. Completed ideas are excluded from the “Ideas to revisit” list. Implementation: PATCH endpoint that merges `metadata` with `completed => !current`, preserving `type`, `logged_date`, `tags`; ThoughtPolicy `update` for authorization.

---

## File structure (create/modify overview)

| Responsibility | Files |
|----------------|--------|
| Ideas = thoughts with metadata | `app/Models/Thought.php` (scopes, helpers) |
| User preferences for revisit | `database/migrations/xxxx_create_user_preferences_table.php`, `app/Models/UserPreference.php` |
| Create idea thought with metadata | `app/Services/ThoughtCaptureService.php` (optional metadata merge); idea creation in controller + MCP |
| Ideas to revisit selection | `app/Services/IdeasToRevisitService.php` |
| Research prompt + store | `app/Services/OpenRouterService.php` (researchNote), `app/Services/ResearchService.php` or logic in controller |
| Web: ideas list, add idea, revisit page, research UI | `app/Http/Controllers/IdeaController.php` (or split), `resources/views/idea/ideas.blade.php`, `resources/views/idea/revisit.blade.php`, updates to `resources/views/idea/index.blade.php` / stream / cards |
| Preferences web UI | `app/Http/Controllers/UserPreferenceController.php` or settings section, view |
| Authorization for idea update | `app/Policies/ThoughtPolicy.php` (add `update`) |
| MCP: capture_idea, get_ideas, research_idea | `app/Http/Controllers/Api/McpController.php` |
| Routes | `routes/web.php` |
| Tests | `tests/Unit/Services/IdeasToRevisitServiceTest.php`, `tests/Feature/IdeasToRevisitPageTest.php`, `tests/Feature/IdeaCaptureTest.php`, `tests/Feature/ResearchTest.php`, `tests/Feature/McpApiTest.php` (extend) |

---

## Chunk 1: Ideas model and capture

### Task 1.1: Thought scopes and helpers for ideas

**Files:**
- Modify: `app/Models/Thought.php`

- [ ] **Step 1: Write failing test**

In `tests/Unit/Models/ThoughtTest.php` (create if missing):

```php
<?php

use App\Models\Thought;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ThoughtTest extends TestCase
{
    use RefreshDatabase;

    public function test_scope_ideas_returns_only_thoughts_with_type_idea(): void
    {
        $user = User::factory()->create();
        Thought::factory()->create(['user_id' => $user->id, 'metadata' => null]);
        Thought::factory()->create(['user_id' => $user->id, 'metadata' => ['type' => 'note']]);
        $idea = Thought::factory()->create(['user_id' => $user->id, 'metadata' => ['type' => 'idea']]);

        $ideas = Thought::query()->where('user_id', $user->id)->ideas()->get();

        $this->assertCount(1, $ideas);
        $this->assertSame($idea->id, $ideas->first()->id);
    }

    public function test_logged_date_returns_metadata_logged_date_or_created_at_date(): void
    {
        $user = User::factory()->create();
        $withLogged = Thought::factory()->create([
            'user_id' => $user->id,
            'metadata' => ['type' => 'idea', 'logged_date' => '2025-03-10'],
            'created_at' => now(),
        ]);
        $withoutLogged = Thought::factory()->create([
            'user_id' => $user->id,
            'metadata' => ['type' => 'idea'],
            'created_at' => now(),
        ]);

        $this->assertSame('2025-03-10', $withLogged->getLoggedDate());
        $this->assertSame($withoutLogged->created_at->toDateString(), $withoutLogged->getLoggedDate());
    }

    public function test_is_idea_completed_returns_true_only_when_metadata_completed_is_true(): void
    {
        $user = User::factory()->create();
        $completed = Thought::factory()->create(['user_id' => $user->id, 'metadata' => ['type' => 'idea', 'completed' => true]]);
        $incomplete = Thought::factory()->create(['user_id' => $user->id, 'metadata' => ['type' => 'idea', 'completed' => false]]);
        $noFlag = Thought::factory()->create(['user_id' => $user->id, 'metadata' => ['type' => 'idea']]);

        $this->assertTrue($completed->isIdeaCompleted());
        $this->assertFalse($incomplete->isIdeaCompleted());
        $this->assertFalse($noFlag->isIdeaCompleted());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Unit/Models/ThoughtTest.php --filter=scope_ideas`
Expected: FAIL (scopeIdeas / getLoggedDate / isIdeaCompleted not defined)

- [ ] **Step 3: Add scope and helper on Thought**

In `app/Models/Thought.php`:
- Add `scopeIdeas(Builder $query): Builder` that filters `metadata->type = 'idea'`. Use Laravel’s `$query->where('metadata->type', 'idea')` so it works on both SQLite and PostgreSQL.
- Add `getLoggedDate(): string` that returns `$this->metadata['logged_date'] ?? $this->created_at->toDateString()` (for ideas; safe for other thoughts).
- Add `isIdeaCompleted(): bool` that returns `($this->metadata['completed'] ?? false) === true` (for ideas; safe for other thoughts). Use in views and in IdeasToRevisitService to exclude completed ideas.

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Unit/Models/ThoughtTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Models/Thought.php tests/Unit/Models/ThoughtTest.php
git commit -m "feat(ideas): add Thought::scopeIdeas and getLoggedDate"
```

---

### Task 1.2: ThoughtCaptureService accepts optional idea metadata

**Files:**
- Modify: `app/Services/ThoughtCaptureService.php`

- [ ] **Step 1: Write failing test**

In `tests/Unit/Services/ThoughtCaptureServiceTest.php` (create if missing), or in `tests/Feature/` if you test via HTTP:
- Test that when creating with `idea_metadata` (type=idea, completed=false, logged_date=Y-m-d), the created thought has that metadata merged (and tags from extractMetadata still applied if present).

Example (unit test with mocked OpenRouter):

```php
$this->mock(OpenRouterService::class, function ($mock): void {
    $mock->shouldReceive('embed')->andReturn(array_fill(0, 1536, 0.1));
    $mock->shouldReceive('extractMetadata')->andReturn(['tags' => []]);
});
$result = $this->captureService->create([
    'content' => 'My idea',
    'user_id' => $user->id,
    'source' => 'web',
    'idea_metadata' => ['type' => 'idea', 'completed' => false, 'logged_date' => '2025-03-14'],
]);
$this->assertSame('idea', $result['thought']->metadata['type']);
$this->assertFalse($result['thought']->metadata['completed']);
$this->assertSame('2025-03-14', $result['thought']->metadata['logged_date']);
```

- [ ] **Step 2: Run test — expect fail**

Run: `php artisan test tests/Unit/Services/ThoughtCaptureServiceTest.php`
Expected: FAIL (idea_metadata not used)

- [ ] **Step 3: Implement**

In `ThoughtCaptureService::create()` and `createOne()`: accept optional `idea_metadata` array; when present, merge it into `$metadata` after `extractMetadata` / normalizeMetadataTags so `type`, `completed`, `logged_date` are set. Ensure `createOne` receives and merges it.

- [ ] **Step 4: Run test — expect pass**

Run: `php artisan test tests/Unit/Services/ThoughtCaptureServiceTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Services/ThoughtCaptureService.php tests/Unit/Services/ThoughtCaptureServiceTest.php
git commit -m "feat(ideas): ThoughtCaptureService accepts idea_metadata"
```

---

### Task 1.3: Web — “Add idea” form and ideas list

**Files:**
- Modify: `app/Http/Controllers/IdeaController.php`, `routes/web.php`
- Create: `resources/views/idea/ideas.blade.php`

- [ ] **Step 1: Route and controller method**

Add route: `Route::get('/ideas', [IdeaController::class, 'ideas'])->name('idea.ideas');` and `Route::post('/ideas', [IdeaController::class, 'storeIdea'])->name('ideas.store');` (or single resource-style). In `IdeaController@ideas`: query `Thought::where('user_id', auth()->id())->ideas()->orderByDesc(created_at)->paginate(20)`, return view `idea.ideas` with `ideas`. In `storeIdea`: validate `content`, optional `logged_date` (date format); call `ThoughtCaptureService::create` with `idea_metadata`: `['type' => 'idea', 'completed' => false, 'logged_date' => $request->input('logged_date') ?? now()->toDateString()]`, redirect back with success.

- [ ] **Step 2: View**

Create `resources/views/idea/ideas.blade.php`: extend `layouts.idea`, section “Ideas” (or “Ideas to revisit” link in nav later). List ideas: snippet, logged date (use `$thought->getLoggedDate()`), and a **completed** control (see Step 3). Form at top: “Add idea” — textarea content, optional date input logged_date, submit. Use same CSRF and styling patterns as `idea/index.blade.php`.

- [ ] **Step 3: Mark idea as complete (toggle)**

**Backend:** Add `ThoughtPolicy::update(User $user, Thought $thought): bool` (return `$thought->user_id === $user->id`). Register route `Route::patch('/ideas/{thought}/completed', [IdeaController::class, 'toggleCompleted'])->name('ideas.toggle-completed');`. In `IdeaController::toggleCompleted(Thought $thought)`: `$this->authorize('update', $thought)`; if `($thought->metadata['type'] ?? null) !== 'idea'` return 404 or 422; merge metadata so `completed` is the inverse of current (`! ($thought->metadata['completed'] ?? false)`), preserving existing `type`, `logged_date`, `tags` (e.g. `$thought->update(['metadata' => array_merge($thought->metadata ?? [], ['type' => 'idea', 'completed' => ! ($thought->metadata['completed'] ?? false)])])`); return redirect back with success flash, or JSON for AJAX (e.g. `['completed' => $thought->fresh()->metadata['completed']]`).

**UI:** In the ideas list, each idea row shows whether it’s complete (e.g. via `$thought->isIdeaCompleted()`). Add one control per row that **toggles** completed state:
- **Option A:** Checkbox — checked when complete. Form POST/PATCH to `ideas.toggle-completed` (method spoofing if needed). On submit, reload or replace row so the checkbox state and label stay in sync.
- **Option B:** Button “Mark complete” / “Mark incomplete” — label depends on current state; same PATCH endpoint.

Prefer Option A (checkbox) for quick scanning; ensure the form or AJAX request sends the PATCH to the correct thought id.

- [ ] **Step 4: Write feature test**

`tests/Feature/IdeaIdeasTest.php`: authenticated user can get /ideas, see empty state; post new idea, see it in list with logged_date; **PATCH idea to toggle completed — assert idea shows as completed and no longer appears in “Ideas to revisit” (or assert metadata.completed true); toggle again and assert completed false**; list shows completed flag per idea.

- [ ] **Step 5: Run tests and commit**

Run: `php artisan test tests/Feature/IdeaIdeasTest.php`
Commit: `feat(ideas): web add idea form and ideas list with toggle completed`

---

### Task 1.4: MCP tool capture_idea

**Files:**
- Modify: `app/Http/Controllers/Api/McpController.php`

- [ ] **Step 1: Register tool**

In `respondToolsList`, add tool `capture_idea`: description “Save an idea (thought with type idea, optional logged_date). Same as capture_thought plus idea metadata.” Input: `content` (required), `logged_date` (optional, ISO date string). Add `capture_idea` to `$knownMethods` and in `dispatch()` call new handler.

- [ ] **Step 2: Implement handler**

`capture_idea` params: content, optional logged_date. Validate; call `ThoughtCaptureService::create` with `idea_metadata`: `['type' => 'idea', 'completed' => false, 'logged_date' => $params['logged_date'] ?? now()->toDateString()]`, source `mcp`. Return `['id' => $thought->id]`. For chunked result return root id.

- [ ] **Step 3: Update show() methods list**

In `show()` response, add `capture_idea` to the `methods` array so connector discovery shows it.

- [ ] **Step 4: Test**

In `tests/Feature/McpApiTest.php`: add test that POST with method `capture_idea`, params content + optional logged_date, returns id and thought is stored with metadata.type idea.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Api/McpController.php tests/Feature/McpApiTest.php
git commit -m "feat(mcp): add capture_idea tool"
```

---

## Chunk 2: Ideas to revisit

### Task 2.1: User preferences migration and model

**Files:**
- Create: `database/migrations/2026_03_15_000001_create_user_preferences_table.php`
- Create: `app/Models/UserPreference.php`

- [ ] **Step 1: Migration**

Table `user_preferences`: `id` (bigIncrements), `user_id` (foreignId), `key` (string, index), `value` (text), `timestamps`. Unique on `['user_id', 'key']`.

- [ ] **Step 2: Model**

`UserPreference`: fillable `user_id`, `key`, `value`; cast `value` as needed (e.g. leave as string and decode in service). Relationship `user()` belongsTo User. Static `get(User $user, string $key, mixed $default = null)` and `set(User $user, string $key, mixed $value)`.

- [ ] **Step 3: Run migration**

Run: `php artisan migrate`
Expected: Migration ran.

- [ ] **Step 4: Unit test**

`tests/Unit/Models/UserPreferenceTest.php`: get/set for a key, default when missing.

- [ ] **Step 5: Commit**

```bash
git add database/migrations/2026_03_15_000001_create_user_preferences_table.php app/Models/UserPreference.php tests/Unit/Models/UserPreferenceTest.php
git commit -m "feat(ideas): user_preferences table and model"
```

---

### Task 2.2: IdeasToRevisitService

**Files:**
- Create: `app/Services/IdeasToRevisitService.php`
- Create: `tests/Unit/Services/IdeasToRevisitServiceTest.php`

- [ ] **Step 1: Failing test**

Test: given user with incomplete ideas of different ages (logged_date / created_at), service returns list ordered by age (oldest first or by weight), limited by user preference `ideas_to_revisit_limit` (default 15), and optionally filtered by `ideas_to_revisit_min_age_days` (exclude ideas newer than N days). Test empty list when no incomplete ideas.

- [ ] **Step 2: Implement**

`IdeasToRevisitService::forUser(User $user): array`: read preferences `ideas_to_revisit_limit` (default 15), `ideas_to_revisit_min_age_days` (optional). Query thoughts: `Thought::where('user_id', $user->id)->ideas()` and `metadata->completed != true` (or missing). Filter by min_age_days if set (logged_date or created_at date <= today - min_age_days). Order by age (e.g. order by logged_date asc or by computed “age” desc). Take(limit). Return array of Thought models (or id, content snippet, logged_date, link). Handle malformed metadata (missing type/completed) gracefully: treat as incomplete if type=idea and completed not true.

- [ ] **Step 3: Run tests**

Run: `php artisan test tests/Unit/Services/IdeasToRevisitServiceTest.php`
Expected: PASS

- [ ] **Step 4: Commit**

```bash
git add app/Services/IdeasToRevisitService.php tests/Unit/Services/IdeasToRevisitServiceTest.php
git commit -m "feat(ideas): IdeasToRevisitService selection logic"
```

---

### Task 2.3: Web — “Ideas to revisit” page and preferences UI

**Files:**
- Modify: `app/Http/Controllers/IdeaController.php` (or new RevisitController), `routes/web.php`, `resources/views/layouts/idea.blade.php`
- Create: `resources/views/idea/revisit.blade.php`, preferences view or section

- [ ] **Step 1: Revisit page**

Route: `Route::get('/ideas/revisit', [IdeaController::class, 'revisit'])->name('idea.revisit');` Method: inject `IdeasToRevisitService`, call `forUser(auth()->user())`, return view `idea.revisit` with `ideas`. View: title “Ideas to revisit”, list each idea (snippet, link to idea detail or stream), optional logged date / age. Empty state when no ideas qualify.

- [ ] **Step 2: Nav link**

In `resources/views/layouts/idea.blade.php`, add link “Ideas to revisit” to nav (e.g. next to Stream) pointing to `route('idea.revisit')`.

- [ ] **Step 3: Preferences**

Route: `Route::get('/settings/ideas-revisit', ...)->name('settings.ideas-revisit.index');` and `Route::put('/settings/ideas-revisit', ...)->name('settings.ideas-revisit.update');` (or under existing settings). Controller: read UserPreference for `ideas_to_revisit_limit`, `ideas_to_revisit_min_age_days`; on update validate and set. View: form with limit (number), min_age_days (optional number). Link from revisit page or main settings.

- [ ] **Step 4: Feature test**

`tests/Feature/IdeasToRevisitPageTest.php`: authenticated user gets revisit page; sees empty state when no incomplete ideas; with incomplete ideas sees list; with preference limit sees at most N items.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/... routes/web.php resources/views/idea/revisit.blade.php resources/views/layouts/idea.blade.php tests/Feature/IdeasToRevisitPageTest.php
git commit -m "feat(ideas): Ideas to revisit page and preferences"
```

---

### Task 2.4: MCP tool get_ideas

**Files:**
- Modify: `app/Http/Controllers/Api/McpController.php`

- [ ] **Step 1: Register and implement**

In `respondToolsList`, add `get_ideas`: description “Return the same list as the Ideas to revisit page: incomplete ideas weighted by age, bounded by user preferences.” Input: none (or optional limit override). In `dispatch()`, call `getIdeas($params)`: use `IdeasToRevisitService::forUser(auth()->user())`, return array of `['id', 'content' (snippet), 'logged_date', 'created_at']` for Cursor. Add to `$knownMethods` and `respondToolsCall` allowlist.

- [ ] **Step 2: Test**

In `tests/Feature/McpApiTest.php`: call `get_ideas`, assert response contains list structure; with no ideas assert empty list.

- [ ] **Step 3: Commit**

```bash
git add app/Http/Controllers/Api/McpController.php tests/Feature/McpApiTest.php
git commit -m "feat(mcp): add get_ideas tool"
```

---

## Chunk 3: AI research

### Task 3.1: OpenRouterService research note method

**Files:**
- Modify: `app/Services/OpenRouterService.php`
- Create or extend: `tests/Unit/Services/OpenRouterServiceTest.php`

- [ ] **Step 1: Failing test**

Mock HTTP; call `researchNote('Given this idea: build a small SaaS.')`; assert request to CHAT_URL with prompt containing “Given this idea” and “research note”, “next steps”; assert return is non-empty string.

- [ ] **Step 2: Implement**

Add method `researchNote(string $ideaContent, ?string $existingResearch = null): string`. Build user message: “Given this idea: [content]. Produce a short research note: 2–4 sentences on what’s relevant, key considerations, and 2–3 concrete next steps. Be concise.” If `$existingResearch` provided, append “Existing research: [text]. You may extend or refresh it.” Use same CHAT_URL, model from config (e.g. metadata_model), max_tokens ~512. Return `choices.0.message.content` or throw on failure.

- [ ] **Step 3: Run test**

Run: `php artisan test tests/Unit/Services/OpenRouterServiceTest.php`
Expected: PASS

- [ ] **Step 4: Commit**

```bash
git add app/Services/OpenRouterService.php tests/Unit/Services/OpenRouterServiceTest.php
git commit -m "feat(research): OpenRouterService::researchNote"
```

---

### Task 3.2: Create and link research thought

**Files:**
- Modify: `app/Services/ThoughtCaptureService.php` or new `app/Services/ResearchService.php`

- [ ] **Step 1: Logic**

Create research thought: content = research text; metadata = `['type' => 'research', 'idea_id' => $ideaId]`; no embedding needed for v1 (or use embed for consistency). Use `Thought::create` with user_id, source `web` or `mcp`, so that all research for an idea can be queried by `Thought::where('user_id', $user)->where('metadata->type', 'research')->where('metadata->idea_id', $ideaId)`. Add Thought scope `scopeResearchForIdea(Builder $query, string $ideaId)` if helpful.

- [ ] **Step 2: Research “run” flow**

Function or controller: given idea (id or content), call `OpenRouterService::researchNote($ideaContent)`. On success: create thought with metadata type research and idea_id; return thought. On failure: throw or return error so caller can show “Research failed — try again”.

- [ ] **Step 3: “Research this idea: [the idea]” flow**

Create idea first (ThoughtCaptureService with idea_metadata), then run research and link research thought to that new idea id. If research fails, keep the idea and show “Research failed — try again” (spec v1 behaviour).

- [ ] **Step 4: Test**

Feature test: run research for existing idea, assert new thought with type research and idea_id exists; run “add idea + research” with content, assert idea and research thought created, research linked to idea.

- [ ] **Step 5: Commit**

```bash
git add app/Services/ResearchService.php app/Models/Thought.php tests/Feature/ResearchTest.php
git commit -m "feat(research): create and link research thoughts"
```

---

### Task 3.3: Web UI — research on idea (list/detail, regenerate)

**Files:**
- Modify: `app/Http/Controllers/IdeaController.php`, `resources/views/idea/ideas.blade.php` and/or idea detail, `resources/views/idea/stream_thoughts.blade.php` (if ideas shown there)
- Create or modify: idea detail view if needed

- [ ] **Step 1: Routes**

`Route::post('/ideas/{thought}/research', ...)->name('ideas.research');` and `Route::post('/ideas/research', ...)->name('ideas.research-new');` (for “Research this idea: [the idea]” — body content = idea text, creates idea then research). Controller: authorize idea belongs to user; call research service; redirect back with success or error flash.

- [ ] **Step 2: Show research on idea**

On idea list or detail: load research thoughts for each idea (e.g. `Thought::where('metadata->type','research')->where('metadata->idea_id', $idea->id)->orderByDesc('created_at')`). Show “Research: [snippet]”, “View full”, “Regenerate”. List all linked research (newest or all).

- [ ] **Step 3: Buttons**

“Research this idea” on existing idea (button to POST ideas/{id}/research). “Research this idea: [the idea]” — form with text input that POSTs to ideas/research with content; backend creates idea then research.

- [ ] **Step 4: Error handling**

If AI call fails, show “Research failed — try again”. For research-new flow, if research fails after creating idea, still redirect with error message and idea saved.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/IdeaController.php resources/views/idea/... routes/web.php
git commit -m "feat(research): web UI research actions and display"
```

---

### Task 3.4: MCP tool research_idea

**Files:**
- Modify: `app/Http/Controllers/Api/McpController.php`

- [ ] **Step 1: Register and implement**

Add tool `research_idea`: params `idea_id` (UUID, optional) or `content` (string). If idea_id: load idea, run research, create linked research thought. If content only: create idea first, then research, link to new idea. Return `['idea_id' => ..., 'research_id' => ...]` or error. Add to tools list and dispatch.

- [ ] **Step 2: Test**

In `tests/Feature/McpApiTest.php`: call research_idea with content; assert idea and research thought created and research has idea_id in metadata.

- [ ] **Step 3: Commit**

```bash
git add app/Http/Controllers/Api/McpController.php tests/Feature/McpApiTest.php
git commit -m "feat(mcp): add research_idea tool"
```

---

## Chunk 4: Error handling and polish (spec §5)

- [ ] **Task 4.1:** Validate idea metadata in web and MCP: completed boolean, logged_date ISO date. Graceful handling of missing/malformed metadata in IdeasToRevisitService and idea queries (treat missing completed as false for ideas).
- [ ] **Task 4.2:** Revisit page: if no ideas qualify, show empty state (already in Task 2.3). Ensure selection logic unit tests cover age weighting, limit, min_age.
- [ ] **Task 4.3:** Research: rate-limit or cost controls if needed (config or middleware); document in dev/spec.

---

## Execution handoff

After implementing all chunks, run full test suite and manual smoke test:

- `php artisan test`
- Web: add idea, list ideas, toggle completed, open Ideas to revisit, run research on idea, “Research this idea: [new idea]”.
- MCP: capture_idea, get_ideas, research_idea (with idea_id and with content).

**Plan complete and saved to `docs/superpowers/plans/2026-03-15-ideatub-ideas-implementation.md`. Ready to execute?**

Use **superpowers:subagent-driven-development** (if subagents available) or **superpowers:executing-plans** to implement this plan. Prefer one subagent per task with two-stage review where applicable.
