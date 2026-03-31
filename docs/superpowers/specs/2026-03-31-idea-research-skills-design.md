# IdeaTub - Idea research skills design

**Date:** 2026-03-31
**Status:** Approved
**Scope:** Let users add an idea and have IdeaTub automatically start a linked research investigation using IdeaTub-managed, user-owned research skills with bounded customisation.

## 1. Summary

- Add a user-facing `Research Skills` feature that lets each account configure how IdeaTub researches ideas.
- Keep the research workflow engine inside IdeaTub so automation, cost limits, retries, and saving behaviour remain centrally controlled.
- Allow users to create and edit bounded skills that shape prompts, workflow type, context selection, output structure, and automation preferences.
- Support automatic research for new ideas when the user enables a default auto-run skill.
- Save each execution as a `research run` linked to the originating idea, selected skill version, and final research thought.

## 2. Goals and non-goals

### 2.1 Goals

- Preserve the current "capture an idea and IdeaTub handles the research" experience.
- Give users meaningful customisation without exposing raw infrastructure or arbitrary agent wiring.
- Keep spend and reliability under IdeaTub's control.
- Make research execution visible and understandable through statuses and run history.
- Fit cleanly into the existing idea and research flows already present in the product.
- Keep the rollout narrow enough that existing research entry points can move behind the new run model without forcing every UI surface to expose the full skill system immediately.

### 2.2 Non-goals

- Building a fully open workflow engine where users define arbitrary execution graphs.
- Allowing arbitrary external API or tool calls from user-defined skills in v1.
- Exposing unrestricted model choice, token limits, or retry policies directly to users.
- Replacing every existing research surface with the full skill-management UI on day one.
- Multi-user shared skill libraries or marketplace-style distribution in v1.

## 3. Product behavior

### 3.1 Core user flow

The intended experience stays IdeaTub-native:

1. A user adds an idea as normal.
2. IdeaTub saves the idea immediately.
3. If the user has enabled `auto-run default skill`, IdeaTub queues one background research run using that default skill.
4. If auto-run is off, the user can start research manually and choose from available skills.
5. The idea card or detail page shows status such as `Queued`, `Researching`, `Completed`, or `Failed`.
6. When the run finishes, IdeaTub saves a linked research thought and exposes it from the originating idea.

### 3.2 Manual and automatic use

Users should be able to:

- save an idea without research
- save an idea and start research immediately
- rerun research on an existing idea
- rerun using a different skill
- set one skill as their default
- opt into automatic research for new ideas

The save-path rule should be explicit:

- `Save idea` saves the idea only when auto-run is off.
- `Save idea` saves the idea and queues the default skill when auto-run is on.
- `Save + research` always saves the idea and starts research explicitly, allowing the user to pick a skill when needed.
- IdeaTub must allow only one active run per idea at a time. If that idea already has a `queued` or `running` run, the product should surface or reuse the existing run instead of creating another, regardless of which skill was requested.

### 3.3 UI shape

The UI should remain simple and product-oriented:

- On the idea composer:
  - `Save idea`
  - `Save + research`
  - default-skill indicator when auto-run is enabled
- On idea cards and detail pages:
  - current research status
  - active skill name
  - link to the finished research
  - rerun action
- In settings:
  - `Research Skills` index
  - create/edit skill
  - set default skill
  - auto-run toggle

The UI should feel like "configure how IdeaTub researches for me", not "build an agent pipeline".

## 4. System design

### 4.1 Research skill

A `ResearchSkill` is a user-owned configuration record. It should include:

- `user_id`
- `name`
- `description`
- `workflow_type`
- `instructions`
- `context_options`
- `output_shape`
- `intensity`
- `is_manual_enabled`
- `is_default`
- `allow_auto_run`
- `is_active`
- `version`

The skill defines intent and bounded preferences, but not executable code.

