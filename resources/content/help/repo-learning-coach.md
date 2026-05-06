# Repo Learning Coach in IdeaTub

**Repo Learning Coach** is an in-app learning surface that mirrors the intent of the upstream OB1 recipe [repo-learning-coach](https://github.com/NateBJones-Projects/OB1/tree/main/recipes/repo-learning-coach): markdown-backed curriculum and research, synced into IdeaTub, with capture into your normal **thoughts** stream.

There is **no separate learning MCP server**; use the web app at **`/learn`** (signed in) after content is synced.

## What ships today (two phases)

| Phase | Capabilities |
|-------|----------------|
| **1 — Read + capture** | Project list/detail, research and lesson readers, **related thoughts** (semantic search when configured), **capture** from a lesson into thoughts with learning metadata. |
| **2 — Assessment + progress** | Per-lesson **quiz** submit and scoring, **attempt history**, **lesson progress** (bookmark + mark complete), **lesson notes**. Quiz submits are checked against **lesson content version** so stale pages are rejected after a sync. |

## Content folder layout (`content_root`)

Each **learning project** points at a directory on disk (the project’s `content_root`). The sync command reads:

- **`research/*.md`** — research documents (YAML frontmatter + markdown body).
- **`curriculum/lessons/*.md`** — lessons (frontmatter + body). Optional quiz data is defined in lesson frontmatter and normalized into `learning_quizzes` / `learning_quiz_questions`.

Optional **`learning.config.json`** at the content root can hold project-level defaults (see sync service in the codebase for the exact schema).

Slugs come from frontmatter (`slug`); sync **upserts** by slug and **prunes** rows whose files disappeared.

## Frontmatter essentials

Typical lesson/research frontmatter includes at least:

- **`slug`** — stable id (matches filename convention in docs).
- **`title`** — display title.

Lessons may also include `summary`, `goals`, `order`, `stage`, `difficulty`, `relatedResearch` or `related_research_slugs`, and quiz-related keys as supported by `LearningSyncService`.

## Sync from the repo machine

Run Artisan on a machine that can read `content_root`:

```bash
php artisan learning:sync {project_slug} --user={your_user_id}
```

- **`{project_slug}`** — the `learning_projects.slug` for that user (create/configure the project in the DB or your admin flow so `content_root` is set to the curriculum directory).
- **`--user`** — numeric user id who **owns** the project; only that user’s project row is updated.

Re-run after editing markdown; lesson **content_version** bumps when lesson/quiz payload changes so the UI can block outdated quiz submissions.

## Using it in the app

1. Open **`/learn/projects`** to list your learning projects.
2. Open a project, then **Research** or **Lessons**.
3. On a lesson: read content, use **Related thoughts** if semantic search is available, **Capture** to save takeaways into thoughts, optional **Quiz** / **Progress** / **Notes** when present.

**Related thoughts** require an embeddings-capable setup (e.g. OpenRouter key configured for your environment). If search is unavailable, the panel stays empty with an explanatory message.

## Capture and Stream

Captures use the same pipeline as other captures: artifacts appear as **thoughts** with `source` and **source_metadata** (including `learning_project_slug`, `lesson_slug`, `artifact_type`, `lesson_url`, etc.) so you can find them in Stream and search.

## Further reading

- In-repo design: `docs/superpowers/specs/2026-05-06-repo-learning-coach-ideatub-two-phase-design.md`
- Upstream recipe: [OB1 `recipes/repo-learning-coach`](https://github.com/NateBJones-Projects/OB1/tree/main/recipes/repo-learning-coach)
