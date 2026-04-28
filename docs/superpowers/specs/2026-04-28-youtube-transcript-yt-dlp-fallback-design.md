# YouTube Transcript Retrieval with `yt-dlp` Fallback

**Date:** 2026-04-28  
**Status:** Proposed  
**Owner:** Video transcript pipeline  
**Related issue:** automatic YouTube transcript retrieval can fail with `transcript_unavailable` (`TranscriptsDisabledException`) while manual transcripts work.

## 1. Problem

IdeaTub currently fetches YouTube transcripts via `App\Services\Email\YouTubeTranscriptService` (package: `mrmysql/youtube-transcript`) and executes a single background fetch attempt in `FetchVideoTranscript`.

When that provider cannot retrieve tracks, transcript status becomes terminal (`failed` or `unavailable`) and users must recover manually. This is acceptable as a fallback, but automatic success rate is lower than desired for some videos.

## 2. Goals

- Keep existing provider as primary fetch path.
- Add `yt-dlp` as the primary automated fallback behind a feature flag.
- Preserve existing status and failure semantics used by `VideoCaptureService::applyTranscriptFetchResult`.
- Keep manual transcript entry as the final, reliable fallback.
- Avoid introducing hard runtime failures when fallback tooling is unavailable.

## 3. Non-goals

- Replacing manual transcript support.
- Introducing new external paid transcript providers in this change.
- Adding multi-step retry orchestration across multiple queued jobs.
- Reworking transcript storage model.

## 4. Chosen approach

Use a provider-chain orchestrator:

1. Try existing YouTube transcript provider (`mrmysql/youtube-transcript`).
2. On primary failure, if feature flag is enabled, try `yt-dlp`.
3. Return normalized success/failure result to existing persistence pipeline.
4. If both fail, keep current failure behavior and allow manual transcript entry.

This keeps fallback behavior centralized and prevents queue-job logic from becoming provider-specific.

## 5. Architecture

### 5.1 New service: `VideoTranscriptOrchestrator`

Responsibilities:

- Accept canonical video URL input.
- Execute providers in order.
- Normalize outputs to the existing payload contract:
  - success: `ok`, `video_id`, `language_code`, `transcript`
  - failure: `ok=false`, `reason`, `video_id`, optional `detail`
- Emit provider-aware logs for observability (`provider_attempts`, `provider_used`).

Provider order:

1. `YouTubeTranscriptService` (always)
2. `YtDlpTranscriptService` (only when config flag enabled and primary failed)

### 5.2 New service: `YtDlpTranscriptService`

Responsibilities:

- Invoke `yt-dlp` for subtitle extraction.
- Parse subtitle output into plain transcript text.
- Return the same normalized contract used by the primary provider.

Expected behavior:

- Does not throw unhandled exceptions to job layer.
- Converts execution errors/timeouts/missing binary into bounded failure payloads.
- Supports a configurable binary path and timeout.

## 6. Queue/job integration

`FetchVideoTranscript` changes:

- Keep root thought validation and canonical URL resolution unchanged.
- Replace direct call to `YouTubeTranscriptService::fetchForUrl` with orchestrator call.
- Continue using `VideoCaptureService::applyTranscriptFetchResult` and downstream research queue behavior unchanged.

Result: persistence, metadata writes, and research-ready behavior remain consistent with existing flow.

## 7. Failure semantics

Top-level statuses remain unchanged:

- `available`
- `unavailable`
- `failed`
- `manual`

Normalization rules:

- Primary success: return immediately.
- Primary fail + fallback disabled: return primary failure.
- Primary fail + fallback enabled + fallback success: return success.
- Primary fail + fallback enabled + fallback fail: return bounded failure reason:
  - `transcript_unavailable` when both effectively indicate no transcript availability
  - `youtube_fetch_failed` for tooling/network/parse/runtime failures
  - `youtube_rate_limited` only when provider can confidently detect it

If `yt-dlp` is missing or not executable:

- Treat as fallback failure, not as job crash.
- Log provider-level reason (for example `yt_dlp_unavailable`) for diagnostics.
- Return normalized top-level failure payload.

## 8. Feature flag and configuration

Add config keys:

- `TRANSCRIPTS_YT_DLP_ENABLED` (default `false`)
- `TRANSCRIPTS_YT_DLP_BIN` (default `yt-dlp`)
- `TRANSCRIPTS_YT_DLP_TIMEOUT_SECONDS` (default `25`)

Operational policy:

- Deploy with flag off first.
- Enable in staging, then production progressively.

## 9. Manual transcript fallback

Manual fallback remains mandatory and authoritative:

- On terminal non-success, UI continues to allow manual paste.
- Manual transcript state (`manual` / `pasted`) remains protected from automatic overwrite unless user explicitly triggers a new auto-fetch action.

This ensures users are never blocked by provider/API limitations.

## 10. Testing plan

### 10.1 Unit tests: orchestrator

- Primary success short-circuits fallback.
- Primary fail + flag off returns primary failure.
- Primary fail + flag on + fallback success returns success.
- Both fail returns bounded normalized failure.

### 10.2 Unit tests: `YtDlpTranscriptService`

- Successful subtitle extraction and transcript normalization.
- Timeout handling.
- Non-zero exit handling.
- Missing binary handling.

### 10.3 Feature tests: transcript job flow

- `FetchVideoTranscript` still updates root and transcript child correctly on orchestrator success.
- Existing no-op behavior for manual transcript states remains unchanged.
- Failure paths still end in states compatible with manual transcript entry and research flow.

## 11. Rollout and verification

Rollout steps:

1. Ship code with `TRANSCRIPTS_YT_DLP_ENABLED=false`.
2. Validate in staging on known failing IDs (including `vQDEJptjtWw`).
3. Enable in production for controlled interval.
4. Monitor logs and compare success/failure ratios before and after.

Success signal:

- Increased transcript auto-fetch success rate without increased queue failures or user-facing regressions.

## 12. Risks and mitigations

- **Risk:** `yt-dlp` not present in some environments.  
  **Mitigation:** feature flag default off + graceful missing-binary handling.

- **Risk:** provider output format changes.  
  **Mitigation:** robust parser + bounded failure path + tests with representative subtitle fixtures.

- **Risk:** slower job execution when fallback runs.  
  **Mitigation:** strict command timeout and provider attempt logging.

