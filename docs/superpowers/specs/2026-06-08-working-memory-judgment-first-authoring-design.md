# Working Memory: Judgment-First Unified Authoring

**Date:** 2026-06-08  
**Status:** Approved  
**Related:** [2026-05-06-working-memory-ai-authored-structure-design.md](./2026-05-06-working-memory-ai-authored-structure-design.md), [2026-05-18-working-memory-hybrid-external-first-design.md](./2026-05-18-working-memory-hybrid-external-first-design.md)

## Overview

Unify external MCP agent and internal IdeaTub consolidation authoring around one judgment-first spec. Same eight sections, same tone rules; transport differs (markdown upsert vs JSON composer).

## Decisions

| Topic | Decision |
|-------|----------|
| Scope | Both agent (MCP) and internal composer |
| Citations | Judgment-first: optional inline; Source Notes is primary citation surface |
| Baseline | Prior canonical memory included by default; `fresh_start` skips it |
| Canonical spec | `resources/prompts/working-memory-authoring-core.md` |
| Agent wrapper | `resources/prompts/working-memory-authoring-agent.md` |

## Architecture

### Shared core spec

Eight sections: Current Focus, Active Priorities, Recent Changes, Open Questions, Risks / Blockers, Next Actions, Latest Signals, Source Notes.

### Baseline input

Evidence pack and agent workflow include prior canonical memory unless `fresh_start=true`.

### Validation (judgment-first)

- Hard fail: missing required sections
- Hard fail: empty Source Notes when evidence exists
- Hard fail: invalid citation URLs when citations present
- Removed: per-bullet citation requirement, coverage threshold gate, unused compaction gate

### Surfaces

- Internal: `WorkingMemoryComposerPromptBuilder` embeds core spec
- Agent: wrapper prompt + MCP tool descriptions + `/help/working-memory-authoring`
- UI: optional `fresh_start` on rebuild forms

## Rollout

1. Prompt files + help page + MCP descriptions
2. Validator relaxation + evidence pack prior memory + composer refactor
3. `fresh_start` threading + docs updates

## Success criteria

- Agent and internal outputs follow identical section schema and judgment rules
- Internal builds no longer fall back to legacy assembler due to citation gaps
- Prior memory is baseline by default; `fresh_start` works on both paths
