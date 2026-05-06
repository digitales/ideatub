# Working Memory Manual Refresh Surfaces Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add manual **Refresh working memory** buttons on global, project, and tag-context surfaces that queue consolidated scope rebuilds safely.

**Architecture:** Introduce a small web refresh controller with thin surface-specific POST endpoints that normalize/authorize scope and dispatch `ConsolidateWorkingMemory`. Reuse one dispatch helper to avoid duplication, then wire buttons into the project module, memory page header, and tag stream page. Validate with feature tests for queueing, authorization, and UI rendering.

**Tech Stack:** Laravel 12 (controllers, routes, jobs, policies), Blade views, PHPUnit/Pest feature tests, existing working-memory services/jobs.

---

## File structure (creates + touches)

| Path | Responsibility |
|------|----------------|
| `app/Http/Controllers/WorkingMemoryRefreshController.php` | New POST handlers for global/project/tag refresh actions plus shared dispatch helper. |
| `routes/web.php` | Register authenticated refresh routes for each surface context. |
| `resources/views/projects/partials/working-memory-module.blade.php` | Add project refresh button in project module. |
| `resources/views/memory/show.blade.php` | Add refresh button in global/project memory page header. |
| `resources/views/idea/stream.blade.php` | Add tag-context refresh button when `tag` is active. |
| `tests/Feature/WorkingMemoryRefreshFeatureTest.php` | New endpoint tests for queueing, auth, and validation behavior. |
| `tests/Feature/WorkingMemoryWebTest.php` | Assert refresh button renders on memory page surfaces. |
| `tests/Feature/ProjectShowTest.php` or `tests/Feature/ProjectMemoryModuleTest.php` | Assert project module shows refresh button and posts correctly. |
| `tests/Feature/IdeaStreamTest.php` | Assert tag stream shows refresh button only with active tag and hides otherwise. |

---

### Task 1: Add refresh controller and routes

**Files:**
- Create: `app/Http/Controllers/WorkingMemoryRefreshController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/WorkingMemoryRefreshFeatureTest.php`

- [ ] **Step 1: Write failing endpoint tests**

```php
public function test_global_refresh_queues_consolidated_job(): void
{
    Queue::fake();
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('working-memory.refresh.global'))
        ->assertRedirect();

    Queue::assertPushed(ConsolidateWorkingMemory::class, function ($job) use ($user): bool {
        $r = new ReflectionClass($job);
        return $r->getProperty('userId')->getValue($job) === $user->id
            && $r->getProperty('scopeType')->getValue($job) === 'global'
            && $r->getProperty('scopeKey')->getValue($job) === 'global';
    });
}
```

```php
public function test_project_refresh_requires_authorization(): void
{
    Queue::fake();
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $project = Project::factory()->for($owner)->create();

    $this->actingAs($intruder)
        ->post(route('working-memory.refresh.project', $project))
        ->assertForbidden();

    Queue::assertNotPushed(ConsolidateWorkingMemory::class);
}
```

- [ ] **Step 2: Run tests to confirm failure**

Run: `php artisan test tests/Feature/WorkingMemoryRefreshFeatureTest.php`  
Expected: FAIL (route/controller missing).

- [ ] **Step 3: Implement controller with shared dispatch helper**

```php
final class WorkingMemoryRefreshController extends Controller
{
    public function refreshGlobal(Request $request): RedirectResponse
    {
        $this->dispatchConsolidated($request, 'global', 'global');
        return back()->with('success', 'Queued consolidated rebuild for global working memory.');
    }

    public function refreshProject(Request $request, Project $project): RedirectResponse
    {
        $this->authorize('view', $project);
        $this->dispatchConsolidated($request, 'project', (string) $project->getKey());
        return back()->with('success', 'Queued consolidated rebuild for project working memory.');
    }
}
```

```php
private function dispatchConsolidated(Request $request, string $scopeType, string $scopeKey): void
{
    [$normalizedType, $normalizedKey] = app(WorkingMemoryScopeNormalizer::class)->normalize($scopeType, $scopeKey);
    ConsolidateWorkingMemory::dispatch((int) $request->user()->id, $normalizedType, $normalizedKey);
}
```

- [ ] **Step 4: Add routes**

```php
Route::post('/memory/refresh', [WorkingMemoryRefreshController::class, 'refreshGlobal'])
    ->name('working-memory.refresh.global');
Route::post('/projects/{project}/memory/refresh', [WorkingMemoryRefreshController::class, 'refreshProject'])
    ->name('working-memory.refresh.project');
Route::post('/stream/tag/{tag}/memory/refresh', [WorkingMemoryRefreshController::class, 'refreshTag'])
    ->name('working-memory.refresh.tag');
```

- [ ] **Step 5: Run tests and commit**

