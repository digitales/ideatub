# Debugging search (idea index semantic search)

When search returns no results or fewer than expected, use these steps.

## 1. Check the distance threshold

Search uses **cosine distance** and only shows thoughts with `distance <= SEARCH_MAX_DISTANCE` (see `IdeaController::SEARCH_MAX_DISTANCE`, default `0.9`). Cosine distance: **0** = identical, **2** = opposite. If the threshold is too low, every thought can be filtered out.

- **Temporarily raise the threshold** in `app/Http/Controllers/IdeaController.php`: e.g. set `SEARCH_MAX_DISTANCE` to `1.5` or `2.0`. If results appear, the threshold was too strict.
- **Fallback**: If no thoughts pass the threshold, the app now falls back to the top 20 by distance (no threshold) so something always shows when thoughts exist.

## 2. Log the query and distances

After getting the embedding in `IdeaController::index()`, you can log the top distances for the current user’s thoughts (one query):

```php
$vector = is_array($embedding) ? json_encode($embedding) : (string) $embedding;
$topDistances = \DB::select(
    'SELECT id, content, embedding <=> ?::vector AS distance FROM thoughts WHERE user_id = ? AND embedding IS NOT NULL ORDER BY embedding <=> ?::vector LIMIT 10',
    [$vector, auth()->id(), $vector]
);
\Log::info('Search top distances', ['query' => $query, 'distances' => $topDistances]);
```

If distances are all above `SEARCH_MAX_DISTANCE`, the threshold is too strict.

To see the exact SQL Laravel runs, enable the query log:

```php
\DB::enableQueryLog();
// ... run the search ...
\Log::info('Search query', \DB::getQueryLog());
```

## 3. Bypass the threshold temporarily

To confirm the problem is the threshold and not the embedding or query, use `nearestTo` only (no `nearestWithin`):

In `IdeaController::index()`, replace the search block with:

```php
$thoughts = (clone $baseQuery)
    ->nearestTo($embedding, self::SEARCH_LIMIT)
    ->paginate(self::SEARCH_LIMIT, ['*'], 'page', $page);
```

If results appear, the issue is the threshold or the raw SQL in `scopeNearestWithin`; if they still don’t, the issue is embedding, `user_id`, or data.

## 4. Verify embeddings exist

Thoughts must have a non-null `embedding` to appear in search. In tinker or a quick route:

```php
\App\Models\Thought::where('user_id', auth()->id())->whereNotNull('embedding')->count();
```

If this is 0, no thoughts have embeddings (e.g. created before embeddings were added or embedding job failed).

## 5. Check for exceptions

Search is wrapped in try/catch and redirects with “Search is temporarily unavailable” on failure. Check `storage/logs/laravel.log` for the real exception (e.g. OpenRouter embed failure, or pgvector syntax error from the raw `<=>` query).

## Summary

| Symptom | Likely cause |
|--------|----------------|
| No results at all | Threshold too low, or no embeddings, or exception (check logs). |
| Only a few results | Threshold too low; try raising `SEARCH_MAX_DISTANCE`. |
| Error / redirect | See `storage/logs/laravel.log` for the exception. |
