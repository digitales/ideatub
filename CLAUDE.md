# IdeaTub – Claude project instructions

When the project is opened in Claude Desktop or Claude Code, this file is read automatically.

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

## IdeaTub: Save meeting notes via capture_plan

When the user wants meeting notes in IdeaTub as a first-class type (Stream → **Meetings**), use **capture_plan** with:

- **doc_type**: `meeting`.
- **plan_slug**: A short slug for this meeting or series (e.g. `2026-04-01-standup`, `weekly-design-sync`). Same slug for all sections of one meeting doc so Stream can show them together via tag `meeting:<slug>`.
- **project**, **file_path**, **section_title**: Same conventions as other `capture_plan` docs.

**Stream:** Filter by tag, e.g. `/stream?tag=meeting-2026-04-01-standup`, or open **Stream → Meetings**.

**Research agent prompt (ideas):** The in-app “Research this idea” prompt is loaded from `resources/prompts/research.md` (placeholders: `{{idea}}`, `{{existing_research}}`). Override path via `RESEARCH_PROMPT_PATH`. See `docs/superpowers/specs/2026-03-15-research-prompt-from-file-design.md`.
