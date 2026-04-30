# Repo Learning Coach (IdeaTub) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship an in-app codebase-learning workspace (markdown sync, research + lessons UI, quizzes with attempts, progress, capture into `thoughts`, related-thought search) matching `docs/superpowers/specs/2026-04-30-repo-learning-coach-ideatub-design.md`.

**Architecture:** Postgres holds `learning_*` tables owned per user. An Artisan sync command reads markdown + optional JSON manifest from a **filesystem path** stored on each `LearningProject` (`content_root`). UI is **Blade** (same as `ProjectController` / `HelpController`), not Inertia—adjusting the design doc’s “Vue/Inertia” wording at implementation time. Capture uses existing `ThoughtCaptureService`; related content uses `ThoughtSearchService`.

**Tech Stack:** Laravel 12, Blade, Tailwind, Pest, Symfony YAML (already transitive) for frontmatter, existing OpenRouter + pgvector paths.

---

## File structure (creates + touches)

| Path | Role |
|------|------|
| `database/migrations/2026_04_30_120000_create_learning_tables.php` | All `learning_*` tables (no `learning_tracks` in v1—single ordered lesson list). |
| `app/Models/LearningProject.php` … `LearningLessonNote.php` | Eloquent models + relationships. |
| `database/factories/Learning*Factory.php` | Factories for feature tests. |
| `app/Policies/LearningProjectPolicy.php` | `view`, `update`, `delete` scoped to `user_id`. |
| `app/Services/Learning/LearningMarkdownFrontmatterParser.php` | Parse `---` YAML + body; validate required keys. |
| `app/Services/Learning/LearningContentPaths.php` | Resolve `research/*.md`, `curriculum/lessons/*.md`, `learning.config.json` under `content_root`. |
| `app/Services/Learning/LearningSyncService.php` | Upsert/prune by slug inside DB transaction; bump `content_version` when lesson/quiz payload changes. |
| `app/Services/Learning/LearningQuizGradingService.php` | Score submission; create attempt + responses. |
| `app/Services/Learning/LearningThoughtBridge.php` | Build `ThoughtCaptureService::create` payload + optional related query via `ThoughtSearchService`. |
| `app/Console/Commands/LearningSyncCommand.php` | `learning:sync {project}` / `{project_id}`. |
| `app/Http/Controllers/Learning/*Controller.php` | Index, CRUD project, research, lesson, quiz POST, progress POST, capture POST. |
| `app/Http/Requests/Learning/*Request.php` | Validation for store/update/sync/capture/quiz. |
| `resources/views/learning/**/*.blade.php` | Layout, project list/show, research index/show, lesson show (sidebar + content + panels). |
| `routes/web.php` | Auth group `/learn` routes + route names. |
| `app/Http/Controllers/HelpController.php` + `resources/views/help-*.blade.php` + help index link | Help page for markdown layout + `php artisan learning:sync`. |
| `CLAUDE.md`, `AGENTS.md` | Short subsection: feature entrypoints + sync command + MCP reminder. |
| `docs/superpowers/specs/2026-04-30-repo-learning-coach-ideatub-design.md` | Status line + note “UI implemented as Blade”. |
| `THIRD_PARTY_OB1.md` or appendix | Upstream Repo Learning Coach URL + license pointer if not already covered. |
| `tests/Unit/Services/Learning/*Test.php` | Parser, grading, sync idempotency. |
| `tests/Feature/Learning/*Test.php` | Policy, sync command, lesson flow, quiz attempt, capture. |

---

### Task 1: Migration — `learning_*` tables

**Files:**
- Create: `database/migrations/2026_04_30_120000_create_learning_tables.php`

- [ ] **Step 1: Add migration file**

