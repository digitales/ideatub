# Cursor rules

Project-specific rules for Cursor. They apply when the described files are in context.

## ideatub-sync-docs.mdc

**When it applies:** Editing or viewing markdown under `decisions/`, `dev/`, `support/`, `specs/`, or `docs/superpowers/plans/` / `docs/superpowers/specs/`.

**What it does:** Tells the AI how to sync those docs to IdeaTub via the MCP tool `capture_plan` (correct `doc_type`, `file_path`, `plan_slug`, and that content must be sent — the server does not read local files).

## ideatub-sync-research.mdc

**When it applies:** Saving research agent output (or any research) to IdeaTub, or when editing markdown under `research/`.

**What it does:** Tells the AI how to save research to IdeaTub via `capture_plan` with `doc_type` `research`, and to set `project` to the research topic so it’s recorded in `source_metadata`. Use the same `plan_slug` for all sections of one research run so Stream can show them under a tag like `research:<slug>`.

## Using these rules in another project

1. Get the rule file(s): copy from this repo, or **download from the IdeaTub Help page** (Help → MCP section → “Download ideatub-sync-docs.mdc” or “Download ideatub-sync-research.mdc”).
2. Put the file(s) in that project’s `.cursor/rules/` (create the directory if needed).
3. Ensure IdeaTub MCP is configured in Cursor for that project (same MCP server URL + your key); the rules only guide *how* to call the tool.
4. Optionally adjust the `globs` in the frontmatter if your project uses different paths.

No other files from this repo are required; each rule is self-contained.
