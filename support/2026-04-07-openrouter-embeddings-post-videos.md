# OpenRouter embeddings failure on POST /videos — Customer Support Investigation

**Date**: 2026-04-07  
**Status**: Resolved (mitigation shipped)  
**Customer**: Unknown (production logs)  
**Priority**: Medium  
**Reported By**: Monitoring / logs  

## Issue Description

Production logs around **2026-04-07 17:14 UTC** showed:

1. `INFO: Web server process shut down...` (17:13:16)
2. `ERROR: OpenRouter embeddings response missing data[0].embedding.` (17:14:56 and 17:14:59)
3. Nginx: `a client request body is buffered to a temporary file` for `POST /videos HTTP/1.1` to `ideatub.com` (17:14:58)

The embedding error text matches a `RuntimeException` thrown in application code when the OpenRouter embeddings HTTP call returns **success (2xx)** but the JSON body does not contain a usable `data[0].embedding` array.

## Customer Impact

- **Video save** (`POST /videos`) can fail with a generic “Unable to save video” / 503 if embedding throws inside `VideoCaptureService::capture()` (root or transcript chunk path).
- **Semantic search** for that content would be incomplete if embedding were skipped elsewhere; on this path the request fails outright when embed is required.

## Investigation Steps

1. **Located error source** — `App\Services\OpenRouterService::embed()`:

```40:45:app/Services/OpenRouterService.php
        $response->throw();

        $embedding = $response->json('data.0.embedding');
        if (! is_array($embedding)) {
            throw new \RuntimeException('OpenRouter embeddings response missing data[0].embedding.');
        }
```

   - `$response->throw()` only raises on HTTP client/server errors. A **200 (or other 2xx) with an unexpected JSON shape** (empty `data`, missing `embedding`, or non-array value) produces exactly this message.

2. **Correlated route** — `POST /videos` → `VideoController::store` → `VideoCaptureService::capture()`. Capture **always** calls `OpenRouterService::embed()` for the video root content, and **again for each transcript chunk** when a pasted transcript is saved (`replaceTranscriptChunks`). Those calls are **not** wrapped in try/catch; failures bubble to the controller’s `report($e)` and 503/redirect error path.

3. **Nginx buffer warning** — Large `POST` bodies are spooled to disk when they exceed `client_body_buffer_size`. The app allows `transcript` up to **524288** bytes (`VideoController` validation), so this warning is **expected** for large pasted transcripts and is not itself proof of failure.

4. **Web server shutdown** — The earlier “Web server process shut down” line is consistent with a **deploy, scale event, or process recycle**; treat as context only unless it coincides with user-visible downtime.

## Root Cause Analysis

**Confirmed (code path):** OpenRouter’s embeddings endpoint returned an HTTP response that passed `throw()` but **did not** yield `data[0].embedding` as an array of floats.

**Not yet confirmed (upstream why):** Typical causes to check in production:

- Transient OpenRouter or upstream provider issue returning an empty `data` array or alternate error payload with **2xx** status (unusual but possible via proxies/gateways).
- **Embedding model** misconfiguration (`OPENROUTER_EMBEDDING_MODEL` in `config/services.php`, default `openai/text-embedding-3-small`) — model deprecated, renamed, or returning a different schema for some inputs.
- **Quota / rate limiting** — some APIs return non-standard JSON while still using 2xx; verify OpenRouter dashboard and response body at time of incident (not currently logged).

## Resolution

- **Immediate:** Re-try the operation; if it persists, check OpenRouter status, account quota, and that `OPENROUTER_EMBEDDING_MODEL` is a supported embeddings model on OpenRouter.
- **Operational:** `OpenRouterService::embed()` logs `body_preview` on malformed success responses.
- **Mitigation (code):** `VideoCaptureService` now uses `embedOrNull()` for the video root and transcript chunks (and transcript-fetch root updates). If OpenRouter fails, the thought is still saved with `embedding` null so `POST /videos` no longer hard-fails; semantic search will omit those rows until embeddings exist (e.g. future backfill).

## Customer Communication

- (None yet — internal log review.)

## Prevention & Follow-up

- [x] On missing `data[0].embedding`, log **HTTP status**, **model**, and a **truncated** response body before throwing — implemented in `OpenRouterService::embed()` (2026-04-07).
- [x] **Degrade gracefully** on embed failure during video capture — implemented via `VideoCaptureService::embedOrNull()` (2026-04-07). Optional later: backfill job for null embeddings.

## Related Issues

- `support/2026-03-16-sync-user-jira-activity-job-failure.md` — notes OpenRouter embedding failure modes (key, model, quota).
- `support/2026-04-02-youtube-transcript-browser-vs-app.md` — video/transcript context.

## Lessons Learned

- **2xx + malformed body** is a distinct failure mode from HTTP errors; `throw()` alone does not catch it.
- Large video transcript posts will routinely trigger **nginx client body temp files**; correlate with embedding errors only if timing and request flow match.

## References

- `app/Services/OpenRouterService.php` — `embed()`
- `app/Services/Video/VideoCaptureService.php` — `capture()`, `replaceTranscriptChunks()`
- `app/Http/Controllers/VideoController.php` — `store()`
- `config/services.php` — `services.openrouter.embedding_model`