Create the migration with the following complete contents (adjust timestamp prefix if it collides):

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('learning_projects', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('slug', 128);
            $table->string('title');
            $table->string('content_root'); // absolute or base-relative path on server
            $table->string('source_url', 2048)->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'slug']);
        });

        Schema::create('learning_research_documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('learning_project_id')->constrained('learning_projects')->cascadeOnDelete();
            $table->string('slug', 128);
            $table->string('title');
            $table->text('summary')->nullable();
            $table->string('category', 128)->nullable();
            $table->string('source_url', 2048)->nullable();
            $table->longText('body_markdown');
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['learning_project_id', 'slug']);
        });

        Schema::create('learning_lessons', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('learning_project_id')->constrained('learning_projects')->cascadeOnDelete();
            $table->string('slug', 128);
            $table->string('title');
            $table->string('stage', 128)->nullable();
            $table->string('difficulty', 64)->nullable();
            $table->unsignedInteger('order')->default(0);
            $table->text('summary')->nullable();
            $table->json('goals')->nullable();
            $table->json('related_research_slugs')->nullable();
            $table->longText('body_markdown');
            $table->unsignedInteger('content_version')->default(1);
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['learning_project_id', 'slug']);
            $table->index(['learning_project_id', 'order']);
        });

        Schema::create('learning_quizzes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('learning_lesson_id')->constrained('learning_lessons')->cascadeOnDelete();
            $table->string('title');
            $table->unsignedTinyInteger('passing_score')->default(70);
            $table->timestamps();
        });

        Schema::create('learning_quiz_questions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('learning_quiz_id')->constrained('learning_quizzes')->cascadeOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->text('prompt');
            $table->json('options');
            $table->unsignedTinyInteger('correct_option_index');
            $table->text('explanation')->nullable();
            $table->timestamps();

            $table->index(['learning_quiz_id', 'sort_order']);
        });

        Schema::create('learning_lesson_progress', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('learning_lesson_id')->constrained('learning_lessons')->cascadeOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->string('bookmark_position', 512)->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'learning_lesson_id']);
        });

        Schema::create('learning_quiz_attempts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('learning_quiz_id')->constrained('learning_quizzes')->cascadeOnDelete();
            $table->unsignedTinyInteger('score')->default(0);
            $table->boolean('passed')->default(false);
            $table->timestamps();

            $table->index(['user_id', 'learning_quiz_id']);
        });

        Schema::create('learning_quiz_responses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('learning_quiz_attempt_id')->constrained('learning_quiz_attempts')->cascadeOnDelete();
            $table->foreignUuid('learning_quiz_question_id')->constrained('learning_quiz_questions')->cascadeOnDelete();
            $table->unsignedTinyInteger('selected_option_index')->nullable();
            $table->boolean('correct')->default(false);
            $table->timestamps();

            $table->unique(['learning_quiz_attempt_id', 'learning_quiz_question_id']);
        });

        Schema::create('learning_lesson_notes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('learning_lesson_id')->constrained('learning_lessons')->cascadeOnDelete();
            $table->text('body');
            $table->timestamps();

            $table->index(['user_id', 'learning_lesson_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('learning_lesson_notes');
        Schema::dropIfExists('learning_quiz_responses');
        Schema::dropIfExists('learning_quiz_attempts');
        Schema::dropIfExists('learning_lesson_progress');
        Schema::dropIfExists('learning_quiz_questions');
        Schema::dropIfExists('learning_quizzes');
        Schema::dropIfExists('learning_lessons');
        Schema::dropIfExists('learning_research_documents');
        Schema::dropIfExists('learning_projects');
    }
};
```

- [ ] **Step 2: Run migrations**

Run: `php artisan migrate`

Expected: completes without errors on local Postgres (and SQLite for tests after `php artisan migrate --env=testing` if used).

- [ ] **Step 3: Commit**

```bash
git add database/migrations/2026_04_30_120000_create_learning_tables.php
git commit -m "feat(learning): add learning_projects and related tables"
```

---

### Task 2: Models, factories, policy

**Files:**
- Create: `app/Models/LearningProject.php`
- Create: `app/Models/LearningResearchDocument.php`
- Create: `app/Models/LearningLesson.php`
- Create: `app/Models/LearningQuiz.php`
- Create: `app/Models/LearningQuizQuestion.php`
- Create: `app/Models/LearningLessonProgress.php`
- Create: `app/Models/LearningQuizAttempt.php`
- Create: `app/Models/LearningQuizResponse.php`
- Create: `app/Models/LearningLessonNote.php`
- Create: `database/factories/LearningProjectFactory.php` (and factories for child models as needed)
- Create: `app/Policies/LearningProjectPolicy.php`
- Modify: `app/Providers/AppServiceProvider.php` or `AuthServiceProvider` — `LearningProject::class => LearningProjectPolicy::class`

- [ ] **Step 1: Implement models** using `HasUuids` where primary key is uuid (match `Project` model). Fillable arrays mirror migration columns. Relationships:

`LearningProject` `hasMany` research, lessons.  
`LearningLesson` `hasOne` quiz; `hasMany` progress, notes.  
`LearningQuiz` `hasMany` questions, attempts.

- [ ] **Step 2: `LearningProjectPolicy`**

Complete policy:

```php
<?php

namespace App\Policies;

use App\Models\LearningProject;
use App\Models\User;

class LearningProjectPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, LearningProject $learningProject): bool
    {
        return (int) $learningProject->user_id === (int) $user->id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, LearningProject $learningProject): bool
    {
        return (int) $learningProject->user_id === (int) $user->id;
    }

    public function delete(User $user, LearningProject $learningProject): bool
    {
        return (int) $learningProject->user_id === (int) $user->id;
    }
}
```

Register in `AppServiceProvider::boot()`:

```php
use App\Models\LearningProject;
use App\Policies\LearningProjectPolicy;

