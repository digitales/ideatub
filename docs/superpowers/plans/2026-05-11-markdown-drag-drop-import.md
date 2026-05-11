# Markdown Drag-and-Drop Import — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add drag-and-drop markdown file import to the project detail page, with editable titles, a shared content type selector, and rendered markdown previews.

**Architecture:** Alpine.js drop zone component on `projects/show.blade.php` opens a modal on file drop. Modal fetches server-rendered markdown previews via a new lightweight endpoint. On confirm, a JSON POST to a new import endpoint creates thoughts via the existing `ThoughtCaptureService` and links them to the project via `ProjectMembershipService`. The `FileImportService` is extended with a new `importMarkdownWithMetadata()` method.

**Tech Stack:** Laravel 12 (PHP 8.2), Alpine.js, Tailwind CSS, Pest

**Spec:** `docs/superpowers/specs/2026-05-11-markdown-drag-drop-import-design.md`

---

### Task 1: Add routes for preview and import endpoints

**Files:**
- Modify: `routes/web.php:259-276` (inside the `if (config('features.file_upload'))` block)

- [ ] **Step 1: Add the two new routes**

In `routes/web.php`, inside the `if (config('features.file_upload'))` block (line 259), add two routes inside the existing `Route::prefix('imports')` group, plus one project-scoped route after the group:

```php
// Inside the Route::prefix('imports')->name('imports.')->group(function () { ... }) block,
// after the existing routes (after the destroyThoughts route at line 274):

Route::post('/preview-markdown', [ImportController::class, 'previewMarkdown'])
    ->name('preview-markdown');
```

And outside the imports prefix group but still inside the `if (config('features.file_upload'))` block:

```php
Route::post('/projects/{project}/import-markdown', [ImportController::class, 'importMarkdown'])
    ->name('projects.import-markdown');
```

The full modified block should look like:

```php
if (config('features.file_upload')) {
    Route::prefix('imports')->name('imports.')->group(function () {
        Route::post('/quick', [ImportController::class, 'quick'])
            ->middleware('throttle:import-upload')->name('quick');
        Route::post('/batch', [ImportController::class, 'batch'])
            ->middleware('throttle:import-upload')->name('batch');
        Route::post('/preview-markdown', [ImportController::class, 'previewMarkdown'])
            ->name('preview-markdown');
        Route::get('/{batch}', [ImportController::class, 'show'])
            ->middleware('can:view,batch')->name('show');
        Route::get('/{batch}/status', [ImportController::class, 'status'])
            ->middleware(['can:view,batch', 'throttle:60,1'])->name('status');
        Route::post('/{batch}/cancel', [ImportController::class, 'cancel'])
            ->middleware('can:cancel,batch')->name('cancel');
        Route::post('/{batch}/retry-failed', [ImportController::class, 'retryFailed'])
            ->middleware('can:retryFailed,batch')->name('retry-failed');
        Route::delete('/{batch}/thoughts', [ImportController::class, 'destroyThoughts'])
            ->middleware('can:deleteThoughts,batch')->name('thoughts.destroy');
    });
    Route::post('/projects/{project}/import-markdown', [ImportController::class, 'importMarkdown'])
        ->name('projects.import-markdown');
}
```

Note: the `preview-markdown` route must be placed **before** the `/{batch}` route, otherwise Laravel will try to resolve `preview-markdown` as a batch ID.

- [ ] **Step 2: Verify routes are registered**

Run:
```bash
php artisan route:list --name=imports.preview
php artisan route:list --name=projects.import-markdown
```

Expected: Both routes appear with correct methods and URIs.

- [ ] **Step 3: Commit**

```bash
git add routes/web.php
git commit -m "feat: add routes for markdown preview and project import endpoints"
```

---

### Task 2: Add `previewMarkdown` method to ImportController

**Files:**
- Modify: `app/Http/Controllers/ImportController.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Import/MarkdownPreviewTest.php`:

```php
<?php

namespace Tests\Feature\Import;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MarkdownPreviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_preview_returns_rendered_html(): void
    {
        config()->set('features.file_upload', true);
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->postJson(route('imports.preview-markdown'), [
                'content' => '# Hello World',
            ]);

        $response->assertOk();
        $response->assertJsonStructure(['html']);
        $this->assertStringContainsString('<h1>', $response->json('html'));
        $this->assertStringContainsString('Hello World', $response->json('html'));
    }

    public function test_preview_strips_yaml_front_matter(): void
    {
        config()->set('features.file_upload', true);
        $user = User::factory()->create();

        $content = "---\ntitle: My Doc\n---\n# Actual Content";

        $response = $this->actingAs($user)
            ->postJson(route('imports.preview-markdown'), [
                'content' => $content,
            ]);

        $response->assertOk();
        $this->assertStringContainsString('Actual Content', $response->json('html'));
        $this->assertStringNotContainsString('title: My Doc', $response->json('html'));
    }

    public function test_preview_rejects_empty_content(): void
    {
        config()->set('features.file_upload', true);
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->postJson(route('imports.preview-markdown'), [
                'content' => '',
            ]);

        $response->assertUnprocessable();
    }

    public function test_preview_requires_authentication(): void
    {
        config()->set('features.file_upload', true);

        $response = $this->postJson(route('imports.preview-markdown'), [
            'content' => '# Test',
        ]);

        $response->assertUnauthorized();
    }

    public function test_preview_returns_404_when_feature_disabled(): void
    {
        config()->set('features.file_upload', false);
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->postJson(route('imports.preview-markdown'), [
                'content' => '# Test',
            ]);

        $response->assertNotFound();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run:
```bash
php artisan test tests/Feature/Import/MarkdownPreviewTest.php --filter=test_preview_returns_rendered_html
```

Expected: FAIL — method `previewMarkdown` does not exist on `ImportController`.

- [ ] **Step 3: Implement `previewMarkdown` on ImportController**

Add the following method to `app/Http/Controllers/ImportController.php`. Add the necessary imports at the top of the file:

```php
use App\Support\MarkdownDisplayHelper;
use App\Support\SafeCommonMarkConverter;
use Illuminate\Http\Request;
```

Then add the method at the end of the class (before the closing `}`):

```php
public function previewMarkdown(Request $request): JsonResponse
{
    if (! config('features.file_upload', false)) {
        abort(404);
    }

    $validated = $request->validate([
        'content' => ['required', 'string', 'max:1048576'],
    ]);

    $cleaned = MarkdownDisplayHelper::stripPreambleForMarkdownDisplay($validated['content']);
    $html = SafeCommonMarkConverter::toHtml($cleaned);

    return response()->json(['html' => $html]);
}
```

- [ ] **Step 4: Run all preview tests to verify they pass**

Run:
```bash
php artisan test tests/Feature/Import/MarkdownPreviewTest.php
```

Expected: All 5 tests PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/ImportController.php tests/Feature/Import/MarkdownPreviewTest.php
git commit -m "feat: add markdown preview endpoint for file import"
```

---

### Task 3: Add `importMarkdownWithMetadata` to FileImportService

**Files:**
- Modify: `app/Services/Import/FileImportService.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Import/MarkdownImportServiceTest.php`:

```php
<?php

namespace Tests\Feature\Import;

use App\Models\Project;
use App\Models\Thought;
use App\Models\User;
use App\Services\Import\FileImportService;
use App\Services\OpenRouterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class MarkdownImportServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $mock = Mockery::mock(OpenRouterService::class);
        $mock->shouldIgnoreMissing();
        $mock->shouldReceive('generateEmbedding')->andReturn(null);
        $mock->shouldReceive('extractMetadata')->andReturn([]);
        $this->app->instance(OpenRouterService::class, $mock);
    }

    public function test_imports_thought_type_with_correct_source(): void
    {
        $user = User::factory()->create();
        $project = Project::create(['user_id' => $user->id, 'title' => 'Test Project']);

        $service = app(FileImportService::class);
        $thought = $service->importMarkdownWithMetadata(
            content: '# My thought content',
            title: 'My Thought',
            type: 'thought',
            project: $project,
            user: $user,
            originalFilename: 'my-thought.md',
        );

        $this->assertInstanceOf(Thought::class, $thought);
        $this->assertEquals('upload', $thought->source);
        $this->assertEquals('My Thought', data_get($thought->metadata, 'title'));
        $this->assertTrue($project->thoughts()->whereKey($thought->id)->exists());
    }

    public function test_imports_meeting_type_with_correct_source(): void
    {
        $user = User::factory()->create();
        $project = Project::create(['user_id' => $user->id, 'title' => 'Test Project']);

        $service = app(FileImportService::class);
        $thought = $service->importMarkdownWithMetadata(
            content: '# Meeting notes for Q2 planning',
            title: 'Q2 Planning',
            type: 'meeting',
            project: $project,
            user: $user,
        );

        $this->assertEquals('meeting', $thought->source);
        $this->assertEquals('meeting', data_get($thought->source_metadata, 'doc_type'));
        $this->assertEquals('Q2 Planning', data_get($thought->metadata, 'title'));
    }

    public function test_imports_research_type_with_correct_source(): void
    {
        $user = User::factory()->create();
        $project = Project::create(['user_id' => $user->id, 'title' => 'Test Project']);

        $service = app(FileImportService::class);
        $thought = $service->importMarkdownWithMetadata(
            content: '# Research on competitor pricing',
            title: 'Competitor Pricing',
            type: 'research',
            project: $project,
            user: $user,
        );

        $this->assertEquals('research', $thought->source);
        $this->assertEquals('research', data_get($thought->source_metadata, 'doc_type'));
    }

    public function test_preserves_original_filename_in_source_metadata(): void
    {
        $user = User::factory()->create();
        $project = Project::create(['user_id' => $user->id, 'title' => 'Test Project']);

        $service = app(FileImportService::class);
        $thought = $service->importMarkdownWithMetadata(
            content: '# Content',
            title: 'Title',
            type: 'thought',
            project: $project,
            user: $user,
            originalFilename: 'notes-2026-05-11.md',
        );

        $this->assertEquals('notes-2026-05-11.md', data_get($thought->source_metadata, 'original_filename'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run:
```bash
php artisan test tests/Feature/Import/MarkdownImportServiceTest.php --filter=test_imports_thought_type_with_correct_source
```

Expected: FAIL — method `importMarkdownWithMetadata` does not exist.

- [ ] **Step 3: Implement `importMarkdownWithMetadata` on FileImportService**

Add the following import at the top of `app/Services/Import/FileImportService.php`:

```php
use App\Models\Project;
use App\Models\User;
```

Then add this method at the end of the class (before the closing `}`):

```php
public function importMarkdownWithMetadata(
    string $content,
    string $title,
    string $type,
    Project $project,
    User $user,
    ?string $originalFilename = null,
): Thought {
    $sourceMap = [
        'thought' => 'upload',
        'meeting' => 'meeting',
        'research' => 'research',
        'plan' => 'plan',
        'decision' => 'decision',
        'spec' => 'spec',
    ];

    $source = $sourceMap[$type] ?? 'upload';

    $sourceMeta = [
        'provenance' => 'upload',
        'untrusted_origin' => true,
    ];

    if ($type !== 'thought') {
        $sourceMeta['doc_type'] = $type;
    }

    if ($originalFilename !== null) {
        $sourceMeta['original_filename'] = $originalFilename;
        $sourceMeta['file_path'] = $originalFilename;
    }

    $result = $this->capture->create([
        'content' => $content,
        'user_id' => (int) $user->id,
        'source' => $source,
        'source_metadata' => $sourceMeta,
        'idea_metadata' => ['title' => $title],
    ]);

    /** @var Thought */
    $thought = $result['thought'] ?? $result['root'];

    $this->projectMembership->addThought($project, $thought);

    return $thought;
}
```

- [ ] **Step 4: Run all service tests to verify they pass**

Run:
```bash
php artisan test tests/Feature/Import/MarkdownImportServiceTest.php
```

Expected: All 4 tests PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Import/FileImportService.php tests/Feature/Import/MarkdownImportServiceTest.php
git commit -m "feat: add importMarkdownWithMetadata to FileImportService"
```

---

### Task 4: Add `importMarkdown` method to ImportController

**Files:**
- Modify: `app/Http/Controllers/ImportController.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Import/MarkdownImportEndpointTest.php`:

