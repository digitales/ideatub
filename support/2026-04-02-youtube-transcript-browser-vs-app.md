# YouTube transcript visible in browser but not in IdeaTub — Support investigation

**Date**: 2026-04-02  
**Status**: Investigating (workarounds documented)  
**Priority**: Medium  
**Reported By**: Customer / Internal  

## Issue description

User reported that automatic transcript fetch for video [https://www.youtube.com/watch?v=sdVnCV3wtg8](https://www.youtube.com/watch?v=sdVnCV3wtg8) (`sdVnCV3wtg8`) failed in IdeaTub, while the transcript appears accessible when viewing the video in the browser (e.g. from the video detail / captions UI).

## Customer impact

- Video thought may show **Transcript unavailable** or **Transcript fetch failed** (see `ThoughtDetailPresenter` / `StreamThoughtCardPresenter` transcript status labels).
- Video research or downstream features that depend on transcript text may run with limited context (see `VideoResearchPromptBuilder`).

## How IdeaTub loads transcripts (technical)

1. **Service**: `App\Services\Email\YouTubeTranscriptService` uses Packagist library [`mrmysql/youtube-transcript`](https://packagist.org/packages/mrmysql/youtube-transcript) (`TranscriptListFetcher`), which follows YouTube’s **unofficial** Innertube / caption-track flow (not a supported public API). See `dev/2026-03-21-youtube-transcript-validation.md`.
2. **Language selection**: After listing caption tracks, the code requests **English only** — first a manual `en` track, then a generated (`asr`) `en` track:

```48:55:app/Services/Email/YouTubeTranscriptService.php
        try {
            $list = $this->transcriptListFetcher->fetch($videoId);

            try {
                $transcript = $list->findTranscript(['en']);
            } catch (NoTranscriptFoundException) {
                $transcript = $list->findGeneratedTranscript(['en']);
            }
```

3. **Failure reasons** (stored on the thought as `metadata.transcript_error_reason` in some paths): `transcript_unavailable`, `youtube_rate_limited`, `youtube_po_token_required`, `youtube_fetch_failed`, etc. (see dev doc table).

## Why the browser can disagree with the app

| Factor | Browser (YouTube) | IdeaTub |
|--------|-------------------|---------|
| **Mechanism** | Official player UI, user session, cookies, possibly translated transcript view | Server-side HTTP via third-party library |
| **Language** | User may see auto-translated captions or a non-English track presented as “transcript” | Only `en` manual then `en` auto captions; **no** fallback to other language codes today |
| **Access control** | Logged-in user, regional consent flows handled in UI | Datacenter IP; may hit **rate limits**, **consent/cookie** interstitials, or **PO-token** caption URLs (see `PoTokenRequiredException` in dev notes) |
| **Timing** | Retry by refreshing | Single queued job attempt (`FetchVideoTranscript` try count = 1); transient YouTube errors are not retried automatically |

So “I see a transcript on YouTube” does **not** guarantee the same track is retrievable by our server-side fetcher in the same language or under the same anti-bot rules.

## Investigation steps (for this report)

1. **Reproduce in app**: Confirm thought `metadata.type === 'video'`, `metadata.video_id` / `video_url`, and `metadata.transcript_status` (`unavailable` vs `failed` vs `pending`).
2. **Read error reason**: If present, `metadata.transcript_error_reason` narrows the bucket (e.g. `youtube_rate_limited`, `youtube_po_token_required`, `transcript_unavailable`).
3. **Optional live check** (engineering): From app environment, `YouTubeTranscriptService::fetchForUrl($canonicalUrl)` — result varies by network/region/time.

**Spot check (2026-04-02, dev environment):** `fetchForUrl('https://www.youtube.com/watch?v=sdVnCV3wtg8')` returned **`ok => true`** with `language_code => en` and a long transcript body. That indicates the video is not inherently “no captions” for English; a user-visible failure may be **transient** (rate limit / token / transport) or **environment-specific** (production IP, proxy, or queue not running).

## Root cause analysis

**Class of issue**: Expected mismatch between **first-party YouTube UX** and **third-party server-side caption retrieval**, compounded by **English-only** selection and **single-attempt** background fetch.

**For this specific video ID**: Not confirmed as “captions missing”; dev fetch succeeded. Treat user report as **transient or environment-specific** unless production logs show a stable `transcript_error_reason`.

## Resolution / customer communication

Suggested reply:

- IdeaTub loads captions with an **automated service**, not the same path as your browser. YouTube sometimes blocks or limits that; captions may also be **non-English** only while the site shows a **translated** view we do not fetch yet.
- **Workaround**: Open the video thought → use **Fetch transcript** again if shown, or **paste the transcript** when saving a video (Ideas / home composer), or add transcript text via the same flows the product exposes for manual capture.
- If it keeps failing, note the **approximate time** and we can check logs for `youtube_transcript.fetch_failed` / `transcript_error_reason`.

## Prevention and follow-up

- [ ] Product/engineering: Consider **fallback languages** (e.g. first available track from `TranscriptList::getIterator()` / `getAvailableLanguageCodes()`) when `en` is missing — aligns better with “I see captions in the browser.”
- [ ] Optional: **Retry** `FetchVideoTranscript` once or twice on `youtube_rate_limited` / `youtube_fetch_failed` with backoff (careful with queue abuse).
- [ ] Help: Short note that automatic fetch is best-effort and manual paste is supported (Help already mentions optional transcript; could add “browser may show captions we can’t auto-fetch”).

## Stream observation (same case)

On **Stream** (`/stream`), this workflow can surface as **two distinct entries**:

1. **Original video thought** — the post that carries the YouTube embed / video metadata.
2. **Separate research thought** — the saved “Research this idea” output (its own card in the feed).

**Production examples (2026-04-02):**

| Role | URL |
|------|-----|
| Video thought | [ideatub.com/thoughts/019d503c-e76f-7373-96d0-72c13c84b248](https://ideatub.com/thoughts/019d503c-e76f-7373-96d0-72c13c84b248) |
| Research (thought page) | [ideatub.com/thoughts/019d503d-1e62-72d1-826a-abf087e74b62](https://ideatub.com/thoughts/019d503d-1e62-72d1-826a-abf087e74b62) |
| Research (research route) | [ideatub.com/research/019d503d-1e62-72d1-826a-abf087e74b62](https://ideatub.com/research/019d503d-1e62-72d1-826a-abf087e74b62) |

**Support note:** Seeing both the **video row** and a **research** card is consistent with research being stored as its **own thought** (often tagged `research:*`), not merged into the parent video card. If customers expect a single Stream row, clarify that research is a separate artifact or treat as product feedback (grouping / parent link in UI).

## Related issues

- [Video thought title/channel (Content card)](./2026-04-02-video-thought-youtube-metadata-missing.md) — oEmbed metadata and root `content` rebuilds.

## References

- `app/Services/Email/YouTubeTranscriptService.php`
- `app/Jobs/FetchVideoTranscript.php`
- `dev/2026-03-21-youtube-transcript-validation.md`
- `vendor/mrmysql/youtube-transcript/src/TranscriptList.php` (`getAvailableLanguageCodes`, `findTranscript`)

## Lessons learned

YouTube “transcript visible” in the **player** is a poor single signal for **server-side** caption API success; always correlate with `transcript_error_reason` and environment.
