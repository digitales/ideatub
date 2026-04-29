<!--
  IdeaTub distribution — adapted from OB1 meeting-synthesis.
  Upstream: https://github.com/NateBJones-Projects/OB1/tree/main/skills/meeting-synthesis
-->
---
name: meeting-synthesis
description: |
 Workflow for turning meeting transcripts or notes into decisions, action items,
 unresolved questions, risks, and follow-up artifacts. Use for prompts like
 "summarize this meeting", "extract action items", "what did we decide", or
 "draft the follow-up". Best when you have notes or a transcript, attendee context,
 and the reason the meeting happened. Optional IdeaTub MCP search and capture can
 pull surrounding context and store the final synthesis or decisions.
author: Nate B. Jones
version: 1.0.0-ideatub.1
---

# Meeting Synthesis

## IdeaTub MCP (prefilled)

| Resource | URL |
|----------|-----|
| MCP endpoint | `https://ideatub.com/api/mcp` |
| MCP keys | `https://ideatub.com/settings/mcp-keys` |
| Research-to-decision Help | `https://ideatub.com/help/research-to-decision` |
| MCP Help (tools and auth) | `https://ideatub.com/help#mcp` |

Auth: send **`x-ideatub-key: YOUR_MCP_KEY`**, or append **`?key=YOUR_MCP_KEY`** to the MCP URL (header preferred). Self-hosted: replace `https://ideatub.com` with your instance origin.

**Tools:** `search_thoughts`, `browse_recent`, `capture_plan`, `capture_meeting`, `capture_thought`. For meeting-shaped captures use **`capture_meeting`** or **`capture_plan`** with `doc_type: meeting`. MCP cannot read local files; paste **`content`**. See repository file `resources/prompts/research-to-decision-ideatub.md`.

## Problem

Meeting notes are usually either too raw to reuse or too polished to trust. This
skill turns them into a durable record of what happened, what was decided, what is
still unresolved, and what needs to happen next.

## Audience

- Primary: both operators and investors

## When to Use

- Summarizing a partner, diligence, customer, or internal strategy meeting
- Extracting decisions, actions, and follow-up items from transcripts or notes
- Producing a clean meeting brief for a team or memo workflow
- Turning discussion into reusable decision context

## When Not to Use

- Broad source synthesis across many documents: use `research-synthesis`
- Competitive market mapping without a meeting artifact: use `competitive-analysis`
- Final memo drafting from a full diligence packet: use `deal-memo-drafting`
- Reviewing spreadsheet assumptions: use `financial-model-review`

## Required Context

Gather or confirm:

- transcript, notes, or a faithful meeting summary
- attendees and their roles if known
- the meeting purpose
- any surrounding project, deal, or strategic context
- whether the desired output is internal notes, a decision log, or a follow-up message

## Process

1. Frame the meeting.
 - State what meeting this was and why it mattered.
2. Extract the real signal.
 - Separate decisions, action items, risks, unresolved questions, and useful context.
3. Attribute where possible.
 - Name owners and decision-makers when the notes support it.
4. Preserve uncertainty honestly.
 - If a point sounds important but was not resolved, keep it as unresolved.
5. Produce the right artifact.
 - Decision log, action list, follow-up draft, or meeting brief depending on the ask.
6. Optionally use IdeaTub MCP.
 - Run **`search_thoughts`** for prior related meetings or project notes before starting.
 - Capture key decisions or the final synthesis with **`capture_meeting`** / **`capture_plan`** (`doc_type`: `meeting`; reuse **`plan_slug`**) when the work is complete.

## Evidence and Judgment Rules

- Use the meeting artifact as the primary source of truth.
- Do not inflate tentative discussion into final decisions.
- Keep attribution explicit when it is known and unforced when it is not.
- Preserve material disagreement or open risk instead of smoothing it away.
- Separate "decided", "assigned", and "discussed".

## Output

Default output:

- meeting purpose and quick summary
- decisions made
- action items with owners if known
- risks and unresolved questions
- recommended next follow-up
- optional follow-up message or memo input

## Works Well With

- `research-synthesis` when interviews or diligence calls become part of the evidence set
- `deal-memo-drafting` when management or partner calls inform the recommendation
- `competitive-analysis` when customer or market conversations sharpen the market picture

## Notes

- This skill should reduce ambiguity, not create false closure.
- The most valuable meeting output is often the list of unresolved questions.