```php
<?php

namespace Tests\Feature\Import;

use App\Models\Project;
use App\Models\Thought;
use App\Models\User;
use App\Services\OpenRouterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class MarkdownImportEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('features.file_upload', true);
        $mock = Mockery::mock(OpenRouterService::class);
        $mock->shouldIgnoreMissing();
        $mock->shouldReceive('generateEmbedding')->andReturn(null);
        $mock->shouldReceive('extractMetadata')->andReturn([]);
        $this->app->instance(OpenRouterService::class, $mock);
    }

    public function test_imports_single_file_as_thought(): void
    {
        $user = User::factory()->create();
        $project = Project::create(['user_id' => $user->id, 'title' => 'Test']);

        $response = $this->actingAs($user)
            ->postJson(route('projects.import-markdown', $project), [
                'type' => 'thought',
                'files' => [
                    ['title' => 'My Note', 'content' => '# Hello from markdown'],
                ],
            ]);

        $response->assertOk();
        $response->assertJsonCount(1, 'imported');
        $response->assertJsonPath('imported.0.title', 'My Note');
        $response->assertJsonPath('imported.0.status', 'success');
        $response->assertJsonCount(0, 'failed');

        $this->assertDatabaseHas('project_thought', [
            'project_id' => $project->id,
        ]);
    }

    public function test_imports_multiple_files(): void
    {
        $user = User::factory()->create();
        $project = Project::create(['user_id' => $user->id, 'title' => 'Test']);

        $response = $this->actingAs($user)
            ->postJson(route('projects.import-markdown', $project), [
                'type' => 'research',
                'files' => [
                    ['title' => 'File One', 'content' => '# Research one'],
                    ['title' => 'File Two', 'content' => '# Research two'],
                ],
            ]);

        $response->assertOk();
        $response->assertJsonCount(2, 'imported');
        $this->assertEquals(2, $project->thoughts()->count());
    }

    public function test_rejects_invalid_type(): void
    {
        $user = User::factory()->create();
        $project = Project::create(['user_id' => $user->id, 'title' => 'Test']);

        $response = $this->actingAs($user)
            ->postJson(route('projects.import-markdown', $project), [
                'type' => 'invalid_type',
                'files' => [
                    ['title' => 'Note', 'content' => '# Hello'],
                ],
            ]);

        $response->assertUnprocessable();
    }

    public function test_rejects_empty_files_array(): void
    {
        $user = User::factory()->create();
        $project = Project::create(['user_id' => $user->id, 'title' => 'Test']);

        $response = $this->actingAs($user)
            ->postJson(route('projects.import-markdown', $project), [
                'type' => 'thought',
                'files' => [],
            ]);

        $response->assertUnprocessable();
    }

    public function test_rejects_missing_title(): void
    {
        $user = User::factory()->create();
        $project = Project::create(['user_id' => $user->id, 'title' => 'Test']);

        $response = $this->actingAs($user)
            ->postJson(route('projects.import-markdown', $project), [
                'type' => 'thought',
                'files' => [
                    ['content' => '# Hello'],
                ],
            ]);

        $response->assertUnprocessable();
    }

    public function test_requires_authentication(): void
    {
        $user = User::factory()->create();
        $project = Project::create(['user_id' => $user->id, 'title' => 'Test']);

        $response = $this->postJson(route('projects.import-markdown', $project), [
            'type' => 'thought',
            'files' => [
                ['title' => 'Note', 'content' => '# Hello'],
            ],
        ]);

        $response->assertUnauthorized();
    }

    public function test_returns_404_when_feature_disabled(): void
    {
        config()->set('features.file_upload', false);
        $user = User::factory()->create();
        $project = Project::create(['user_id' => $user->id, 'title' => 'Test']);

        $response = $this->actingAs($user)
            ->postJson(route('projects.import-markdown', $project), [
                'type' => 'thought',
                'files' => [
                    ['title' => 'Note', 'content' => '# Hello'],
                ],
            ]);

        $response->assertNotFound();
    }

    public function test_forbids_importing_to_other_users_project(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $project = Project::create(['user_id' => $owner->id, 'title' => 'Test']);

        $response = $this->actingAs($other)
            ->postJson(route('projects.import-markdown', $project), [
                'type' => 'thought',
                'files' => [
                    ['title' => 'Note', 'content' => '# Hello'],
                ],
            ]);

        $response->assertForbidden();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run:
```bash
php artisan test tests/Feature/Import/MarkdownImportEndpointTest.php --filter=test_imports_single_file_as_thought
```

Expected: FAIL — method `importMarkdown` does not exist on `ImportController`.

- [ ] **Step 3: Implement `importMarkdown` on ImportController**

Add the `FileImportService` import if not already present:

```php
use App\Services\Import\FileImportService;
```

Then add this method at the end of the `ImportController` class (before the closing `}`):

```php
public function importMarkdown(
    Request $request,
    Project $project,
    DemoMode $demo,
    FileImportService $fileService,
): JsonResponse {
    if (! config('features.file_upload', false)) {
        abort(404);
    }
    if ($demo->enabled()) {
        abort(403, 'Uploads are disabled in demo mode.');
    }
    if ($project->user_id !== $request->user()->id) {
        abort(403, 'You do not own this project.');
    }

    $validated = $request->validate([
        'type' => ['required', 'string', 'in:thought,meeting,research,plan,decision,spec'],
        'files' => ['required', 'array', 'min:1'],
        'files.*.title' => ['required', 'string', 'max:255'],
        'files.*.content' => ['required', 'string', 'max:1048576'],
    ]);

    $imported = [];
    $failed = [];

    foreach ($validated['files'] as $file) {
        try {
            $thought = $fileService->importMarkdownWithMetadata(
                content: $file['content'],
                title: $file['title'],
                type: $validated['type'],
                project: $project,
                user: $request->user(),
            );

            $imported[] = [
                'id' => $thought->id,
                'title' => $file['title'],
                'status' => 'success',
            ];
        } catch (\Throwable $e) {
            $failed[] = [
                'title' => $file['title'],
                'status' => 'failed',
                'error' => $e->getMessage(),
            ];
        }
    }

    return response()->json([
        'imported' => $imported,
        'failed' => $failed,
    ]);
}
```

- [ ] **Step 4: Run all endpoint tests to verify they pass**

Run:
```bash
php artisan test tests/Feature/Import/MarkdownImportEndpointTest.php
```

Expected: All 7 tests PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/ImportController.php tests/Feature/Import/MarkdownImportEndpointTest.php
git commit -m "feat: add importMarkdown endpoint for project markdown import"
```

