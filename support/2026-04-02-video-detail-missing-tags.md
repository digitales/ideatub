# Video thought detail — missing tags (video root and research preview) — Support investigation

**Date**: 2026-04-02  
**Status**: Resolved (code)  
**Priority**: Medium  
**Reported By**: Customer / Internal  

## Issue description

On the thought detail page for **video** thoughts, users expected to see **tags** in line with other types and with linked **research**: the header showed little or no tag affordance when the video root had no `metadata.tags`, the **Video metadata** sidebar listed transcript and capture fields but not tags, and the **Research preview** card showed summary/sections without the linked research document’s tags.

## Customer impact

- Harder to discover Stream filters (`#video`, topic tags) from the video detail page.
- Linked research carried tags in the database (e.g. `research`, `video`, extracted topics) but that was not visible next to the inline preview.
- Video roots created by capture often had **no** `metadata.tags` at all, so the shared header tag row had nothing to render except “Edit”.

## Root cause analysis

1. **Video capture** (`VideoCaptureService`) never added a default type tag on the root; unlike `VideoResearchService`, which always assigns at least `research` and `video` on the research thought, the video root could be saved with no `tags` key.
2. After successful **video research**, topic tags lived on the **research** thought only; the **video** root was not updated with those tags, so the detail header and Stream stayed sparse on the root record.
3. **Presentation**: `VideoThoughtMetadataPresenter` did not expose tags in the sidebar; the shared research preview partial had no `tags` field in its payload.

## Resolution

- Ensure every video root gets at least the **`video`** tag whenever root metadata is written (initial capture and transcript terminal updates).
- After saving new linked research, **merge** tags from the research thought onto the video root, **excluding** the `research` tag (so the root is not misclassified in Stream).
- **Video metadata** sidebar: **Tags** row when `metadata.tags` is non-empty.
- **Research preview** payload: include `tags` from the document root; **Research preview** card shows “Research tags” with Stream links (same for email-linked previews using the same partial).

Implementation: `VideoCaptureService`, `VideoResearchService`, `VideoThoughtMetadataPresenter`, `IdeaController::buildResearchPreviewPayloadFromLinkedResearchThought`, `resources/views/idea/partials/thought_detail_research_preview_card.blade.php`.

## Customer communication

- **Existing** video thoughts created before this change may still have empty `metadata.tags` until the user adds tags (header **Edit**) or runs **Rerun research** (which merges tags onto the root after a successful run).

## Prevention and follow-up

- [ ] Optional one-off backfill command to add `video` to roots that lack it (if product wants historical parity).

## References

- Layout parity spec: `docs/superpowers/specs/2026-04-02-video-detail-email-parity-design.md`
- Related transcript investigation: `support/2026-04-02-youtube-transcript-browser-vs-app.md`
