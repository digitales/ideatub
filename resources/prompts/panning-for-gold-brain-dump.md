<!--
  Panning for Gold — brain-dump wrapper (IdeaTub).
  Core methodology: panning-for-gold-core.md
  Upstream lineage: https://github.com/NateBJones-Projects/OB1/tree/main/recipes/panning-for-gold
-->

# Panning for Gold — brain-dump mode

## When this wrapper applies

Use for **non-meeting** unstructured sources: stream-of-consciousness notes, journal exports, ChatGPT/Claude session dumps, conference scribbles, end-of-day dumps, multi-topic markdown without a meeting frame.

## Instructions

1. Read **`resources/prompts/panning-for-gold-core.md`** and follow it end-to-end.
2. For Phase 3.5 **`capture_plan`** calls, default to **`doc_type: research`** so Stream surfaces under Research (`research:<slug>`).
3. If the dump is clearly a **decision log** or **plan document**, use **`doc_type: decision`** or **`doc_type: plan`** instead and follow existing IdeaTub path conventions (see `CLAUDE.md` and `.cursor/rules/ideatub-sync-docs.mdc`).
4. Keep **`plan_slug`** stable for the entire run.
5. Set **`project`** to the topic or repo name.

## Triggers

Examples: “Pan this brain dump”, “process this export”, “what’s in this paste”, processing `docs/brainstorming/…` files that are not meeting transcripts.