---

### Task 5: Add meeting processing hook

**Files:**
- Modify: `app/Services/Import/FileImportService.php`

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/Import/MarkdownImportServiceTest.php`:

```php
public function test_meeting_type_queues_auto_run(): void
{
    Queue::fake();

    $user = User::factory()->create();
    $project = Project::create(['user_id' => $user->id, 'title' => 'Test Project']);

    $meetingService = Mockery::mock(\App\Services\Meetings\MeetingService::class);
    $meetingService->shouldReceive('queueAutoRunForMeetingThought')
        ->once()
        ->withArgs(function (Thought $thought, string $source) {
            return $source === 'upload';
        });
    $this->app->instance(\App\Services\Meetings\MeetingService::class, $meetingService);

    $service = app(FileImportService::class);
    $service->importMarkdownWithMetadata(
        content: '# Meeting notes',
        title: 'Standup',
        type: 'meeting',
        project: $project,
        user: $user,
    );
}
```

Also add these imports at the top of the test file:

```php
use Illuminate\Support\Facades\Queue;
```

- [ ] **Step 2: Run test to verify it fails**

Run:
```bash
php artisan test tests/Feature/Import/MarkdownImportServiceTest.php --filter=test_meeting_type_queues_auto_run
```

Expected: FAIL — `queueAutoRunForMeetingThought` was never called.

- [ ] **Step 3: Add meeting processing to `importMarkdownWithMetadata`**

In `app/Services/Import/FileImportService.php`, add the import at the top:

```php
use App\Services\Meetings\MeetingService;
```

Update the constructor to inject `MeetingService`:

```php
public function __construct(
    private ImportStagingStore $staging,
    private ThoughtCaptureService $capture,
    private ProjectMembershipService $projectMembership,
    private MeetingService $meetingService,
) {}
```

Add this block at the end of `importMarkdownWithMetadata`, just before `return $thought;`:

```php
if ($type === 'meeting') {
    $this->meetingService->queueAutoRunForMeetingThought($thought, 'upload');
}
```

- [ ] **Step 4: Run the meeting test to verify it passes**

Run:
```bash
php artisan test tests/Feature/Import/MarkdownImportServiceTest.php --filter=test_meeting_type_queues_auto_run
```

Expected: PASS.

- [ ] **Step 5: Run all service tests to check for regressions**

Run:
```bash
php artisan test tests/Feature/Import/MarkdownImportServiceTest.php
```

Expected: All 5 tests PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Services/Import/FileImportService.php tests/Feature/Import/MarkdownImportServiceTest.php
git commit -m "feat: queue meeting processing for meeting-type markdown imports"
```

