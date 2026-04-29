# Design: Research-to-Decision workflow (IdeaTub integration)

**Status:** Implemented (prompt, workspace README, Cursor rule, Help page, docs cross-links)  
**Date:** 2026-04-30

## Attribution and source

This integration adapts the **Research-to-Decision Workflow** composition recipe from **OB1**.

- **Upstream recipe:** [OB1 — `recipes/research-to-decision-workflow`](https://github.com/NateBJones-Projects/OB1/tree/main/recipes/research-to-decision-workflow) (README, `workflow-template.md`, `metadata.json`).
- **Canonical skills (behavior):** [competitive-analysis](https://github.com/NateBJones-Projects/OB1/tree/main/skills/competitive-analysis), [financial-model-review](https://github.com/NateBJones-Projects/OB1/tree/main/skills/financial-model-review), [research-synthesis](https://github.com/NateBJones-Projects/OB1/tree/main/skills/research-synthesis), [meeting-synthesis](https://github.com/NateBJones-Projects/OB1/tree/main/skills/meeting-synthesis), [deal-memo-drafting](https://github.com/NateBJones-Projects/OB1/tree/main/skills/deal-memo-drafting). This repo ships **`resources/skills/research-to-decision/*/SKILL.md`** (IdeaTub MCP and Help URLs prefilled; version `1.0.0-ideatub.*`). Refresh content from upstream OB1 when needed; keep prefilled URLs aligned with production or document self-host substitution.

IdeaTub’s deliverable is **downstream documentation + agent instructions**: same sequencing and handoffs as OB1, with **IdeaTub MCP** (`search_thoughts`, `browse_recent`, `get_ideas`, `capture_plan`, `capture_meeting`, `capture_thought`) replacing Open Brain search/capture semantics, and repo-local paths under `docs/research-to-decision/`.

## Relationship to Panning for Gold

| Workflow | Role |
|----------|------|
| **Panning for Gold** | Unstructured text → inventory → gold-found → MCP (`research` / `meeting`). See `docs/superpowers/specs/2026-04-29-panning-for-gold-ideatub-design.md`. |
| **Research-to-Decision** | Decision brief → chained OB1 skills → operator brief or investor memo; explicit artifacts between steps. |

They **compose**: gold-found or panning outputs can feed **research-synthesis** or inform scope; they do not replace **competitive-analysis**, **financial-model-review**, or **deal-memo-drafting**.

## Goal

- Document **install order**, **workspace layout**, **handoffs**, **skip rules**, and **two paths** (operator vs investor) with IdeaTub-specific MCP fields.
- Provide a **Help** page for web-app users configuring MCP and agents.
- Keep **OB1 skills** as the behavioral source of truth; this repo adds only sequencing and IdeaTub mapping.

## Non-goals (v1)

- No new Laravel models or in-app UI for workflow execution.

## Update (bundled skills)

OB1 **`SKILL.md`** bodies are vendored under **`resources/skills/research-to-decision/`** with IdeaTub-specific URL tables and MCP tool names; upstream parity is maintained manually or by diffing against OB1.

## Files

| Path | Role |
|------|------|
| `resources/prompts/research-to-decision-ideatub.md` | Agent adaptation: paths, `plan_slug`, `doc_type`, priming with `search_thoughts`, handoff table. |
| `resources/skills/research-to-decision/**/SKILL.md` | OB1 skill bodies + IdeaTub prefilled URLs. |
| `docs/research-to-decision/README.md` | Workspace layout, upstream links, quick start. |
| `docs/research-to-decision/{sources,meetings,models}/` | Placeholder dirs for artifacts (`.gitkeep`). |
| `.cursor/rules/research-to-decision-ideatub.mdc` | Cursor: when to read the adaptation prompt. |
| `resources/content/help/research-to-decision.md` | User-facing Help markdown (rendered at `/help/research-to-decision`). |
| `CLAUDE.md`, `AGENTS.md`, `docs/mcp-integration-guide.md` | Cross-links. |

## Verification (v1)

- Help route returns 200 for authenticated users; markdown renders.
- Docs paths exist; README links resolve to GitHub OB1.
