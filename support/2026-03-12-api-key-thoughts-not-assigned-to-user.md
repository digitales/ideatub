# API Key Thoughts Not Assigned to User - Customer Support Investigation

**Date**: 2026-03-12  
**Status**: Resolved  
**Customer**: [Reported by user; anonymized]  
**Priority**: High  
**Reported By**: Customer  

## Issue Description

When using the MCP API with an API key (e.g. from AI agents such as Cursor, Claude, ChatGPT), thoughts created via `capture_thought` were not being assigned to the user who owns the key. Thoughts were stored with `user_id` null or otherwise not scoped to the key owner, so they did not appear in that user’s thought list and broke multi-user isolation.

## Customer Impact

- Any user relying on MCP + API key for capturing thoughts from AI agents was affected.
- New thoughts from agents were not associated with the key owner.
- Search, browse, and stats for that user would not include those thoughts.
- Violates the documented behavior that “the key identifies the user” and all tools run in that user’s context ([project-spec.md §3.1](../decisions/project-spec.md)).

## Investigation Steps

1. **Trace MCP auth flow**  
   - Confirmed `McpController::__invoke()` resolves the user via `resolveUser($request)` from either Bearer JWT (OAuth) or `?key=...` / `x-ideatub-key` (API key).
   - Confirmed the controller sets `$request->setUserResolver(fn () => $user)` so `$request->user()` returns the resolved user.

2. **Trace thought creation and scoping**  
   - `capture_thought` and other tools use `auth()->id()` for `user_id` and for filtering (e.g. `Thought::query()->where('user_id', auth()->id())`).
   - The default auth guard is `web` (session). For stateless API requests there is no session, so `auth()->user()` / `auth()->id()` were never populated from the request.

3. **Root cause**  
   - The request’s user was set only via `$request->setUserResolver()`. Laravel’s `auth()` helper uses the default guard (session), not the request’s user resolver, so `auth()->id()` remained null for API key (and OAuth) requests. Thoughts were therefore created with `user_id => null` (allowed by the schema).

## Root Cause Analysis

- **Cause:** The MCP layer set the “current user” only on the **Request** (`setUserResolver`), while all tool logic used **Auth** (`auth()->id()`). For stateless MCP requests the Auth guard has no user, so `auth()->id()` was null.
- **Why it wasn’t caught earlier:** Web UI and other session-based flows use the same `auth()->id()` and do have a logged-in user; only the stateless MCP API path lacked a user in the guard.

## Resolution

- In `App\Http\Controllers\Api\McpController`, immediately after resolving the user and setting the request user resolver, the controller now calls `Auth::setUser($user)` so the default guard has the resolved user for the rest of the request.
- All existing uses of `auth()->id()` in the MCP tools (e.g. `searchThoughts`, `browseRecent`, `thoughtStats`, `captureThought`) now see the key/JWT owner as the current user. Thoughts created via API key or OAuth are correctly assigned to that user.

**Code change:**  
- File: `app/Http/Controllers/Api/McpController.php`  
- After `$request->setUserResolver(fn () => $user);` add: `Auth::setUser($user);`  
- Added: `use Illuminate\Support\Facades\Auth;`

## Customer Communication

- [2026-03-12]: Issue reported; investigation and fix completed. Recommend redeploy and, if any thoughts were created with null `user_id` before the fix, consider a one-off data script to assign them if they can be attributed (e.g. by key usage logs); otherwise treat as orphaned.

## Prevention & Follow-up

- [ ] Consider adding an integration test that calls MCP with an API key and asserts that a captured thought has `user_id` equal to the key owner.
- [ ] Optionally add a constraint or application-level check so that new thoughts are never stored with null `user_id` for authenticated MCP requests.

## Related Issues

- MCP auth design: [decisions/project-spec.md §3.1](../decisions/project-spec.md), [decisions/2025-03-10-mcp-auth-multi-agent.md](../decisions/2025-03-10-mcp-auth-multi-agent.md).

## Lessons Learned

- For stateless API endpoints that resolve a user from a token or key, the “current user” must be set on the Auth guard (e.g. `Auth::setUser($user)`) if downstream code uses `auth()->user()` or `auth()->id()`. Setting only `$request->setUserResolver()` is not sufficient for guard-based auth.

## References

- [decisions/project-spec.md](../decisions/project-spec.md) — MCP auth and user isolation  
- [app/Http/Controllers/Api/McpController.php](../app/Http/Controllers/Api/McpController.php) — MCP request handling and tool dispatch  
