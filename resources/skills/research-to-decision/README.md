# Research-to-Decision skills (IdeaTub bundle)

Each skill folder mirrors **[OB1](https://github.com/NateBJones-Projects/OB1)** `skills/<name>/` and includes **`SKILL.md`** (adapted for IdeaTub), **`README.md`** (install/troubleshooting), and **`metadata.json`** (manifest with `ideatub_mcp` in `requires`). Copy the whole folder into `~/.claude/skills/<name>/` or your client’s skills directory.

**License:** Redistribution is under the terms linked in **[`THIRD_PARTY_OB1.md`](../../../THIRD_PARTY_OB1.md)** (FSL-1.1-MIT; [upstream `LICENSE.md`](https://github.com/NateBJones-Projects/OB1/blob/main/LICENSE.md)).

| Folder | Upstream |
|--------|----------|
| [competitive-analysis](./competitive-analysis/SKILL.md) | [OB1](https://github.com/NateBJones-Projects/OB1/tree/main/skills/competitive-analysis) |
| [financial-model-review](./financial-model-review/SKILL.md) | [OB1](https://github.com/NateBJones-Projects/OB1/tree/main/skills/financial-model-review) |
| [research-synthesis](./research-synthesis/SKILL.md) | [OB1](https://github.com/NateBJones-Projects/OB1/tree/main/skills/research-synthesis) |
| [meeting-synthesis](./meeting-synthesis/SKILL.md) | [OB1](https://github.com/NateBJones-Projects/OB1/tree/main/skills/meeting-synthesis) |
| [deal-memo-drafting](./deal-memo-drafting/SKILL.md) | [OB1](https://github.com/NateBJones-Projects/OB1/tree/main/skills/deal-memo-drafting) |

## Prefilled URLs

Adaptations use the production host **`https://ideatub.com`** for MCP and Help links. If you self-host, replace that origin with your **`APP_URL`**.

## Install (Claude Code example)

```bash
mkdir -p ~/.claude/skills/competitive-analysis
cp /path/to/ideatub/resources/skills/research-to-decision/competitive-analysis/SKILL.md ~/.claude/skills/competitive-analysis/SKILL.md
# repeat for each skill folder you need
```

Restart or reload the client. Full workflow: [`docs/research-to-decision/README.md`](../../../docs/research-to-decision/README.md) and [`resources/prompts/research-to-decision-ideatub.md`](../../../resources/prompts/research-to-decision-ideatub.md).
