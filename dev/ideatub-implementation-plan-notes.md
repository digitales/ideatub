# IdeaTub Implementation Plan — Dev Notes

**Date**: 2025-03-10  
**Status**: In Progress  
**Related Spec**: `decisions/project-spec.md`  
**Plan**: `docs/superpowers/plans/2025-03-10-ideatub-implementation.md`

## Purpose

This note links the project spec to the implementation plan and records where to look for execution details.

## Implementation Approach

- **Source of truth:** `decisions/project-spec.md` defines product decisions, auth model (per-user MCP keys), and feature order: (1) User + per-user auth, (2) Web UI, (3) Evernote mirror.
- **Execution plan:** `docs/superpowers/plans/2025-03-10-ideatub-implementation.md` breaks this into Phase 0 (bootstrap core if missing), Phase 1 (user isolation + MCP keys), Phase 2 (Web UI), Phase 3 (Evernote).
- **Agent workflow:** Use @superpowers:subagent-driven-development or @superpowers:executing-plans to implement; track steps via checkboxes in the plan.

## Key Files (from plan)

| Phase | Key files                                                                                                                                                                            |
| ----- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| 0     | `app/Models/Thought.php`, `app/Services/OpenRouterService.php`, `app/Http/Controllers/Api/McpController.php`, `app/Http/Controllers/Api/SlackIngestController.php`, `routes/api.php` |
| 1     | `app/Models/UserMcpKey.php`, migration `user_mcp_keys`, migration `user_id` on thoughts, MCP controller key resolution                                                               |
| 2     | `app/Http/Controllers/IdeaController.php`, `resources/views/idea/index.blade.php`, `routes/web.php`                                                                                  |
| 3     | `app/Services/EvernoteService.php`, `app/Jobs/SyncThoughtToEvernote.php`, migration `evernote_note_guid`                                                                             |

## Current codebase note

The repo may currently contain a different Laravel app (e.g. Vinlytic-style). The plan assumes either (a) IdeaTub core already exists elsewhere and will be merged, or (b) Phase 0 is run first to create Thought, OpenRouter, MCP, and Slack in this repo. Adjust migrations and route registration (e.g. `bootstrap/app.php` for `api` routes) to match the existing Laravel version and structure.

## References

- [Project spec](../decisions/project-spec.md)
- [MCP auth and multi-agent](../decisions/2025-03-10-mcp-auth-multi-agent.md)
- [IdeaTub follow-up decisions](../decisions/2025-03-10-ideatub-follow-up-decisions.md)
- [Comment-on-thought implementation plan](comment-on-thought-implementation-plan.md) — future work for parent_id, comments UI, MCP, Evernote
