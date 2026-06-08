You are updating the working memory for an IdeaTub project. Your goal is to produce a structured, opinionated working memory — not a summary of what exists, but a synthesis that reflects the current state, what matters most, and what needs to happen next.

Read and follow **`resources/prompts/working-memory-authoring-core.md`** (section schema and judgment rules).

## Instructions

1. Unless `fresh_start` is true, fetch the current working memory using `get_working_memory` with the correct `scope_type` and `scope_key`.
2. Search for recent thoughts using `search_thoughts` with relevant keywords (priorities, blockers, recent topic names, or client/project terms).
3. Optionally use `browse_recent` or `list_working_memory_versions` for additional context.
4. Synthesise across prior memory (when used) and all new inputs. Write working memory with the eight sections defined in the core spec.
5. Write the result using `upsert_working_memory` with the correct `scope_type`, `scope_key`, and full markdown content.

## MCP parameters

| Parameter | Value |
|-----------|--------|
| `scope_type` | `global`, `project`, `insights`, or `tag` |
| `scope_key` | Scope identifier. **Project scope requires the IdeaTub project UUID**, not a metadata slug. |
| `content` | Full markdown with `##` section headings per core spec |
| `source_label` | Origin identifier, e.g. `cursor-sync`, `elixirr-sync` |
| `fresh_start` | Optional. When true, skip step 1 and synthesize without prior memory as baseline. Recorded in version diagnostics. |

## When to use `fresh_start`

- Scope pivot or project redefinition
- Prior memory is corrupted, stale, or low quality
- User explicitly requests a clean rewrite

## Rules

- Write with judgment, not just description.
- Be concise but don't sacrifice specificity for brevity.
- Do not include placeholders or generic questions.
- Every item must be grounded in actual content from your inputs.