---

### Task 6: Add the drop zone and import modal to the project detail page

**Files:**
- Modify: `resources/views/projects/show.blade.php`

- [ ] **Step 1: Add the drop zone and modal markup**

In `resources/views/projects/show.blade.php`, after the "Add thought" section (after line 51, after the `</section>` that closes the Add thought block) and before the Members section, add this block:

```blade
@if (config('features.file_upload'))
    <section
        x-data="mdDropZone({
            previewUrl: '{{ route('imports.preview-markdown') }}',
            importUrl: '{{ route('projects.import-markdown', $project) }}',
            csrfToken: '{{ csrf_token() }}',
        })"
        class="rounded-2xl border border-memory-violet/20 bg-white/80 backdrop-blur p-5 mb-8"
        @dragover.prevent="onDragOver"
        @dragleave.prevent="onDragLeave"
        @drop.prevent="onDrop($event)"
    >
        <div
            class="border-2 border-dashed rounded-xl p-6 text-center transition-colors"
            :class="dragging ? 'border-memory-violet bg-memory-violet/5' : 'border-slate-brand/20'"
        >
            <p class="text-sm text-slate-brand/70">
                <span class="font-medium text-memory-violet">Drop .md files here</span> to import into this project
            </p>
        </div>

        <template x-if="skippedCount > 0 && !modalOpen">
            <p class="mt-2 text-xs text-amber-600" x-text="skippedCount + ' file(s) skipped — only .md supported'"></p>
        </template>

        {{-- Import Modal --}}
        <template x-if="modalOpen">
            <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/40" @click.self="closeModal">
                <div class="bg-white rounded-2xl shadow-xl max-w-2xl w-full mx-4 max-h-[85vh] flex flex-col" @keydown.escape.window="closeModal">
                    <div class="px-6 pt-5 pb-4 border-b border-slate-brand/10">
                        <h3 class="text-lg font-semibold text-deep-indigo">Import Markdown Files</h3>

                        <div class="mt-3">
                            <label class="block text-xs font-medium text-slate-brand/70 mb-1">Content type</label>
                            <select
                                x-model="selectedType"
                                class="w-full rounded-lg border border-memory-violet/20 bg-white px-3 py-2 text-sm text-deep-indigo"
                            >
                                <option value="thought">Thought</option>
                                <option value="meeting">Meeting</option>
                                <option value="research">Research</option>
                                <option value="plan">Plan</option>
                                <option value="decision">Decision</option>
                                <option value="spec">Spec</option>
                            </select>
                        </div>

                        <template x-if="skippedCount > 0">
                            <p class="mt-2 text-xs text-amber-600" x-text="skippedCount + ' file(s) skipped — only .md supported'"></p>
                        </template>
                    </div>

                    <div class="flex-1 overflow-y-auto px-6 py-4 space-y-4">
                        <template x-for="(file, index) in files" :key="index">
                            <div class="rounded-xl border border-memory-violet/10 bg-white/60 p-4">
                                <div class="flex items-center gap-2 mb-3">
                                    <input
                                        type="text"
                                        x-model="file.title"
                                        class="flex-1 rounded-lg border border-memory-violet/20 bg-white px-3 py-1.5 text-sm text-deep-indigo"
                                        placeholder="Title"
                                    />
                                    <button
                                        type="button"
                                        @click="removeFile(index)"
                                        class="text-xs text-slate-brand hover:text-red-600 shrink-0"
                                    >Remove</button>
                                </div>
                                <div class="prose prose-sm max-w-none text-slate-brand max-h-48 overflow-y-auto rounded-lg border border-slate-brand/10 bg-slate-50 p-3">
                                    <template x-if="file.previewHtml">
                                        <div x-html="file.previewHtml"></div>
                                    </template>
                                    <template x-if="!file.previewHtml && file.previewLoading">
                                        <p class="text-xs text-slate-brand/50">Loading preview…</p>
                                    </template>
                                    <template x-if="!file.previewHtml && !file.previewLoading">
                                        <pre class="whitespace-pre-wrap text-xs" x-text="file.content.substring(0, 2000)"></pre>
                                    </template>
                                </div>
                            </div>
                        </template>
                    </div>

                    <div class="px-6 py-4 border-t border-slate-brand/10 flex items-center justify-between">
                        <span class="text-xs text-slate-brand/50" x-text="files.length + ' file(s)'"></span>
                        <div class="flex gap-2">
                            <button
                                type="button"
                                @click="closeModal"
                                class="rounded-lg border border-slate-brand/20 px-4 py-2 text-sm font-medium text-slate-brand hover:bg-slate-50"
                            >Cancel</button>
                            <button
                                type="button"
                                @click="submitImport"
                                :disabled="importing || files.length === 0"
                                class="rounded-lg bg-memory-violet px-4 py-2 text-sm font-medium text-white hover:opacity-90 disabled:opacity-50"
                                x-text="importing ? 'Importing…' : 'Import ' + files.length + ' file(s)'"
                            ></button>
                        </div>
                    </div>

                    <template x-if="importError">
                        <p class="px-6 pb-4 text-xs text-red-600" x-text="importError"></p>
                    </template>
                </div>
            </div>
        </template>
    </section>
@endif
```