// inside boot():
Gate::policy(LearningProject::class, LearningProjectPolicy::class);
```

- [ ] **Step 3: Factories** — minimal `LearningProjectFactory` with `user_id` from `User::factory()`, random `slug`, `content_root` pointing to `storage_path('framework/testing/learning-fixture')` for tests.

- [ ] **Step 4: Commit**

```bash
git add app/Models/Learning*.php database/factories/Learning*.php app/Policies/LearningProjectPolicy.php app/Providers/AppServiceProvider.php
git commit -m "feat(learning): models, factories, LearningProjectPolicy"
```

---

### Task 3: Frontmatter parser (unit-tested)

**Files:**
- Create: `app/Services/Learning/LearningMarkdownFrontmatterParser.php`
- Create: `tests/Unit/Services/Learning/LearningMarkdownFrontmatterParserTest.php`

- [ ] **Step 1: Parser implementation**

Parse file contents: split on first two `---` lines; `Symfony\Component\Yaml\Yaml::parse` on the middle block; return `['frontmatter' => array, 'body' => string]`. Throw `InvalidArgumentException` if `slug` or `title` missing for research; for lessons also require `order` (int) or default `order` to `0` explicitly in code and document.

- [ ] **Step 2: Failing test**

```php
<?php

use App\Services\Learning\LearningMarkdownFrontmatterParser;

