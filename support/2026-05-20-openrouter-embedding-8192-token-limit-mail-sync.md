# OpenRouter embedding 8192 token limit (mail sync) — Customer Support Investigation

**Date**: 2026-05-20  
**Status**: Resolved (code fix)  
**Customer**: Production (mail import path)  
**Priority**: High  
**Reported By**: Monitoring  

## Issue Description

`SyncMailAccountIncremental` → `EmailImportService` → `ThoughtCaptureService` failed embedding a large email body:

`OpenRouter embeddings request failed: HTTP 400: Invalid 'input': maximum context length is 8192 tokens.`

## Root Cause Analysis

The May 2026 embedding hardening (`support/2026-05-08-openrouter-ai-integration-failures.md`) had two gaps:

1. **Retry guard** — Context-limit retry only ran when `mb_strlen($retryInput) < mb_strlen($input)`. Token-dense bodies under the 24k **character** cap (common for HTML email) could fail at 5–8k chars while still exceeding 8192 tokens; retry was skipped and the job threw.
2. **HTTP 400** — ` $response->throw()` ran before reading `error.message` on some paths; 400 responses with a parseable context-limit message did not always get a truncation retry.

Proactive truncation used `embedding_chars_per_token=2.0` (up to ~12k chars for an 8192-token model), which is too optimistic for dense or markup-heavy mail.

## Resolution

`OpenRouterService::embed()`:

- Proactive cap via `OPENROUTER_EMBEDDING_MAX_TOKENS` (default 8192) and `OPENROUTER_EMBEDDING_PROACTIVE_CHARS_PER_TOKEN` (default 1.0) before the first request (~6144 chars).
- Up to **two** context-limit retries with strictly smaller budgets (`retry` then `aggressive`).
- Read provider errors from HTTP 4xx bodies before throwing; retry on context-limit, then `RequestException` for other HTTP failures.

## Prevention

- [ ] Monitor `OpenRouter embedding retrying after provider context-limit` warnings after deploy.
- [ ] Optional: lower `OPENROUTER_EMBEDDING_MAX_INPUT_CHARS` if mail bodies are routinely huge and metadata loss is acceptable.

## References

- `app/Services/OpenRouterService.php`
- `config/services.php`
- `tests/Unit/Services/OpenRouterServiceTest.php`
- `support/2026-05-08-openrouter-ai-integration-failures.md`
