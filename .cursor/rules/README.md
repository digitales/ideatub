# Cursor rules

Project-specific rules for Cursor. They apply when the described files are in context.

## ideatub-sync-docs.mdc

**When it applies:** Editing or viewing markdown under `decisions/`, `dev/`, `support/`, `specs/`, or `docs/superpowers/plans/` / `docs/superpowers/specs/`.

**What it does:** Tells the AI how to sync those docs to IdeaTub via the MCP tool `capture_plan` (correct `doc_type`, `file_path`, `plan_slug`, and that content must be sent — the server does not read local files).

## Using this rule in another project

1. Get the rule file: copy `ideatub-sync-docs.mdc` from this repo, or **download it from the IdeaTub Help page** (in the app: Help → MCP section → “Download ideatub-sync-docs.mdc”).
2. Put the file in that project’s `.cursor/rules/` (create the directory if needed).
3. Ensure IdeaTub MCP is configured in Cursor for that project (same MCP server URL + your key); the rule only guides *how* to call the tool.
4. Adjust the `globs` in the frontmatter if your project uses different paths (e.g. `docs/plans/` instead of `docs/superpowers/plans/`).

No other files from this repo are required; the rule is self-contained.
