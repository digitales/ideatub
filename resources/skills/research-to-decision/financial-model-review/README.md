# Financial Model Review

> Standalone skill pack for reviewing an existing financial model, forecast, or scenario set before using it in a decision.

This folder matches [OB1 `skills/financial-model-review`](https://github.com/NateBJones-Projects/OB1/tree/main/skills/financial-model-review). IdeaTub ships **`SKILL.md`** with prefilled IdeaTub MCP URLs plus **`README.md`** and **`metadata.json`** adapted for IdeaTub.

Workflow context: [Research-to-Decision Workflow](https://github.com/NateBJones-Projects/OB1/tree/main/recipes/research-to-decision-workflow).

## Prerequisites

- Model artifact, export, or pasted assumptions.
- **IdeaTub MCP** (optional): `https://ideatub.com/settings/mcp-keys`, endpoint `https://ideatub.com/api/mcp`.

## Installation

Copy **`SKILL.md`** into your skills directory (e.g. `~/.claude/skills/financial-model-review/`). Optionally include `README.md` and `metadata.json`.

**Download bundle:** [Research-to-decision skills](https://ideatub.com/help/research-to-decision/skills) on IdeaTub.

## Trigger examples

- “Review this model” / “Stress test these assumptions”
- “What breaks in this forecast?”
- “Is this model decision-ready?”

## Files

| File | Purpose |
|------|---------|
| `SKILL.md` | Prompt / workflow (required) |
| `README.md` | Install and troubleshooting |
| `metadata.json` | OB1-style manifest (`ideatub_mcp` in `requires`) |

## Troubleshooting

**Critiques business but not the model:** Provide the spreadsheet or assumption export.

**Overly certain review:** Hidden formulas stay unknown—keep labels honest.

**Turned into a build request:** This skill reviews existing work only.