For v1, use a separate immutable `ResearchSkillVersion` record for each behavioural edit. The editable `ResearchSkill` remains the user-facing container, and `ResearchRun` records should always point to the exact `ResearchSkillVersion` used for that run, even if the live skill is edited later.

### 4.2 Research run

A `ResearchRun` records one execution of research for one idea. It should include:

- the originating idea thought ID
- the user ID
- the selected skill ID and skill version
- workflow type used
- status (`queued`, `running`, `completed`, `failed`, `cancelled`)
- stage index / progress
- estimated and actual usage metadata where available
- error summary if failed
- final research thought ID when successful

This gives the product a durable audit trail and lets the UI show trustworthy status instead of a binary "has research / no research".

At enqueue time, the run should snapshot resolved runtime fields from the selected skill version, including workflow type and prompt-shaping settings, so in-flight runs are not reinterpreted by later edits.

### 4.3 Workflow engine

IdeaTub owns the workflow engine. Users choose from supported workflow types, but the app controls:

- stage order
- max number of stages
- approved model mappings
- retry behaviour
- timeout behaviour
- save-back behaviour
- budget enforcement

For v1, support a small set of built-in workflow types:

- `quick_brief`
- `deep_research`

Each workflow type may run one to three sequential stages, but the stage graph is fixed by IdeaTub.

Defer more specialised types such as `compare_options` until the product has a clear multi-input model for them.

### 4.4 Prompt builder

The prompt builder assembles each model request from:

- idea content
- optional existing linked research
- selected IdeaTub context such as tags or related thoughts
- the user's skill instructions
- IdeaTub's workflow-specific framing and output requirements

This preserves flexibility while preventing raw user-defined payloads from bypassing product guardrails.

Context inclusion must be capped for cost control. For v1:

- tags: include at most the top 10 normalized tags from the idea
- related thoughts: include at most 3 related thoughts
- related-thought excerpts: truncate each excerpt to a fixed safe length
- existing research: include only the most recent linked research document, truncated when necessary

The prompt builder should apply deterministic truncation and prioritisation rules before the model request is sent.

### 4.5 Result saver

The final stage output is saved as a linked research thought using the existing research model. Intermediate stage outputs may be stored on the run for inspection and debugging, but they are not the primary user-facing artifact.

## 5. Skill editor

### 5.1 Editable fields

For v1, the skill editor should expose bounded, comprehensible controls:

- `Name`
- `What this skill is for`
- `Workflow type`
- `Instructions`
- `Context to include`
- `Output structure`
- `Intensity`
- `Available for manual use`
- `Allow auto-run`
- `Set as default`

Only one skill may be the default per user. Setting a new default should unset the previous one in the same write path.

### 5.2 Recommended controls

Prefer product-language controls over raw LLM controls:

- `Intensity`: concise / standard / thorough
- `Context to include`: idea only / idea + tags / idea + related thoughts / idea + existing research
- `Output structure`: summary / evidence / risks / next steps

IdeaTub should map these choices to approved runtime settings internally.

For v1, `Output structure` should be a multi-select checklist with a bounded set of sections. IdeaTub converts the selected sections into the final prompt contract.

### 5.3 What users should not control directly

Do not expose these directly in v1:

- arbitrary external tool calls
- unrestricted model IDs
- temperature and token sliders
- retry counts
- free-form stage graphs
- custom save destinations

## 6. Automation behavior

### 6.1 Default skill automation

When a user enables automatic research:

1. IdeaTub saves the idea immediately.
2. IdeaTub resolves the user's default eligible skill.
3. IdeaTub creates a queued research run.
4. A background job executes the workflow.
5. The UI shows live or near-live status.
6. On success, the linked research thought appears automatically.

An eligible default skill must be:

- owned by the current user
- active
- marked as default
- marked `allow_auto_run`
- backed by a workflow type that is enabled in the current rollout

If no valid default skill exists, IdeaTub should save the idea without failing the primary action.

### 6.2 Manual triggering

Manual research should use the same underlying runner. The only difference is how the skill is selected:

- use the default skill
- choose another skill at run time
- rerun an old idea with the same skill or a new one

### 6.3 Why automation stays in IdeaTub

The automation is part of the product value. Users should benefit from:

- automatic queuing
- reliable background execution
- result linking
- status tracking
- rerun controls

without having to operate external agents themselves.

## 7. Guardrails and cost control

### 7.1 Central controls

To balance flexibility with predictable spend, IdeaTub should enforce:

- approved model allow-lists per workflow type
- max token budgets per run
- max stage counts per workflow type
- max related-context sizes and truncation rules
- timeout limits
- retry limits
- auto-run restricted to skills explicitly marked safe for automation
- max active runs per user
- per-user queue fairness or basic rate limiting

### 7.2 Cost-sensitive design

The main cost risks are:

- expensive model selection
- multi-stage workflows
- oversized prompts from too much context
- repeated reruns
- auto-run on every new idea

The design addresses this by keeping final runtime choices inside IdeaTub even when users customise the skill.

For v1, keep limits simple and explicit rather than adaptive:

- one active run per idea
- a bounded number of active runs per user
- optional cooldown or throttle for repeated reruns

### 7.3 User-facing clarity

The UI should communicate enough for trust without overwhelming the user:

- which skill ran
- whether the run is automatic or manual
- current status
- failure reason when relevant
- rerun actions

Optional later enhancement: show estimated or recent usage in settings.

## 8. Failure handling

- If research fails, the idea remains saved normally.
- Failed runs should not remove or corrupt earlier research linked to the same idea.
- The run record should capture a short error summary.
- The user should be able to rerun with:
  - the same skill
  - another skill
  - a lower-intensity or smaller-scope skill
- Partial stage outputs may be kept for debugging but should not be surfaced as the main research result unless explicitly designed later.
- Cancellation should be supported at the run level. A cancelled run should move to `cancelled`, stop further stages, and leave any existing linked research untouched.

## 9. Implementation direction

### 9.1 Recommended rollout

Roll this out in bounded steps:

1. Add `ResearchSkill` and `ResearchRun` data models and relationships.
2. Introduce an IdeaTub-owned workflow runner that supports one built-in workflow type first.
3. Add a minimal `Research Skills` settings UI.
4. Support default-skill selection and auto-run for new ideas.
5. Move existing research entry points onto the new run model behind the scenes, even if some UI surfaces still expose only the simple built-in flow during rollout.
6. Add additional workflow types once the base execution and status model is stable.

### 9.2 Recommended v1 boundary

For v1:

- keep OpenRouter as the only model provider
- support up to three sequential stages
- support text-in/text-out stages only
- ship `quick_brief` first, with `deep_research` added only if the base workflow proves stable enough for launch scope
- save one final research document back into IdeaTub
- keep external tools and API calls out of scope
- keep admin/support tooling to logs and existing internal observability rather than building dedicated support UI

This delivers real user-owned skills while keeping the system understandable and supportable.

## 10. Testing strategy

Add coverage for:

- skill ownership and per-user isolation
- default skill selection
- auto-run for new ideas
- manual run creation
- workflow stage progression
- failed run handling
- final research linking to the idea
- prevention of invalid or over-budget skill execution
- rerun flows using same or different skills

Prefer focused service and feature tests around the runner and controller boundaries rather than brittle UI-only assertions.

## 11. Open questions

- Should auto-run apply to every new idea or only user-selected subsets later such as tags or sources?
- Should intermediate stage outputs ever be visible in the UI, or remain internal for debugging?
- Should the initial skill library ship with built-in templates users can duplicate and customise?

## 12. Out of scope (v1)

- Arbitrary external tool or API execution from skills
- Marketplace or public sharing of skills
- Team-level shared skill libraries
- Visual workflow builders
- User-defined branching or conditional logic
- Model-provider abstraction beyond OpenRouter
- Dedicated notifications for run completion
- Large or unlimited per-user skill libraries beyond a reasonable product cap