Run: `php artisan test tests/Feature/WorkingMemoryRefreshFeatureTest.php`  
Expected: PASS.

```bash
git add app/Http/Controllers/WorkingMemoryRefreshController.php routes/web.php tests/Feature/WorkingMemoryRefreshFeatureTest.php
git commit -m "feat(memory): add manual consolidated refresh endpoints"
```

---

### Task 2: Implement tag refresh validation and queue semantics

**Files:**
- Modify: `app/Http/Controllers/WorkingMemoryRefreshController.php`
- Modify: `tests/Feature/WorkingMemoryRefreshFeatureTest.php`

- [ ] **Step 1: Add failing tag validation tests**

```php
public function test_tag_refresh_rejects_blank_tag(): void
{
    Queue::fake();
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('working-memory.refresh.tag', ['tag' => '   ']))
        ->assertRedirect();

    $this->assertTrue(session()->has('error'));
    Queue::assertNotPushed(ConsolidateWorkingMemory::class);
}
```

```php
public function test_tag_refresh_normalizes_tag_and_queues_job(): void
{
    Queue::fake();
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('working-memory.refresh.tag', ['tag' => '  AI-Notes  ']))
        ->assertRedirect();

    Queue::assertPushed(ConsolidateWorkingMemory::class, function ($job) use ($user): bool {
        $r = new ReflectionClass($job);
        return $r->getProperty('userId')->getValue($job) === $user->id
            && $r->getProperty('scopeType')->getValue($job) === 'tag'
            && $r->getProperty('scopeKey')->getValue($job) === 'ai-notes';
    });
}
```

- [ ] **Step 2: Run tests to confirm fail**

Run: `php artisan test tests/Feature/WorkingMemoryRefreshFeatureTest.php --filter=tag_refresh`  
Expected: FAIL.

- [ ] **Step 3: Implement `refreshTag`**

```php
public function refreshTag(Request $request, string $tag): RedirectResponse
{
    $normalized = Str::of($tag)->trim()->lower()->toString();
    if ($normalized === '') {
        return back()->with('error', 'Invalid tag context for working memory refresh.');
    }

    $this->dispatchConsolidated($request, 'tag', $normalized);

    return back()->with('success', 'Queued consolidated rebuild for tag working memory.');
}
```

- [ ] **Step 4: Run tests and commit**

Run: `php artisan test tests/Feature/WorkingMemoryRefreshFeatureTest.php`  
Expected: PASS.

```bash
git add app/Http/Controllers/WorkingMemoryRefreshController.php tests/Feature/WorkingMemoryRefreshFeatureTest.php
git commit -m "feat(memory): add validated tag-scope manual refresh action"
```

---

### Task 3: Add refresh button to project module

**Files:**
- Modify: `resources/views/projects/partials/working-memory-module.blade.php`
- Test: `tests/Feature/ProjectShowTest.php` or `tests/Feature/ProjectMemoryModuleTest.php`

- [ ] **Step 1: Write failing render test**

```php
public function test_project_module_shows_refresh_button(): void
{
    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create();

    $response = $this->actingAs($user)->get(route('projects.show', $project));

    $response->assertSee('Refresh working memory', false);
}
```

- [ ] **Step 2: Run failing test**

Run: `php artisan test tests/Feature/ProjectShowTest.php --filter=refresh_button`  
Expected: FAIL.

- [ ] **Step 3: Add project module button form**

```blade
<form method="POST" action="{{ route('working-memory.refresh.project', $project) }}" class="mt-3">
    @csrf
    <button type="submit" class="text-xs font-medium text-memory-violet px-3 py-1.5 rounded-lg border border-memory-violet/25 hover:bg-memory-violet/5 transition-colors">
        Refresh working memory
    </button>
    <p class="mt-1 text-[11px] text-slate-brand/50">Queues a consolidated rebuild.</p>
</form>
```

- [ ] **Step 4: Run tests and commit**

Run: `php artisan test tests/Feature/ProjectShowTest.php`  
Expected: PASS.

```bash
git add resources/views/projects/partials/working-memory-module.blade.php tests/Feature/ProjectShowTest.php
git commit -m "feat(memory): add project-module manual refresh button"
```

---

### Task 4: Add refresh button to memory pages (global and project scope)

**Files:**
- Modify: `resources/views/memory/show.blade.php`
- Modify: `tests/Feature/WorkingMemoryWebTest.php`

- [ ] **Step 1: Add failing tests for button visibility**

```php
public function test_global_memory_page_shows_refresh_button(): void
{
    config(['features.working_memory_ui' => true]);
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('memory.show'));

    $response->assertSee('Refresh working memory', false);
}
```

