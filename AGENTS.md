# Agent instructions (Codex and other AGENTS.md readers)

This repository’s primary Claude Code instructions live in **`CLAUDE.md`**. For Panning for Gold only, use the same pointers:

## Panning for Gold

- **Meetings / transcripts:** Read `resources/prompts/panning-for-gold-meeting.md`, then `resources/prompts/panning-for-gold-core.md`.
- **Brain dumps / exports:** Read `resources/prompts/panning-for-gold-brain-dump.md`, then `resources/prompts/panning-for-gold-core.md`.

Outputs default to **`docs/brainstorming/`**. IdeaTub MCP capture (`capture_plan`, `capture_meeting`, `capture_thought`) is described in **`CLAUDE.md`** and in the core prompt.

Upstream methodology: [OB1 — panning-for-gold](https://github.com/NateBJones-Projects/OB1/tree/main/recipes/panning-for-gold)

Design: `docs/superpowers/specs/2026-04-29-panning-for-gold-ideatub-design.md`

## Research-to-Decision (OB1 + IdeaTub)

- **Adaptation prompt:** `resources/prompts/research-to-decision-ideatub.md` (read first; then the OB1 `SKILL.md` for the active step).
- **Workspace:** `docs/research-to-decision/README.md` and `sources/`, `meetings/`, `models/`.
- **Upstream recipe:** [research-to-decision-workflow](https://github.com/NateBJones-Projects/OB1/tree/main/recipes/research-to-decision-workflow) — skills live in [OB1 `skills/`](https://github.com/NateBJones-Projects/OB1/tree/main/skills).

MCP usage matches **`CLAUDE.md`**. Design: `docs/superpowers/specs/2026-04-30-research-to-decision-ideatub-design.md`. Help page: `/help/research-to-decision`.
