# Meeting Skill Design (v1)

## Goal

Add a dedicated meeting skill pipeline that ingests plain-text meeting transcripts/notes, produces structured summaries and hybrid categorization, and supports both:

- automatic processing after meeting capture, and
- manual reruns through MCP.

This implementation is intentionally separate from the research skill domain.

## Scope

### Included

- New dedicated domain models and persistence:
  - `meeting_skills`
  - `meeting_skill_versions`
  - `meeting_runs`
- New meeting workflow services:
  - `MeetingSkillManager`
  - `MeetingPromptBuilder`
  - `MeetingWorkflowRunner`
  - `MeetingService`
- New queued job:
  - `ProcessMeetingRun`
- MCP additions:
  - new method/tool `process_meeting`
  - auto-run wiring for `capture_meeting` and `capture_plan` with `doc_type=meeting`
- Meeting analysis persistence:
  - child thought linked to the root meeting thought
  - tags include `meeting_analysis` and `meeting_analysis:<slug>` when a `meeting:<slug>` tag exists.
- Default requested output sections:
  - `summary`
  - `positives`
  - `things_to_watch`
  - `actions`
  - `conclusion`

### Excluded (v1)

- UI pages/forms for managing meeting skills
- file upload transcript ingestion (`.txt`, `.md`)
- external connectors (Zoom/Meet/Gong)

## Data Model

### MeetingSkill

Tracks user-owned meeting processing configuration and default/auto-run flags.

Key fields:

- `is_manual_enabled`
- `allow_auto_run`
- `is_default`
- `is_active`
- `latest_version_number`

### MeetingSkillVersion

Immutable snapshots for reproducible runs.

Key fields:

- `workflow_type` (`meeting_brief` only in v1)
- `instructions`
- `context_options`
- `output_shape`
- `core_categories`
- `custom_categories`
- `intensity`
- `is_auto_run_eligible`

### MeetingRun

Run lifecycle and execution snapshot store.

Key fields:

- `status` (`queued`, `running`, `completed`, `failed`, `cancelled`)
- `meeting_thought_id`
- `meeting_skill_id`
- `meeting_skill_version_id`
- `workflow_type_snapshot`
- `context_options_snapshot`
- `output_shape_snapshot`
- `core_categories_snapshot`
- `custom_categories_snapshot`
- `intensity_snapshot`
- `final_meeting_thought_id`
- `error_summary`

## Processing Flow

1. Meeting note/transcript is captured as `doc_type=meeting` (`capture_plan` or meeting aliases).
2. If user has eligible default auto-run meeting skill, queue `MeetingRun`.
3. `ProcessMeetingRun` executes `MeetingWorkflowRunner`.
4. `MeetingPromptBuilder` generates bounded JSON-output prompt for OpenRouter.
5. LLM output is normalized to guaranteed core-category shape.
6. Analysis thought is saved as a child of the root meeting thought.
7. Run is marked `completed` or `failed` with error summary.

## Hybrid Categorization Contract

Core categories are always present:

- `decisions`
- `action_items`
- `risks`
- `blockers`
- `follow_ups`

Custom categories are optional and skill-defined.

Action items are normalized to structured rows:

- `task`
- `owner`
- `due_date`
- `confidence` (`high`, `medium`, `low`)

## MCP API Additions

### `process_meeting`

Manual trigger for meeting processing.

Input:

- one of:
  - `thought_id` (existing meeting thought UUID)
  - `content` (new plain-text transcript content)
- optional:
  - `plan_slug`
  - `meeting_skill_id`
  - `force_rerun`

Output:

- `meeting_id`
- `meeting_run_id`
- `analysis_id` (`null` until run completion)

Validation rules:

- exactly one of `thought_id` or `content`
- `thought_id` must be UUID and belong to current user
- `thought_id` target must have `metadata.type=meeting`

## Error Handling

- Unsupported workflow type throws and marks run failed.
- LLM/network failures mark run failed with truncated `error_summary`.
- Duplicate active runs for the same `meeting + skill` are reused unless `force_rerun=true`.
- Invalid manual params return JSON-RPC invalid params errors.

## Testing Coverage

- Unit:
  - `MeetingPromptBuilderTest`
  - `MeetingWorkflowRunnerTest`
- Feature:
  - `MeetingRunWorkflowTest`
  - MCP coverage in `McpApiTest` for `process_meeting` and meeting auto-run queueing.

## Operational Notes

- Local test runs currently require a reachable Postgres test DB because project PHPUnit defaults to `DB_CONNECTION=pgsql`.
- Prompt builder tests run without DB bootstrapping and validate formatting/category constraints.
