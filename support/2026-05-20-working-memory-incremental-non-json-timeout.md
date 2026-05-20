# Working memory incremental refresh — non-JSON, hard validation, job timeout — Customer Support Investigation

**Date**: 2026-05-20  
**Status**: Resolved (code: per-scope dispatch + scope job timeout)  
**Customer**: User ID `3` (production)  
**Priority**: Medium  
**Reported By**: Monitoring / application logs  

## Production environment (2026-05-20)

Relevant vars confirmed on live:

| Variable | Value | Implication |
|----------|--------|-------------|
| `FEATURE_WORKING_MEMORY_AI_AUTHORED` | `true` | AI compose path active — errors are from this path, not “authoring off”. |
| `WORKING_MEMORY_AUTHORING_ENABLED` | `true` | Same. |
| `FEATURE_WORKING_MEMORY_UI` / `INSIGHTS` | `true` | UI/insights enabled; unrelated to this failure chain. |
| `WORKING_MEMORY_INSIGHTS_MODEL_ENABLED` | `true` | Insights scope may use model synthesis when incremental runs. |
| `OPENROUTER_CHAT_TIMEOUT_SECONDS` | `90` | Each composer call can block up to **90s** (not 60s). |
| `OPENROUTER_CHAT_RETRY_TIMES` | `2` | Transient connection failures can add retries before a successful response. |
| `OPENROUTER_METADATA_TIMEOUT_SECONDS` | `60` | Metadata path only; composer uses chat timeout. |

**Not set in the provided list** (code defaults apply):

| Effective setting | Default | Notes |
|-------------------|---------|--------|
| Composer model | `openai/gpt-4o-mini` | Via `WORKING_MEMORY_COMPOSER_MODEL` → `WORKING_MEMORY_AUTHORING_MODEL` → `OPENROUTER_METADATA_MODEL`. |
| `RESEARCH_MAX_TOKENS` | `2048` | Shared by `researchFromPrompt()` for working-memory compose. |
| `WORKING_MEMORY_LOG_LLM_DECODE_FAILURE_PREVIEW` | `false` | No `raw_preview` in logs yet. |
| `WORKING_MEMORY_CITATION_MIN_COVERAGE` | `1.00` | 100% citation coverage when validation runs; incident here failed earlier (empty sections). |

MCP/logging vars (`MCP_DEBUG_AUTH`, `MCP_LOG_TOOL_CALLS`, etc.) are unrelated.

## Issue Description

Three related warnings appeared on the live site for user `3`:

1. `WorkingMemoryAiAuthorService: model returned non-JSON output.`  
   Context: `scope_type=tag`, `scope_key=research`

2. `WorkingMemoryBuilderService: build failed, attempting fallback.`  
   Context: `user_id=3`, `scope_type=global`, `scope_key=global`, `build_type=incremental`,  
   `message=Missing required sections: Current Focus, Active Priorities, Recent Changes, Open Questions, Risks / Blockers, Next Actions, Latest Signals, Source Notes`

3. `RefreshWorkingMemoryIncremental failed permanently.`  
   Context: `thought_id=019e4451-2181-71cf-aeb9-974281d96152`,  
   `message=App\Jobs\RefreshWorkingMemoryIncremental has timed out.`

## Customer Impact

- **Incremental working memory** for affected scopes did not get a new AI-authored version on that run.
- **Global** scope likely retained the previous version via fallback (`lastKnownGoodVersion`) or legacy assembler output if no prior version existed; `freshness_state` may show `degraded` when fallback reuses the latest version.
- **Tag `research`** scope did not refresh successfully when the composer returned unparseable JSON.
- The triggering thought’s refresh job **exhausted retries** (3 attempts, 30s backoff) due to queue timeout, so **all scopes** in that job’s loop may have been left incomplete for that attempt.
- `build_started_at` should be cleared on permanent failure (`RefreshWorkingMemoryIncremental::failed()`), so scopes should not stay stuck in “updating” indefinitely unless a build was mid-flight on a different job.

Soft degradation: existing working memory content remains served; this is a refresh failure, not data loss.

## Investigation Steps

1. **Code path — non-JSON**  
   `WorkingMemoryAiAuthorService::authorFromEvidence()` calls `OpenRouterService::researchFromPrompt()` with `working_memory.authoring_composer_model`, then `LlmJsonDecoder::decode()`. On `null`, it logs the warning and returns `emptyOutput()` (all eight sections present but empty). See `app/Services/WorkingMemory/WorkingMemoryAiAuthorService.php`.

