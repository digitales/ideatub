# Competitive Analysis

> Standalone skill pack for competitor profiling, pricing comparison, market mapping, and strategic recommendations.

This folder matches [OB1 `skills/competitive-analysis`](https://github.com/NateBJones-Projects/OB1/tree/main/skills/competitive-analysis). **IdeaTub** ships **`SKILL.md`** with prefilled MCP/Help URLs plus this **`README.md`** (adapted for IdeaTub MCP instead of Open Brain).

For the full multi-step workflow see the [Research-to-Decision Workflow](https://github.com/NateBJones-Projects/OB1/tree/main/recipes/research-to-decision-workflow).

## Supported clients

Claude Code, Codex, Cursor, or any client that loads reusable skill/instruction files.

## Prerequisites

- **IdeaTub MCP** (optional but recommended): create an MCP key at `https://ideatub.com/settings/mcp-keys` and connect your client to `https://ideatub.com/api/mcp`. Self-hosted: use your instance URL.
- Public sources for competitor sites, pricing, and docs when comparing the market.

## Installation

1. Copy **`SKILL.md`** (and optionally **`README.md`**) into your client’s skills directory, e.g. `~/.claude/skills/competitive-analysis/`.
2. Reload the client.

**From the web app:** Help → [Research-to-decision skills](https://ideatub.com/help/research-to-decision/skills) — view prompts or download the ZIP bundle.

## Trigger examples

- “Analyze our competitors”
- “Benchmark our pricing”
- “Map the market” / “Who are we up against?”
- “Build a SWOT”

## Files

| File | Purpose |
|------|---------|
| `SKILL.md` | Prompt / workflow (required) |
| `README.md` | Human-oriented install and troubleshooting |

## Troubleshooting

**Generic market summary:** Include the decision the analysis supports (pricing vs positioning vs diligence).

**Invented pricing/features:** Keep evidence rules from `SKILL.md`; label unknowns.

**Too broad a competitor set:** Narrow ICP or segment.
