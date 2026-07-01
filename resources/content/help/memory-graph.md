# Memory graph levels

IdeaTub exposes five optional graph views over your thoughts. Each level is gated by its own feature flag (default off except the project graph).

## Feature flags

| Flag | Level | Default |
|------|--------|---------|
| `FEATURE_MEMORY_GRAPH_LOCAL` | Thought-detail neighborhood | `false` |
| `FEATURE_MEMORY_GRAPH_PROJECT` | Project graph | `true` |
| `FEATURE_MEMORY_GRAPH_TAG` | Tag constellation | `false` |
| `FEATURE_MEMORY_GRAPH_SEMANTIC` | Similarity (k-NN) edges | `false` |
| `FEATURE_MEMORY_GRAPH_VAULT` | Filtered vault graph | `false` |
| `FEATURE_MEMORY_GRAPH_SUGGESTIONS` | Background link suggestions | `false` |

Set flags in `.env`. When a flag is off, its routes return **404** (not a visible disabled state).

## Routes

| Level | Page | Data |
|-------|------|------|
| Local | `/thoughts/{id}/graph` | `/thoughts/{id}/graph/data` |
| Project | `/projects/{project}/graph` | `/projects/{project}/graph/data` |
| Tag | `/graph/tags?tag=…` | `/graph/tags/data?tag=…` |
| Semantic | `/thoughts/{id}/semantic-graph` | `/thoughts/{id}/semantic-graph/data` |
| Vault | `/graph` | `/graph/data` |

## Edge types

- **Explicit links** — curated `thought_links` (sync at read time).
- **Structure** — `parent_id` sections and chunks.
- **Shared tags** — pairwise tag overlap (vault/tag modes; capped).
- **Semantic** — on-demand pgvector k-NN when the semantic flag is on and you opt in via `include_semantic=1` or the semantic layer.

Semantic edges are **never** written automatically to `thought_links`.

## Suggestions (optional)

When `FEATURE_MEMORY_GRAPH_SUGGESTIONS` is on, a background job computes up to five semantic neighbors per thought (after create or content change). Suggestions appear on the thought detail page; you can **promote** (creates a link) or **dismiss**. Promoted rows are marked so they do not reappear.

## Performance tips

- Start with explicit links only (project graph or vault “Links only” preset).
- Enable neighbors, sections, shared tags, or semantic layers only when you need them.
- Vault graph caps nodes and may truncate with `meta.truncated`.
