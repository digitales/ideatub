# OpenRouter AI Integration Failures - Customer Support Investigation

**Date**: 2026-05-08
**Status**: Resolved
**Customer**: Internal user `3`
**Priority**: High
**Reported By**: Monitoring

## Issue Description
Multiple production errors were reported across AI integration paths:
- OpenRouter embeddings failures with provider error: maximum context length 8192 tokens.
- Digest compaction jobs logging "model returned non-JSON output."
- Repeated MCP warning logs for `tools/call` with `capture_meeting`.
- OpenRouter chat completion timeouts (`cURL error 28` after 30000ms) during digest generation.

## Customer Impact
- 1 known user directly impacted in reported traces.
- Intermittent failed captures/compactions reduced reliability of memory updates.
- High operational noise from warning logs made triage harder.

## Investigation Steps
1. Traced stack frames through `OpenRouterService`, `ThoughtCaptureService`, `BuildScopeDigestJob`, and `McpController`.
2. Confirmed embeddings guard used character truncation only, which can still exceed token limits for some inputs.
3. Confirmed digest JSON decoding accepted plain JSON/fenced JSON only; prose-wrapped JSON was dropped.
4. Confirmed chat calls relied on short fixed timeout at key path used by digest jobs.
5. Confirmed MCP `tools/call` invocations were logged at warning severity even when successful.
6. Added regression tests for embedding context-limit retry and prose-wrapped JSON decoding.

## Root Cause Analysis
The errors were related AI integration boundary failures:
- **Token limit mismatch**: embeddings path truncated by chars, not provider token budget, so high token-density input could still breach model context.
- **Fragile output parsing**: digest parser rejected responses containing valid JSON wrapped in surrounding text.
- **Network resilience gap**: chat completion requests for digest generation used a short timeout with no transport-level retry, causing transient provider/network latency to fail jobs.
- **Logging severity mismatch**: MCP tool invocation telemetry used warning level, creating false-positive operational alerts.

## Resolution
Implemented hardening in code:
- `OpenRouterService`:
  - Added provider context-limit detection and one automatic retry with aggressive token-safe truncation.
  - Added configurable embedding/chat/connect timeouts.
  - Added retry for transient chat connection failures.
- `LlmJsonDecoder`:
  - Added fallback extraction of first balanced JSON object from prose-wrapped model output.
- `McpController`:
  - Changed `tools/call` logging to opt-in info logging via config instead of unconditional warnings.
- Config updates:
  - Added new OpenRouter timeout/retry/token-safety env-backed config values.
  - Added `MCP_LOG_TOOL_CALLS` toggle.

## Customer Communication
- 2026-05-08: Confirmed all reported incidents are linked to AI integration points and deployed a hardening patch set with regression coverage.

## Prevention & Follow-up
- [ ] Monitor error rates for:
  - `OpenRouter embeddings request failed`
  - `BuildScopeDigestJob: model returned non-JSON output`
  - `ConnectionException` timeouts on OpenRouter chat
- [ ] If timeouts persist, evaluate background retry policy/backoff at job layer and model-specific timeout profiles.
- [ ] Add dashboard panels for OpenRouter success/failure and latency percentiles by endpoint.

## Related Issues
- OpenRouter embedding context overflow (HTTP 400 max context length 8192 tokens)
- BuildScopeDigestJob non-JSON authoring output
- OpenRouter chat timeout (`cURL error 28`)

## Lessons Learned
- Character limits are not sufficient proxies for token limits on embedding APIs.
- LLM JSON handling in production should tolerate minor format drift, not only ideal outputs.
- Warning-level logs should indicate actionable faults, not normal invocation telemetry.

## References
- `app/Services/OpenRouterService.php`
- `app/Support/Json/LlmJsonDecoder.php`
- `app/Http/Controllers/Api/McpController.php`
- `config/services.php`
- `config/mcp.php`
