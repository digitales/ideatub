# Extensions (household_maintenance, job_search): Tags + Metadata vs Separate Tables

**Date:** 2026-03-13  
**Status:** Accepted  

## Question

We already have **source**, **thought type** (in metadata), and **tags**. Is that enough to support “extensions” like household maintenance or job search, or would introducing per-extension tables be cleaner?

## Current model (recap)

- **source** (column): Where the thought came from — e.g. `mcp`, `cursor`, `web`.
- **source_metadata** (JSON): Used for capture_plan (doc_type, plan_slug, file_path, project, etc.).
- **metadata** (JSON): Extracted by OpenRouter — **type** (idea, note, task, meeting, quote), **tags** (array), **people**, **action_items**. Tags are normalized to lowercase and used for Stream filtering (`/stream?tag=...`) and Evernote notebook mapping.

So “thought type” lives in `metadata.type`; “extension” or category can be represented as a **tag** (e.g. `household_maintenance`, `job_search`).

## Answer: tags + metadata are enough for “extension views”

**Yes.** Using a **tag per extension** (e.g. `household_maintenance`, `job_search`, `household_knowledge`, `professional_contacts`) already supports:

- **Stream by extension:** `/stream?tag=job_search` or `/stream?tag=household_maintenance` gives a dedicated view. No new tables needed.
- **Agent capture:** When the user says “remember we used Benjamin Moore Hail Navy in the living room,” the agent calls `capture_thought` and can pass **tags** (e.g. `['household_knowledge', 'living_room']`). Same for job-search notes, maintenance log entries, etc.
- **Semantic search:** `search_thoughts` runs over all thoughts; the agent can reason across extensions or focus by suggesting the user open Stream filtered by tag.
- **Cross-extension reasoning:** All data stays in one store, so the agent can connect e.g. job search + contacts + conference notes in one conversation.

So for “I want a place to dump notes and see them by theme,” **tags + existing metadata are sufficient**. No schema change.

## When tags + metadata are not enough

They start to strain when an extension needs:

1. **Strict, queryable schema** — e.g. maintenance: `appliance`, `warranty_date`, `last_service_date`; job search: `company`, `role`, `stage`, `applied_at`.
2. **Efficient filtered queries** — e.g. “maintenance items where last_service &lt; 30 days ago” or “job applications in stage ‘interview’.” Doing that with JSON in `metadata` means ad hoc keys and JSON-path queries; no DB-level validation or indexes on those keys.
3. **Referential integrity** — e.g. job application → contact, maintenance item → thought. You can store IDs in metadata, but the DB won’t enforce them.

In those cases, **per-extension tables are cleaner**: clear schema, indexes, validation, and simple SQL for dashboards and reports.

## Recommendation

- **Default: use tags (and metadata) only.**  
  Model extensions as tags: `household_maintenance`, `job_search`, `household_knowledge`, `professional_contacts`, etc. Stream by tag is the “human door” for that extension. Optional: allow the agent (or UI) to set **metadata** keys beyond type/tags/people/action_items (e.g. `metadata.warranty_date`) for display or ad hoc filtering — accept that those are flexible and not indexed.

- **Add tables only when an extension needs structured, queryable data.**  
  Examples: a proper maintenance log (appliance, warranty_date, last_service) with “expiring in 30 days” views, or a job pipeline (company, stage, applied_at) with kanban or filters. Then introduce e.g. `maintenance_items(thought_id, ...)` or `job_applications(thought_id, ...)` with `thought_id` FK to keep the thought as the “memory” and the table as the structured view. Agent can write the thought (with tag) and, if we add tools or app logic, write the extension row.

So: **tags + type + source support extensions like household_maintenance and job_search as “views” and capture buckets.** Introduce **individual tables** only when you need clean schema, validation, or efficient querying for that extension.

---

## Summary (stored 2026-03-13)

**Intent:** Extend IdeaTub with the [OB1 (Open Brain) guide](https://github.com/NateBJones-Projects/OB1) in two ways: (A) add OB1-style extensions (e.g. Household Knowledge Base) as MCP tools and conventions inside IdeaTub; (B) document IdeaTub as an Open Brain–aligned setup (mapping OB1 steps to IdeaTub, linking to Companion Prompts).

**Decision on structure:** Keep the **current structure without new tables** by default. Extensions are implemented as **tags + metadata** on the existing `thoughts` table (e.g. `household_knowledge`, `household_maintenance`, `professional_contacts`). New MCP tools (e.g. `add_household_item`, `search_household_items`) are thin wrappers that create/query thoughts with the right metadata and tags.

**Why this helps:** (1) **Easier extensions** — one store, one pipeline; new domains = new tool names + metadata contracts, no migrations or new models. (2) **Thoughts stay central** — the “brain” is a single thing (thoughts); extensions are views and tools over that store, not separate silos. Cross-domain questions (“plumber + kitchen paint”) stay one semantic search. Add dedicated tables only when an extension needs strict schema, validation, or efficient structured queries (see “When tags + metadata are not enough” above).
