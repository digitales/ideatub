# Research-to-decision workflow

This page explains how to use the **[Research-to-Decision Workflow](https://github.com/NateBJones-Projects/OB1/tree/main/recipes/research-to-decision-workflow)** from the OB1 project with **IdeaTub** as your memory layer: search prior notes, capture each step’s outputs, and review them in Stream.

## Prerequisites

1. **IdeaTub account** and **[MCP key](/settings/mcp-keys)** — same as [MCP integration](/help#mcp).
2. **MCP-connected agent** (Cursor, Claude Code, Claude Desktop, ChatGPT with connector, etc.) using your instance endpoint and key.
3. **Skills** — Use the bundled **`SKILL.md`** files shipped with the IdeaTub project (**`resources/skills/research-to-decision/`**). They match [OB1](https://github.com/NateBJones-Projects/OB1/tree/main/skills) behavior but include **prefilled IdeaTub URLs** (`https://ideatub.com/api/mcp`, Help links, MCP keys page). Copy each file into your agent’s skills directory (instructions in that folder’s **README**). Self-hosted: replace `https://ideatub.com` in those files with your site origin.

Also read **`resources/prompts/research-to-decision-ideatub.md`** for workspace paths and `capture_plan` conventions.

## Workspace folders

In your **code or files project** (not only inside IdeaTub’s repo), create:

- `docs/research-to-decision/sources/` — articles, research packets, competitive notes  
- `docs/research-to-decision/meetings/` — transcripts or notes for meeting synthesis  
- `docs/research-to-decision/models/` — spreadsheets or assumption exports for model review  

Use [OB1’s workflow template](https://github.com/NateBJones-Projects/OB1/blob/main/recipes/research-to-decision-workflow/workflow-template.md) for a starter **decision brief** (who decides, operator vs investor path, where files live).

## Two paths (high level)

| Path | Sequence | Typical outcome |
|------|----------|-----------------|
| **Operator** | competitive-analysis → research-synthesis → meeting-synthesis | Strategic brief, GTM view |
| **Investor** | competitive-analysis → financial-model-review → research-synthesis → meeting-synthesis → deal-memo-drafting | Diligence-style memo |

Skip steps you do not need (no model → skip financial-model-review; no meetings → skip meeting-synthesis; brief-only → skip deal-memo-drafting). The [upstream README](https://github.com/NateBJones-Projects/OB1/tree/main/recipes/research-to-decision-workflow) lists handoffs between steps.

## Using IdeaTub MCP

- **`search_thoughts`** — Before competitive or synthesis work, search for prior captures on competitors, markets, or meetings.
- **`capture_plan`** — Save each major artifact with a single **`plan_slug`** per workflow run (e.g. `r2d-2026-04-30-acme`) so Stream can group sections. Set **`project`** to your repo or workspace name. Choose **`doc_type`** (`research`, `decision`, `meeting`, `spec`, …) to match how you want items to appear in Stream.
- **`capture_thought`** — Short follow-ups or actions.
- **`get_ideas`** — Optional: pull “ideas to revisit” when framing decisions.

Details and tag formatting: [Plans and docs as thoughts](/help#plans) on the main Help page.

## Panning for Gold (different workflow)

**[Panning for Gold](https://github.com/NateBJones-Projects/OB1/tree/main/recipes/panning-for-gold)** turns unstructured brain dumps or transcripts into an inventory and **gold-found** file, then captures to IdeaTub. Use it when the input is messy; use **Research-to-Decision** when you already have a scoped decision and sources. They can chain: pan first, then feed distilled output into research synthesis.

Repo prompts: `resources/prompts/panning-for-gold-core.md` plus meeting vs brain-dump wrappers — see **`CLAUDE.md`** in the IdeaTub repo.

## Troubleshooting

| Issue | What to try |
|-------|-------------|
| Agent ignores skill order | Name the OB1 recipe (“Research-to-Decision”) and paste the step list; open the relevant `SKILL.md` for the current step. |
| Empty synthesis | Strengthen upstream handoffs; save intermediate markdown under `docs/research-to-decision/` and pass paths to the agent. |
| MCP errors on large paste | Split **`capture_plan`** into sections under the same **`plan_slug`** or shorten content (see MCP limits in project docs). |

For connection issues, see **Troubleshooting** under [MCP integration](/help#mcp).
