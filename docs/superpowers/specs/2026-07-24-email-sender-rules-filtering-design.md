# Email Sender Rules Settings Filtering Design

**Date:** 2026-07-24  
**Status:** Approved  
**Scope:** Add server-side filtering and pagination to `/settings/email-sender-rules` so users can find rules by action and sender email without loading an unfiltered full list.

## 1. Summary

The email sender rules settings page currently loads every rule for the authenticated user (`orderBy('sender_email')->get()`). As rule lists grow, that becomes hard to scan.

This design keeps the existing Blade settings page and extends it with:

- URL query params for **action** filter and **sender email** search
- Server-side **pagination** (25 per page)
- Filter preservation across Add / Update / Remove redirects

Rule semantics (`allow` / `ignore` / `review` / `extra_process`) are unchanged.

## 2. Goals

- Let users filter the settings list by rule action
- Let users search by partial sender email address
- Keep filters bookmarkable and refresh-safe via the URL
- Paginate results so large rule sets remain usable
- Preserve active filters after create / update / delete
- Stay consistent with existing Blade settings pages (no Inertia rewrite)

## 3. Non-goals

- Domain-wide or wildcard sender rules
- Client-side live search (incompatible with correct server pagination)
- Rewriting the page in Vue / Inertia
- Filtering imported emails or inbox items (this is rules only)
- Changing how rules are evaluated at import time
- Sorting options beyond the existing `sender_email` ascending order

## 4. Approach

**Chosen:** Server-filtered Blade page (extend current controller + view).

Rejected alternatives:

- Full Inertia/Vue rewrite — snappier later, but inconsistent with nearby settings and overkill for one list
- JSON API + client table — extra surface area with no existing pattern on this page

## 5. Filter model

### 5.1 Query parameters

All optional on `GET /settings/email-sender-rules`:

| Param | Meaning |
|-------|---------|
| `action` | Exact match on rule action: `allow`, `ignore`, `review`, or `extra_process`. Empty / missing / `all` = no action filter |
| `q` | Case-insensitive substring match on `sender_email` after trim. Empty = no search |
| `page` | Laravel pagination page |

### 5.2 Controller behavior (`index`)

1. Start from `$request->user()->emailSenderRules()`
2. If `action` is one of `EmailSenderRule::actions()`, apply `where('action', $action)`
3. If `q` is non-empty after trim, apply a safe case-insensitive `LIKE` on `sender_email` (escape `%` and `_` in user input)
4. Validate `q` as `nullable|string|max:255`; invalid `action` values are ignored (treat as “all”), not a 422
5. `orderBy('sender_email')->paginate(25)->withQueryString()` so pagination links keep `action` and `q`
6. Pass `rules` (paginator), plus current filter values for form defaults, to the view

Feature-flag gating stays as today: when `services.email_sender_policy.enabled` is false, the page returns 404.

### 5.3 Mutations and redirect preservation

`store`, `update`, and `destroy` continue to authorize and mutate as today (ownership 403, reconcile job when policy enabled).

After success, redirect to `settings.email-sender-rules.index` **including the active filter query string**.

**Naming note:** Add and Update forms already POST `action` as the rule’s action. Filter preservation must not reuse that field name.

Mechanism:

- Add / Update / Remove forms include hidden fields `filter_action` and `filter_q` mirroring the current list filters
- Controllers read those optional fields, map valid `filter_action` → query `action` and trimmed `filter_q` → query `q`, then redirect with only non-empty query params
- Do not persist invalid `filter_action` values into the redirect URL
- The GET filter form continues to use public query names `action` and `q` only

## 6. UI

Above the “Your rules” list on `resources/views/settings/email-sender-rules.blade.php`:

1. **Filter bar** — GET form to the index route:
   - Text input labeled for sender search (`q`)
   - Select for action: All / Allow / Ignore / Review / Extra process (`action`)
   - Submit: “Filter”
   - Clear control: link/button to the index route with no query params

2. **Result chrome:**
   - When filters are active, show a short summary such as “N matching rules” using the paginator total
   - Empty filtered state: “No rules match these filters.” (distinct from “No sender rules yet.”)

3. **List + Add form** — existing layout and actions unchanged aside from hidden `filter_action` / `filter_q` fields and pagination under the list

4. **Pagination** — Laravel paginator links, styled to fit the settings page

## 7. Data / auth boundaries

- Rules remain user-scoped; no cross-user leakage
- Update / destroy still `abort_unless($emailSenderRule->user_id === $request->user()->id, 403)`
- No schema migration; filtering uses existing `email_sender_rules` columns (`sender_email`, `action`)

## 8. Testing

Extend `tests/Feature/EmailSenderRuleSettingsTest.php`:

- Filter by `action` returns only matching rules
- `q` matches substring of `sender_email` (case-insensitive)
- Combined `action` + `q` works; pagination links retain both
- Empty filtered state vs empty account state
- After update / destroy (and store when `filter_action` / `filter_q` are present), redirect keeps query `action` / `q`
- Invalid `action` query param does not error and behaves as unfiltered-by-action
- Existing coverage (auth, feature flag 404, CRUD, ownership) remains green

## 9. Files likely touched

- `app/Http/Controllers/EmailSenderRuleSettingsController.php`
- `resources/views/settings/email-sender-rules.blade.php`
- `tests/Feature/EmailSenderRuleSettingsTest.php`

## 10. Out of scope follow-ups

- Debounced live search with AJAX (would still need server search across pages)
- Per-user page-size preference
- Bulk edit / bulk delete of filtered rules
