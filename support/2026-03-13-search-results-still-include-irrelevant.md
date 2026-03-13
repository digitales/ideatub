# Search results still include irrelevant thoughts - Customer Support Investigation

**Date**: 2026-03-13
**Status**: Resolved
**Customer**: User report (support)
**Priority**: Medium
**Reported By**: Customer

## Issue Description

When searching for a specific term (e.g. "coningsby"), results included many thoughts that do not relate to the search term — e.g. Coningsby Gallery results mixed with thoughts about Dezeen, ButterCMS, How's My Commute, MastercardFoundation, competitive intelligence, Cursor agents, etc. Only thoughts that are actually about the search term should appear.

## Customer Impact

- **Users affected**: All users using semantic search on the idea index
- **Severity**: Medium — search is noisy and undermines trust in results
- **Business impact**: Users cannot reliably find thoughts by topic

## Investigation Steps

1. Reviewed prior fix in `support/2026-03-13-search-results-relevance-and-anchor.md`: relevance was addressed by introducing `nearestWithin` with a similarity threshold and documenting `SEARCH_MAX_DISTANCE = 0.5`.
2. Checked current code: `IdeaController::SEARCH_MAX_DISTANCE` is set to **0.9**, not 0.5.
3. Cosine distance: 0 = identical, 2 = opposite. A threshold of 0.9 is very permissive and allows thoughts that are only loosely related in embedding space (e.g. same user/workspace context, same domain terms) to be included.
4. Conclusion: the permissive threshold (0.9) was allowing irrelevant thoughts to pass the filter.

## Root Cause Analysis

The semantic search uses pgvector cosine distance and only returns thoughts with `distance <= SEARCH_MAX_DISTANCE`. The value **0.9** was too high: many thoughts that are not about the query (e.g. "coningsby") still fall within 0.9 cosine distance (e.g. other projects or topics from the same workspace). The original resolution in the earlier support doc specified **0.5** for stricter relevance; that value was not present in the live code.

## Resolution

- **`app/Http/Controllers/IdeaController.php`**: Set `SEARCH_MAX_DISTANCE` from `0.9` to `0.5` so that only thoughts with cosine distance ≤ 0.5 are returned. The list may be shorter than 20 when there are fewer close matches; the existing fallback (top N by distance when zero pass the threshold) remains for edge cases.
- If users later report too few results for valid queries, consider making the threshold configurable (e.g. env) or relaxing slightly (e.g. 0.6) as noted in the prior support doc.

## Prevention & Follow-up

- [ ] Consider making `SEARCH_MAX_DISTANCE` configurable (e.g. config or env) if users report too few or too many results.
- [ ] If zero thoughts pass the threshold, the fallback shows top N by distance; consider surfacing a "No close matches" message and suggesting broadening the query.

## Related Issues

- `support/2026-03-13-search-results-relevance-and-anchor.md` — initial relevance and anchor fix (documented 0.5; code had drifted to 0.9)

## References

- `app/Http/Controllers/IdeaController.php` — `SEARCH_MAX_DISTANCE`, `nearestWithin`
- `app/Models/Thought.php` — `scopeNearestWithin`
- pgvector cosine distance: `<=>` operator
