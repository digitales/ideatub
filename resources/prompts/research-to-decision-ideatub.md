<!--
  Research-to-Decision — IdeaTub adaptation (sequencing + MCP + paths).

  Upstream recipe: https://github.com/NateBJones-Projects/OB1/tree/main/recipes/research-to-decision-workflow
  Canonical skill behavior lives in OB1 skills/ (install SKILL.md per upstream README).
-->

# Research-to-Decision workflow (IdeaTub)

## Purpose

Chain OB1 skills—**competitive-analysis → research-synthesis → meeting-synthesis**, with optional **financial-model-review** and **deal-memo-drafting**—so each step produces a **clean artifact for the next**. IdeaTub MCP stores durable outputs and supplies prior context via search.

**Skills are upstream:** Read each step’s OB1 `SKILL.md`. This file only adds **IdeaTub workspace paths**, **`plan_slug` / `doc_type`**, **priming**, and **paths** (operator vs investor).

## Workspace

- **Sources:** `docs/research-to-decision/sources/`
- **Meetings:** `docs/research-to-decision/meetings/`
- **Models:** `docs/research-to-decision/models/`

Start from a **decision brief** (audience, decision question, operator vs investor path, where sources live). Adapt [OB1 workflow-template.md](https://github.com/NateBJones-Projects/OB1/blob/main/recipes/research-to-decision-workflow/workflow-template.md).

## IdeaTub MCP before and during the run

**Priming (optional):** Call **`search_thoughts`** with queries about the market, competitors, or past meetings before **competitive-analysis** or **research-synthesis**. Use **`browse_recent`** or **`get_ideas`** if the user wants continuity or backlog ideas tied to the decision.

**Per run:** Choose one **`plan_slug`** (e.g. `r2d-2026-04-30-acme-diligence`). Reuse it for every `capture_plan` in this run so Stream groups sections (tag `research:<slug>` or matching doc type prefix).

**`project`:** Set to the repo/workspace name (same convention as `CLAUDE.md` sync docs).

**Content size:** MCP may reject payloads over **65535** characters. Split into multiple `capture_plan` calls with the same `plan_slug` and distinct `section_title`s, or summarise with user consent.

## Handoffs → suggested `doc_type`

Map OB1 handoffs to Stream-friendly types (adjust if the user prefers specs as `spec`):

| Step output | Typical local path | `capture_plan` notes |
|-------------|-------------------|----------------------|
| Competitive brief | under `sources/` or sibling markdown | **`research`** or **`spec`** |
| Model review memo | under `models/` | **`research`** |
| Research synthesis | under `sources/` | **`research`** |
| Meeting synthesis | under `meetings/` | **`meeting`** (or **`research`** if not Meetings-focused) |
| Final memo / recommendation | deliverable root | **`decision`** or **`research`** |

Use **`capture_thought`** for discrete actions or sharp follow-ups (parallel to Panning for Gold).

**Stream:** Filter with `/stream?tag=research-<plan_slug>` (replace `:` with `-` per app conventions; match tag shown on captured thoughts).

## Paths (from OB1 recipe)

### Operator path

`competitive-analysis` → `research-synthesis` → `meeting-synthesis`

Use when the goal is a strategic brief, GTM view, or internal decision—not necessarily an IC memo.

### Investor path

`competitive-analysis` → `financial-model-review` → `research-synthesis` → `meeting-synthesis` → `deal-memo-drafting`

Use when economics matter and the deliverable is memo-style diligence.

## Skip rules (same as OB1)

- No meaningful model → skip **financial-model-review**.
- No call / interview / review notes → skip **meeting-synthesis**.
- Final deliverable is a strategy brief, not a memo → skip **deal-memo-drafting**.

## Relation to Panning for Gold

- **Panning** (`resources/prompts/panning-for-gold-*.md`): unstructured dump/transcript → inventory → gold-found → MCP.
- **This workflow**: scoped sources + decision brief → skill chain → brief/memo.

If the input is **raw chaos**, run panning first; feed **gold-found** or distilled bullets into **research-synthesis** when appropriate. Do not replace OB1 analysis skills with panning alone.

## Credential tracker (IdeaTub)

Replace Open Brain fields with:

| Field | Value |
|-------|--------|
| IdeaTub MCP URL | From Help → MCP integration (`/api/mcp` on your instance) |
| MCP key | Profile → MCP key (create once, store securely) |
| `search_thoughts` | yes / no (if MCP configured, yes) |
| Workspace path | Repo root containing `docs/research-to-decision/` |
| Default `plan_slug` for this run | `r2d-<short-label>` |
