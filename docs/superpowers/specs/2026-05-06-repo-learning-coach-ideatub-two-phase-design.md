# Design: Repo Learning Coach (IdeaTub) — Two-Phase Delivery

**Status:** Draft — design validated in chat, pending written-spec review  
**Date:** 2026-05-06

## Attribution and source

This design adapts the upstream OB1 recipe for local repo learning:

- Upstream recipe: [OB1 `recipes/repo-learning-coach`](https://github.com/NateBJones-Projects/OB1/tree/main/recipes/repo-learning-coach)
- IdeaTub implementation target: hosted Laravel app using existing `thoughts` capture/retrieval infrastructure.

## Goal

Deliver Repo Learning Coach capabilities in IdeaTub using a phased rollout:

1. **Phase 1 (Read + Capture):** markdown sync, research + lesson reading UI, related-thought retrieval, and learning artifact capture into `thoughts`.
2. **Phase 2 (Assessment + Progress):** quiz scoring/history, lesson progress tracking, and optional lesson notes.

The design intentionally reuses IdeaTub's current MCP/web capture and semantic retrieval stack instead of introducing a separate app or backend.

## Non-goals

- No separate local React + Express app in v1/v2.
- No new MCP server for learning workflows.
- No marketplace/collaborative curriculum authoring in these phases.
- No requirement for pixel parity with upstream UI; follow IdeaTub Blade + Tailwind patterns.

## Current parity snapshot

### Already available in IdeaTub

- Durable memory capture via `capture_thought` / `capture_plan` and web capture.
- Semantic retrieval via `search_thoughts` and `ThoughtSearchService`.
- Per-user isolation and MCP authentication model.
- Working-memory retrieval surfaces (`get_working_memory`, REST equivalent) for broader context assembly.

### Missing for Repo Learning Coach

- `learning_*` domain schema and models.
- Markdown content sync pipeline and `learning:sync` command.
- Learning UI surfaces (`/learn` namespace).
- Learning-specific capture metadata and lesson-context related-thought panel.
- Quiz attempt/response persistence, progress tracking, and reporting UI.

## Phase architecture

### Shared architecture (both phases)

- **Markdown source of truth:** `content_root` per learning project, with:
  - `research/*.md`
  - `curriculum/lessons/*.md`
  - optional `learning.config.json`
- **Sync contract:** upsert by stable `slug`, prune removed slugs, deterministic ordering by lesson `order`.
- **Ownership model:** learning projects owned by `user_id`; child records scoped through project ownership.
- **Memory bridge:** reuse `ThoughtCaptureService` and `ThoughtSearchService` rather than duplicating embedding/search logic.

### Phase 1 architecture (Read + Capture)

- Add foundational learning schema for content and quiz definitions:
  - `learning_projects`
  - `learning_research_documents`
  - `learning_lessons`
  - `learning_quizzes`
  - `learning_quiz_questions`
- Implement sync services:
  - frontmatter parser
  - content path resolver
  - upsert/prune sync service
  - Artisan `learning:sync {project} --user={id}`
- Build read and capture UI under auth:
  - project list/detail
  - research index/detail
  - lesson view with sidebar ordering
  - related-thought panel
  - capture panel for `takeaway`, `confusion`, `lesson_summary`

### Phase 2 architecture (Assessment + Progress)

- Extend schema for learner activity:
  - `learning_lesson_progress`
  - `learning_quiz_attempts`
  - `learning_quiz_responses`
  - optional `learning_lesson_notes`
- Add services/controllers for:
  - quiz submission validation/scoring
  - attempt + response persistence in a single transaction
  - completion/bookmark updates
  - note creation/listing (if enabled)
- Extend lesson UI to show:
  - latest score/pass result
  - attempt history summary
  - completion/progress status

## Data model notes

- Keep lesson body as markdown in DB and render via existing safe markdown path.
- Keep quiz question options as JSON array with explicit `correct_option_index`.
- Store `learning_lessons.content_version` in Phase 1; bump on lesson/quiz payload changes during sync.
- In Phase 2, guard quiz submits against stale `content_version` when needed.

## Capture metadata contract (Phase 1)

Learning captures write to `thoughts` with:

- `source`: `learning`
- `source_metadata.learning_project_slug`
- `source_metadata.lesson_slug`
- `source_metadata.artifact_type` (`takeaway` | `confusion` | `lesson_summary`)
- `source_metadata.lesson_url`

Optional product enhancement: add `learning:<project-slug>` tag for stream filtering.

## User flows

### Phase 1 flows

1. User creates a learning project (`slug`, `title`, `content_root`).
2. User runs sync (CLI and/or UI-triggered command path).
3. User browses research and lessons.
4. Lesson page shows markdown + related thoughts.
5. User captures a learning artifact into `thoughts` and can retrieve it via existing search.

### Phase 2 flows

1. User submits quiz answers on lesson page.
2. System computes score and pass/fail against `passingScore`.
3. System stores attempt + per-question responses.
4. User marks lesson complete and/or updates bookmark.
5. User sees current progress and recent attempt results.

## Error handling

### Phase 1

- Validate required frontmatter fields (`slug`, `title`; lesson-specific fields as required by parser contract).
- Fail fast on invalid YAML, unreadable `content_root`, duplicate slugs, or malformed quiz blocks.
- Run prune only after successful parse/upsert pass to avoid partial destructive states.
- Related-thought query failures are non-blocking and render empty state.
- Capture failures return actionable feedback and preserve form input where feasible.

### Phase 2

- Reject quiz submissions with question IDs not belonging to the lesson quiz.
- Use transactional write for attempt + responses to avoid partial records.
- Handle stale payload/version mismatch with explicit user-visible retry guidance.
- Keep historical attempts immutable for auditability.

## Testing strategy

### Phase 1 tests

- **Unit:** parser behavior, sync idempotency, prune behavior, `content_version` bump logic.
- **Feature:** auth/policy scoping, sync command outcomes, lesson/research read routes, capture metadata assertions.
- **Resilience:** related-thought fallback behavior in environments where semantic lookup is unavailable.

### Phase 2 tests

- **Unit:** grading correctness (all correct, partial, unanswered edge cases).
- **Feature:** attempt persistence, pass threshold behavior, progress updates, user isolation.
- **Regression:** lesson content updates do not corrupt or overwrite historical attempts.

## Rollout and acceptance

### Milestones

1. **M1 (Phase 1 alpha):** schema + sync + read UI.
2. **M2 (Phase 1 complete):** related-thought panel + capture panel + docs/help.
3. **M3 (Phase 2 alpha):** quiz submit/scoring + attempt/response persistence.
4. **M4 (Phase 2 complete):** progress/bookmarks/notes + stale-version handling + polish.

### Acceptance criteria

### Phase 1 complete when

- Sync can import and prune markdown-driven research/lessons by slug.
- Lessons/research render correctly and remain owner-scoped.
- Lesson capture writes to `thoughts` with agreed learning metadata.
- Related-thought panel is functional or gracefully degrades.

### Phase 2 complete when

- Quiz submissions persist attempts/responses and return accurate pass/fail.
- Progress state persists and displays reliably.
- Content-version mismatch is handled explicitly.
- Historical attempt records remain readable across sync changes.

## Parity matrix (target state)

- Markdown sync parity: **Phase 1**
- Research/lesson read parity: **Phase 1**
- Capture + retrieval parity: **Phase 1** (leveraging existing IdeaTub primitives)
- Quiz attempts/scoring parity: **Phase 2**
- Progress/history parity: **Phase 2**

## Open decisions (implementation-plan level)

- Whether lesson notes ship in initial Phase 2 or as a scoped follow-up inside Phase 2.
- Whether to expose sync as UI action, CLI-only in v1, or both.
- Whether to store additional denormalized reporting fields for dashboard performance (defer unless needed).

## Scope check

This is scoped as one feature area with two implementation phases. It does not require decomposition into separate specs.
