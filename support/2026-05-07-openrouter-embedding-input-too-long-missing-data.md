# OpenRouter embeddings response missing data[0].embedding (input too long) - Customer Support Investigation

**Date**: 2026-05-07  
**Status**: Resolved (root cause identified; remediation defined)  
**Customer**: Unknown (anonymized)  
**Priority**: Medium  
**Reported By**: Internal

## Issue Description

An embeddings request reported:

- `OpenRouter embeddings response missing data[0].embedding.`

Captured response context:

```json
{
  "status": 200,
  "model": "openai/text-embedding-3-small",
  "body_preview": "{\"error\":{\"message\":\"HTTP 400: {\\n  \\\"error\\\": {\\n    \\\"message\\\": \\\"Invalid 'input': maximum context length is 8192 tokens.\\\",\\n    \\\"type\\\": \\\"invalid_request_error\\\",\\n    \\\"param\\\": null,\\n    \\\"code\\\": null\\n  }\\n}\",\"code\":400}}"
}
```

Although the transport status was 200, the payload contains an upstream 400-style error indicating the input was too large for the embedding model context window.

## Customer Impact

- Requests that call embeddings with oversized input can fail in whichever flow calls `OpenRouterService::embed()`.
- User-visible impact depends on caller behavior:
  - Flows with graceful degradation continue but store null/missing embeddings.
  - Flows without graceful degradation can return errors to users.
- Semantic similarity/retrieval quality can degrade when embeddings are skipped.

## Investigation Steps

1. Reviewed the logged response context and confirmed the body includes an explicit upstream error:
   - `Invalid 'input': maximum context length is 8192 tokens.`
2. Confirmed embedding parsing path in `app/Services/OpenRouterService.php`:
   - Calls `$response->throw()` (HTTP layer only).
   - Then expects `data.0.embedding`.
   - Throws `RuntimeException` when `data[0].embedding` is not present.
3. Verified this failure mode occurs when provider returns an error-shaped JSON body (or proxied error payload) that does not include embeddings data.

## Root Cause Analysis

Root cause is oversized embedding input sent to model `openai/text-embedding-3-small` (max context length 8192 tokens), combined with response handling that assumes successful payload shape after HTTP-level success.

Specifically:

- The embedding request body is currently sent as raw input text without explicit length guardrails in `OpenRouterService::embed()`.
- When provider/proxy returns an error object instead of `data[0].embedding`, the service surfaces a generic "missing data[0].embedding" runtime error.

## Resolution

No platform outage; issue is a deterministic input-limit violation.

Immediate operational resolution:

- Retry only with shorter input content.
- Where possible, split/chunk long content before embedding.

Engineering remediation (recommended):

1. Add preflight input size guardrails in `OpenRouterService::embed()` (truncate or chunk before API call).
2. Detect error-shaped payloads (e.g. `error.message`, `error.code`) and throw a clearer exception that includes provider reason.
3. Ensure all high-volume/long-content embedding callers degrade gracefully when embeddings fail.

## Customer Communication

- 2026-05-07: Explained that this is not random API corruption; the input exceeded embedding token limits. Advised reducing/chunking content and noted code-level guardrail improvements.

## Prevention & Follow-up

- [ ] Add embed preflight length handling (truncate/chunk strategy) with tests.
- [ ] Improve error parsing to surface upstream limit messages directly.
- [ ] Add metrics/log dimension for embedding input size and limit-related failures.
- [ ] Audit embedding call sites for consistent graceful-degradation behavior.

## Related Issues

- `support/2026-04-07-openrouter-embeddings-post-videos.md` - Similar symptom (`missing data[0].embedding`) with mitigation history.

## Lessons Learned

- A 2xx HTTP status is not sufficient proof of a usable embeddings payload.
- Limit-related provider errors can be wrapped/proxied and appear as shape mismatches unless explicitly parsed.
- Input-size guardrails belong at service boundaries, not only at UI or controller layers.

## References

- `app/Services/OpenRouterService.php`
- `config/services.php`
- `support/2026-04-07-openrouter-embeddings-post-videos.md`