2. **Code path — missing sections**  
   `WorkingMemoryBuilderService` runs only when **both** `features.working_memory_ai_authored` and `working_memory.authoring_enabled` are true on production. Empty structured sections fail **hard** validation in `WorkingMemoryOutputValidator` (`failure_type=hard`, message lists every empty required section). The builder **throws** `RuntimeException`, caught by the outer `catch`, which logs “build failed, attempting fallback” and returns `lastKnownGoodVersion` if one exists. See `app/Services/WorkingMemory/WorkingMemoryBuilderService.php` lines 109–127.

3. **Linkage**  
   The global “Missing required sections” message is **consistent with** the same root cause as non-JSON: composer output could not be decoded → empty sections → hard fail. It is not a separate validator bug.

4. **Code path — job timeout**  
   `RefreshWorkingMemoryIncremental` has **no** `$timeout` property (unlike `ConsolidateWorkingMemory`, `RunResearchRun`, etc.). It loops **every scope** from `WorkingMemoryScopeResolver::forThought()` and calls `buildIncremental()` for each (global, each project UUID/slug, each metadata tag, forced tags, and `insights` if research). Each scope with AI authoring enabled performs at least one OpenRouter chat call (up to `services.openrouter.chat_timeout_seconds`, **90s on live**). Several scopes in one job can exceed the **queue worker** timeout (often 60s on managed hosts), producing `has timed out` after retries are exhausted. **Live config makes this more likely than the 60s default** — one slow scope can consume the entire worker budget.

5. **Trigger**  
   `ThoughtObserver` dispatches `RefreshWorkingMemoryIncremental` on thought create/update (content/metadata/etc.). Thought `019e4451-2181-71cf-aeb9-974281d96152` is the job payload.

6. **Prior art**  
   - `support/2026-05-08-build-scope-digest-non-json-tag-anthropic.md` — same decode failure pattern for digest jobs (tag scope).  
   - `support/2026-05-08-openrouter-ai-integration-failures.md` — OpenRouter timeouts and JSON hardening (decoder prose extraction, configurable timeouts).  
   Production logs do not include `raw_preview` unless `WORKING_MEMORY_LOG_LLM_DECODE_FAILURE_PREVIEW=true`.

## Root Cause Analysis

| Layer | Assessment |
|--------|------------|
| **Immediate** | OpenRouter composer returned body that `LlmJsonDecoder` could not parse into a JSON object for at least the **tag/research** scope (logged explicitly). |
| **Propagated** | Same failure mode for **global** incremental build yields empty sections → hard validation → exception → fallback warning (not a distinct schema bug). |
| **Job failure** | One `RefreshWorkingMemoryIncremental` job processes **multiple scopes serially**, each potentially waiting up to **~90s** on OpenRouter (live); cumulative time exceeds worker/job timeout → permanent failure after 3 tries. |
| **Config** | AI authoring is correctly enabled; incident is not “flags off”. Unset composer model → `gpt-4o-mini`; unset preview flag → no decode visibility. |

**Confirmed from production** (`raw_preview` 2026-05-20): model returned **markdown** instead of JSON — formats seen include `## Current Focus` headings and `**Current Focus**` bold labels (with `#` title, numbered lists, and `-` bullets). Content was usable; `LlmJsonDecoder` returned null until markdown fallback parsing was extended.

**Other causes still possible**:

- Prose-only / refusal vs **truncated JSON** (`research.max_tokens` default 2048 shared by `researchFromPrompt`).
- Oversized evidence pack for global scope (`authoring_max_prompt_input_chars` default 60000) leading to truncation mid-object.
- Composer model choice (`WORKING_MEMORY_COMPOSER_MODEL` / `WORKING_MEMORY_AUTHORING_MODEL`) JSON adherence on live.

**Less likely**

- Tag filter bug for `research` (would usually skip AI path with too few thoughts, not reach decode warning).
- External-memory guard (applies to consolidation overwrite, not this incremental path).

## Resolution

### Immediate (operator)

