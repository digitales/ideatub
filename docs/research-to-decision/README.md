# Research-to-Decision workspace (IdeaTub)

This folder holds **local artifacts** for the [OB1 Research-to-Decision Workflow](https://github.com/NateBJones-Projects/OB1/tree/main/recipes/research-to-decision-workflow): sources, meeting notes, and financial models you reference while running the skill chain in Cursor, Claude Code, or another agent.

## Skills (bundled in this repo)

Prefilled IdeaTub MCP and Help URLs live in **`resources/skills/research-to-decision/`** (one folder per skill; each contains `SKILL.md`). Copy those files into your agent’s skills directory (see [`resources/skills/research-to-decision/README.md`](../../resources/skills/research-to-decision/README.md)).

| Skill | Bundled `SKILL.md` | Upstream OB1 |
|-------|-------------------|--------------|
| Competitive analysis | [`SKILL.md`](../../resources/skills/research-to-decision/competitive-analysis/SKILL.md) | [GitHub](https://github.com/NateBJones-Projects/OB1/tree/main/skills/competitive-analysis) |
| Financial model review | [`SKILL.md`](../../resources/skills/research-to-decision/financial-model-review/SKILL.md) | [GitHub](https://github.com/NateBJones-Projects/OB1/tree/main/skills/financial-model-review) |
| Research synthesis | [`SKILL.md`](../../resources/skills/research-to-decision/research-synthesis/SKILL.md) | [GitHub](https://github.com/NateBJones-Projects/OB1/tree/main/skills/research-synthesis) |
| Meeting synthesis | [`SKILL.md`](../../resources/skills/research-to-decision/meeting-synthesis/SKILL.md) | [GitHub](https://github.com/NateBJones-Projects/OB1/tree/main/skills/meeting-synthesis) |
| Deal memo drafting | [`SKILL.md`](../../resources/skills/research-to-decision/deal-memo-drafting/SKILL.md) | [GitHub](https://github.com/NateBJones-Projects/OB1/tree/main/skills/deal-memo-drafting) |

The **recipe** defines sequencing and handoffs; the skills define **how** each step behaves. Refresh from upstream when OB1 updates; keep the IdeaTub URL table in sync.

## Folder layout

| Directory | Use |
|-----------|-----|
| `sources/` | Articles, exports, competitive notes, pasted research packets |
| `meetings/` | Transcripts or notes that feed **meeting-synthesis** |
| `models/` | Spreadsheet exports or assumption dumps for **financial-model-review** |

## IdeaTub-specific instructions

Read **`resources/prompts/research-to-decision-ideatub.md`** before a run: same paths as this README, plus **`capture_plan`** / **`search_thoughts`** conventions and operator vs investor paths.

**In-app setup:** On your IdeaTub instance open **Help → Research-to-decision workflow** (URL path `/help/research-to-decision`) for MCP setup and how this relates to Panning for Gold.

## Workflow template (OB1)

Use [workflow-template.md](https://github.com/NateBJones-Projects/OB1/blob/main/recipes/research-to-decision-workflow/workflow-template.md) from the upstream recipe as a starting brief; adapt filenames to `docs/research-to-decision/` as needed.
