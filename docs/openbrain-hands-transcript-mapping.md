# OpenBrain “Hands” Video Transcript — What Applies to IdeaTub and What to Change

This doc maps the OpenBrain “giving your open brain hands and feet” video to **IdeaTub**. IdeaTub is a Laravel implementation of the Open Brain concept: personal memory + MCP so agents and humans share one source of truth.

---

## What applies to IdeaTub (no change)

- **Shared surface:** “Every extension needs to be a surface that both you and your agent can see and act upon.” IdeaTub already does this: thoughts are the shared store; MCP tools and the web UI read/write the same data.
- **Single source of truth:** “No sync layer, no export layer.” The `thoughts` table (and related data) is the source of truth; MCP and web both hit it. Consistency is architectural.
- **Keyhole vs visual:** “Chat through a keyhole” — the transcript says you need a visual layer so you can scan and edit, not only converse. IdeaTub’s web app + Stream (and tag-filtered views) are that “human door.”
- **Agent + human doors:** “Agent reaches through MCP; human reaches through a view.” Same in IdeaTub: agent uses `search_thoughts`, `capture_thought`, `capture_plan`; human uses the app and Stream.
- **Principles for use cases:**
  - **Time-bridging:** Agent memory doesn’t decay like human memory; value from linking events across months/years (e.g. “anyone I’ve been neglecting,” maintenance dates).
  - **Cross-category reasoning:** Power in connections across data (e.g. job hunt: contacts + conference notes + postings).
  - **Agent surfaces, human decides, agent executes:** Agent does memory and pattern recognition; human does judgment and decisions.
- **Future-proofing:** “Every time the models get smarter, every extension gets more valuable.” Same bet: you keep logging; better models improve search, summarization, and suggestions. MCP means any future agent (ChatGPT, OpenClaw, etc.) can plug in.
- **No middlemen:** “Your data, no one in the middle.” IdeaTub is self-hosted/your instance; data stays in your DB; MCP key is per-user, not per-SaaS.

---

## What needs changing for IdeaTub

### 1. Product and stack

| Transcript says | IdeaTub reality |
|-----------------|-----------------|
| OpenBrain | **IdeaTub** (product name). |
| Supabase | **Laravel + PostgreSQL** (or SQLite in dev). No Supabase. |
| “Create a table in your OpenBrain database” | You don’t create arbitrary tables. You use **thoughts** (and tags, metadata, comments). For structured long-form, you use **capture_plan** (plans, decisions, dev, support, specs) with `doc_type` and `plan_slug`. |
| “Add a light visual over the top” / “build a Vercel app” | The **human door already exists**: web UI (search, capture, recent), **Stream** (`/stream`), and Stream by tag (`/stream?tag=...`). You don’t deploy a separate Vercel app per use case; you use the app and, if needed, tag-based or future “views” inside IdeaTub. |
| Lovable, Vercel | Not in the default path. IdeaTub is one app (Laravel + Vue/Inertia + Blade). Custom “dashboards” (e.g. maintenance expiring in 30 days) could be: (a) Stream + tag + metadata, (b) a future IdeaTub feature (views/dashboards), or (c) an external app calling IdeaTub’s API/MCP. |

### 2. Data model: tables vs thoughts

- **OpenBrain (transcript):** User-defined **tables** per use case (household knowledge table, maintenance table, job pipeline tables); then one “human door” view per table (or multi-table).
- **IdeaTub:** **Thought-centric.** One primary store: **thoughts** (with `metadata`: tags, type, etc.). Use cases are modeled by:
  - **Tags** (e.g. `household`, `maintenance`, `job-search`, `professional-relationships`).
  - **Metadata** (e.g. dates, categories) for filtering and display.
  - **capture_plan** for long-form docs (plans, decisions, dev, support, specs) with tags like `decision:project-spec`, `plan:2026-03-12-tag-and-stream` — viewable in Stream by tag.

