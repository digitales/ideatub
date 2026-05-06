# Repo Learning Coach (IdeaTub) Two-Phase Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship Repo Learning Coach inside IdeaTub in two phases: Phase 1 for markdown sync, read surfaces, related-thoughts, and capture; Phase 2 for quiz attempts/scoring and lesson progress.

**Architecture:** Reuse existing IdeaTub primitives (`ThoughtCaptureService`, `ThoughtSearchService`, Blade routing/policies) and add a focused `learning_*` domain. Phase 1 establishes content schema and sync/read/capture paths. Phase 2 layers learner-state tables and quiz/progress write flows without reworking Phase 1 read infrastructure.

**Tech Stack:** Laravel 12, Blade, Tailwind, Pest, PostgreSQL/SQLite test support, existing OpenRouter/pgvector paths.

---

## File structure (creates + touches)

| Path | Role |
|------|------|
| `database/migrations/2026_05_06_120000_create_learning_phase1_tables.php` | Phase 1 content tables (`projects`, `research_documents`, `lessons`, `quizzes`, `quiz_questions`). |
| `database/migrations/2026_05_06_120100_add_content_version_to_learning_lessons.php` | Versioning column for stale submission handling later. |
| `database/migrations/2026_05_06_120200_create_learning_phase2_tables.php` | Phase 2 learner-state tables (`progress`, `attempts`, `responses`, optional `notes`). |
| `app/Models/Learning*.php` | Learning domain models and relationships. |
| `app/Policies/LearningProjectPolicy.php` | Owner-only access scope. |
| `app/Services/Learning/LearningMarkdownFrontmatterParser.php` | Parse YAML frontmatter + markdown body. |
| `app/Services/Learning/LearningContentPaths.php` | Resolve `content_root` file paths and globs. |
| `app/Services/Learning/LearningSyncService.php` | Upsert/prune logic and content_version bumping. |
| `app/Services/Learning/LearningThoughtBridge.php` | Capture learning artifacts into `thoughts` + source metadata. |
| `app/Services/Learning/LearningQuizGradingService.php` | Phase 2 grading + attempt persistence transaction. |
| `app/Console/Commands/LearningSyncCommand.php` | `learning:sync {project} --user={id}` command entrypoint. |
| `app/Http/Controllers/Learning/*.php` | Learning project/read/capture/progress/quiz endpoints. |
| `app/Http/Requests/Learning/*.php` | Input validation for create/update/capture/quiz flows. |
| `resources/views/learning/**/*.blade.php` | Learning UI screens and reusable partials. |
| `routes/web.php` | `/learn` route registration. |
| `resources/views/help-repo-learning.blade.php`, `app/Http/Controllers/HelpController.php` | User help docs for folder contract and sync command. |
| `tests/Unit/Services/Learning/*Test.php` | Parser/sync/grading unit tests. |
| `tests/Feature/Learning/*Test.php` | Auth/policy/routes/capture/quiz/progress feature tests. |

---

### Task 1: Phase 1 schema migration

**Files:**
- Create: `database/migrations/2026_05_06_120000_create_learning_phase1_tables.php`
- Test: existing migration smoke (`php artisan migrate` + `php artisan test`)

- [ ] **Step 1: Write migration with Phase 1 content tables**

Create migration with:
- `learning_projects` (`id` uuid, `user_id`, `slug`, `title`, `content_root`, `source_url`, timestamps, unique `user_id+slug`)
- `learning_research_documents` (`learning_project_id`, `slug`, `title`, `summary`, `category`, `source_url`, `body_markdown`, `synced_at`)
- `learning_lessons` (`learning_project_id`, `slug`, `title`, `stage`, `difficulty`, `order`, `summary`, `goals` json, `related_research_slugs` json, `body_markdown`, `synced_at`)
- `learning_quizzes` (`learning_lesson_id`, `title`, `passing_score`)
- `learning_quiz_questions` (`learning_quiz_id`, `sort_order`, `prompt`, `options` json, `correct_option_index`, `explanation`)

- [ ] **Step 2: Run migration locally**

Run: `php artisan migrate`  
Expected: migration succeeds with new `learning_*` Phase 1 tables.

- [ ] **Step 3: Run baseline test pass**

Run: `php artisan test`  
Expected: no regression from schema changes.

