# SyncUserJiraActivity Job Failure - Customer Support Investigation

**Date**: 2026-03-16  
**Status**: Resolved  
**Customer**: User ID 3  
**Priority**: Medium  
**Reported By**: Internal (queue / monitoring)

## Issue Description

The queued job `App\Jobs\SyncUserJiraActivity` **failed** (payload from failed job system). Payload summary:

| Field | Value |
|-------|--------|
| Job | `App\Jobs\SyncUserJiraActivity` |
| User ID | 3 |
| Days | 40 |
| maxTries | 2 |
| backoff | 120 (seconds) |
| Handler | `Illuminate\Queue\CallQueuedHandler@call` |

The job syncs a user's Jira activity (issues created/updated/commented) into IdeaTub thoughts: it calls the Jira API, then for each event runs idempotency checks, generates embeddings via OpenRouter, and creates Thought records.

**Note:** The actual exception message was not included in the report. To complete root cause analysis, obtain it from one of:

- Queue failure logs (e.g. `failed_jobs` table, Horizon, or queue worker logs)
- User’s Jira sync status: the job writes the exception message to the user preference `JiraSettingsController::getSyncStatusKey()` with `status: 'failed'` and `message: $e->getMessage()` (see `SyncUserJiraActivity::handle` catch block and `failed()` method).

## Customer Impact

- **Users affected:** 1 (user ID 3)
- **Impact:** Jira activity for the last 40 days was not synced; user may see “failed” or stale status in Jira sync settings and missing Jira-sourced thoughts.
- **Business impact:** Integration reliability; user may retry or report “Jira sync not working.”

## Investigation Steps

1. **Get the exception message**
   - Query `failed_jobs` (or equivalent) for this job and read `exception`.
   - Or load user 3’s preferences and read the value for the Jira sync status key; the stored `message` is the exception message.

2. **Confirm user and credentials**
   - Ensure user ID 3 exists and has a `user_jira_credentials` record (job exits early with “completed” if user or credential is null).
   - If credentials exist, consider re-validating with `JiraSyncService::validateCredentials()` (same as settings UI) to rule out expired/revoked token or wrong site URL.

3. **Classify failure point**
   - **Before fetch:** User/credential missing → no exception, job completes without syncing.
   - **During fetch:** `JiraSyncService::fetchEvents()` can throw:
     - `InvalidJiraCredentialsException` (401/403 from Jira)
     - `Illuminate\Http\Client\RequestException` (other HTTP errors, timeouts)
     - Other runtime errors (e.g. malformed response, missing fields).
   - **During embed/thought creation:** `OpenRouterService::embed()` or `Thought::create()` (e.g. DB/constraint errors).

4. **Check environment**
   - Jira: site URL, API token validity, rate limits.
   - OpenRouter: `OPENROUTER_API_KEY`, `OPENROUTER_EMBEDDING_MODEL`; rate/quotas.
   - DB: connectivity, constraints on `thoughts` / `user_preferences`.

5. **Reproduce (if needed)**
   - Dispatch once for user 3 with 1–7 days: `SyncUserJiraActivity::dispatch(3, 7)` and run the worker; capture the exception.

## Root Cause Analysis

**Identified (timeout):** User saw "Sync failed — App\\Jobs\\SyncUserJiraActivity has timed out."

- The job did not set a `$timeout` property, so the queue worker’s default (often 60s) applied.
- For a 40-day window the sync (1) runs many Jira API calls (myself + JQL search + per-issue changelog + per-issue comments, up to 100 issues), then (2) for each new event calls OpenRouter embed and creates a Thought. With many events this can take several minutes.
- **Fix applied:** `SyncUserJiraActivity` now sets `public int $timeout = 600` (10 minutes). Ensure the queue worker is started with a timeout that allows this (e.g. `php artisan queue:work --timeout=600` or Horizon `timeout` in config).

Other possible causes (if failure persists):

- **Invalid/expired Jira credentials** → 401/403 → `InvalidJiraCredentialsException`.
- **Jira API timeout or 5xx** → RequestException.
- **OpenRouter embedding failure** → Missing key, model name, or quota/rate limit.

## Resolution

- **Timeout:** Set job `$timeout = 600` and run the queue worker with at least that (e.g. `queue:work --timeout=600` or Horizon config). For very large syncs (e.g. 40 days, many issues), suggest user sync a shorter range first (e.g. 7–14 days), then run again for older data if needed.
- If credentials: ask user to re-add/verify Jira integration in settings.
- If OpenRouter: check env and quotas; consider retries or backoff.

## Customer Communication

- *None yet.*

## Prevention & Follow-up

- [ ] Add structured logging in `SyncUserJiraActivity` (e.g. log exception and user id when catch/failed run) so future failures are easier to diagnose.
- [ ] Consider capping `days` in the UI/API (e.g. max 30) to reduce risk of timeouts on large syncs.
- [ ] Document in `/dev` or support playbook: “Jira sync failed” → check user preference sync status message and `failed_jobs.exception`.

## Related Issues

- Jira integration: `docs/superpowers/specs/2026-03-16-jira-integration-design.md`, `docs/superpowers/plans/2026-03-16-jira-integration.md`

## Lessons Learned

*To be filled after resolution.*

## References

- Job: `app/Jobs/SyncUserJiraActivity.php`
- Service: `app/Services/JiraSyncService.php`
- Sync status key: `JiraSettingsController::getSyncStatusKey()`
- Config: `config/services.php` → `jira` (default_days, scheduled_sync_days)
