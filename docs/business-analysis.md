# IdeaTub — Business Analysis & Commercial Viability

**Date:** 2026-05-31  
**Author:** Senior Analysis (Claude Code)

---

## 1. What Is IdeaTub?

IdeaTub is an **AI-native personal knowledge system** built on Laravel + PostgreSQL (pgvector). It captures thoughts from multiple surfaces — web UI, AI assistants (via MCP), email, Jira, YouTube, and web articles — embeds them semantically, and retrieves them through natural-language search.

The core thesis: knowledge workers using AI daily lack a **durable, searchable memory layer**. AI assistants are stateless; notes apps are keyword-only; personal wikis are high-friction. IdeaTub sits at the intersection: capture is effortless (one line to an AI client), retrieval is semantic, and the system builds a working memory corpus that feeds back into AI workflows.

---

## 2. The Problem It Solves

| Pain | Current workaround | IdeaTub fix |
|------|-------------------|-------------|
| AI assistants forget between sessions | Manually re-paste context | `get_working_memory` gives AI instant project context |
| Research scattered across tools | Copy into Notion/Obsidian (friction) | Capture from any surface in seconds |
| Can't find old thoughts | Keyword search misses intent | pgvector cosine similarity search |
| Meeting notes go nowhere | Manual action-item extraction | `process_meeting` → auto-summarise → capture |
| Evernote users locked in | Export and re-import | Live Evernote mirror (async sync) |

---

## 3. Core Functionality

### 3.1 Capture Surfaces
- **Web UI** — direct form; lowest friction for solo use
- **MCP (AI clients)** — Claude, ChatGPT, Cursor call `capture_thought`/`capture_plan` mid-conversation; no context switching
- **Email (Postmark inbound)** — forward an email to your unique address; lands in inbox as a thought
- **Gmail/Fastmail sync** — pull email accounts; sender rules auto-tag and route
- **Jira** — assigned tickets sync as thoughts; tracked in stream
- **YouTube** — transcript extraction → thought
- **Web articles** — scrape + summarise → thought with source attribution
- **Bulk import** — markdown file upload for migration

### 3.2 Retrieval & Organisation
- **Semantic search** — vector similarity (not keyword) across all thoughts
- **Stream** — chronological filterable feed: all / research / meetings / articles / videos / emails / Jira / plans
- **Projects** — hierarchical containers with ordered thought membership; shareable
- **Ideas list** — "ideas to revisit" with age weighting; configurable review window
- **Inbox** — capture holding area; snooze, convert, archive

### 3.3 Working Memory
Working Memory is IdeaTub's most strategically differentiated feature. It maintains **scope-aware snapshots** (global, per-project, insights) that agents can read before acting. External (human-authored) and AI-consolidated versions coexist with version history and compaction. This transforms IdeaTub from a notes app into a **live context layer for AI agents**.

### 3.4 AI Workflows (Built-in)
- **Panning for Gold** — structured prompt chain: extract threads from meeting transcripts or brain dumps → synthesise → capture
- **Research-to-Decision** — sequential skill chain: competitive analysis → research synthesis → meeting synthesis → (optional) financial model review → deal memo
- **Repo Learning Coach** — import markdown curriculum into a learning domain; sync lessons; quiz and progress tracking
- **Research per idea** — queue per-thought LLM research job; results captured back as thoughts

### 3.5 Integrations
- Evernote (mirror)
- Jira (sync)
- Gmail / Fastmail (inbound + outbound rules)
- Postmark (inbound email)
- OpenRouter (embeddings + metadata extraction via LLM)
- Stripe (billing)
- Google / GitHub OAuth
- MCP Streamable HTTP (Claude, ChatGPT, Cursor, any MCP client)

---

## 4. Target Users

### Primary
**Knowledge workers who use AI assistants daily** — researchers, analysts, consultants, product managers, developers, writers. They need persistent memory across sessions, fast capture mid-conversation, and the ability to pull context back into future AI sessions.

### Secondary
- **Teams doing decision-heavy work** (consulting, advisory, VC, agencies) — Research-to-Decision workflow is a natural fit for competitive analysis and deal memos
- **Evernote migrators** — users leaving Evernote who want a modern, AI-integrated alternative with a migration path
- **Developers using AI coding assistants** — MCP integration with Cursor and Claude Code; project-scoped working memory is directly useful in engineering workflows