1. ~~Confirm production flags~~ — **confirmed** both AI authoring flags are `true` on live.
2. Temporarily set `WORKING_MEMORY_LOG_LLM_DECODE_FAILURE_PREVIEW=true` (and reasonable `WORKING_MEMORY_LLM_DECODE_FAILURE_PREVIEW_MAX_CHARS`) to capture one failing `raw_preview` in logs; **disable after triage** (PII). This is the highest-value missing knob in the current env list.
3. Check OpenRouter dashboard for failures/latency on **`openai/gpt-4o-mini`** (effective composer unless `OPENROUTER_METADATA_MODEL` / `WORKING_MEMORY_COMPOSER_MODEL` is set elsewhere).
4. Re-run refresh for the thought after mitigating timeout/model issues:  
   `RefreshWorkingMemoryIncremental::dispatch('019e4451-2181-71cf-aeb9-974281d96152')`  
   or save a trivial metadata touch on the thought to re-queue (if acceptable).
5. Inspect `working_memories` for user `3`: scopes with `build_started_at` set, `freshness_state=degraded`, and whether `latest_version_id` advanced.

### Mitigations (config, no deploy)

- **Do not** raise `OPENROUTER_CHAT_TIMEOUT_SECONDS` further for this incident — already **90s**; that increases per-scope wall time and worsens job timeout risk unless the worker `--timeout` is raised in lockstep.
- If the queue worker timeout is 60s (typical on Laravel Cloud), either raise worker timeout to **≥ (scopes × 90s)** or fix via code (per-scope jobs / job `$timeout`) — env alone cannot fix multi-scope refresh.
- Set `RESEARCH_MAX_TOKENS=4096` (trial) if `raw_preview` shows truncated JSON mid-object.
- Set `WORKING_MEMORY_COMPOSER_MODEL` to a JSON-reliable model (e.g. same model used successfully for digests elsewhere) and retest tag `research` only.
- Lower scope fan-out indirectly by reducing tags/projects on hot thoughts (operational workaround only).

### Engineering follow-up (if recurrent)

- [x] Per-scope dispatch: `RefreshWorkingMemoryIncremental` fans out to `RefreshWorkingMemoryIncrementalScope` (default timeout 600s via `WORKING_MEMORY_INCREMENTAL_SCOPE_JOB_TIMEOUT_SECONDS`).
- [x] Empty-section hard validation (non-JSON compose) uses legacy fallback inline — no `build failed, attempting fallback` exception path for that case (`shouldUseLegacyFallbackForHardValidation`).
- [x] `WorkingMemoryComposerMarkdownParser` — when compose returns markdown `##` / `**section**` headings, parse into `structured_sections` and attach evidence references for citation validation.
- [x] `WORKING_MEMORY_COMPOSER_MAX_TOKENS` (default 4096) and `researchFromPromptCompletion()` — retry once at `WORKING_MEMORY_COMPOSER_MAX_TOKENS_LENGTH_RETRY` (default 8192) when `finish_reason=length`; log `finish_reason`, `model`, and `max_tokens` on compose failures.
- [ ] Dedicated `max_tokens` for working-memory composer (not shared `research.max_tokens`).
- [ ] Sample `raw_preview` on decode failure in production behind config (already implemented; ensure ops know the flag).

## Customer Communication

- Pending: confirm whether user `3` sees stale or “degraded” badges on Memory scopes after the incident window.

## Prevention & Follow-up

- [ ] Correlate the three log lines to a single `thought_id` and timestamp in log aggregation.
- [ ] Alert on `RefreshWorkingMemoryIncremental failed permanently` separately from non-JSON warnings.
- [ ] Document that non-JSON + missing sections + incremental fallback are often **one incident**, not three independent bugs.

## Related Issues

- `support/2026-05-08-build-scope-digest-non-json-tag-anthropic.md`
- `support/2026-05-08-openrouter-ai-integration-failures.md`
- `support/2026-05-08-compaction-version-writer-validation-hard-failed.md` (validator hard-fail semantics; different writer path)

## Lessons Learned

- `WorkingMemoryAiAuthorService` fails softly (empty output), but the builder escalates empty sections to **hard** validation and an exception — logs look like two failures, one chain.
- Multi-scope incremental refresh in a single queue job is fragile when each scope triggers a full LLM compose call.

## References

- `app/Services/WorkingMemory/WorkingMemoryAiAuthorService.php`
- `app/Services/WorkingMemory/WorkingMemoryBuilderService.php`
- `app/Services/WorkingMemory/WorkingMemoryOutputValidator.php`
- `app/Jobs/RefreshWorkingMemoryIncremental.php`
- `app/Services/WorkingMemory/WorkingMemoryScopeResolver.php`
- `app/Observers/ThoughtObserver.php`
- `app/Support/Json/LlmJsonDecoder.php`
- `config/working_memory.php`, `config/features.php`, `config/research.php`, `config/services.php`
