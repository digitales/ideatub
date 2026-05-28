# IdeaTub – Claude project instructions

When the project is opened in Claude Desktop or Claude Code, this file is read automatically.

## IdeaTub: Read working memory

When you need scoped memory for context assembly, use either interface:

- **MCP method:** `get_working_memory`
- **REST endpoint:** `GET /api/thoughts/working-memory`

Required scope parameters for both:

- `scope_type`: `global`, `project`, or `insights`
- `scope_key`: scope identifier (`global` for global and insights)

Canonical examples:

- Global scope: `scope_type=global`, `scope_key=global`
- Project scope: `scope_type=project`, `scope_key=my-app`
- Insights scope: `scope_type=insights`, `scope_key=global` (research-heavy corpus; versioned like other scopes)

MCP JSON-RPC example:

```json
{
  "jsonrpc": "2.0",
  "id": 10,
  "method": "get_working_memory",
  "params": {
    "scope_type": "project",
    "scope_key": "my-app"
  }
}
```

REST example:

```bash
curl -H "Authorization: Bearer <OAUTH_TOKEN>" \
  "http://localhost:8000/api/thoughts/working-memory?scope_type=global&scope_key=global"
```

**External-first hybrid (project scopes):** After `capture_plan`, sync curated `current.md` via MCP **`upsert_working_memory`** (`scope_key` = **IdeaTub project UUID**, not slug; optional `source_label`, e.g. `elixirr-sync`). Fresh external memory is protected from accidental overwrite on refresh. Inspect prior snapshots with **`list_working_memory_versions`** / **`get_working_memory_version`**. Response includes **`canonical_version_id`**, **`canonical_created_at`**, and **`source_label`** for staleness checks. See `docs/mcp-integration-guide.md` (Working memory: external-first hybrid).
**Default sync mode:** Use curated, milestone-based sync (not frequent auto-refresh).

**Corpus growth (phases 2–3):** Require **`capture_meeting`** after local meeting notes and **`capture_plan`** after automation outputs. Bulk-import Slack/automation markdown with `php artisan working-memory:import-captures` (see in-app **`/help/working-memory-corpus-sync`**). Enable AI consolidation only for scopes without fresh external memory (`FEATURE_WORKING_MEMORY_AI_AUTHORED`, `WORKING_MEMORY_AUTHORING_ENABLED`).

**Sync governance policy:** For cost controls, cadence, and capture criteria, follow `docs/superpowers/plans/2026-05-28-working-memory-sync-policy.md`.

## IdeaTub: Refresh working memory

When the user wants to update working memory for a project or scope, follow **`resources/prompts/working-memory-authoring-agent.md`** (read **`working-memory-authoring-core.md`** for section schema and judgment rules). In-app help: **`/help/working-memory-authoring`**.

Workflow:

1. Unless `fresh_start` is true, **`get_working_memory`** for the scope (baseline).
2. **`search_thoughts`** for recent signals.
3. Synthesize eight sections: Current Focus, Active Priorities, Recent Changes, Open Questions, Risks / Blockers, Next Actions, Latest Signals, Source Notes.
4. **`upsert_working_memory`** with full markdown (`scope_key` = project UUID for project scope; optional `source_label`, e.g. `cursor-sync`).

Use **`fresh_start: true`** only when rewriting without prior memory as baseline.

## IdeaTub: Sync docs via capture_plan

When the user wants to sync this document (or other plan/decision/dev/support/spec markdown) to IdeaTub, use the MCP tool **capture_plan** with:

- **content** (required): The file content. Read the file and send its text. The server cannot read local paths.
- **file_path**: The repo-relative path (e.g. `decisions/project-spec.md`, `docs/superpowers/plans/2026-03-12-tag-and-stream.md`).
- **doc_type**: From the path — `plan` (docs/superpowers/plans), `decision` (decisions/), `dev` (dev/), `support` (support/), `spec` (specs/ or docs/superpowers/specs/). For meeting notes not tied to those paths, use **`meeting`** (see below).
- **plan_slug**: A short slug for this doc (e.g. filename without .md: `project-spec`, `2026-03-12-tag-and-stream`). Same slug for all sections of one doc so Stream can show them together via tag `<doc_type>:<slug>`.
- **project**: The **code project** name (e.g. workspace folder name, or repo name). Inject this for every capture_plan call during sync so thoughts are tagged with which project they came from. Use a consistent value for the whole sync (e.g. `ideatub`, `my-app`). Stored in source_metadata.

