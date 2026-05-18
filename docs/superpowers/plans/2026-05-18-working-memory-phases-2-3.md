# Working Memory Phases 2 & 3 — Implementation Notes

**Goal:** Grow the IdeaTub thought corpus and enable AI consolidation for scopes without fresh external agent memory.

**Spec:** [2026-05-18-working-memory-hybrid-external-first-design.md](../specs/2026-05-18-working-memory-hybrid-external-first-design.md), [2026-05-12-working-memory-parity-design.md](../specs/2026-05-12-working-memory-parity-design.md)

## IdeaTub (shipped)

| Deliverable | Location |
|-------------|----------|
| Help: corpus sync playbook | `/help/working-memory-corpus-sync`, `resources/content/help/working-memory-corpus-sync.md` |
| Bulk import command | `php artisan working-memory:import-captures` |
| Consolidate skip/force | `working-memory:consolidate --only-without-external`, `--force` |
| Docs | `CLAUDE.md`, `docs/mcp-integration-guide.md` |

## Elixirr / operator (out of repo)

| Task | Owner |
|------|--------|
| `capture_meeting` required final step in meeting skills | `elixirr-meeting-writer`, `elixirr-meeting-notes` |
| `capture_plan` after automation runs | Automation runner configs |
| `capture_plan` after new Slack summary | `elixirr-comms-normalizer` |
| Dezeen Slack backfill | Run import against `outputs/slack/` with `--project-id` |
| Production AI flags | Enable only when ready for non-external scopes |

## Verification

```bash
php artisan test tests/Feature/ImportWorkingMemoryCapturesCommandTest.php
php artisan test tests/Feature/WorkingMemoryConsolidateCommandExternalTest.php
```
