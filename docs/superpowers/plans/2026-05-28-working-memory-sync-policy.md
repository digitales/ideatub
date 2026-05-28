# IdeaTub Working Memory Sync Policy

**Status:** Proposed default policy (cost-controlled)  
**Owner:** Product + Engineering  
**Effective date:** 2026-05-28  
**Review cadence:** Weekly for first month, then monthly

---

## Decision

Do not fully deactivate IdeaTub working memory updates.  
Deactivate high-frequency automatic refresh flows and move to curated, milestone-based sync.

This keeps IdeaTub useful as a shared memory surface while reducing token spend on low-value summarization churn.

---

## Policy Goals

1. Reduce token/credit usage for working memory generation.
2. Preserve high-signal, durable context in IdeaTub.
3. Improve practical retrieval value for future agent and operator workflows.
4. Maintain a simple operating model that can be audited and tuned.

---

## Default Operating Mode

### 1) Sync mode

- **Default:** Curated sync only (manual or checkpoint-triggered).
- **Disabled by default:** Continuous or frequent full-memory auto-refresh.
- **Allowed exception:** Short, time-boxed bursts during critical launches/incidents.

### 2) Scope strategy

- **Keep:** `global` and active `project` scopes.
- **Optional:** `insights` only when there is a clear retrieval use case.
- **Deprioritize:** dormant project scopes with no recent retrieval demand.

### 3) Authoring strategy

- Prefer external-first curated memory for project scopes.
- If fresh external memory exists, avoid unnecessary AI re-authoring passes.
- Use AI-authored consolidation only when external memory is stale or absent.

---

## What To Sync

### Always sync (high signal)

- Decisions (architectural and product).
- Approved plans/specs with stable intent.
- Meeting outcomes: decisions, actions, risks, open questions.
- Material changes in constraints, assumptions, or milestones.

### Usually skip (low signal)

- Iterative draft churn and partial thought scaffolding.
- Repeated re-captures of near-identical content.
- Temporary execution logs that do not change strategy or decisions.

---

## Sync Triggers And Cadence

Use event-driven sync instead of constant sync:

- End of day (max once per active scope/day).
- End of feature milestone.
- After major decision or planning checkpoint.
- After key stakeholder meeting synthesis is finalized.

Hard guardrail:

- Do not sync the same scope repeatedly within a short window unless the delta is meaningful.

---

## Budget Guardrails

Set and enforce three limits:

1. **Monthly budget cap** for working-memory token spend.
2. **Per-sync size target** (keep captures concise and sectioned).
3. **Per-scope sync frequency cap** (prevent duplicate refresh loops).

Operational behavior when limits are exceeded:

- Pause non-critical syncs automatically.
- Continue only critical decision/meeting captures.
- Review and re-enable after manual approval.

---

## Quality Guardrails

A sync should only be accepted when it is:

- Structured (clear headings and sections).
- Decision-relevant (contains durable context, not transient noise).
- Differential (adds new information vs previous snapshot).
- Source-aware (links back to plan/meeting artifacts where possible).

---

## KPI Review Framework

Track these each review period:

- Token spend per scope.
- Number of sync operations per scope.
- Retrieval hit rate (how often synced memory was actually used downstream).
- Redundancy rate (captures with low or no net-new value).

Decision rule:

- If retrieval hit rate remains low for 2 review cycles, reduce sync frequency or pause that scope.

---

## Rollout Plan

### Week 1 (immediate)

- Pause high-frequency auto-sync.
- Keep manual/checkpoint sync for high-signal artifacts only.
- Track baseline spend and retrieval usage.

### Week 2-4

- Tune per-scope cadence based on real usage.
- Keep only scopes with demonstrated retrieval value.
- Document exceptions and repeatable capture patterns.

### Month 2 onward

- Move to monthly governance review.
- Adjust policy thresholds based on observed ROI.

---

## Enforcement Checklist

Before any working-memory update:

- Is this artifact durable and decision-relevant?
- Is there meaningful delta from the latest snapshot?
- Is this scope within frequency and budget limits?
- Is this update needed now, or can it wait until checkpoint?

If any answer is "no", skip sync.

---

## Practical Recommendation For Current Setup

- Keep IdeaTub working memory enabled in curated mode.
- Disable frequent automatic updates.
- Capture plans/decisions/meeting syntheses at milestones.
- Reassess after two weeks using spend + retrieval metrics.
