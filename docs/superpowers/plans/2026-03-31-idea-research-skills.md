# Idea Research Skills Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add IdeaTub-managed, user-owned research skills so ideas can automatically or manually start linked research runs with bounded customisation and central cost controls.

**Architecture:** Add three new persisted concepts: `ResearchSkill`, immutable `ResearchSkillVersion`, and `ResearchRun`. Route all idea research requests through a new workflow runner that snapshots skill version settings at enqueue time, executes one bounded workflow (`quick_brief` in v1), and saves the final output back into the existing research thought flow. Expose user controls through a small settings surface plus idea-page status/actions, while keeping automation, concurrency, and budgeting in IdeaTub-owned services.

**Tech Stack:** Laravel 12, PHP 8.2+, Blade, queues/events/jobs, Pest/PHPUnit feature tests, SQLite in test/dev.

---

## File Structure

### New files

- `app/Models/ResearchSkill.php`  
  User-owned skill container with relationships to versions and runs.

- `app/Models/ResearchSkillVersion.php`  
  Immutable behavioural snapshot for each saved skill revision.

- `app/Models/ResearchRun.php`  
  Tracks queued/running/completed/failed/cancelled execution state per idea.

- `database/factories/ResearchSkillFactory.php`
- `database/factories/ResearchSkillVersionFactory.php`
- `database/factories/ResearchRunFactory.php`

- `app/Services/Research/ResearchSkillManager.php`  
  Creates/updates skills, versions, default-skill exclusivity, and eligibility checks.

- `app/Services/Research/ResearchPromptBuilder.php`  
  Builds bounded prompt payloads from idea content, selected context, and skill version settings.

- `app/Services/Research/ResearchWorkflowRunner.php`  
  Resolves run state transitions, executes `quick_brief`, and persists final research output.

- `app/Jobs/RunResearchRun.php`  
  Queue job that executes one `ResearchRun`.

- `app/Http/Controllers/ResearchSkillSettingsController.php`  
  Settings UI for listing, creating, editing, and toggling defaults/automation.

- `app/Http/Requests/StoreResearchSkillRequest.php`  
  Validation for creating skills.

- `app/Http/Requests/UpdateResearchSkillRequest.php`  
  Validation for editing skills.

- `app/View/Presenters/Ideas/IdeaResearchStatusPresenter.php`  
  Shapes active-run and latest-run status for the ideas list/detail UI.

- `resources/views/settings/research-skills/index.blade.php`  
  List skills and show current default/automation controls.

- `resources/views/settings/research-skills/_form.blade.php`  
  Shared skill create/edit form.

- `resources/views/settings/research-skills/create.blade.php`  
  New skill page.

- `resources/views/settings/research-skills/edit.blade.php`  
  Edit skill page.

- `database/migrations/2026_03_31_000001_create_research_skills_table.php`
- `database/migrations/2026_03_31_000002_create_research_skill_versions_table.php`
- `database/migrations/2026_03_31_000003_create_research_runs_table.php`

- `tests/Feature/ResearchSkillSettingsControllerTest.php`
- `tests/Feature/ResearchRunWorkflowTest.php`
- `tests/Unit/Services/ResearchPromptBuilderTest.php`
- `tests/Unit/Services/ResearchSkillManagerTest.php`
- `tests/Unit/Services/ResearchWorkflowRunnerTest.php`

### Existing files to modify

- `app/Services/ResearchService.php`  
  Stop directly running/saving research from controller/MCP entry points; create ideas and delegate to the new run model.

- `app/Http/Controllers/IdeaController.php`  
  Wire `Save idea`, `Save + research`, manual rerun, and idea-page status to `ResearchRun`.

- `app/Http/Controllers/Api/McpController.php`  
  Route `research_idea` through the new run creation flow while preserving current MCP surface.

- `app/Events/IdeaResearchRequested.php`  
  Replace or narrow this event to carry `ResearchRun` IDs instead of raw thoughts, or remove it if the queue job becomes the only dispatch path.

- `app/Listeners/RunResearchForIdeaListener.php`  
  Replace with `ResearchRun` execution logic or retire it after the queue job takes over.

- `app/Providers/AppServiceProvider.php`  
  Remove obsolete event listener wiring if the new job path supersedes it.

- `app/Models/Thought.php`  
  Add relationships/helpers for active/latest research runs if needed by UI queries.

- `app/Models/User.php`  
  Add relationships for skills and runs.

- `resources/views/idea/ideas.blade.php`  
  Replace the split add/research forms with the new composer actions and default-skill indicator.