### Tertiary
- **Educators / self-learners** — Learning Coach module (lessons, quizzes, progress)
- **Solo founders / content creators** — brain dump → structured output via Panning for Gold

---

## 5. Commercial Model

### Current
- **Free tier** — trial period (configurable days)
- **Pro** — monthly subscription (Stripe; price via config)
- **Lifetime** — one-time purchase (Stripe; price via config)

### Assessment
The Free → Pro → Lifetime ladder is simple and proven for personal productivity tools. Lifetime is good for early adopters and trust-building. The risk: lifetime purchasers don't generate recurring revenue and can anchor pricing expectations. Recommend capping lifetime availability or raising the price as feature set matures.

---

## 6. Competitive Landscape

| Competitor | Overlap | IdeaTub advantage |
|-----------|---------|------------------|
| Notion / Obsidian | Notes, knowledge base | Semantic search; MCP-native; effortless AI capture |
| Mem.ai | AI-first notes, auto-tagging | Working memory scopes; agent workflow chains; MCP protocol |
| Roam Research | Bidirectional linking, PKM | Lower friction capture; AI integration; multi-surface |
| Evernote | Notes, capture | Modern stack; AI; IdeaTub can mirror Evernote (migration path) |
| Perplexity / Claude Projects | AI with memory | IdeaTub is user-controlled, portable, multi-agent |
| Rewind / Recall | Passive capture | IdeaTub is intentional + AI-workflow-native |

**Key moat:** MCP integration is protocol-level, not app-specific. As MCP adoption grows (Claude, ChatGPT, Cursor, etc.), IdeaTub becomes the natural persistent memory layer for any AI client. No major PKM competitor has this at depth yet.

---

## 7. Business Case Assessment

### Strengths
1. **Timing** — MCP is emerging as the dominant AI agent integration protocol. IdeaTub is early. First-mover advantage in the "persistent memory for AI workflows" niche.
2. **Network effect potential** — Project sharing + team working memory creates light team-level stickiness without requiring org-wide rollout.
3. **Migration path** — Evernote mirror lowers switching cost for a large displaced user base.
4. **Workflow depth** — Panning for Gold and Research-to-Decision are differentiators over basic capture tools; they demonstrate use-case fit for high-value users.
5. **Tech stack is solid** — Laravel + pgvector is production-ready, scalable, and well-understood. Not a prototype.

### Risks
1. **Large incumbents** — Notion, Obsidian, and Mem all have significant distribution. Differentiation must be maintained on AI-native workflows, not just features.
2. **OpenAI / Anthropic native memory** — Both companies are building persistent memory into their products. If ChatGPT memory and Claude Projects become robust, the "memory gap" shrinks.
3. **MCP protocol risk** — MCP is early; if the protocol does not become the standard, the integration moat weakens.
4. **Monetisation ceiling on personal tools** — Individual productivity tools typically cap around $10–20/month per user. B2B / team pricing unlocks higher ARPU.
5. **Operational complexity** — Multiple integrations (Evernote, Jira, Gmail, Postmark, OpenRouter, Stripe) = maintenance surface. Keep integration footprint intentional.

### Viability Verdict
**Viable as a focused B2C product; strong upside if B2B team tier is added.** The core value proposition is real and differentiated. The MCP angle is genuinely early. The risk is distribution — this market is crowded at the top, so growth will depend on finding and dominating a specific niche rather than competing on breadth.

---

## 8. What Can Be Sold — Tiered Commercial Roadmap

### Short-Term (0–6 months): Sharpen the Core

**What to sell:**
- Pro subscription (individual) — semantic search + working memory + all capture surfaces
- Lifetime access — for early adopters / community building

**Key actions:**
- Define and market the "AI assistant memory" use case explicitly; current positioning is too broad
- Optimise onboarding around the MCP setup flow — this is the sticky differentiator; users who connect it to Claude/ChatGPT will retain far better than web-only users
- Quantify working memory value: show users how much context they've saved / how many thoughts are in scope; numbers drive retention
- Lock down Evernote migration story: a dedicated landing page for "leaving Evernote" has clear organic SEO upside given Evernote's recent decline