- [ ] **Step 4: Commit**

```bash
git add database/migrations/2026_05_06_120000_create_learning_phase1_tables.php
git commit -m "feat(learning): add phase 1 learning content schema"
```

---

### Task 2: Lesson content version column

**Files:**
- Create: `database/migrations/2026_05_06_120100_add_content_version_to_learning_lessons.php`

- [ ] **Step 1: Add column migration**

Add `content_version` unsigned integer default `1` to `learning_lessons`.

- [ ] **Step 2: Apply migration**

Run: `php artisan migrate`  
Expected: new `content_version` column present.

- [ ] **Step 3: Commit**

```bash
git add database/migrations/2026_05_06_120100_add_content_version_to_learning_lessons.php
git commit -m "feat(learning): add lesson content_version"
```

---

### Task 3: Models and policy for Phase 1

**Files:**
- Create: `app/Models/LearningProject.php`
- Create: `app/Models/LearningResearchDocument.php`
- Create: `app/Models/LearningLesson.php`
- Create: `app/Models/LearningQuiz.php`
- Create: `app/Models/LearningQuizQuestion.php`
- Create: `app/Policies/LearningProjectPolicy.php`
- Modify: policy registration location used by repo (`AuthServiceProvider` or `AppServiceProvider`)
- Test: `tests/Feature/Learning/LearningProjectPolicyTest.php`

- [ ] **Step 1: Write failing policy test**

Create feature test that asserts:
- owner can view project
- non-owner gets forbidden

- [ ] **Step 2: Run policy test to verify failure**

Run: `php artisan test tests/Feature/Learning/LearningProjectPolicyTest.php`  
Expected: FAIL due to missing models/policy or unregistered policy.

- [ ] **Step 3: Implement models with relationships**

Implement `HasUuids`, fillables/casts, and key relationships:
- project `hasMany` research docs + lessons
- lesson `hasOne` quiz
- quiz `hasMany` questions

- [ ] **Step 4: Implement and register policy**

Owner check on `view/update/delete` comparing `user_id`.

- [ ] **Step 5: Re-run policy test**