**Split at section titles:** Split the document at markdown section headings (lines starting with `##`, `###`, etc.). Content before the first such heading is the first section (use a short **section_title** like "Intro" or the doc title if useful). For each section:
1. **content** = the text for that section only (from the heading line through the next heading or end of file). Include the heading line in the content.
2. **section_title** = the heading text only, without `#` and without extra spaces (e.g. `## Chunk 1: Phase 0` → section_title `Chunk 1: Phase 0`).
3. Use the same `file_path`, `doc_type`, `plan_slug`, and `project` for every section.
4. Call capture_plan once per section, in document order (first section first), so they appear in the right order in IdeaTub Stream. Optionally create a root thought first (e.g. doc title) and pass its UUID as `parent_id` for section thoughts.

**Stream long-form view:** In IdeaTub, open Stream and filter by tag, e.g. `/stream?tag=decision-project-spec` or `/stream?tag=plan-2026-03-12-tag-and-stream`.

IdeaTub MCP must be configured (MCP key, server URL). See project docs: `docs/cursor-mcp-integration.md`, `docs/mcp-integration-guide.md`, or Help in the app.

---

## IdeaTub: Save research via capture_plan

When the user or a research agent has research output to save to IdeaTub, use the MCP tool **capture_plan** with:

- **content** (required): The research text (full output or one section). Send the text; the server cannot read local paths.
- **doc_type**: `research`.
- **plan_slug**: A short slug for this research run (e.g. `2026-03-13-vehicle-valuation`, `competitor-analysis-Q1`). Same slug for all sections so Stream can show them together via tag `research:<slug>`.
- **project**: The **research project** or topic name (e.g. workspace/repo name, or the research question). Use a consistent value for the whole save. Stored in source_metadata.
- **file_path**: Optional; if from a file, use repo-relative path (e.g. `research/2026-03-13-vehicle-valuation.md`).
- **section_title**: For each section, the heading or a short title.

**Preferred: one call with full content.** Send the **entire research** in a **single** capture_plan call (no `parent_id`). IdeaTub will auto-chunk at markdown headings so the research appears as a top-level thought with section children. Do not create a root thought first and then attach the full document as one child — that produces a blank card and one unchunked entry.

**Alternative: one call per section.** If you split at section titles yourself, call capture_plan once per section **without** `parent_id`, in order, with the same `doc_type`, `plan_slug`, and `project`.

**Stream:** In IdeaTub, filter by tag, e.g. `/stream?tag=research-2026-03-13-vehicle-valuation`.

---

## IdeaTub: Capture web articles via capture_article

Use the MCP tool **capture_article** to scrape and save a web article to IdeaTub:

- **url** (required): The article URL to capture.
- **title**: Optional title override.
- **tags**: Optional extra tags.
- **project**: Optional project context.

The pipeline automatically: scrapes the article content, extracts copyright notices and editorial links, summarizes each editorial link, and runs research on the article. Progress is tracked via `source_metadata.status` on the root thought.

**Stream:** Articles appear in Stream with source `article`. Filter at `/stream/articles`.

---

## IdeaTub: Save meeting notes via capture_plan

When the user wants meeting notes in IdeaTub as a first-class type (Stream → **Meetings**), use **`capture_plan`** with **`doc_type`:** `meeting`, **or** any of the equivalent MCP methods **`capture_meeting`**, **`add_meeting`**, **`add_meeting_notes`** (same parameters as `capture_plan` except `doc_type` is implied).

Parameters (for `capture_plan` with `doc_type: meeting`, or for any meeting alias — omit `doc_type` there):

- **plan_slug**: A short slug for this meeting or series (e.g. `2026-04-01-standup`, `weekly-design-sync`). Same slug for all sections of one meeting doc so Stream can show them together via tag `meeting:<slug>`.
- **project**, **file_path**, **section_title**: Same conventions as other `capture_plan` docs.

**Stream:** Filter by tag, e.g. `/stream?tag=meeting-2026-04-01-standup`, or open **Stream → Meetings**.