- [ ] **Step 2: Verify the page renders without JS errors**

Run:
```bash
php artisan test tests/Feature/Import/MarkdownImportEndpointTest.php
```

Expected: All tests still pass (the template uses `x-data="mdDropZone(...)"` which won't break server rendering).

- [ ] **Step 3: Commit**

```bash
git add resources/views/projects/show.blade.php
git commit -m "feat: add drag-and-drop zone and import modal to project detail page"
```

---

### Task 7: Add the `mdDropZone` Alpine.js component

**Files:**
- Modify: `resources/js/app.js`

- [ ] **Step 1: Add the Alpine component**

In `resources/js/app.js`, before the `Alpine.start();` line (line 1418), add the `mdDropZone` Alpine component:

```javascript
Alpine.data('mdDropZone', ({ previewUrl, importUrl, csrfToken }) => ({
  dragging: false,
  modalOpen: false,
  files: [],
  selectedType: 'thought',
  skippedCount: 0,
  importing: false,
  importError: '',

  onDragOver() {
    this.dragging = true;
  },

  onDragLeave() {
    this.dragging = false;
  },

  async onDrop(event) {
    this.dragging = false;
    const droppedFiles = Array.from(event.dataTransfer?.files || []);
    if (droppedFiles.length === 0) return;

    const MAX_SIZE = 1048576;
    const mdFiles = [];
    let skipped = 0;

    for (const f of droppedFiles) {
      if (!f.name.toLowerCase().endsWith('.md')) {
        skipped++;
        continue;
      }
      if (f.size === 0 || f.size > MAX_SIZE) {
        skipped++;
        continue;
      }
      mdFiles.push(f);
    }

    this.skippedCount = skipped;

    if (mdFiles.length === 0) {
      return;
    }

    const fileEntries = [];

    for (const f of mdFiles) {
      const content = await this.readFileText(f);
      if (!content || content.trim() === '') {
        this.skippedCount++;
        continue;
      }
      const title = f.name.replace(/\.md$/i, '');
      fileEntries.push({
        title,
        content,
        previewHtml: null,
        previewLoading: true,
      });
    }

    if (fileEntries.length === 0) return;

    this.files = fileEntries;
    this.importError = '';
    this.modalOpen = true;

    for (let i = 0; i < this.files.length; i++) {
      this.fetchPreview(i);
    }
  },

  readFileText(file) {
    return new Promise((resolve) => {
      const reader = new FileReader();
      reader.onload = () => resolve(reader.result);
      reader.onerror = () => resolve(null);
      reader.readAsText(file);
    });
  },

  async fetchPreview(index) {
    const file = this.files[index];
    if (!file) return;
    try {
      const res = await fetch(previewUrl, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
          'X-CSRF-TOKEN': csrfToken,
        },
        body: JSON.stringify({ content: file.content }),
      });
      if (res.ok) {
        const data = await res.json();
        file.previewHtml = data.html || null;
      }
    } catch {
      // fallback to raw content (previewHtml stays null)
    } finally {
      file.previewLoading = false;
    }
  },

  removeFile(index) {
    this.files.splice(index, 1);
    if (this.files.length === 0) {
      this.closeModal();
    }
  },

  closeModal() {
    this.modalOpen = false;
    this.files = [];
    this.skippedCount = 0;
    this.importError = '';
  },

  async submitImport() {
    if (this.importing || this.files.length === 0) return;
    this.importing = true;
    this.importError = '';

    const payload = {
      type: this.selectedType,
      files: this.files.map((f) => ({
        title: f.title,
        content: f.content,
      })),
    };

    try {
      const res = await fetch(importUrl, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
          'X-CSRF-TOKEN': csrfToken,
        },
        body: JSON.stringify(payload),
      });

      if (!res.ok) {
        if (res.status === 419) {
          this.importError = 'Session expired. Please refresh the page.';
          return;
        }
        const data = await res.json().catch(() => ({}));
        this.importError = data.message || 'Import failed. Please try again.';
        return;
      }

      const data = await res.json();
      const failedCount = (data.failed || []).length;
      const importedCount = (data.imported || []).length;

      if (failedCount > 0 && importedCount === 0) {
        this.importError = `All ${failedCount} file(s) failed to import.`;
        return;
      }

      if (failedCount > 0) {
        this.importError = `${importedCount} imported, ${failedCount} failed.`;
        return;
      }

      window.location.reload();
    } catch {
      this.importError = 'Import failed. Check your network and try again.';
    } finally {
      this.importing = false;
    }
  },
}));
```

- [ ] **Step 2: Build the frontend assets**

Run:
```bash
npm run build
```

Expected: Build succeeds with no errors.

- [ ] **Step 3: Commit**

```bash
git add resources/js/app.js
git commit -m "feat: add mdDropZone Alpine component for drag-and-drop markdown import"
```

---

### Task 8: Final integration test and cleanup

**Files:**
- All previously modified files

- [ ] **Step 1: Run all import-related tests**

Run:
```bash
php artisan test tests/Feature/Import/
```

Expected: All tests PASS.

- [ ] **Step 2: Run existing import tests for regressions**

Run:
```bash
php artisan test tests/Feature/ImportedEmailSchemaTest.php
```

Expected: PASS (no regressions).

- [ ] **Step 3: Run Laravel Pint for code style**

Run:
```bash
./vendor/bin/pint app/Http/Controllers/ImportController.php app/Services/Import/FileImportService.php
./vendor/bin/pint tests/Feature/Import/
```

Expected: Files formatted (or already clean).

- [ ] **Step 4: Run the full test suite to check for regressions**

Run:
```bash
php artisan test
```

Expected: No new failures.

- [ ] **Step 5: Final commit (if Pint made changes)**

```bash
git add -A
git commit -m "style: apply Laravel Pint formatting to import feature files"
```

---

## File Structure Summary

| File | Action | Responsibility |
|------|--------|----------------|
| `routes/web.php` | Modify | Add 2 new routes |
| `app/Http/Controllers/ImportController.php` | Modify | Add `previewMarkdown` and `importMarkdown` methods |
| `app/Services/Import/FileImportService.php` | Modify | Add `importMarkdownWithMetadata` method, inject `MeetingService` |
| `resources/views/projects/show.blade.php` | Modify | Add drop zone + import modal markup |
| `resources/js/app.js` | Modify | Add `mdDropZone` Alpine component |
| `tests/Feature/Import/MarkdownPreviewTest.php` | Create | Tests for preview endpoint |
| `tests/Feature/Import/MarkdownImportServiceTest.php` | Create | Tests for `importMarkdownWithMetadata` |
| `tests/Feature/Import/MarkdownImportEndpointTest.php` | Create | Tests for import endpoint |
