# Research-to-Decision workspace (IdeaTub)

This folder holds **local artifacts** for the [OB1 Research-to-Decision Workflow](https://github.com/NateBJones-Projects/OB1/tree/main/recipes/research-to-decision-workflow): sources, meeting notes, and financial models you reference while running the skill chain in Cursor, Claude Code, or another agent.

## Canonical behavior upstream

Install and invoke the five OB1 skills from their repositories (copy `SKILL.md` into your client’s skills directory as described in each README):

| Skill | Upstream |
|-------|----------|
| Competitive analysis | [skills/competitive-analysis](https://github.com/NateBJones-Projects/OB1/tree/main/skills/competitive-analysis) |
| Financial model review | [skills/financial-model-review](https://github.com/NateBJones-Projects/OB1/tree/main/skills/financial-model-review) |
| Research synthesis | [skills/research-synthesis](https://github.com/NateBJones-Projects/OB1/tree/main/skills/research-synthesis) |
| Meeting synthesis | [skills/meeting-synthesis](https://github.com/NateBJones-Projects/OB1/tree/main/skills/meeting-synthesis) |
| Deal memo drafting | [skills/deal-memo-drafting](https://github.com/NateBJones-Projects/OB1/tree/main/skills/deal-memo-drafting) |

The **recipe** defines sequencing and handoffs; the skills define **how** each step behaves.

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