it('parses research frontmatter and body', function () {
    $raw = <<<'MD'
---
slug: architecture-overview
title: Architecture Overview
summary: Short
category: architecture
---
# Hello

Body here.
MD;

    $p = new LearningMarkdownFrontmatterParser;
    $out = $p->parse($raw);

    expect($out['frontmatter']['slug'])->toBe('architecture-overview')
        ->and($out['body'])->toContain('Body here.');
});
```

- [ ] **Step 3: Run test** — `php artisan test tests/Unit/Services/Learning/LearningMarkdownFrontmatterParserTest.php`

- [ ] **Step 4: Implement until green; commit**

```bash
git add app/Services/Learning/LearningMarkdownFrontmatterParser.php tests/Unit/Services/Learning/LearningMarkdownFrontmatterParserTest.php
git commit -m "feat(learning): markdown YAML frontmatter parser"
```

---

### Task 4: Path resolver + sync service

**Files:**
- Create: `app/Services/Learning/LearningContentPaths.php`
- Create: `app/Services/Learning/LearningSyncService.php`
- Create: `tests/Unit/Services/Learning/LearningSyncServiceTest.php`

**Contracts:**

- `LearningContentPaths::researchGlob(LearningProject $p): array<string>` — full paths under `{content_root}/research/*.md`.
- `LearningContentPaths::lessonGlob` — `{content_root}/curriculum/lessons/*.md`.
- Optional `learning.config.json` at `{content_root}` with `title` override (merge after slug from folder).

`LearningSyncService::sync(LearningProject $project): array{research: int, lessons: int}`:

1. Start transaction.
2. Collect all slugs from disk for research + lessons.
3. Upsert each file; for lessons parse embedded quiz YAML per [OB1 README](https://github.com/NateBJones-Projects/OB1/tree/main/recipes/repo-learning-coach) (`quiz.title`, `quiz.passingScore`, `quiz.questions[]`).
4. Delete DB rows whose slugs are absent from disk (cascade removes quizzes/questions—ensure order: delete orphaned quizzes by lesson id before deleting lessons, or rely on cascade from lesson delete).
5. When lesson markdown or quiz payload hash changes, increment `content_version`.
6. Commit.

- [ ] **Step 1:** Write unit test with temporary directory: two markdown files → sync → assert rows; delete one file → sync → assert pruned.

- [ ] **Step 2:** Implement services; run `php artisan test tests/Unit/Services/Learning/LearningSyncServiceTest.php`.

- [ ] **Step 3: Commit**

```bash
git add app/Services/Learning/LearningContentPaths.php app/Services/Learning/LearningSyncService.php tests/Unit/Services/Learning/LearningSyncServiceTest.php
git commit -m "feat(learning): content paths and sync service"
```

---

### Task 5: Artisan `learning:sync`

**Files:**
- Create: `app/Console/Commands/LearningSyncCommand.php`

- [ ] **Step 1: Command**

Signature: `learning:sync {project : UUID of learning project}`. Resolve model, authorize via policy for console (use `$project->user_id` match or run as owner only—document that console runs as system: pass `--user=` optional or restrict to `APP_ENV=local` only; **simplest v1:** command accepts project UUID and runs sync if project exists—Invoke `LearningSyncService` **without** auth—protect by documenting “run only on trusted hosts”; **better:** add `--user=` id and abort if project not owned.)

Implement owner check:

```php
if ((int) $project->user_id !== (int) $this->option('user')) {
    $this->error('Project does not belong to this user.');
    return self::FAILURE;
}
```

Register option `--user=` required.

- [ ] **Step 2: Feature test** creates user + project + fixture path, runs `Artisan::call('learning:sync', [...])`.

- [ ] **Step 3: Commit**

```bash
git add app/Console/Commands/LearningSyncCommand.php tests/Feature/Learning/LearningSyncCommandTest.php
git commit -m "feat(learning): learning:sync artisan command"
```

---

### Task 6: Quiz grading service

**Files:**
- Create: `app/Services/Learning/LearningQuizGradingService.php`
- Create: `tests/Unit/Services/Learning/LearningQuizGradingServiceTest.php`

- [ ] **Step 1: Grading**

Method `submit(LearningQuiz $quiz, User $user, array $answersByQuestionId): LearningQuizAttempt` where `$answersByQuestionId` is `question_uuid => selected_index`. Load all questions for quiz; verify count matches; compute percent correct; set `passed` vs `passing_score`; insert attempt + one response row per question inside transaction.

- [ ] **Step 2: Tests** — all correct, partial, empty options edge case.

- [ ] **Step 3: Commit**

```bash
git add app/Services/Learning/LearningQuizGradingService.php tests/Unit/Services/Learning/LearningQuizGradingServiceTest.php
git commit -m "feat(learning): quiz grading and attempts"
```

---

### Task 7: HTTP layer — projects + research + lessons (read)

**Files:**
- Create: `app/Http/Controllers/Learning/LearningProjectController.php`
- Create: `app/Http/Controllers/Learning/LearningResearchController.php`
- Create: `app/Http/Controllers/Learning/LearningLessonController.php`
- Create: `app/Http/Requests/Learning/StoreLearningProjectRequest.php`, `UpdateLearningProjectRequest.php`
- Modify: `routes/web.php`
- Create: `resources/views/learning/layout.blade.php`, `projects/index.blade.php`, `projects/create.blade.php`, `projects/show.blade.php`, `research/index.blade.php`, `research/show.blade.php`, `lessons/show.blade.php`

- [ ] **Step 1: Routes** (inside `Route::middleware(['auth'])` group):

```php
Route::prefix('learn')->name('learn.')->group(function () {
    Route::resource('projects', \App\Http\Controllers\Learning\LearningProjectController::class)
        ->parameters(['projects' => 'learning_project'])
        ->except(['edit', 'update']); // or full resource + edit later

    Route::get('projects/{learning_project}/research', [\App\Http\Controllers\Learning\LearningResearchController::class, 'index'])
        ->name('research.index');
    Route::get('projects/{learning_project}/research/{slug}', [\App\Http\Controllers\Learning\LearningResearchController::class, 'show'])
        ->name('research.show');

    Route::get('projects/{learning_project}/lessons/{slug}', [\App\Http\Controllers\Learning\LearningLessonController::class, 'show'])
        ->name('lessons.show');
});
```

Use implicit `LearningProject` binding with scoped slug or uuid—prefer route key `learning_project` resolved by uuid.

- [ ] **Step 2: Controllers** — `authorize` on each action; lesson show loads quiz + questions ordered; sidebar lists lessons `orderBy('order')`.

- [ ] **Step 3: Blade** — extend main app layout (`x-layout` or existing `@extends` pattern from `resources/views/projects/`). Render markdown body via existing `SafeCommonMarkConverter` or `MarkdownDisplayHelper` consistent with Help pages.

- [ ] **Step 4: Feature test** — guest 302; other user 403; owner 200.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Learning routes/web.php resources/views/learning app/Http/Requests/Learning tests/Feature/Learning/LearningReadRoutesTest.php
git commit -m "feat(learning): project and read-only research/lesson UI"
```

---

### Task 8: Progress, quiz POST, lesson notes

**Files:**
- Create: `app/Http/Controllers/Learning/LearningLessonProgressController.php` (invokable or single action)
- Create: `app/Http/Controllers/Learning/LearningQuizAttemptController.php`
- Create: `app/Http/Controllers/Learning/LearningLessonNoteController.php`
- Modify: `routes/web.php`
- Modify: `resources/views/learning/lessons/show.blade.php`

- [ ] **Step 1: POST mark complete** — `Route::post('projects/{learning_project}/lessons/{slug}/progress', ...)` sets `completed_at` on `LearningLessonProgress` upsert.

- [ ] **Step 2: POST quiz** — validate question IDs belong to lesson’s quiz; call `LearningQuizGradingService`; redirect back with flash score.

- [ ] **Step 3: Notes** — simple textarea POST list/store for user’s notes (optional list on lesson page).

- [ ] **Step 4: Feature tests** for quiz submission creates attempt + responses.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Learning/LearningLessonProgressController.php app/Http/Controllers/Learning/LearningQuizAttemptController.php app/Http/Controllers/Learning/LearningLessonNoteController.php routes/web.php resources/views/learning tests/Feature/Learning
git commit -m "feat(learning): progress, quiz attempts, lesson notes"
```

---

### Task 9: Thought capture + related thoughts

**Files:**
- Create: `app/Services/Learning/LearningThoughtBridge.php`
- Create: `app/Http/Controllers/Learning/LearningCaptureController.php`
- Modify: `resources/views/learning/lessons/show.blade.php`

- [ ] **Step 1: Bridge**

Method `captureTakeaway(User $user, LearningLesson $lesson, string $artifactType, string $content): Thought` where `$artifactType` is one of `takeaway`, `confusion`, `lesson_summary`. Call `ThoughtCaptureService::create` and return `$result['thought']` (non-chunked path):

```php
$result = $this->captureService->create([
    'content' => $content,
    'user_id' => $user->id,
    'source' => 'learning',
    'source_metadata' => [
        'learning_project_id' => $lesson->learning_project_id,
        'learning_project_slug' => $lesson->project->slug,
        'lesson_slug' => $lesson->slug,
        'artifact_type' => $artifactType,
        'lesson_url' => route('learn.lessons.show', ['learning_project' => $lesson->learning_project_id, 'slug' => $lesson->slug]),
    ],
    'no_chunking' => true,
]);

return $result['thought'];
```

(Adjust route names/parameters after Task 7 naming is finalized—prefer explicit associative binding keys.)

Optional tags: `extraTags` => `['learning:'.$lesson->project->slug]` if product wants Stream filtering.

- [ ] **Step 2: Related thoughts** — In `LearningLessonController@show`, inject `ThoughtSearchService` and pass `relatedThoughts` collection using query string built from `$lesson->title."\n".$lesson->summary` with small limit (e.g. 5). Guard: if OpenRouter fails in test env, catch and pass empty (or mock in test).

- [ ] **Step 3: Feature test** — HTTP fake or integration: assert Thought row created with metadata keys (use sqlite + nullable embedding—may need to mock `OpenRouterService` in container for tests).

- [ ] **Step 4: Commit**

```bash
git add app/Services/Learning/LearningThoughtBridge.php app/Http/Controllers/Learning/LearningCaptureController.php app/Http/Controllers/Learning/LearningLessonController.php resources/views/learning/lessons/show.blade.php tests/Feature/Learning/LearningCaptureTest.php
git commit -m "feat(learning): capture to thoughts and related search"
```

---

### Task 10: Help + docs + attribution

**Files:**
- Modify: `app/Http/Controllers/HelpController.php`
- Create: `resources/views/help-repo-learning.blade.php`
- Modify: main help index view (where other help links live)
- Modify: `routes/web.php` help routes
- Modify: `CLAUDE.md`, `AGENTS.md`
- Modify: `docs/superpowers/specs/2026-04-30-repo-learning-coach-ideatub-design.md` — Status **Approved**; add note under Architecture that shipped UI is Blade.
- Create or modify: `THIRD_PARTY_OB1.md` — bullet for Repo Learning Coach recipe URL

- [ ] **Step 1:** Help page documents directory layout:

```
content_root/
  learning.config.json   # optional: { "title": "..." }
  research/*.md
  curriculum/lessons/*.md
```

and `php artisan learning:sync {uuid} --user={id}`.

- [ ] **Step 2: Commit**

```bash
git add app/Http/Controllers/HelpController.php resources/views/help-repo-learning.blade.php resources/views/help.blade.php routes/web.php CLAUDE.md AGENTS.md docs/superpowers/specs/2026-04-30-repo-learning-coach-ideatub-design.md THIRD_PARTY_OB1.md
git commit -m "docs(learning): Help page, CLAUDE/AGENTS, spec status, attribution"
```

---

### Task 11: Full suite + Pint

- [ ] **Step 1:** Run `php artisan test`

Expected: all green.

- [ ] **Step 2:** Run `./vendor/bin/pint --dirty`

- [ ] **Step 3: Commit** if formatting changes only.

```bash
git add -A && git commit -m "style: pint learning feature"
```

---

## Self-review (plan vs spec)

| Spec section | Task coverage |
|--------------|---------------|
| Markdown source of truth | Tasks 3–5 |
| Postgres structured state | Task 1–2 |
| In-app UI | Tasks 7–8 (Blade) |
| Quizzes + attempts + progress | Tasks 1, 6, 8 |
| Capture + metadata | Task 9 |
| Related thoughts | Task 9 |
| Sync prune/upsert | Task 4–5 |
| Testing | Tasks 3–9, 11 |
| Help + agent discovery | Task 10 |
| Tracks table | Explicitly **out of v1** (ordered `learning_lessons.order`); update design spec footnote in Task 10 |
| Content root open question | **Resolved:** filesystem path on `learning_projects.content_root` |

Placeholder scan: none intentional; route parameter arrays in snippets must be reconciled with actual route definitions during implementation.

---

## Execution handoff

**Plan complete and saved to `docs/superpowers/plans/2026-04-30-repo-learning-coach-ideatub.md`. Two execution options:**

1. **Subagent-Driven (recommended)** — Dispatch a fresh subagent per task, review between tasks, fast iteration (`superpowers:subagent-driven-development`).

2. **Inline execution** — Run tasks in this session with checkpoints (`superpowers:executing-plans`).

**Which approach do you want?**
