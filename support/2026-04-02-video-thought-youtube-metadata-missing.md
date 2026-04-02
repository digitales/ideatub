# Video thought: title/channel missing from Content — Support investigation

**Date**: 2026-04-02  
**Status**: Resolved (product fix + documentation)  
**Priority**: Medium  
**Reported By**: Internal  

## Issue description

On the thought detail page, the **Content** card for a `video` thought showed only the generic line **YouTube video**, the canonical URL, and transcript status. Users expected to see **video title** and **channel** (and sometimes view counts / relative dates as in the YouTube UI). Screenshots compared a richer-looking state (title + channel line) with the minimal template.

## Customer impact

- Harder to scan Stream and thought detail for which video was captured.
- Embeddings and research prompts built from root `content` carried less useful context when only the generic label was stored.

## Investigation steps

1. **Confirmed storage model**: Root video thoughts use `VideoThoughtContentBuilder` for `thought.content`. Before the fix, that template was fixed text `YouTube video`, URL, and transcript status — no title or channel in `metadata` or `content`.
2. **Why it felt like a regression**: Any path that **rewrites** the root (capture, `FetchVideoTranscript` terminal states) rebuilt `content` from that same template. If richer text had been present only in `content` (e.g. manual edit) or in an unreleased build, it would be **overwritten** on the next automated update.
3. **View counts / “3 weeks ago”**: YouTube’s public **oEmbed** endpoint does not expose view count or publish date. Those fields are not part of the implemented fix; only **title** and **author (channel) name** are sourced from oEmbed.

## Root cause analysis

- **No enrichment**: The application did not fetch or persist YouTube title/channel metadata.
- **Destructive rebuilds**: Transcript fetch completion (`applyTranscriptFetchSuccess` / unavailable / failed) always replaced root `content` with the minimal template, so any richer `content` could disappear after fetch.

## Resolution

- **Implementation** (2026-04-02): Added `YouTubeOEmbedService` (best-effort GET to `https://www.youtube.com/oembed`) to populate `metadata.video_title` and `metadata.video_author_name` when missing.
- **Capture and transcript handlers** call enrichment before rebuilding root `content` via `VideoThoughtContentBuilder::rootContentFromMetadata()`, so title/channel survive transcript lifecycle updates.
- **Video metadata sidebar** (`VideoThoughtMetadataPresenter`) shows **Title** and **Channel** rows when those keys are set.

Existing thoughts without titles can regain metadata by **re-saving the same video** (Ideas composer / `Fetch transcript` / MCP `capture_video` duplicate URL path) so capture runs again, or by waiting for a path that applies transcript state with oEmbed still missing (e.g. fetch transcript success).

## Customer communication

- Video thoughts now pull **title and channel** from YouTube’s public oEmbed when the request succeeds (network-dependent).
- **View counts and upload age** are not shown in-app from oEmbed; the canonical link remains the source of truth for opening YouTube.

## Prevention and follow-up

- [ ] Optional: background job to backfill oEmbed for older `video` roots missing `video_title`.
- [ ] If product needs **views / published date**, spec a separate source (e.g. YouTube Data API with quota and keys), not oEmbed alone.

## Related issues

- [YouTube transcript browser vs app](./2026-04-02-youtube-transcript-browser-vs-app.md) — different issue class (caption fetch vs display metadata).

## Lessons learned

Treat root `content` for typed thoughts as **derived** from structured metadata when automated jobs rewrite it; otherwise enrichment must be reapplied on every rebuild path.

## References

- `app/Services/Video/YouTubeOEmbedService.php`
- `app/Services/Video/VideoThoughtContentBuilder.php`
- `app/Services/Video/VideoCaptureService.php`
- `app/View/Presenters/Thoughts/VideoThoughtMetadataPresenter.php`
- `app/Jobs/FetchVideoTranscript.php`