Run: `php artisan test tests/Feature/Learning/LearningProjectPolicyTest.php`  
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Models/Learning*.php app/Policies/LearningProjectPolicy.php app/Providers/*.php tests/Feature/Learning/LearningProjectPolicyTest.php
git commit -m "feat(learning): add phase 1 models and project ownership policy"
```

---

### Task 4: Frontmatter parser service (TDD)

**Files:**
- Create: `app/Services/Learning/LearningMarkdownFrontmatterParser.php`
- Create: `tests/Unit/Services/Learning/LearningMarkdownFrontmatterParserTest.php`

- [ ] **Step 1: Write failing parser tests**

Cover:
- valid frontmatter + markdown body parse
- invalid/missing frontmatter raises exception
- missing required keys (`slug`, `title`) raises exception

- [ ] **Step 2: Run failing unit test**

Run: `php artisan test tests/Unit/Services/Learning/LearningMarkdownFrontmatterParserTest.php`  
Expected: FAIL due to missing parser.

- [ ] **Step 3: Implement minimal parser**

Use delimiter-based split on first two `---` blocks and YAML parsing. Return:
- `frontmatter` array
- `body` markdown string

- [ ] **Step 4: Re-run parser test**

Run: `php artisan test tests/Unit/Services/Learning/LearningMarkdownFrontmatterParserTest.php`  
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Learning/LearningMarkdownFrontmatterParser.php tests/Unit/Services/Learning/LearningMarkdownFrontmatterParserTest.php
git commit -m "feat(learning): add markdown frontmatter parser"
```

---

### Task 5: Content path resolver + sync service (Phase 1 core)

**Files:**
- Create: `app/Services/Learning/LearningContentPaths.php`
- Create: `app/Services/Learning/LearningSyncService.php`
- Create: `tests/Unit/Services/Learning/LearningSyncServiceTest.php`

- [ ] **Step 1: Write failing sync tests with temp fixture directories**

Cover:
- imports research + lessons by slug
- re-run is idempotent
- removed files are pruned from DB
- lesson payload changes increment `content_version`

- [ ] **Step 2: Run failing sync tests**

Run: `php artisan test tests/Unit/Services/Learning/LearningSyncServiceTest.php`  
Expected: FAIL due to missing services.

- [ ] **Step 3: Implement content path resolver**

Expose methods resolving:
- `{content_root}/research/*.md`
- `{content_root}/curriculum/lessons/*.md`
- optional `{content_root}/learning.config.json`

- [ ] **Step 4: Implement sync upsert/prune logic**

Implement transactional flow:
1. parse disk files
2. upsert research/lessons/quizzes/questions by slug
3. prune missing slugs only after successful parse pass
4. bump lesson `content_version` on lesson body or quiz payload change

- [ ] **Step 5: Re-run sync tests**

Run: `php artisan test tests/Unit/Services/Learning/LearningSyncServiceTest.php`  
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Services/Learning/LearningContentPaths.php app/Services/Learning/LearningSyncService.php tests/Unit/Services/Learning/LearningSyncServiceTest.php
git commit -m "feat(learning): implement content path and sync services"
```

---

### Task 6: `learning:sync` Artisan command

**Files:**
- Create: `app/Console/Commands/LearningSyncCommand.php`
- Modify: command registration path used by repo
- Create: `tests/Feature/Learning/LearningSyncCommandTest.php`

- [ ] **Step 1: Write failing command test**

Test command with:
- valid owner `--user` sync works
- mismatched owner returns failure

- [ ] **Step 2: Run failing command test**

Run: `php artisan test tests/Feature/Learning/LearningSyncCommandTest.php`  
Expected: FAIL.

- [ ] **Step 3: Implement command**

Signature: `learning:sync {project} {--user=}`  
Behavior:
- load project UUID
- verify owner matches `--user`
- call `LearningSyncService`
- output synced research/lesson counts

- [ ] **Step 4: Re-run command test**

Run: `php artisan test tests/Feature/Learning/LearningSyncCommandTest.php`  
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Console/Commands/LearningSyncCommand.php tests/Feature/Learning/LearningSyncCommandTest.php
git commit -m "feat(learning): add learning sync artisan command"
```

---

### Task 7: Phase 1 read UI routes and controllers

**Files:**
- Create: `app/Http/Controllers/Learning/LearningProjectController.php`
- Create: `app/Http/Controllers/Learning/LearningResearchController.php`
- Create: `app/Http/Controllers/Learning/LearningLessonController.php`
- Create: `app/Http/Requests/Learning/StoreLearningProjectRequest.php`
- Create: `app/Http/Requests/Learning/UpdateLearningProjectRequest.php`
- Modify: `routes/web.php`
- Create: `resources/views/learning/projects/index.blade.php`
- Create: `resources/views/learning/projects/show.blade.php`
- Create: `resources/views/learning/research/index.blade.php`
- Create: `resources/views/learning/research/show.blade.php`
- Create: `resources/views/learning/lessons/show.blade.php`
- Test: `tests/Feature/Learning/LearningReadRoutesTest.php`

- [ ] **Step 1: Write failing route/policy feature tests**

Cover:
- guest redirected to login
- owner can access routes
- non-owner forbidden

- [ ] **Step 2: Run failing feature test**

Run: `php artisan test tests/Feature/Learning/LearningReadRoutesTest.php`  
Expected: FAIL due to missing routes/controllers/views.

- [ ] **Step 3: Implement routes and read controllers**

Add `/learn` prefixed routes for:
- project list/show
- research index/show by slug
- lesson show by slug

All actions call `authorize('view', $learningProject)`.

- [ ] **Step 4: Implement minimal read views**

Render:
- lesson/research markdown content
- lesson sidebar ordered by `order`

- [ ] **Step 5: Re-run read feature tests**

Run: `php artisan test tests/Feature/Learning/LearningReadRoutesTest.php`  
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Learning app/Http/Requests/Learning routes/web.php resources/views/learning tests/Feature/Learning/LearningReadRoutesTest.php
git commit -m "feat(learning): add phase 1 read routes and views"
```

---

### Task 8: Phase 1 capture bridge + related thoughts panel

**Files:**
- Create: `app/Services/Learning/LearningThoughtBridge.php`
- Create: `app/Http/Controllers/Learning/LearningCaptureController.php`
- Modify: `app/Http/Controllers/Learning/LearningLessonController.php`
- Modify: `resources/views/learning/lessons/show.blade.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/Learning/LearningCaptureTest.php`

- [ ] **Step 1: Write failing capture feature test**

Assert POST capture:
- creates thought row
- sets `source=learning`
- includes `source_metadata` keys (`learning_project_slug`, `lesson_slug`, `artifact_type`, `lesson_url`)

- [ ] **Step 2: Run failing capture test**

Run: `php artisan test tests/Feature/Learning/LearningCaptureTest.php`  
Expected: FAIL.

- [ ] **Step 3: Implement capture bridge + controller**

Add validated artifact type enum and pass payload to `ThoughtCaptureService`.

- [ ] **Step 4: Add related-thought query in lesson show**

Use `ThoughtSearchService` query seed from lesson title/summary; catch exceptions and return empty list.

- [ ] **Step 5: Re-run capture test**

Run: `php artisan test tests/Feature/Learning/LearningCaptureTest.php`  
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Services/Learning/LearningThoughtBridge.php app/Http/Controllers/Learning/LearningCaptureController.php app/Http/Controllers/Learning/LearningLessonController.php resources/views/learning/lessons/show.blade.php routes/web.php tests/Feature/Learning/LearningCaptureTest.php
git commit -m "feat(learning): add lesson capture and related thoughts"
```

---

### Task 9: Phase 2 schema migration

**Files:**
- Create: `database/migrations/2026_05_06_120200_create_learning_phase2_tables.php`

- [ ] **Step 1: Add learner-state migration**

Add:
- `learning_lesson_progress` (`user_id`, `learning_lesson_id`, `completed_at`, `bookmark_position`)
- `learning_quiz_attempts` (`user_id`, `learning_quiz_id`, `score`, `passed`, `lesson_content_version`)
- `learning_quiz_responses` (`learning_quiz_attempt_id`, `learning_quiz_question_id`, `selected_option_index`, `correct`)
- `learning_lesson_notes` (optional in same migration; `user_id`, `learning_lesson_id`, `body`)

- [ ] **Step 2: Run migration**

Run: `php artisan migrate`  
Expected: Phase 2 tables created successfully.

- [ ] **Step 3: Commit**

```bash
git add database/migrations/2026_05_06_120200_create_learning_phase2_tables.php
git commit -m "feat(learning): add phase 2 progress and quiz attempt tables"
```

---

### Task 10: Phase 2 models + grading service

**Files:**
- Create: `app/Models/LearningLessonProgress.php`
- Create: `app/Models/LearningQuizAttempt.php`
- Create: `app/Models/LearningQuizResponse.php`
- Create: `app/Models/LearningLessonNote.php` (if enabled)
- Modify: phase 1 models to add new relationships
- Create: `app/Services/Learning/LearningQuizGradingService.php`
- Create: `tests/Unit/Services/Learning/LearningQuizGradingServiceTest.php`

- [ ] **Step 1: Write failing grading tests**

Cover:
- all-correct answers produce 100 and pass
- partial answers compute correct score and pass/fail correctly
- invalid question IDs rejected
- attempt stores per-question response correctness

- [ ] **Step 2: Run failing grading tests**

Run: `php artisan test tests/Unit/Services/Learning/LearningQuizGradingServiceTest.php`  
Expected: FAIL.

- [ ] **Step 3: Implement new models + relationships**

Ensure attempt references quiz + user and stores `lesson_content_version`.

- [ ] **Step 4: Implement grading service transaction**

Atomic write:
1. validate quiz question ownership
2. compute score and pass flag
3. create attempt
4. create responses

- [ ] **Step 5: Re-run grading tests**

Run: `php artisan test tests/Unit/Services/Learning/LearningQuizGradingServiceTest.php`  
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Models/Learning*.php app/Services/Learning/LearningQuizGradingService.php tests/Unit/Services/Learning/LearningQuizGradingServiceTest.php
git commit -m "feat(learning): add grading service and phase 2 models"
```

---

### Task 11: Phase 2 quiz/progress HTTP flows

**Files:**
- Create: `app/Http/Controllers/Learning/LearningQuizAttemptController.php`
- Create: `app/Http/Controllers/Learning/LearningLessonProgressController.php`
- Create: `app/Http/Controllers/Learning/LearningLessonNoteController.php` (if notes enabled)
- Create: `app/Http/Requests/Learning/SubmitLearningQuizRequest.php`
- Create: `app/Http/Requests/Learning/UpsertLearningProgressRequest.php`
- Modify: `routes/web.php`
- Modify: `resources/views/learning/lessons/show.blade.php`
- Test: `tests/Feature/Learning/LearningQuizAndProgressTest.php`

- [ ] **Step 1: Write failing feature tests**

Cover:
- owner can submit quiz and attempt rows are created
- stale `content_version` submission is rejected with clear message
- owner can mark complete/bookmark
- non-owner forbidden

- [ ] **Step 2: Run failing feature tests**

Run: `php artisan test tests/Feature/Learning/LearningQuizAndProgressTest.php`  
Expected: FAIL.

- [ ] **Step 3: Implement POST controllers and routes**

Add routes for:
- quiz submit
- progress upsert
- notes post (if included)

- [ ] **Step 4: Add lesson UI sections for results/progress**

Render latest attempt summary and completion state.

- [ ] **Step 5: Re-run feature tests**

Run: `php artisan test tests/Feature/Learning/LearningQuizAndProgressTest.php`  
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Learning app/Http/Requests/Learning routes/web.php resources/views/learning/lessons/show.blade.php tests/Feature/Learning/LearningQuizAndProgressTest.php
git commit -m "feat(learning): add phase 2 quiz submission and progress flows"
```

---

### Task 12: Help/docs updates and attribution

**Files:**
- Create: `resources/views/help-repo-learning.blade.php`
- Modify: `app/Http/Controllers/HelpController.php`
- Modify: help index view
- Modify: `routes/web.php` (help route)
- Modify: `CLAUDE.md`
- Modify: `AGENTS.md`

- [ ] **Step 1: Add help page content**

Document:
- expected content folder layout
- frontmatter essentials
- `learning:sync {project} --user={id}`
- two-phase feature status (Phase 1/2 capabilities)

- [ ] **Step 2: Link help page from help index**

Ensure discoverability from existing help surfaces.

- [ ] **Step 3: Update agent docs pointers**

Add short section for learning sync and capture context.

- [ ] **Step 4: Commit**

```bash
git add resources/views/help-repo-learning.blade.php app/Http/Controllers/HelpController.php resources/views/help*.blade.php routes/web.php CLAUDE.md AGENTS.md
git commit -m "docs(learning): add help page and agent guidance"
```

---

### Task 13: Full verification, lint/format, and final regression pass

**Files:**
- Modify: any files touched by formatter
- Test: full suite

- [ ] **Step 1: Run targeted learning test suite**

Run: `php artisan test tests/Unit/Services/Learning tests/Feature/Learning`  
Expected: PASS.

- [ ] **Step 2: Run full app test suite**

Run: `php artisan test`  
Expected: PASS.

- [ ] **Step 3: Run formatter**

Run: `./vendor/bin/pint --dirty`  
Expected: clean formatting for touched files.

- [ ] **Step 4: Re-run learning tests after formatting**

Run: `php artisan test tests/Unit/Services/Learning tests/Feature/Learning`  
Expected: PASS.

- [ ] **Step 5: Commit formatting-only changes (if any)**

```bash
git add -A
git commit -m "style: format learning feature files"
```

---

## Self-review (spec coverage)

- **Phase split:** Explicitly preserved across tasks (Phase 1 Tasks 1-8, Phase 2 Tasks 9-11).
- **Markdown sync + prune:** Covered in Task 5 and command entrypoint Task 6.
- **Read surfaces:** Covered in Task 7.
- **Capture + related thoughts:** Covered in Task 8.
- **Quiz/progress:** Covered in Tasks 9-11.
- **Help/discoverability:** Covered in Task 12.
- **Verification:** Covered in Task 13.

Placeholder scan: no TBD/TODO placeholders.  
Type consistency: table/service names align with spec and prior tasks.

---

## Execution handoff

**Plan complete and saved to `docs/superpowers/plans/2026-05-06-repo-learning-coach-ideatub-two-phase-implementation.md`. Two execution options:**

**1. Subagent-Driven (recommended)** - I dispatch a fresh subagent per task, review between tasks, fast iteration.

**2. Inline Execution** - Execute tasks in this session using executing-plans, batch execution with checkpoints.

**Which approach?**
