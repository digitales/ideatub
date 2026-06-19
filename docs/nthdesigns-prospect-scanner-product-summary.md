# nthdesigns Prospect Scanner — Product Summary & Marketing Analysis

**Date:** 2026-06-15  
**Status:** Internal product reference (scanner repo + ideatub)  
**Author:** Senior analysis

**Related:** [One-page sales sheet](./nthdesigns-prospect-scanner-sales-sheet.md) · [Business case (scanner repo)](https://github.com/digitales/nthdesigns-scanner/blob/main/docs/business-case.md) · [Decision: initial outreach niche/city](../decisions/2026-06-09-initial-outreach-niche-city.md)

---

## Executive summary

**nthdesigns Prospect Scanner** is a B2B prospecting and outreach platform built for UK local-service businesses. It finds SMEs with weak digital presence, scores them on two independent signals — **Google Business Profile (GBP) health** and **website accessibility/performance** — then turns those findings into **shareable audit reports** and **AI-drafted cold outreach**.

Today it is an internal tool for nthdesigns (a UK design and accessibility agency), but it is architected for eventual SaaS licensing to other agencies and freelancers. The core thesis: no single competitor combines **local prospect discovery + dual-signal scoring + verifiable public reports + AI outreach** in one workflow.

**Stack:** Laravel 13, Inertia + React, PostgreSQL, async job queues (Laravel Cloud), Google Places API, Playwright + axe-core + Lighthouse, Claude via OpenRouter for outreach generation.

---

## What the application does

The product closes the gap between “finding leads” and “proving why they should care.” An operator runs a niche + city search (e.g. “private dental clinic in Edinburgh”); the system discovers local businesses via Google Places, audits their GBP and website, ranks them by weakness (higher score = weaker = better prospect), generates a personalised report link, and drafts outreach that embeds that proof.

The highest-converting flow is deliberate: **cold email → public audit report → booking call**. The prospect can verify every claim before replying — not opinion, but numbers, screenshots, and competitor comparison.

---

## Key features (as built)

### 1. Discovery pipeline

| Feature | Description |
|--------|-------------|
| **Niche + city search** | Queries Google Places by category and location; returns scored prospects per run |
| **Direct URL scan** | Audit a single website without Places discovery — useful for inbound leads or referrals |
| **Niche opportunity scanner** | Batch surveys 50+ niches across 100+ UK cities; ranks markets by opportunity score before committing to full searches |
| **Benchmark capture** | Stores the top-ranked local competitor at scan time; all GBP scoring is relative to that benchmark |
| **CPC benchmark integration** | Optional cost-per-click figures (from Keyword Planner) enrich GBP outreach with “£X per click on Google Ads” framing |

### 2. Dual-signal scoring engine

**GBP signal (absolute + relative):**
- Missing phone, no website, low reviews, no photos, no description, incomplete hours
- Review, photo, and rating gaps vs local benchmark competitor

**Accessibility signal (axe-core + Lighthouse):**
- Violation counts by severity (critical → minor)
- Lighthouse accessibility score
- Performance score as a separate surfaced signal
- Screenshots of failing elements

**Combined output:** 0–100 weakness score plus a **dominant pitch angle** — GBP, accessibility, or both — which drives AI outreach angle selection.

### 3. Public audit reports (`/r/{token}`)

Shareable, unlisted, no-login reports for each prospect:
- Letter grade (A–F), violation breakdown, plain-English explanations
- GBP vs benchmark comparison
- Lighthouse-style scores and screenshots
- Configurable expiry (default 30 days)
- View tracking (count + first-view IP)
- **Booking CTA** — inline calendar or external redirect; `.ics` download support

These reports are already professional enough to sell as standalone deliverables.

### 4. AI outreach generation

- Claude-powered personalised cold emails per prospect (via OpenRouter)
- Pitch angle auto-resolved from dominant score or manually overridden (GBP / A11y / Both)
- Report URL embedded when available
- Bulk generation: select multiple prospects, queue, generate batch
- CPC benchmark injected into GBP/Both pitches when configured
- Token usage tracked per generation

### 5. CRM and pipeline tooling

| Feature | Purpose |
|--------|---------|
| **Outreach status** | `generated` → `sent` → `responded` |
| **Prospect notes** | Timestamped free-text notes per prospect |
| **Prospect lists** | Manual collections and smart saved filters |
| **Follow-up pipeline** | Per-list status (New → Contacted → Replied → Booked → Closed) + due dates |
| **Tags** | Config-suggested + user-created tags on prospects and niches |
| **Niche annotations** | Global and per-market notes/tags on niche opportunities |
| **Warm leads** | Prospects who viewed the report, received outreach, but haven’t replied |
| **Shared list sheets** (`/s/{token}`) | External share of curated prospect lists (no contact details) |
| **Search history** | Re-run and compare past scans |
| **Report dashboard** | All reports with view stats and warm badges |
| **Booking dashboard** | Track bookings from report CTAs |
| **CSV export** | Export prospect data |
| **Ignored prospects** | Suppress businesses from future results |

### 6. Operator intelligence

- **CMS detection** — identifies WordPress, Shopify, etc. for pitch tailoring
- **API usage tracking & quotas** — monitors Google Places, AI, and browser service spend
- **MCP integration** — AI clients (Cursor, Claude, ChatGPT) can monitor searches and trigger single-site audits via OAuth
- **Connected apps** — revoke AI agent access from Settings
- **Niche maintenance** — bootstrap catalog, run market scans, ignore low-yield niches

---

## Saleable features for public marketing

### Tier 1: Hero differentiators (lead with these)

1. **Dual-signal prospect scoring** — Find businesses weak on Google Maps and accessibility in one scan. No competitor combines GBP weakness + WCAG violations + combined ranking.

2. **Verifiable public audit reports** — Send proof, not promises. Sellable standalone: WCAG audit £400–800, GBP audit £150–200, bundle £500–900.

3. **AI outreach with automatic pitch angle** — Personalised cold email in seconds with the right hook (GBP vs compliance vs combined).

4. **Niche opportunity scanner** — Know which markets to pursue before spending on searches. Strong SaaS hook for agencies.

### Tier 2: Workflow and conversion features

5. **Warm lead detection** — They opened your report but didn’t reply; follow up now.

6. **CPC-enriched GBP pitch** — “Businesses in your category spend £6.50 per click — your GBP delivers that visibility free.”

7. **Benchmark-relative GBP scoring** — See exactly how they compare to the #1 business in their niche and city.

8. **Integrated booking from reports** — Report CTA → calendar slots → booking dashboard.

9. **Prospect lists and pipeline CRM** — From discovery to closed deal without leaving the tool.

### Tier 3: Agency / SaaS positioning

10. **White-label audit delivery** — Agencies send `/r/{token}` links; future white-label tier (~£149/month).

11. **MCP / AI agent integration** — Monitor pipeline from Cursor or Claude.

12. **Direct URL audit** — Audit any website in ~60 seconds.

13. **CMS detection** — Know if they’re on WordPress before you pitch.

14. **CSV export and shared list sheets** — CRM import and partner sharing without exposing contact data.

---

## Target audiences and positioning

| Audience | Pain | Positioning |
|----------|------|---------------|
| **Design & accessibility agencies** (primary) | Need qualified leads with a compliance angle | Pipeline generator for WCAG remediation and design work |
| **Local SEO freelancers** | Manual GBP prospecting is slow | Find weak GBP profiles and pitch with proof |
| **Accessibility consultancies** | EAA enforcement creates urgency | Prospect UK SMEs with verifiable WCAG failures |
| **Agency VAs / BD operators** | Outreach at scale is time-consuming | Score, report, and draft email in one workflow |
| **SME business owners** (report recipients) | Skeptical of cold pitches | See specific issues — no login, no sales call required first |

**Recommended niche focus:** private dental/healthcare, small legal firms, independent hospitality.

---

## Competitive moat

| Competitor | Gap |
|-----------|-----|
| BrightLocal / Whitespark | GBP only; no accessibility; not prospecting + outreach |
| Semrush / Ahrefs | Site audits; not local-prospect-focused; expensive; weak GBP signal |
| Siteimprove / Deque | Enterprise accessibility; no prospecting layer; £10k+ contracts |
| Wave / axe DevTools | Manual testing; no automation or outreach |
| Outscraper | Raw data; no scoring, reports, or AI outreach |

**The moat:** dual-signal scoring + public report layer + AI outreach. A prospect verifies every email claim themselves.

---

## Market timing (why now)

1. **European Accessibility Act (EAA)** — enforcement began June 2025. UK SMEs with EU-facing services face real compliance risk.

2. **Google local search dependency** — GBP placement drives zero-click local discovery. Paid CPCs for local categories run **£3–25/click**.

Neither angle requires the prospect to trust an agency’s word alone.

---

## Revenue model summary

| Layer | What you sell | Price indication |
|-------|---------------|------------------|
| **Internal pipeline** | Client work sourced by the tool | ~£72k/year attributable revenue (conservative model) |
| **Report as product** | Standalone audit deliverable | £400–800 (A11y), £150–200 (GBP) |
| **Done-for-you GBP** | Managed optimisation | £250–400 one-off; £200–350/month retainer |
| **Accessibility retainer** | Monthly re-scan + report | £100–250/month |
| **SaaS** (future) | Solo / Agency / White-label | £39 / £89 / £149 per month |

---

## Public-facing product narrative

**One-liner:**  
Find local businesses losing customers to weak Google profiles and inaccessible websites — then prove it with a report they can’t ignore.

**Three-bullet homepage (SaaS / agency audience):**
1. **Discover** — Scan any niche and city; rank prospects by GBP and accessibility weakness
2. **Prove** — Auto-generate shareable audit reports with screenshots, scores, and competitor comparison
3. **Convert** — AI-drafted outreach with the right pitch angle and a booking link built in

**Proof point:**  
“Your email says 23 accessibility violations. Your report shows all 23 — with screenshots.”

---

## Current state vs SaaS-ready

The platform is **production-deployed** (Laravel Cloud), multi-tenancy-ready (`user_id` on searches, per-user settings), and functionally complete for internal use. Gaps before external SaaS launch: Stripe billing, usage metering per plan, onboarding flow, white-label report branding — estimated **4–8 weeks** of focused work.

**Immediate recommendation:** Run weekly niche searches, send outreach with report links, measure conversion. The product already supports a full revenue workflow without further feature work.

---

## Homepage mapping (see sales sheet)

The live marketing homepage (`nthdesigns-scanner` `/`) targets **UK SMEs buying audits**, not agencies buying the tool. See [Homepage recommendations](#homepage-recommendations) in the sales sheet companion doc for what to add where.