- `resources/views/idea/partials/ideas_list.blade.php`  
  Render active run status, latest skill name, and rerun action from presenter data.

- `resources/views/layouts/idea.blade.php`  
  Add settings navigation link if the settings surface needs one.

- `routes/web.php`  
  Add settings routes for research skills and update idea routes if new endpoints are needed.

- `tests/Feature/ResearchServiceTest.php`
- `tests/Feature/IdeaIdeasTest.php`
- `tests/Feature/EmailResearchControllerTest.php`
- `app/Models/UserPreference.php`

### Existing docs to reference while implementing

- `docs/superpowers/specs/2026-03-31-idea-research-skills-design.md`
- `docs/superpowers/specs/2026-03-15-research-prompt-from-file-design.md`

---

### Task 1: Add the persisted research skill and run models

**Files:**
- Create: `database/migrations/2026_03_31_000001_create_research_skills_table.php`
- Create: `database/migrations/2026_03_31_000002_create_research_skill_versions_table.php`
- Create: `database/migrations/2026_03_31_000003_create_research_runs_table.php`
- Create: `app/Models/ResearchSkill.php`
- Create: `app/Models/ResearchSkillVersion.php`
- Create: `app/Models/ResearchRun.php`
- Create: `database/factories/ResearchSkillFactory.php`
- Create: `database/factories/ResearchSkillVersionFactory.php`
- Create: `database/factories/ResearchRunFactory.php`
- Modify: `app/Models/User.php`
- Modify: `app/Models/Thought.php`
- Test: `tests/Feature/ResearchRunWorkflowTest.php`

- [ ] **Step 1: Write the failing migration/model coverage test**

```php
public function test_user_can_have_skills_versions_and_runs_linked_to_an_idea(): void
{
    $user = User::factory()->create();
    $idea = Thought::factory()->create([
        'user_id' => $user->id,
        'metadata' => ['type' => 'idea', 'completed' => false, 'logged_date' => now()->toDateString()],
    ]);

    $skill = ResearchSkill::query()->create([
        'user_id' => $user->id,
        'name' => 'Quick brief',
        'description' => 'Default quick brief',
        'is_manual_enabled' => true,
        'allow_auto_run' => true,
        'is_default' => true,
        'is_active' => true,
        'latest_version_number' => 1,
    ]);

    $version = ResearchSkillVersion::query()->create([
        'research_skill_id' => $skill->id,
        'version' => 1,
        'workflow_type' => 'quick_brief',
        'instructions' => 'Focus on concrete next steps.',
        'context_options' => ['idea', 'tags'],
        'output_shape' => ['summary', 'risks', 'next_steps'],
        'intensity' => 'standard',
        'is_auto_run_eligible' => true,
    ]);

    $run = ResearchRun::query()->create([
        'user_id' => $user->id,
        'idea_thought_id' => $idea->id,
        'research_skill_id' => $skill->id,
        'research_skill_version_id' => $version->id,
        'status' => 'queued',
        'workflow_type_snapshot' => 'quick_brief',
        'context_options_snapshot' => ['idea', 'tags'],
        'output_shape_snapshot' => ['summary', 'risks', 'next_steps'],
        'intensity_snapshot' => 'standard',
    ]);

    $this->assertTrue($user->researchSkills->contains($skill));
    $this->assertTrue($idea->researchRuns->contains($run));
    $this->assertSame($version->id, $run->skillVersion->id);
}
```

- [ ] **Step 2: Run the focused test to verify it fails**

Run: `php artisan test tests/Feature/ResearchRunWorkflowTest.php --filter=skills_versions_and_runs`

Expected: FAIL with missing tables/classes such as `ResearchSkill` or `research_runs`.

- [ ] **Step 3: Write the minimal migrations and models**

```php
// research_skills columns
$table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
$table->string('name');
$table->string('description')->default('');
$table->boolean('is_manual_enabled')->default(true);
$table->boolean('allow_auto_run')->default(false);
$table->boolean('is_default')->default(false);
$table->boolean('is_active')->default(true);
$table->unsignedInteger('latest_version_number')->default(0);

// research_skill_versions columns
$table->foreignUuid('research_skill_id')->constrained()->cascadeOnDelete();
$table->unsignedInteger('version');
$table->string('workflow_type');
$table->text('instructions')->default('');
$table->json('context_options')->nullable();
$table->json('output_shape')->nullable();
$table->string('intensity');
$table->boolean('is_auto_run_eligible')->default(false);

// research_runs columns
$table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
$table->uuid('idea_thought_id');
$table->uuid('research_skill_id');
$table->uuid('research_skill_version_id');
$table->string('source')->default('web');
$table->string('status');
$table->string('workflow_type_snapshot');
$table->json('context_options_snapshot')->nullable();
$table->json('output_shape_snapshot')->nullable();
$table->string('intensity_snapshot');
$table->unsignedInteger('current_stage')->default(0);
$table->unsignedInteger('total_stages')->default(1);
$table->json('usage_metadata')->nullable();
$table->uuid('final_research_thought_id')->nullable();
$table->text('error_summary')->nullable();
```

