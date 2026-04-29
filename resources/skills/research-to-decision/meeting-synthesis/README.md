# Meeting Synthesis

> Standalone skill pack for turning meeting transcripts or notes into decisions, actions, risks, open questions, and follow-ups.

This folder matches [OB1 `skills/meeting-synthesis`](https://github.com/NateBJones-Projects/OB1/tree/main/skills/meeting-synthesis). IdeaTub ships **`SKILL.md`** with IdeaTub MCP URLs plus **`README.md`**.

Workflow context: [Research-to-Decision Workflow](https://github.com/NateBJones-Projects/OB1/tree/main/recipes/research-to-decision-workflow).

## Prerequisites

- Notes, transcript, or faithful summary.
- Optional **IdeaTub MCP** for meetings capture: `capture_meeting` / `capture_plan` with `doc_type: meeting`.

## Installation

Copy **`SKILL.md`** into `~/.claude/skills/meeting-synthesis/` (or your client’s equivalent). Optional: **`README.md`**.

**Download:** [Research-to-decision skills](https://ideatub.com/help/research-to-decision/skills).

## Trigger examples

- “Summarize this meeting”
- “Extract action items” / “What did we decide?”
- “Turn this transcript into a brief”

## Files

| File | Purpose |
|------|---------|
| `SKILL.md` | Prompt / workflow (required) |
| `README.md` | Install and troubleshooting |

## Troubleshooting

**Discussion mistaken for decisions:** Preserve decided vs discussed.

**Lost ownership:** Add attendee/role context when possible.

**Too generic:** State meeting purpose and desired output shape.
