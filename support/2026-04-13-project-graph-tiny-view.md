# Project graph appears very small — Customer Support Investigation

**Date**: 2026-04-13  
**Status**: Resolved  
**Customer**: Internal (reported in product)  
**Priority**: Medium  
**Reported By**: Internal

## Issue Description

On the project graph page (`{project} — graph`), the vis-network graph rendered as a tiny, illegible cluster pinned toward the top of a large empty canvas instead of filling the viewport.

## Customer Impact

- Graph view unusable for understanding thought relationships at a glance.
- Affects any member using the project graph with multiple nodes.

## Investigation Steps

1. Reviewed `resources/views/projects/graph.blade.php` and confirmed vis-network is initialized without a post-layout zoom.
2. Compared with vis-network usage patterns: default camera scale does not zoom to the bounding box of nodes after physics stabilization.
3. Checked container sizing: only `min-height` was set; explicit height improves predictable canvas size for the library.

## Root Cause Analysis

1. **No `network.fit()` after stabilization** — The default view leaves a wide “world” scale, so a compact stabilized layout appears as a small blob.
2. **Viewport height** — Giving the graph container a definite height (`min(72vh, 900px)` in addition to `min-height`) keeps the drawing area consistent across viewports.

## Resolution

- Call `network.fit({ padding: 48, animation: false })` on `stabilizationIterationsDone`.
- Set container `height: min(72vh, 900px)` alongside existing `min-height: 420px`.
- Refit on window `resize` (debounced) so layout changes stay usable.

## Customer Communication

- N/A (internal)

## Prevention & Follow-up

- [ ] Consider pinning vis-network version in the script URL to avoid upstream behavioral drift.
- [ ] Optional: add a small “Fit graph” control for manual refit.

## Related Issues

- None logged.

## Lessons Learned

Always fit the camera to node bounds after force-directed layout in graph libraries; default scale is not “fill the panel.”

## References

- `resources/views/projects/graph.blade.php`
- `app/Http/Controllers/ProjectGraphController.php`