**Estimated ARPU:** £8–15/month per user

---

### Medium-Term (6–18 months): Add Team / Collaborative Tier

**What to sell:**
- **Team Pro** — shared projects, team working memory, collaborative research (e.g. 3–10 person consulting team)
- **Agency / Analyst tier** — Research-to-Decision chain as a structured workflow; billing per workspace

**Key actions:**
- Add team invitations, role-based access, and shared project permissions (likely partial foundation exists via project sharing)
- Package Research-to-Decision as a named, marketed workflow — not just a docs feature; a premium tier justification
- Build out activity feed / notifications for collaborative projects
- Evaluate per-seat vs. per-workspace pricing; per-workspace typically wins for small teams

**Estimated ARPU:** £25–80/month per workspace

---

### Long-Term (18 months+): Platform & API Layer

**What to sell:**
- **API / developer tier** — MCP server as a product; teams build their own agents on top of IdeaTub's memory layer
- **Enterprise working memory** — SAML/SSO, audit logs, data residency (EU/UK), admin console
- **Vertical SKUs** — pre-packaged workflow bundles (e.g. "IdeaTub for VC due diligence", "IdeaTub for consulting teams") with curated skills, prompt templates, and onboarding paths
- **White-label / OEM** — licence the MCP memory layer to other SaaS products that want persistent knowledge for their users

**Key actions:**
- Formalise the MCP server as a documented, versioned API product; publish to MCP registries
- Invest in data export / portability (GDPR-aligned, user trust) — this is a moat for enterprise
- Build usage analytics (thoughts captured per day, search queries, working memory reads) to support value-based pricing conversations

**Estimated ARPU:** £100–500+/month per team / enterprise seat

---

## 9. Highest-Value Features to Protect and Develop

Ranked by strategic importance:

1. **Working Memory** — the only feature that makes IdeaTub a first-class citizen in AI agent workflows. Deepen scope types, improve consolidation reliability, add team scopes.
2. **MCP integration** — protocol-level presence in every major AI client. Maintain depth here; don't let it become a checkbox.
3. **Semantic search** — table stakes for "AI-native PKM"; keep embeddings fresh; consider hybrid (semantic + BM25) for precision.
4. **Research-to-Decision workflow** — high-value workflow that justifies premium pricing; the most direct path to B2B positioning.
5. **Panning for Gold** — broad appeal for anyone processing meetings or brain dumps; a strong acquisition hook.
6. **Stream + filtering** — the day-to-day UX; must stay fast and coherent as content volume grows.

---

## 10. Strategic Recommendations

1. **Pick a niche to dominate first** — "AI assistant memory for knowledge workers" is good. "AI assistant memory for consultants / analysts" is better. Niche-first beats broad-first in PKM.
2. **MCP is the growth channel** — prioritise making MCP setup zero-friction; every AI client user who connects IdeaTub is a potential paying user.
3. **Ship team tier early** — even a simple shared-project + shared-working-memory tier unlocks word-of-mouth in teams and raises ARPU ceiling significantly.
4. **Don't compete on integrations** — Evernote, Jira, Gmail are good migration/capture hooks, not core differentiators. Keep them working; don't build more unless user demand is clear.
5. **Price lifetime access higher or sunset it** — as working memory and team features mature, lifetime pricing needs to reflect true LTV; early pricing should not anchor future value.
6. **Invest in trust signals** — data portability, GDPR compliance docs, transparent deletion — especially relevant for users storing sensitive research and meeting notes.

---

## 11. Summary Scorecard

| Dimension | Score | Notes |
|-----------|-------|-------|
| Problem clarity | 8/10 | Real pain, well-understood |
| Product–market fit signal | 6/10 | Early; MCP adoption will be the test |
| Technical differentiation | 8/10 | pgvector + MCP depth is real |
| Monetisation model | 6/10 | Solid foundation; team tier needed for ceiling |
| Competitive moat | 7/10 | MCP timing advantage; at risk if incumbents move fast |
| Operational complexity | 5/10 | Many integrations; maintenance load is real |
| Growth path clarity | 7/10 | Clear if niche is picked and team tier added |

**Overall: Strong foundation, early-mover advantage in a real emerging niche. Execution risk is around distribution and team feature sequencing, not the core product.**