- [ ] **Step 4: Run the focused test to verify it passes**

Run: `php artisan test tests/Feature/ResearchRunWorkflowTest.php --filter=skills_versions_and_runs`

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add database/migrations/2026_03_31_000001_create_research_skills_table.php database/migrations/2026_03_31_000002_create_research_skill_versions_table.php database/migrations/2026_03_31_000003_create_research_runs_table.php database/factories/ResearchSkillFactory.php database/factories/ResearchSkillVersionFactory.php database/factories/ResearchRunFactory.php app/Models/ResearchSkill.php app/Models/ResearchSkillVersion.php app/Models/ResearchRun.php app/Models/User.php app/Models/Thought.php tests/Feature/ResearchRunWorkflowTest.php
git commit -m "feat: add research skill and run models"
```

### Task 2: Build the skill manager and immutable versioning rules

**Files:**
- Create: `app/Services/Research/ResearchSkillManager.php`
- Test: `tests/Unit/Services/ResearchSkillManagerTest.php`
- Modify: `app/Models/ResearchSkill.php`
- Modify: `app/Models/ResearchSkillVersion.php`

- [ ] **Step 1: Write the failing service tests**

```php
public function test_creating_default_skill_unsets_previous_default_for_user(): void
{
    $user = User::factory()->create();
    $manager = app(ResearchSkillManager::class);

    $first = $manager->createSkill($user, [
        'name' => 'Quick brief',
        'description' => 'Default',
        'workflow_type' => 'quick_brief',
        'instructions' => 'Short and useful.',
        'context_options' => ['idea'],
        'output_shape' => ['summary', 'next_steps'],
        'intensity' => 'standard',
        'is_manual_enabled' => true,
        'allow_auto_run' => true,
        'is_default' => true,
    ]);

    $second = $manager->createSkill($user, [
        'name' => 'Manual deep',
        'description' => 'Manual only',
        'workflow_type' => 'quick_brief',
        'instructions' => 'More evidence.',
        'context_options' => ['idea', 'tags'],
        'output_shape' => ['summary', 'evidence', 'risks'],
        'intensity' => 'thorough',
        'is_manual_enabled' => true,
        'allow_auto_run' => true,
        'is_default' => true,
    ]);

    $this->assertFalse($first->fresh()->is_default);
    $this->assertTrue($second->fresh()->is_default);
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test tests/Unit/Services/ResearchSkillManagerTest.php --filter=default_skill`

Expected: FAIL because `ResearchSkillManager` does not exist or does not enforce exclusivity.

- [ ] **Step 3: Implement minimal manager logic**

```php
public function updateSkill(User $user, ResearchSkill $skill, array $data): ResearchSkill
{
    return DB::transaction(function () use ($user, $skill, $data) {
        if (! empty($data['is_default'])) {
            ResearchSkill::query()
                ->where('user_id', $user->id)
                ->whereKeyNot($skill->id)
                ->update(['is_default' => false]);
        }

        $skill->fill([
            'name' => $data['name'],
            'description' => $data['description'],
            'is_manual_enabled' => (bool) $data['is_manual_enabled'],
            'allow_auto_run' => (bool) $data['allow_auto_run'],
            'is_default' => (bool) $data['is_default'],
            'is_active' => true,
            'latest_version_number' => $skill->latest_version_number + 1,
        ])->save();

        $skill->versions()->create([
            'version' => $skill->latest_version_number,
            // ...snapshot behavioural fields...
        ]);

        return $skill->fresh(['latestVersion']);
    });
}
```

- [ ] **Step 4: Run the unit test file**

Run: `php artisan test tests/Unit/Services/ResearchSkillManagerTest.php`

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Research/ResearchSkillManager.php app/Models/ResearchSkill.php app/Models/ResearchSkillVersion.php tests/Unit/Services/ResearchSkillManagerTest.php
git commit -m "feat: add research skill version management"
```

### Task 3: Add the prompt builder and workflow runner

**Files:**
- Create: `app/Services/Research/ResearchPromptBuilder.php`
- Create: `app/Services/Research/ResearchWorkflowRunner.php`
- Test: `tests/Unit/Services/ResearchPromptBuilderTest.php`
- Test: `tests/Unit/Services/ResearchWorkflowRunnerTest.php`
- Modify: `app/Services/OpenRouterService.php`
- Modify: `app/Services/ResearchService.php`

- [ ] **Step 1: Write the failing prompt-builder test**

```php
public function test_prompt_builder_caps_related_context_and_applies_output_sections(): void
{
    $idea = Thought::factory()->make([
        'content' => 'Investigate a tool for founders doing market validation.',
        'metadata' => ['type' => 'idea', 'tags' => ['founders', 'market', 'saas']],
    ]);
    $related = Thought::factory()->count(5)->make();

    $payload = app(ResearchPromptBuilder::class)->buildQuickBriefPrompt(
        idea: $idea,
        instructions: 'Focus on practical validation.',
        contextOptions: ['idea', 'tags', 'related_thoughts'],
        outputShape: ['summary', 'risks', 'next_steps'],
        intensity: 'standard',
        relatedThoughts: $related,
    );

    $this->assertStringContainsString('Investigate a tool', $payload);
    $this->assertStringContainsString('summary', $payload);
    $this->assertStringContainsString('risks', $payload);
    $this->assertStringContainsString('next_steps', $payload);
    $this->assertLessThanOrEqual(3, substr_count($payload, 'Related thought'));
}
```

- [ ] **Step 2: Run the prompt-builder test to verify it fails**

Run: `php artisan test tests/Unit/Services/ResearchPromptBuilderTest.php`

Expected: FAIL because the builder class does not exist.

- [ ] **Step 3: Write the failing workflow-runner test**

```php
public function test_runner_marks_run_complete_and_links_final_research_thought(): void
{
    $run = ResearchRun::factory()->queuedQuickBrief()->create();

    $this->mock(OpenRouterService::class, function ($mock): void {
        $mock->shouldReceive('researchNote')
            ->once()
            ->andReturn("## Summary\nUseful answer\n\n## Next steps\n- Interview users");
    });

    app(ResearchWorkflowRunner::class)->run($run->fresh());

    $run->refresh();

    $this->assertSame('completed', $run->status);
    $this->assertNotNull($run->final_research_thought_id);
}
```

- [ ] **Step 4: Implement the smallest working builder and runner**

```php
public function run(ResearchRun $run): ResearchRun
{
    $run->markRunning(stage: 1, totalStages: 1);

    $prompt = $this->promptBuilder->buildQuickBriefPrompt(
        idea: $run->idea,
        instructions: $run->skillVersion->instructions,
        contextOptions: $run->context_options_snapshot ?? [],
        outputShape: $run->output_shape_snapshot ?? [],
        intensity: $run->intensity_snapshot,
    );

    $result = $this->openRouter->researchNote($prompt);
    $thought = $this->researchService->saveRunResult($run, $result);

    return $run->markCompleted($thought);
}
```

- [ ] **Step 4a: Add the failing failure-path runner test**

```php
public function test_runner_marks_run_failed_and_keeps_existing_research_untouched(): void
{
    $run = ResearchRun::factory()->queuedQuickBrief()->create();
    $priorResearch = Thought::factory()->create(['user_id' => $run->user_id]);

    $this->mock(OpenRouterService::class, function ($mock): void {
        $mock->shouldReceive('researchNote')->once()->andThrow(new RuntimeException('OpenRouter down'));
    });

    app(ResearchWorkflowRunner::class)->run($run->fresh(), existingResearchThought: $priorResearch);

    $run->refresh();

    $this->assertSame('failed', $run->status);
    $this->assertStringContainsString('OpenRouter down', $run->error_summary);
}
```

- [ ] **Step 5: Run both unit test files**

Run: `php artisan test tests/Unit/Services/ResearchPromptBuilderTest.php tests/Unit/Services/ResearchWorkflowRunnerTest.php`

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Services/Research/ResearchPromptBuilder.php app/Services/Research/ResearchWorkflowRunner.php app/Services/OpenRouterService.php app/Services/ResearchService.php tests/Unit/Services/ResearchPromptBuilderTest.php tests/Unit/Services/ResearchWorkflowRunnerTest.php
git commit -m "feat: add research workflow runner"
```

### Task 4: Queue research runs instead of dispatching raw idea events

**Files:**
- Create: `app/Jobs/RunResearchRun.php`
- Modify: `app/Services/ResearchService.php`
- Modify: `app/Http/Controllers/IdeaController.php`
- Modify: `app/Events/IdeaResearchRequested.php`
- Modify: `app/Listeners/RunResearchForIdeaListener.php`
- Modify: `app/Providers/AppServiceProvider.php`
- Test: `tests/Feature/ResearchRunWorkflowTest.php`

- [ ] **Step 1: Write the failing feature test for queued runs**

```php
public function test_manual_research_request_creates_run_and_dispatches_run_job(): void
{
    Queue::fake();

    $user = User::factory()->create();
    $idea = Thought::factory()->create([
        'user_id' => $user->id,
        'metadata' => ['type' => 'idea', 'completed' => false, 'logged_date' => now()->toDateString()],
    ]);
    $skill = ResearchSkill::factory()->defaultQuickBrief()->create(['user_id' => $user->id]);

    $response = $this->actingAs($user)->post(route('ideas.research', $idea), [
        'research_skill_id' => $skill->id,
    ]);

    $response->assertRedirect();
    $this->assertDatabaseHas('research_runs', [
        'idea_thought_id' => $idea->id,
        'research_skill_id' => $skill->id,
        'status' => 'queued',
    ]);
    Queue::assertPushed(RunResearchRun::class);
}
```

- [ ] **Step 1a: Add the failing duplicate-run guard test**

```php
public function test_manual_research_request_reuses_existing_active_run_for_idea(): void
{
    Queue::fake();

    $user = User::factory()->create();
    $idea = Thought::factory()->idea()->create(['user_id' => $user->id]);
    $skill = ResearchSkill::factory()->defaultQuickBrief()->create(['user_id' => $user->id]);
    $version = $skill->latestVersion;

    ResearchRun::factory()->queuedQuickBrief()->create([
        'user_id' => $user->id,
        'idea_thought_id' => $idea->id,
        'research_skill_id' => $skill->id,
        'research_skill_version_id' => $version->id,
    ]);

    $this->actingAs($user)->post(route('ideas.research', $idea), ['research_skill_id' => $skill->id])->assertRedirect();

    $this->assertSame(1, ResearchRun::query()->where('idea_thought_id', $idea->id)->count());
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test tests/Feature/ResearchRunWorkflowTest.php --filter=dispatches_run_job`

Expected: FAIL because controller/service still use `IdeaResearchRequested` and do not enforce the single-active-run rule.

- [ ] **Step 3: Implement run creation + job dispatch**

```php
public function queueIdeaResearch(Thought $idea, ResearchSkillVersion $version, string $source = 'web'): ResearchRun
{
    $existing = ResearchRun::query()
        ->where('idea_thought_id', $idea->id)
        ->whereIn('status', ['queued', 'running'])
        ->latest('created_at')
        ->first();

    if ($existing !== null) {
        return $existing;
    }

    $run = ResearchRun::query()->create([
        'user_id' => $idea->user_id,
        'idea_thought_id' => $idea->id,
        'research_skill_id' => $version->research_skill_id,
        'research_skill_version_id' => $version->id,
        'status' => 'queued',
        'workflow_type_snapshot' => $version->workflow_type,
        'context_options_snapshot' => $version->context_options,
        'output_shape_snapshot' => $version->output_shape,
        'intensity_snapshot' => $version->intensity,
        'source' => $source,
    ]);

    RunResearchRun::dispatch($run->id);

    return $run;
}
```

- [ ] **Step 4: Run the focused feature test**

Run: `php artisan test tests/Feature/ResearchRunWorkflowTest.php --filter=dispatches_run_job`

Expected: PASS.

- [ ] **Step 4a: Run the duplicate-run guard test**

Run: `php artisan test tests/Feature/ResearchRunWorkflowTest.php --filter=existing_active_run`

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Jobs/RunResearchRun.php app/Services/ResearchService.php app/Http/Controllers/IdeaController.php app/Events/IdeaResearchRequested.php app/Listeners/RunResearchForIdeaListener.php app/Providers/AppServiceProvider.php tests/Feature/ResearchRunWorkflowTest.php
git commit -m "refactor: queue idea research through research runs"
```

### Task 5: Add the research skill settings UI

**Files:**
- Create: `app/Http/Controllers/ResearchSkillSettingsController.php`
- Create: `app/Http/Requests/StoreResearchSkillRequest.php`
- Create: `app/Http/Requests/UpdateResearchSkillRequest.php`
- Create: `resources/views/settings/research-skills/index.blade.php`
- Create: `resources/views/settings/research-skills/_form.blade.php`
- Create: `resources/views/settings/research-skills/create.blade.php`
- Create: `resources/views/settings/research-skills/edit.blade.php`
- Modify: `routes/web.php`
- Modify: `resources/views/layouts/idea.blade.php`
- Modify: `app/Models/UserPreference.php`
- Test: `tests/Feature/ResearchSkillSettingsControllerTest.php`

- [ ] **Step 1: Write the failing feature test for settings pages**

```php
public function test_user_can_create_quick_brief_skill_from_settings(): void
{
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('settings.research-skills.store'), [
        'name' => 'Founder quick brief',
        'description' => 'Fast research for startup ideas',
        'workflow_type' => 'quick_brief',
        'instructions' => 'Focus on validation and market demand.',
        'context_options' => ['idea', 'tags'],
        'output_shape' => ['summary', 'risks', 'next_steps'],
        'intensity' => 'standard',
        'is_manual_enabled' => true,
        'allow_auto_run' => true,
        'is_default' => true,
    ]);

    $response->assertRedirect(route('settings.research-skills.index'));
    $this->assertDatabaseHas('research_skills', [
        'user_id' => $user->id,
        'name' => 'Founder quick brief',
        'is_default' => true,
    ]);
}
```

- [ ] **Step 1a: Add the failing auto-run toggle test**

```php
public function test_user_can_enable_global_research_auto_run_in_settings(): void
{
    $user = User::factory()->create();

    $this->actingAs($user)->put(route('settings.research-skills.preferences'), [
        'research_auto_run_enabled' => '1',
    ])->assertRedirect(route('settings.research-skills.index'));

    $this->assertSame(true, UserPreference::get($user->fresh(), 'research_auto_run_enabled', false));
}
```

- [ ] **Step 2: Run the settings test to verify it fails**

Run: `php artisan test tests/Feature/ResearchSkillSettingsControllerTest.php`

Expected: FAIL because the routes/controller/views do not exist.

- [ ] **Step 3: Implement the controller, requests, routes, and Blade forms**

```php
Route::get('/settings/research-skills', [ResearchSkillSettingsController::class, 'index'])->name('settings.research-skills.index');
Route::get('/settings/research-skills/create', [ResearchSkillSettingsController::class, 'create'])->name('settings.research-skills.create');
Route::post('/settings/research-skills', [ResearchSkillSettingsController::class, 'store'])->name('settings.research-skills.store');
Route::get('/settings/research-skills/{researchSkill}/edit', [ResearchSkillSettingsController::class, 'edit'])->name('settings.research-skills.edit');
Route::put('/settings/research-skills/{researchSkill}', [ResearchSkillSettingsController::class, 'update'])->name('settings.research-skills.update');
Route::put('/settings/research-skills/preferences', [ResearchSkillSettingsController::class, 'updatePreferences'])->name('settings.research-skills.preferences');
```

- [ ] **Step 4: Run the settings test file again**

Run: `php artisan test tests/Feature/ResearchSkillSettingsControllerTest.php`

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/ResearchSkillSettingsController.php app/Http/Requests/StoreResearchSkillRequest.php app/Http/Requests/UpdateResearchSkillRequest.php app/Models/UserPreference.php resources/views/settings/research-skills/index.blade.php resources/views/settings/research-skills/_form.blade.php resources/views/settings/research-skills/create.blade.php resources/views/settings/research-skills/edit.blade.php resources/views/layouts/idea.blade.php routes/web.php tests/Feature/ResearchSkillSettingsControllerTest.php
git commit -m "feat: add research skill settings"
```

### Task 6: Update the ideas page for default skill automation and run status

**Files:**
- Modify: `app/Http/Controllers/IdeaController.php`
- Create: `app/View/Presenters/Ideas/IdeaResearchStatusPresenter.php`
- Modify: `resources/views/idea/ideas.blade.php`
- Modify: `resources/views/idea/partials/ideas_list.blade.php`
- Modify: `tests/Feature/IdeaIdeasTest.php`
- Modify: `tests/Unit/View/Presenters/Ideas/IdeaListItemPresenterTest.php`
- Modify: `app/Models/UserPreference.php`

- [ ] **Step 1: Write the failing feature tests for the new composer and status**

```php
public function test_save_idea_queues_default_skill_when_auto_run_enabled(): void
{
    Queue::fake();

    $user = User::factory()->create();
    ResearchSkill::factory()->defaultQuickBrief()->create([
        'user_id' => $user->id,
        'allow_auto_run' => true,
        'is_default' => true,
    ]);

    $response = $this->actingAs($user)->post(route('ideas.store'), [
        'content' => 'Investigate whether dealerships need a pricing dashboard',
    ]);

    $response->assertRedirect(route('idea.ideas'));
    $response->assertSessionHas('success');
    $this->assertDatabaseHas('research_runs', ['status' => 'queued']);
    Queue::assertPushed(RunResearchRun::class);
}
```

- [ ] **Step 1a: Add the failing "save only" test when auto-run is disabled**

```php
public function test_save_idea_does_not_queue_run_when_auto_run_disabled(): void
{
    Queue::fake();

    $user = User::factory()->create();
    ResearchSkill::factory()->defaultQuickBrief()->create([
        'user_id' => $user->id,
        'allow_auto_run' => true,
        'is_default' => true,
    ]);
    UserPreference::set($user, 'research_auto_run_enabled', false);

    $this->actingAs($user)->post(route('ideas.store'), [
        'content' => 'Capture this without research',
    ])->assertRedirect(route('idea.ideas'));

    $this->assertSame(0, ResearchRun::query()->count());
    Queue::assertNothingPushed();
}
```

- [ ] **Step 2: Run the ideas-page tests to verify they fail**

Run: `php artisan test tests/Feature/IdeaIdeasTest.php --filter=default_skill`

Expected: FAIL because `ideas.store` still only creates the idea.

- [ ] **Step 3: Implement the updated idea composer and presenter**

```php
if ($request->boolean('start_research')) {
    $run = $this->researchService->queueExplicitIdeaResearch($idea, $request->user(), $validated['research_skill_id'] ?? null);
}

if ($this->researchService->shouldAutoRunDefaultSkill($request->user())) {
    $run = $this->researchService->queueDefaultIdeaResearch($idea, $request->user(), 'web');
}
```

- [ ] **Step 4: Run the idea-page and presenter tests**

Run: `php artisan test tests/Feature/IdeaIdeasTest.php tests/Unit/View/Presenters/Ideas/IdeaListItemPresenterTest.php`

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/IdeaController.php app/View/Presenters/Ideas/IdeaResearchStatusPresenter.php resources/views/idea/ideas.blade.php resources/views/idea/partials/ideas_list.blade.php tests/Feature/IdeaIdeasTest.php tests/Unit/View/Presenters/Ideas/IdeaListItemPresenterTest.php
git commit -m "feat: show research run status on ideas page"
```

### Task 7: Move MCP research requests onto the run model

**Files:**
- Modify: `app/Http/Controllers/Api/McpController.php`
- Modify: `app/Services/ResearchService.php`
- Modify: `tests/Feature/ResearchServiceTest.php`
- Create or Modify: `tests/Feature/McpResearchIdeaTest.php`

- [ ] **Step 1: Write the failing MCP-path test**

```php
public function test_research_idea_tool_creates_run_for_existing_idea(): void
{
    Queue::fake();

    $user = User::factory()->create();
    $idea = Thought::factory()->create([
        'user_id' => $user->id,
        'metadata' => ['type' => 'idea', 'completed' => false, 'logged_date' => now()->toDateString()],
    ]);
    ResearchSkill::factory()->defaultQuickBrief()->create([
        'user_id' => $user->id,
        'allow_auto_run' => true,
        'is_default' => true,
    ]);

    $this->actingAs($user)
        ->postJson('/api/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'research_idea',
            'params' => ['idea_id' => $idea->id],
        ])
        ->assertOk();

    $this->assertDatabaseHas('research_runs', ['idea_thought_id' => $idea->id]);
}
```

- [ ] **Step 2: Run the MCP-focused test to verify it fails**

Run: `php artisan test tests/Feature/McpResearchIdeaTest.php`

Expected: FAIL because `research_idea` still calls `runResearchForIdea()` directly.

- [ ] **Step 3: Implement MCP delegation through `ResearchService`**

```php
if ($content !== null) {
    $idea = $this->researchService->createIdeaOnly($content, 'mcp');
    $run = $this->researchService->queueDefaultIdeaResearch($idea, Auth::user(), 'mcp');

    return ['idea_id' => $idea->id, 'research_run_id' => $run->id];
}

$run = $this->researchService->queueDefaultIdeaResearch($thought, Auth::user(), 'mcp');

return ['idea_id' => $thought->id, 'research_run_id' => $run->id];
```

- [ ] **Step 4: Run the MCP and research service tests**

Run: `php artisan test tests/Feature/McpResearchIdeaTest.php tests/Feature/ResearchServiceTest.php`

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Api/McpController.php app/Services/ResearchService.php tests/Feature/McpResearchIdeaTest.php tests/Feature/ResearchServiceTest.php
git commit -m "refactor: route MCP research through research runs"
```

### Task 8: Final integration sweep, feature verification, and docs alignment

**Files:**
- Modify: `tests/Feature/EmailResearchControllerTest.php`
- Modify: `tests/Feature/ResearchRunWorkflowTest.php`
- Modify: `docs/superpowers/specs/2026-03-31-idea-research-skills-design.md` (only if implementation changed the agreed contract)
- Modify: `docs/superpowers/plans/2026-03-31-idea-research-skills.md` (check off completed steps during execution only)

- [ ] **Step 1: Update any remaining tests that still assume raw `IdeaResearchRequested` dispatch**

```php
Queue::assertPushed(RunResearchRun::class, function ($job) use ($thought) {
    return ResearchRun::query()
        ->whereKey($job->researchRunId)
        ->where('idea_thought_id', $thought->id)
        ->exists();
});
```

- [ ] **Step 1a: Add remaining ownership, per-user limit, and cancellation coverage**

```php
public function test_user_cannot_edit_another_users_skill(): void
{
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $skill = ResearchSkill::factory()->defaultQuickBrief()->create(['user_id' => $owner->id]);

    $this->actingAs($other)
        ->put(route('settings.research-skills.update', $skill), [...])
        ->assertForbidden();
}

public function test_service_prevents_new_run_when_user_has_hit_active_run_limit(): void
{
    // Seed max active runs, then assert queue attempt returns validation/domain error.
}

public function test_cancelled_run_does_not_continue_execution(): void
{
    // Mark run cancelled before job handle, assert final status stays cancelled.
}
```

- [ ] **Step 2: Run the full focused suite**

Run: `php artisan test tests/Feature/ResearchRunWorkflowTest.php tests/Feature/ResearchSkillSettingsControllerTest.php tests/Feature/IdeaIdeasTest.php tests/Feature/McpResearchIdeaTest.php tests/Feature/ResearchServiceTest.php tests/Feature/EmailResearchControllerTest.php tests/Unit/Services/ResearchSkillManagerTest.php tests/Unit/Services/ResearchPromptBuilderTest.php tests/Unit/Services/ResearchWorkflowRunnerTest.php`

Expected: PASS.

- [ ] **Step 3: Run a broader regression sweep around idea/research surfaces**

Run: `php artisan test tests/Feature/IdeaPageTest.php tests/Feature/IdeaIdeasTest.php tests/Feature/EmailResearchControllerTest.php tests/Feature/ResearchShowTest.php`

Expected: PASS.

- [ ] **Step 4: Run lints / app checks if available**

Run: `php artisan test`

Expected: PASS or only pre-existing unrelated failures.

- [ ] **Step 5: Commit**

```bash
git add tests/Feature/EmailResearchControllerTest.php docs/superpowers/specs/2026-03-31-idea-research-skills-design.md
git commit -m "test: finish research skill integration coverage"
```

---

## Implementation Notes

- Default assumptions for v1:
  - only `quick_brief` ships initially
  - auto-run applies globally to new ideas when enabled
  - intermediate stage outputs stay internal
  - one active run per idea
  - one default skill per user
  - global auto-run uses `UserPreference::get($user, 'research_auto_run_enabled', false)`
  - per-user active run limit is enforced in the service layer via a small constant/config value
  - no dedicated completion notifications

- Keep `ResearchService` as the compatibility layer used by controllers and MCP, but move orchestration into `ResearchSkillManager` and `ResearchWorkflowRunner` so the service does not grow into another god class.

- Prefer factories for `ResearchSkill`, `ResearchSkillVersion`, and `ResearchRun` as soon as the models exist; they will reduce friction in all later test tasks.

- If replacing `IdeaResearchRequested` entirely causes too much churn in one step, keep the event temporarily but change it to carry a `research_run_id`; remove it in a follow-up cleanup commit once the queue job path is stable.

---

## Review Checklist

- The plan keeps the v1 scope to one workflow type.
- The plan snapshots immutable skill versions into runs.
- The plan resolves default-skill exclusivity and one-active-run-per-idea rules.
- The plan preserves the existing product value of automatic idea research.
- The plan updates both web and MCP entry points.
