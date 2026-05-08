# BuildScopeDigestJob non-JSON output (tag scope) — Customer Support Investigation

**Date**: 2026-05-08  
**Status**: Investigating  
**Customer**: User ID `3` (scope `tag` / `anthropic`)  
**Priority**: Medium  
**Reported By**: Monitoring / application logs  

## Issue Description

`BuildScopeDigestJob` logged:

`BuildScopeDigestJob: model returned non-JSON output.`

with context:

```json
{
  "user_id": 3,
  "scope_type": "tag",
  "scope_key": "anthropic"
}
```

No weekly digest compaction (`compaction:weekly-digest`) was written for that scope on that run; the job returned early after the warning (no exception).

## Customer Impact

- Working memory for the **tag** scope `anthropic` did not receive an updated weekly digest for that execution.
- Other scopes and users are unaffected unless they hit the same decode failure.
- Impact is **soft**: the scheduler can enqueue the job again; absence of a digest until the next successful run only degrades compaction freshness for that scope.

## Investigation Steps

1. **Code path**: `BuildScopeDigestJob` builds a prompt via `ScopeDigestPromptBuilder`, calls `OpenRouterService::researchFromPrompt()`, then `LlmJsonDecoder::decode($raw)`. If decode returns `null`, the job logs this warning and returns without calling `CompactionVersionWriter::write()` (see `app/Jobs/BuildScopeDigestJob.php`).
2. **Scope selection**: For `scope_type === 'tag'`, thoughts are filtered with `whereJsonContains('metadata->tags', $scopeKey)` — here `anthropic` must appear in each thought’s `metadata.tags` array. The job also requires at least `working_memory.digest_min_thoughts` (default 3) thoughts in `digest_window_days` (default 7), and skips if a digest already exists in-window (`hasFreshDigest`).
3. **Decoder behavior**: `LlmJsonDecoder` accepts trimmed JSON, strips leading ``` fences when the trimmed body **starts** with ```, falls back to extracting the first balanced `{ ... }` substring, and requires the decoded value to be a PHP **array** (JSON object or array). Root JSON strings/numbers/booleans/null fail; unrecoverable invalid JSON fails; empty content fails (`app/Support/Json/LlmJsonDecoder.php`).
4. **Prompt contract**: `ScopeDigestPromptBuilder` asks for JSON only with keys `summary_markdown`, `structured_sections`, `references` — models sometimes still prepend prose or use fences inconsistently; prose-only or truncated JSON still yields `null`.
5. **Upstream limits**: `researchFromPrompt` uses `research.max_tokens` (default 2048) for chat completions. A large digest can be cut mid-JSON, producing unparseable output and this warning without throwing from OpenRouter.

## Root Cause Analysis

**Not yet confirmed from raw model output** (current warning log does not include a response preview).

Most likely classes of failure:

| Cause | Why decode fails |
|--------|------------------|
| Prose-only or refusal | No extractable `{ ... }` or invalid inner JSON |
| Leading text before `{` | Extractor may still succeed if a complete object exists |
| Truncated JSON (`max_tokens`) | Incomplete braces/strings → `JsonException` after extraction |
| Root JSON scalar | Decoder rejects non-array root after `json_decode` |
| Empty/whitespace body | Would normally throw in `researchFromPrompt` before decode — unlikely if warning fired |

Less likely: bug in tag query — if invalid, you would typically see too few thoughts and an early return **without** this warning.

## Resolution

**Immediate (operator)**

- Re-run digest for this scope after confirming OpenRouter and model config:  
  `php artisan compactions:rebuild` for user `3` / `tag` / `anthropic`, or dispatch `BuildScopeDigestJob` for that tuple (same patterns as `CompactionsRebuildCommand` / `WorkingMemoryBootstrapCommand`).
- If failures repeat, temporarily switch `WORKING_MEMORY_DIGEST_MODEL` (maps to `working_memory.authoring_digest_model`) to a model with stronger JSON adherence, or raise `research.max_tokens` if logs suggest truncation.

**Engineering (if recurrent)**

- Add **sampled or debug-level** logging of `Str::limit($raw, N)` when decode fails (avoid logging full prompts/thoughts in production).
- Consider a structured-output or JSON-schema constrained route for digest models if the gateway supports it.

## Customer Communication

- Pending: confirm whether user `3` needs a one-off digest backfill after next successful run.

## Prevention & Follow-up

- [ ] Correlate this warning with OpenRouter latency/timeouts for the same timestamp.
- [ ] If truncation suspected, bump `research.max_tokens` or shorten digest prompt input (`working_memory.authoring_max_prompt_input_chars`).
- [ ] Optional: extend failure log with `model` and masked `raw_preview` behind config.

## Related Issues

- `support/2026-05-08-openrouter-ai-integration-failures.md` — broader OpenRouter / non-JSON hardening context.

## Lessons Learned

- Digest jobs fail **softly** (warning + skip write); absence of an exception can hide UX impact until working memory is inspected.
- Without a redacted raw snippet, production triage for “non-JSON” is guesswork between prose drift, truncation, and provider quirks.

## References

- `app/Jobs/BuildScopeDigestJob.php`
- `app/Services/WorkingMemory/Compactions/ScopeDigestPromptBuilder.php`
- `app/Support/Json/LlmJsonDecoder.php`
- `app/Services/OpenRouterService.php` (`researchFromPrompt`)
- `config/working_memory.php` (`authoring_digest_model`, digest window/min thoughts)
- `config/research.php` (`max_tokens`)