So: “household knowledge base” in IdeaTub = thoughts tagged e.g. `household` (and optional metadata). “Job search dashboard” = thoughts tagged e.g. `job-search` (and metadata for pipeline stage, company, etc.). You don’t “add a table”; you use thoughts + tags + metadata and Stream (and search).

### 3. Building the “human door”

- **Transcript:** “Create the table → point agent at it via MCP → build a visual (e.g. small web app) → deploy to Vercel so you have a URL.”
- **IdeaTub:** The visual is **built in.** You don’t deploy a new app per use case. You:
  - Use **Stream** for “see everything” and **Stream by tag** for use-case-specific views (e.g. `/stream?tag=maintenance`).
  - Use **search** (and MCP `search_thoughts`) for “find that thing.”
  - Use **capture** (web or MCP) to add thoughts; use **capture_plan** to sync plans/decisions/specs as tagged thoughts for long-form in Stream.
  - If you need a custom dashboard (e.g. “maintenance items expiring in 30 days”), that’s either a dedicated view/route inside IdeaTub (future) or a small external app that uses IdeaTub’s API/MCP — not “every use case = new Vercel app.”

### 4. Use-case mapping (transcript → IdeaTub)

| OpenBrain use case | IdeaTub approach |
|--------------------|------------------|
| Household knowledge (paint colors, plumber, Wi‑Fi, etc.) | Thoughts with tag e.g. `household` (and optional metadata). Agent captures via `capture_thought` in conversation. Human: search + Stream filtered by `household`. |
| Maintenance tracker (appliances, last service, warranty) | Thoughts with tag e.g. `maintenance`; put dates/categories in metadata. Stream by `maintenance`; “expiring in 30 days” = future filter or view on metadata. |
| Professional relationships (“anyone I’ve been neglecting”) | Thoughts with tag e.g. `contacts` or `professional`; log interactions. Agent uses `search_thoughts` + time reasoning; human sees Stream by tag. |
| Job hunt (companies, applications, interviews, follow-ups) | Thoughts tagged e.g. `job-search`; metadata for stage, company, contact. Agent cross-references with other tags (e.g. contacts, conference notes). Stream by `job-search`; capture_plan for resume versions or decision docs if needed. |
| Family schedule / calendar | Same pattern: thoughts (or structured entries) with tags and metadata; agent reasons over them; human uses Stream/search. A dedicated calendar UI would be a feature inside IdeaTub or a separate app using MCP/API. |

### 5. Guides and “build kits”

- **Transcript:** “I have a guide on Substack” / “build kit” per use case.
- **IdeaTub:** Document in **project docs** and **example prompts**: e.g. how to use tags and Stream for household knowledge, job search, maintenance; how to use `capture_plan` for decisions and plans; how to sync docs via the Cursor rule and `capture_plan`. No separate Substack; keep it in-repo (e.g. `docs/`, `resources/content/example-prompts/`).

### 6. MCP and protocol

- **Transcript:** Assumes “MCP server” in general.
- **IdeaTub:** MCP is **JSON-RPC 2.0** at `POST /api/mcp`, not the official MCP Streamable HTTP transport. Some clients may need a bridge. Document this in integration docs (already in `docs/mcp-integration-guide.md`, `docs/cursor-mcp-integration.md`).

---

## Summary

- **Keep:** Shared surface (agent + human), single source of truth, need for a visual “human door,” use-case principles (time-bridging, cross-category, agent surfaces / human decides), future-proofing via MCP, no middlemen.
- **Change for IdeaTub:** Use **IdeaTub** and **Laravel** (not OpenBrain/Supabase); model use cases with **thoughts + tags + metadata** (and `capture_plan` for long-form), not new tables; treat the **existing web app + Stream** as the human door; document use cases and “build” patterns in **project docs and example prompts**, not Substack/Vercel per use case.

This gives you a single reference when turning the OpenBrain “hands” narrative into IdeaTub-specific messaging or docs.