**Research agent prompt (ideas):** The in-app “Research this idea” prompt is loaded from `resources/prompts/research.md` (placeholders: `{{idea}}`, `{{existing_research}}`). Override path via `RESEARCH_PROMPT_PATH`. See `docs/superpowers/specs/2026-03-15-research-prompt-from-file-design.md`.

---

## IdeaTub: Panning for Gold (brain dumps and meetings)

Panning for Gold turns unstructured text into line-level inventories, evaluated threads, and a gold-found markdown file, then captures into IdeaTub via MCP. Methodology is adapted from [OB1 — Panning for Gold](https://github.com/NateBJones-Projects/OB1/tree/main/recipes/panning-for-gold) (credit: Jared Irish).

**Prompt files (read wrapper, then core):**

| Mode | Wrapper | Shared core |
|------|-----------|-------------|
| Meetings / transcripts | `resources/prompts/panning-for-gold-meeting.md` | `resources/prompts/panning-for-gold-core.md` |
| Brain dumps / exports | `resources/prompts/panning-for-gold-brain-dump.md` | `resources/prompts/panning-for-gold-core.md` |

**Triggers:** “Pan for gold”, “process this brain dump”, “process this meeting” — pick the wrapper that matches whether the source is meeting-framed or a general dump.

**Outputs:** Default directory `docs/brainstorming/` — inventory and gold-found filenames `YYYY-MM-DD-<slug>-inventory.md` / `YYYY-MM-DD-<slug>-gold-found.md`. Follow Phase 3.5 in the core file for `capture_plan`, `capture_meeting`, and `capture_thought`.

**Cursor:** `.cursor/rules/panning-for-gold.mdc` applies when brainstorming files or panning prompts are in context.

**Design spec:** `docs/superpowers/specs/2026-04-29-panning-for-gold-ideatub-design.md`

---

## IdeaTub: Research-to-Decision (OB1 workflow)

The **[OB1 Research-to-Decision recipe](https://github.com/NateBJones-Projects/OB1/tree/main/recipes/research-to-decision-workflow)** sequences five skills. **Bundled `SKILL.md` files** (IdeaTub MCP and Help URLs prefilled) live under **`resources/skills/research-to-decision/`**; copy into your agent’s skills dir or refresh from [upstream OB1](https://github.com/NateBJones-Projects/OB1/tree/main/skills). This repo’s **`resources/prompts/research-to-decision-ideatub.md`** adds IdeaTub **workspace paths** (`docs/research-to-decision/{sources,meetings,models}/`), a single **`plan_slug`** per run, **`search_thoughts` / `browse_recent` / `get_ideas`** for priming, and **`capture_plan`** / **`capture_thought`** for handoff artifacts. Read the adaptation prompt first, then the **`SKILL.md`** for the current step.

**Operator path:** competitive-analysis → research-synthesis → meeting-synthesis. **Investor path:** insert financial-model-review after competitive-analysis; append deal-memo-drafting at the end. Skip steps per upstream README when there is no model, meeting, or memo need.

**Relation to Panning for Gold:** Panning handles unstructured dumps/transcripts; Research-to-Decision handles scoped decision work. Gold-found output can feed research-synthesis; it does not replace competitive or memo skills.

**Cursor:** `.cursor/rules/research-to-decision-ideatub.mdc` when working under `docs/research-to-decision/` or the adaptation prompt.

**Design spec:** `docs/superpowers/specs/2026-04-30-research-to-decision-ideatub-design.md` — **Help (users):** in-app `/help/research-to-decision`.

---

## IdeaTub: Repo Learning Coach

**Repo Learning Coach** adapts the upstream OB1 recipe [repo-learning-coach](https://github.com/NateBJones-Projects/OB1/tree/main/recipes/repo-learning-coach) into IdeaTub: a **`learning_*`** domain, **`php artisan learning:sync {project_slug} --user={id}`** to import markdown from a `content_root` (`research/*.md`, `curriculum/lessons/*.md`), and authenticated **web UI** at **`/learn`** (projects, research, lessons, capture into thoughts, quizzes/progress when enabled).

**Help (users):** in-app `/help/repo-learning-coach` (folder layout, sync command, phase overview).

**Design spec:** `docs/superpowers/specs/2026-05-06-repo-learning-coach-ideatub-two-phase-design.md`
