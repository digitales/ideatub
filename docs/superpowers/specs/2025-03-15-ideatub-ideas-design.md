# Ideatub: Ideas, “Ideas to revisit”, and AI research

**Date:** 2025-03-15  
**Status:** Approved for implementation  
**Backend:** Open Brain (Laravel, `open-brain/`). Ideatub = product/MCP name.

---

## 1. Summary

- **Ideas** are thoughts with metadata (`type: idea`, `completed`, `logged_date`). Capture via web and MCP.
- **“Ideas to revisit”** is a web-only notification: a page that shows incomplete ideas weighted by age, computed on load. Optional MCP tool `get_ideas` returns the same list for Cursor.
- **AI research** is stored as linked thoughts; all research for an idea is shown. Triggered on demand (web “Research this idea” / “Research this idea: [the idea]”, MCP `research_idea`). Web supports “Research this idea: [the idea]” to add the idea and run research in one step.

---

## 2. Ideas: model and capture

### 2.1 Data model

- No new tables. Ideas are **thoughts** with:
  - `metadata.type = 'idea'`
  - `metadata.completed` = boolean (default `false`)
  - `metadata.logged_date` = ISO date string (optional; if missing, use `created_at`)

### 2.2 Capture

- **Web**
  - “Add idea” form: create thought with idea metadata.
  - “Research this idea: [the idea]” flow: create the idea thought, run research, create and link research thought (see Section 4).
- **MCP**
  - New tool `capture_idea`: same as capture_thought plus idea metadata (content, optional logged_date).

### 2.3 Web UI (ideas)

- List view filtered to ideas; show logged date, completed flag, snippet.
- Toggle completed on list or detail.
- Optional: edit idea (content and/or logged_date).
- Section name for the “reminders” view: **Ideas to revisit** (see Section 3).

---

## 3. Ideas to revisit (web-only notifications)

### 3.1 Behaviour

- **Compute on load:** Each time the user opens the “Ideas to revisit” page, the app runs the selection logic (no stored digest).
- Selection: **incomplete ideas** only; score/weight by **age** (e.g. older = higher weight); optional light randomness. Return a bounded list (e.g. top 10–15).

### 3.2 Web UI

- Page/section named **Ideas to revisit**.
- Shows the current list (idea snippet, link to idea, optionally logged date / age).
- No push to Slack/email in v1; this is the only notification surface.

### 3.3 Preferences

- **v1:** Stored in DB (one row or key-value per user/site); editable in web UI (e.g. “Ideas to revisit” or notification settings). Preference keys: `ideas_to_revisit_limit` (max number shown, default e.g. 15), `ideas_to_revisit_min_age_days` (optional; don’t show ideas newer than this), `ideas_to_revisit_age_weight` (optional; how much age affects order). Omit weighting in v1 if simpler; limit and min_age are enough to unblock.

### 3.4 MCP

- Tool **`get_ideas`** (v1): same selection logic as the “Ideas to revisit” page; returns that list for Cursor (read-only). No push.

---

## 4. AI research

### 4.1 Purpose

- Attach a research note to an idea: what’s relevant, considerations, 2–3 next steps — generated from the idea text (no live web search in v1).

### 4.2 Trigger

- **On-demand only:** Web: “Research this idea” (existing idea) or “Research this idea: [the idea]” (add idea + research in one step). MCP: `research_idea` (e.g. by idea id or content).

### 4.3 What runs

- Input: idea content (and optionally existing research text for “refresh”).
- Call existing AI gateway (OpenRouter) with a fixed v1 prompt (exact wording can be tuned in implementation): “Given this idea: [content]. Produce a short research note: 2–4 sentences on what’s relevant, key considerations, and 2–3 concrete next steps. Be concise.”
- Output: plain text research note.

### 4.4 Storage (Option B)

- Research is stored as **separate thoughts** linked to the idea:
  - New thought with `metadata.type = 'research'`, `metadata.idea_id = <idea uuid>`, content = research text.
- **All** research for an idea is linked and shown (full history), not only the newest.
- “Regenerate” = create another research thought; UI shows all, with newest or all in a list.

### 4.5 Web UI

- On idea (list or detail): show “Research: [snippet]” when present, “View full” / “Regenerate”.
- List all linked research thoughts for the idea.
- “Research this idea” on existing idea: run research, create linked thought, show result.
- “Research this idea: [the idea]”: create idea, run research, create and link research thought, show idea + research.

### 4.6 MCP

- **`research_idea`** (v1): e.g. `idea_id` or content; runs same prompt, stores as linked research thought.

---

## 5. Error handling and testing

- **Ideas:** Validation for idea metadata (completed boolean, logged_date format). Graceful handling of missing or malformed metadata in queries.
- **Ideas to revisit:** If no ideas qualify, show empty state. Selection logic unit-tested (age weighting, limit, minimum age).
- **Research:** If AI call fails, show clear error. For “Research this idea: [idea]”: **v1 behaviour** — create the idea first, then run research; if research fails, keep the idea and show “Research failed — try again” (and optionally store a flag or empty research so “Regenerate” is available). Do not leave a half-created idea. Rate-limit or cost controls on research if needed.

---

## 6. Out of scope for v1

- Slack/email delivery for reminders.
- Live web search for research.
- Stored daily digest for “Ideas to revisit” (we use compute-on-load only).