```php
public function test_project_memory_page_shows_project_refresh_button(): void
{
    config(['features.working_memory_ui' => true]);
    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create();

    $response = $this->actingAs($user)->get(route('projects.memory.show', $project));
    $response->assertSee('Refresh working memory', false);
}
```

- [ ] **Step 2: Run failing tests**

Run: `php artisan test tests/Feature/WorkingMemoryWebTest.php --filter=refresh_button`  
Expected: FAIL.

- [ ] **Step 3: Update memory header actions**

```blade
@if ($isProjectScope ?? false)
    <form method="POST" action="{{ route('working-memory.refresh.project', $project) }}">
        @csrf
        <button type="submit" class="text-xs font-medium text-memory-violet ...">Refresh working memory</button>
    </form>
@else
    <form method="POST" action="{{ route('working-memory.refresh.global') }}">
        @csrf
        <button type="submit" class="text-xs font-medium text-memory-violet ...">Refresh working memory</button>
    </form>
@endif
```

- [ ] **Step 4: Run tests and commit**

Run: `php artisan test tests/Feature/WorkingMemoryWebTest.php`  
Expected: PASS.

```bash
git add resources/views/memory/show.blade.php tests/Feature/WorkingMemoryWebTest.php
git commit -m "feat(memory): add manual refresh button to global and project memory pages"
```

---

### Task 5: Add refresh button to tag-context stream view

**Files:**
- Modify: `resources/views/idea/stream.blade.php`
- Test: `tests/Feature/IdeaStreamTest.php`

- [ ] **Step 1: Write failing tag-context view tests**

```php
public function test_tag_stream_shows_refresh_button(): void
{
    $user = User::factory()->create();
    Thought::factory()->for($user)->create(['metadata' => ['tags' => ['ai']]]);

    $response = $this->actingAs($user)->get(route('idea.stream', ['tag' => 'ai']));
    $response->assertSee('Refresh working memory', false);
}
```

```php
public function test_non_tag_stream_hides_refresh_button(): void
{
    $user = User::factory()->create();
    $response = $this->actingAs($user)->get(route('idea.stream'));
    $response->assertDontSee('Refresh working memory', false);
}
```

- [ ] **Step 2: Run tests to confirm fail**

Run: `php artisan test tests/Feature/IdeaStreamTest.php --filter=refresh`  
Expected: FAIL.

- [ ] **Step 3: Add tag-only refresh form**

```blade
@if($tag)
    <form method="POST" action="{{ route('working-memory.refresh.tag', ['tag' => $tagSlug ?: $tag]) }}" class="text-center mb-4">
        @csrf
        <button type="submit" class="text-[12px] font-medium text-memory-violet hover:underline">
            Refresh working memory
        </button>
    </form>
@endif
```

- [ ] **Step 4: Run tests and commit**

Run: `php artisan test tests/Feature/IdeaStreamTest.php`  
Expected: PASS.

```bash
git add resources/views/idea/stream.blade.php tests/Feature/IdeaStreamTest.php
git commit -m "feat(memory): add tag-context manual working-memory refresh action"
```

---

### Task 6: Full regression + formatting

**Files:**
- Modify: test files touched above if fixes are needed from regression failures

- [ ] **Step 1: Run focused suite for this feature**

Run:
`php artisan test tests/Feature/WorkingMemoryRefreshFeatureTest.php tests/Feature/WorkingMemoryWebTest.php tests/Feature/ProjectShowTest.php tests/Feature/IdeaStreamTest.php`

Expected: PASS.

- [ ] **Step 2: Run adjacent memory regressions**

Run:
`php artisan test tests/Feature/WorkingMemorySettingsTest.php tests/Feature/WorkingMemoryApiTest.php tests/Feature/McpApiTest.php --filter=working_memory`

Expected: PASS.

- [ ] **Step 3: Format**

Run: `./vendor/bin/pint --dirty`  
Expected: PASS.

- [ ] **Step 4: Commit final stabilization**

```bash
git add app/Http/Controllers/WorkingMemoryRefreshController.php routes/web.php resources/views/projects/partials/working-memory-module.blade.php resources/views/memory/show.blade.php resources/views/idea/stream.blade.php tests/Feature/WorkingMemoryRefreshFeatureTest.php tests/Feature/WorkingMemoryWebTest.php tests/Feature/ProjectShowTest.php tests/Feature/IdeaStreamTest.php
git commit -m "test(memory): cover manual consolidated refresh actions across key surfaces"
```

---

## Self-review checklist

- **Spec coverage:** global/project/tag button surfaces, consolidated queue semantics, auth/validation, and no behavior regression are all mapped to tasks.
- **Placeholder scan:** no TODO/TBD markers; each task has explicit files, tests, commands, and sample code.
- **Type consistency:** route names, scope strings (`global`, `project`, `tag`), and job class naming are consistent across tasks.
