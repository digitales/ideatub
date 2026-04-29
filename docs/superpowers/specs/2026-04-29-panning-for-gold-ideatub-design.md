# Design: Panning for Gold (IdeaTub agent workflow)

**Status:** Approved — implemented (prompts, Cursor rule, `CLAUDE.md`, `AGENTS.md`)  
**Date:** 2026-04-29

## Attribution and source

This design adapts the **Panning for Gold** methodology from the **OB1** project’s published recipe.

- **Upstream recipe:** [OB1 — `recipes/panning-for-gold`](https://github.com/NateBJones-Projects/OB1/tree/main/recipes/panning-for-gold) (README, `panning-for-gold.skill.md`, `metadata.json`).
- **Community contribution:** The upstream README credits **[@jaredirish](https://github.com/jaredirish)** as the author, with review/merge by the Open Brain maintainer team.

IdeaTub’s deliverable is a **downstream integration**: same *process* (extract → checkpoint → evaluate → synthesize, line-level discipline, multi-domain coverage, verdict vocabulary), with **IdeaTub MCP** (`capture_plan`, `capture_meeting`, `capture_thought`, etc.) replacing Open Brain capture semantics, and repo-local markdown paths suitable for work in **Cursor, Claude Code, and Codex**.

When shipping prompt files, include a short **license/attribution header** in each derived file pointing to the upstream directory above unless upstream license dictates otherwise (verify `metadata.json` / repo `LICENSE` at implementation time).

## Goal

Ship an **agent-driven** Panning-for-Gold workflow that:

- Writes **inventory** and **gold-found** markdown under the repo (default `docs/brainstorming/`).
- Persists high-value output to **IdeaTub** via MCP with wrapper-specific `doc_type` and tagging.
- Uses a **shared core** prompt plus **two thin wrappers** (meeting vs general brain dump).
- Is discoverable from **Cursor** (rule), **Claude Code** (`CLAUDE.md`), and **Codex** (`AGENTS.md`).

## Non-goals (v1)

- No new Laravel models, queues, or meeting-skill `workflow_type` for server-side execution.
- No in-app UI for the extract/evaluate checkpoint; confirmation stays **in the agent thread**.
- No automatic connectors to Fathom, Otter, Fireflies, etc. (user supplies file path or pasted content).

## Architecture (conceptual)

| Layer | Responsibility |
|-------|----------------|
| **`panning-for-gold-core.md`** | Phases, domain balance, red-flag checks, verdict set, long-input strategy, IdeaTub MCP field conventions. |
| **`panning-for-gold-meeting.md`** | Meeting defaults: `doc_type: meeting`, Meetings Stream semantics, `capture_meeting` aliases. |
| **`panning-for-gold-brain-dump.md`** | Non-meeting defaults: default `doc_type: research`, tagging for Research Stream. |
| **Tool discovery** | Cursor rule points agents at wrappers → core; `CLAUDE.md` + `AGENTS.md` list paths and triggers. |

## File layout (canonical paths)

| Path | Role |
|------|------|
| `resources/prompts/panning-for-gold-core.md` | Shared methodology + IdeaTub MCP mapping. |
| `resources/prompts/panning-for-gold-meeting.md` | Thin wrapper: meeting mode. |
| `resources/prompts/panning-for-gold-brain-dump.md` | Thin wrapper: brain-dump mode. |
| `.cursor/rules/panning-for-gold.mdc` | Cursor: when user invokes panning, read wrapper then core; optional globs for `docs/brainstorming/**`. |
| `CLAUDE.md` | New subsection: paths + triggers for Claude Code. |
| `AGENTS.md` (repo root) | Same pointers as `CLAUDE.md` for Codex / agents that read `AGENTS.md` (minimal duplication). |

**Optional:** Users of Claude Code may copy a wrapper to `~/.claude/skills/.../SKILL.md` for native skill discovery; canonical text remains under `resources/prompts/`; document re-copy on upgrade.

## Local artifacts

- **Directory:** `docs/brainstorming/` (create if missing).
- **Names:** `YYYY-MM-DD-<slug>-inventory.md` and `YYYY-MM-DD-<slug>-gold-found.md`; `<slug>` normalized once in core (from title or user label).

## IdeaTub MCP contract

**Cross-cutting**

- One **`plan_slug`** per processing run; reuse for all MCP calls in that run so Stream groups correctly (`meeting:<slug>` or `research:<slug>`).
- MCP does not read files from disk; agent must read generated markdown and pass **`content`**.

**Meeting wrapper**

| Step | Local | IdeaTub |
|------|--------|---------|
| Raw capture (if not already stored) | — | `capture_meeting` or `capture_plan` with `doc_type: meeting`, `plan_slug`, `project`, optional `file_path`. |
| Inventory | `…-inventory.md` | Optional `capture_plan` (same `plan_slug`, `section_title` e.g. `Inventory`). |
| Gold-found | `…-gold-found.md` | `capture_plan`, `doc_type: meeting`, same `plan_slug`, prefer **one call with full content** for auto-chunking (see `CLAUDE.md`). |
| ACT NOW / sharp insights | In gold-found | `capture_thought` per discrete actionable item where appropriate. |

**Brain-dump wrapper**

| Step | Local | IdeaTub |
|------|--------|---------|
| Inventory | `…-inventory.md` | Optional `capture_plan`, default **`doc_type: research`**, same pattern as above. |
| Gold-found | `…-gold-found.md` | `capture_plan`, **`doc_type: research`**, same `plan_slug`. |
| Insights | bullets | `capture_thought` as for meetings. |

**Overrides:** Wrapper text may note that if the dump is clearly a decision log or plan, **`doc_type`** may be `decision` or `plan` per existing repo conventions instead of `research`.

**Optional captures:** Inventory MCP sync is **optional**; local files remain authoritative.

## Phase flow and failures

1. **Extract** → write inventory file only (no verdicts).
2. **Checkpoint** → user confirms or revises scope before evaluation.
3. **Evaluate** → top 3–5 threads (cap defined in core).
4. **Synthesize** → gold-found file, then MCP persistence per wrapper.

**Failures**

- Do not proceed past extract if local writes fail.
- On MCP failure: retain local files; retry capture with same `plan_slug` and pasted content.
- Long transcripts: use summary-first + selective quote verification when both summary and full text exist; if still too large, define segment-merge strategy in core without changing phase semantics.

**Limits:** Respect existing MCP/content size limits (e.g. parameter caps in `McpController`); agent truncates or splits with explicit user visibility.

## Verification (v1)

Manual checklist:

- Meeting wrapper + core completes inventory → checkpoint → gold-found; MCP examples match meeting `doc_type`.
- Brain-dump wrapper defaults to `research` for captures.
- `CLAUDE.md`, `.cursor/rules/panning-for-gold.mdc`, and `AGENTS.md` reference the same three prompt paths.

Automated tests for prompt text are **out of scope** for v1.

## Rollout

- Land prompts + Cursor rule + `CLAUDE.md` / `AGENTS.md` updates together or prompts-first.
- Optional follow-up: link from `docs/mcp-integration-guide.md` under workflows.

## Open questions (none blocking v1)

- None recorded; optional Help-page blurb can wait until after repo docs prove sufficient.
