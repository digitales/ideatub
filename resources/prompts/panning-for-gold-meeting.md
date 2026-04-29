<!--
  Panning for Gold — meeting wrapper (IdeaTub).
  Core methodology: panning-for-gold-core.md
  Upstream lineage: https://github.com/NateBJones-Projects/OB1/tree/main/recipes/panning-for-gold
-->

# Panning for Gold — meeting mode

## When this wrapper applies

Use when the source is a **meeting**: transcript, agenda notes, stand-up, interview, sales call, retro, etc.

## Instructions

1. Read **`resources/prompts/panning-for-gold-core.md`** and follow it end-to-end.
2. Use **`doc_type: meeting`** for all `capture_plan` calls in Phase 3.5.
3. Prefer MCP aliases **`capture_meeting`**, **`add_meeting`**, or **`add_meeting_notes`** when storing raw meeting content—the parameters match `capture_plan` except `doc_type` is implied (see `CLAUDE.md`).
4. Keep **`plan_slug`** stable for the whole run (e.g. `2026-04-01-design-sync`). Stream: **Meetings** / tag `meeting:<slug>`.
5. Set **`project`** to the team or codebase name the meeting was about.

## Triggers

Examples: “Pan this meeting”, “process this transcript”, “pan for gold on `docs/…` stand-up notes.”

If the user mixes meeting content with unrelated dump material, use judgment: meeting wrapper if the dominant intent is a meeting; otherwise switch to **`panning-for-gold-brain-dump.md`**.
