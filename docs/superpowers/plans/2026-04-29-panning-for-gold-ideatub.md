# Panning for Gold (IdeaTub) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship Panning for Gold as repo-hosted prompts (`resources/prompts/`), Cursor discovery (`.cursor/rules/panning-for-gold.mdc`), and agent entrypoints (`CLAUDE.md`, `AGENTS.md`), matching `docs/superpowers/specs/2026-04-29-panning-for-gold-ideatub-design.md`.

**Architecture:** Upstream methodology lives in `panning-for-gold-core.md` (adapted from [OB1 Panning for Gold](https://github.com/NateBJones-Projects/OB1/tree/main/recipes/panning-for-gold)); thin wrappers select meeting vs research MCP defaults; no Laravel changes.

**Tech Stack:** Markdown prompts, Cursor rules (`.mdc`), Git.

---

### Task 1: Core prompt

**Files:**
- Create: `resources/prompts/panning-for-gold-core.md`

- [x] **Step 1:** Create `resources/prompts/panning-for-gold-core.md` with attribution header (upstream URL, Jared Irish credit), phases 0–3.5, IdeaTub MCP (`capture_plan`, `capture_meeting`, `capture_thought`, `search_thoughts`), paths under `docs/brainstorming/`, verdict vocabulary (ACT NOW / RESEARCH MORE / PARK / KILL), red-flag tables, long-input strategy, speaker-consolidation subsection for multi-speaker transcripts (condensed). Omit OB1-only Open Brain session rituals; reference upstream for full original skill text.

- [x] **Step 2:** Commit when Task 1–6 complete.

---

### Task 2: Meeting wrapper

**Files:**
- Create: `resources/prompts/panning-for-gold-meeting.md`

- [x] **Step 1:** Create thin wrapper: include core by instruction (“read and follow”), `doc_type: meeting`, `capture_meeting` aliases, `plan_slug` reuse, Stream Meetings note.

---

### Task 3: Brain-dump wrapper

**Files:**
- Create: `resources/prompts/panning-for-gold-brain-dump.md`

- [x] **Step 1:** Create thin wrapper: default `doc_type: research`, override note for `decision`/`plan`, Stream Research tag pattern.

---

### Task 4: Cursor rule

**Files:**
- Create: `.cursor/rules/panning-for-gold.mdc`
- Modify: `.cursor/rules/README.md`

- [x] **Step 1:** Add `panning-for-gold.mdc` with `description`, `globs` for `docs/brainstorming/**` and `resources/prompts/panning-for-gold*.md`, `alwaysApply: false`, instructions to read meeting vs brain-dump wrapper then core when user invokes panning.

- [x] **Step 2:** Document the new rule in `.cursor/rules/README.md`.

---

### Task 5: Claude and Codex entrypoints

**Files:**
- Modify: `CLAUDE.md`
- Create: `AGENTS.md`

- [x] **Step 1:** Append “Panning for Gold” subsection to `CLAUDE.md`: paths to three prompts, triggers (“pan this meeting” → meeting wrapper; “pan this dump” → brain-dump wrapper).

- [x] **Step 2:** Create root `AGENTS.md` mirroring that subsection only (minimal duplication).

---

### Task 6: Docs and brainstorming directory

**Files:**
- Create: `docs/brainstorming/README.md`
- Modify: `docs/mcp-integration-guide.md` (short cross-link)
- Modify: `docs/superpowers/specs/2026-04-29-panning-for-gold-ideatub-design.md` (status → implemented / approved)

- [x] **Step 1:** Add `docs/brainstorming/README.md` explaining outputs land here and pointing to `resources/prompts/panning-for-gold-core.md`.

- [x] **Step 2:** Add a short “Agent workflows” bullet under `docs/mcp-integration-guide.md` linking prompts + design spec.

- [x] **Step 3:** Update design spec **Status** to reflect approval and implementation complete.

---

## Self-review (plan vs spec)

| Spec section | Task coverage |
|--------------|---------------|
| Attribution | Task 1 header + plan references upstream |
| Core + wrappers | Tasks 1–3 |
| Cursor / CLAUDE / AGENTS | Tasks 4–5 |
| Local artifacts `docs/brainstorming/` | Task 6 |
| MCP contract | Task 1 (core) |
| Non-goals (no Laravel) | No backend tasks |

No placeholder steps; automated tests explicitly out of scope per spec.
