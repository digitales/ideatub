# Design: Repo Learning Coach (IdeaTub)

**Status:** Draft — awaiting implementation plan  
**Date:** 2026-04-30

## Attribution and source

This design adapts the **Repo Learning Coach** recipe from the **OB1** project.

- **Upstream recipe:** [OB1 — `recipes/repo-learning-coach`](https://github.com/NateBJones-Projects/OB1/tree/main/recipes/repo-learning-coach) (README, `schema.sql`, markdown content contract, `npm run sync` semantics).
- IdeaTub ships a **hosted integration**: same *roles* (research library, lesson path, quizzes, progress, capture into long-lived memory), implemented on **Laravel + Vue/Inertia + PostgreSQL/pgvector**, using existing **`thoughts`** and **`ThoughtSearchService`** instead of the recipe’s standalone React app and Supabase `repo_learning_*` tables.

When landing code or user-facing copy derived from upstream, include a short **license/attribution** note where appropriate; verify OB1 `LICENSE` / `metadata.json` at implementation time.

## Goal

Deliver a **full learning workspace** inside IdeaTub for onboarding to a codebase (or any structured curriculum backed by markdown):

1. **Markdown as source of truth** — research docs and lessons live in files with YAML frontmatter (fields aligned with upstream README examples: `slug`, `title`, `summary`, `category`, lesson `stage`, `difficulty`, `order`, `goals`, `relatedResearch`, embedded `quiz` block).
2. **Structured state in Postgres** — projects, synced research and lesson rows, quizzes and questions, per-user lesson progress, quiz attempts and per-question responses, optional lesson notes/comments.
3. **In-app UI** — sidebar lesson path, research library, lesson reader, quiz flow with scoring, progress indicators, panel to capture **takeaways**, **confusion notes**, and **lesson summaries** into **`thoughts`** with useful `metadata` / `source_metadata`.
4. **Related prior thoughts** — for each lesson, semantic retrieval via existing embedding pipeline (OpenRouter) and neighbor search scoped to the current user.

## Non-goals (v1)

- **No** separate local React + Express app; behavior lives in IdeaTub only.
- **No** new MCP server required for v1 (existing `capture_thought` / `capture_plan` / `search_thoughts` remain available to agents; optional thin MCP helpers are a later enhancement).
- **No** public curriculum marketplace or multi-editor collaborative authoring in v1 — **owner-only** learning projects unless an existing IdeaTub sharing primitive is explicitly extended later.
- **Pixel-parity** with the upstream Vite UI is not required; layout and components follow IdeaTub patterns.

## Upstream entity mapping

Logical parity with upstream tables (names are IdeaTub-facing; adjust to Laravel conventions):

| Upstream (recipe) | IdeaTub (proposed) |
|-------------------|-------------------|
| `repo_learning_projects` | `learning_projects` |
| `repo_learning_research_documents` | `learning_research_documents` |
| `repo_learning_tracks` | `learning_tracks` (if multi-track; else optional single implicit track) |
| `repo_learning_lessons` | `learning_lessons` |
| `repo_learning_quizzes` | `learning_quizzes` |
| `repo_learning_quiz_questions` | `learning_quiz_questions` |
| `repo_learning_lesson_progress` | `learning_lesson_progress` |
| `repo_learning_quiz_attempts` | `learning_quiz_attempts` |
| `repo_learning_quiz_responses` | `learning_quiz_responses` |
| `repo_learning_lesson_comments` | `learning_lesson_notes` (or `comments` if aligned with app-wide comment patterns) |

**Foreign keys:** All learner-specific rows (`progress`, `attempts`, `responses`, notes) include `user_id` and reference content rows keyed by project + stable **`slug`** from frontmatter after sync.

## Architecture (conceptual)

| Layer | Responsibility |
|-------|----------------|
| **Content root** | Per-project filesystem path on disk (developer machine or deploy host) **or** future upload bundle; v1 may assume a configured absolute/relative path per `learning_project`. |
| **Project config** | Small manifest (JSON/YAML/PHP) analogous to `repo-learning.config.ts`: project `slug`, display title, optional `sourceUrl` / repo URL. |
| **Sync** | Artisan command (e.g. `learning:sync {project}`) reads markdown, parses frontmatter + body, upserts rows by **`slug`**, **prunes** DB rows for slugs removed from disk — same contract as upstream `npm run sync`. |
| **Services** | Sync service, quiz grading (compare selected option to `correctOption` / index), progress updates, thought capture builder (content + metadata), “related thoughts” query wrapping `ThoughtSearchService`. |
| **HTTP / Inertia** | Authenticated routes under a namespace such as `/learn` or `/learning`: project list, project dashboard, research index/detail, lesson show, quiz submit, capture endpoints or form posts. |
| **Policies** | `user_id` on `learning_projects` (owner); all queries scoped to owner for v1. |

## Data model notes

- **Lesson body:** Store normalized markdown **or** HTML snapshot post-sync; rendering path should match existing markdown handling elsewhere in IdeaTub where possible.
- **Quiz questions:** Normalize to rows (`learning_quiz_questions`) with JSON acceptable only where shape is stable and validated (prefer columns for prompt, options array, correct index/key, explanation).
- **Attempts:** Each quiz submission creates one **`learning_quiz_attempt`** (score, passed flag, timestamps) and **`learning_quiz_responses`** rows per question for history and review (parity with upstream).
- **Progress:** `learning_lesson_progress` records completion state and optional position/bookmark for resume.
- **Thoughts integration:** Captures create `Thought` records; recommend `source_metadata` including `learning_project_slug`, `lesson_slug`, `artifact_type` (`takeaway` | `confusion` | `lesson_summary`), optional link back to lesson URL path for deep linking.

## Key user flows

1. **Create project** — User registers a learning project (title, slug, content root path). Run sync (UI button or CLI).
2. **Browse** — Research list/detail; lesson list with ordering from frontmatter `order` / track grouping.
3. **Study lesson** — Render markdown; show related thoughts callout; update progress on explicit “mark complete” or configurable rule.
4. **Take quiz** — Submit answers → persist attempt + responses → show score vs `passingScore` from frontmatter.
5. **Capture** — User selects artifact type, edits text, saves → `Thought` persisted and embedded via existing jobs/pipeline.

## Error handling

- **Sync:** Report missing files, YAML errors, duplicate slugs across files; fail transaction per project or per file with clear logs; do not partially delete without completing prune pass (define transactional boundaries in implementation plan).
- **Quiz:** Validate question IDs belong to lesson’s quiz; reject stale attempts if lesson content version bumped (optional `content_version` on lesson row — open for implementation plan).
- **Capture:** On embedding failure, follow existing Thought creation failure behavior (surface to user, retry).

## Testing

- **Unit:** Frontmatter parser, slug normalization, sync upsert/prune idempotency, quiz scoring edge cases (empty quiz, partial attempts).
- **Feature:** Policy denies other users; happy-path sync + lesson view + quiz submit + capture creates thought visible to owner.
- **SQLite CI:** Use nullable vector / simplified search paths consistent with existing `Thought` tests where pgvector is absent.

## Discoverability

- **Help:** New Help page (e.g. `/help/repo-learning` or `/help/learn-codebase`) describing markdown layout, sync command, and capture semantics.
- **Agent docs:** `CLAUDE.md` / `AGENTS.md` subsection with paths, Artisan sync name, and MCP reminders (`capture_thought`, tags/`plan_slug` conventions if long-form captures are added later).

## Rollout

1. Migrations + models + factories  
2. Sync command + minimal project CRUD  
3. Read-only UI (research + lessons)  
4. Progress + quizzes + attempts  
5. Capture panel + related thoughts  
6. Help + agent doc pointers  

## Open questions (non-blocking for spec approval)

- **Hosting content root:** Single-server path only for v1 vs pushing markdown via IdeaTub file upload — decide in implementation plan.
- **Tracks:** Required if every lesson maps to one default track, or omit `learning_tracks` until a second track exists (YAGNI).
- **Naming in UI:** “Repo learning”, “Codebase lessons”, or product-specific label — copy pass during implementation.

## Verification (spec completeness)

- Goals and non-goals align with user choice: **full** lesson + quiz + attempts + progress + capture where feasible.
- Schema mapping covers upstream README table list.
- Thought integration and semantic related-content path explicitly reuse existing services.
