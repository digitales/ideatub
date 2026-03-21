# YouTube transcript validation (newsletter research)

**Date:** 2026-03-21  
**Status:** DONE  
**Feature:** Email sender rules and newsletter research (`extra_process` YouTube enrichment)

**Spec:** `docs/superpowers/specs/2026-03-21-email-sender-rules-and-research-design.md`

---

## Chosen transcript source/library

**V1 choice:** [`mrmysql/youtube-transcript`](https://packagist.org/packages/mrmysql/youtube-transcript) (spike used **v0.0.5**).

- Fetches caption tracks via YouTube watch-page + Innertube player flow (same general approach as common Python `youtube-transcript-api` ports).
- Requires **PSR-18** HTTP client and **PSR-17** request + stream factories. Laravel already pulls Guzzle transitively; wiring `GuzzleHttp\Client` + `GuzzleHttp\Psr7\HttpFactory` is the straightforward integration.
- Exposes typed exceptions for the main real-world failure buckets (disabled captions, rate limits, etc.).

---

## Supported YouTube URL shapes

`YouTubeTranscriptService` should accept a **full URL string** (as extracted from email bodies) and normalize to an **11-character video id** where possible.

**V1 supported shapes (same id extraction rules as `EmailLinkExtractor` should use for `youtube` links):**

| Shape | Example |
|------|---------|
| Standard watch | `https://www.youtube.com/watch?v=VIDEO_ID` |
| Short host | `https://youtube.com/watch?v=VIDEO_ID` |
| Mobile | `https://m.youtube.com/watch?v=VIDEO_ID` |
| Short link | `https://youtu.be/VIDEO_ID` |
| Shorts | `https://www.youtube.com/shorts/VIDEO_ID` |
| With extra query | `https://www.youtube.com/watch?v=VIDEO_ID&list=PLx…&t=120s` |

**V1 non-support:**

- Playlist-only URLs without a resolvable `v=` or shorts path id (treat as `unsupported_youtube_url`).
- Embed-only URLs without `v=` (unless explicitly added later): `https://www.youtube.com/embed/VIDEO_ID` could be supported in a follow-up; **v1 decision:** support `embed` only if cheaply aligned with the extractor regex (optional stretch).

---

## Rate limits and failure modes

**Observed / library-documented failure modes:**

| Source | What happens |
|--------|----------------|
| YouTube throttling / bot checks | `TooManyRequestsException` (library may surface when Innertube key extraction hits interstitials). |
| Consent / cookie interstitial (EU flows) | `FailedToCreateConsentCookieException` (cannot obtain playable page HTML). |
| Video page / Innertube HTTP errors | `YouTubeRequestFailedException`. |
| Captions disabled or no caption renderer | `TranscriptsDisabledException` (also used when the player response has **no** `playerCaptionsTracklistRenderer`). |
| Caption renderer present but unusable | `NoTranscriptAvailableException` (missing `captionTracks`). |
| Requested language not present | `NoTranscriptFoundException`. |
| Caption download URL requires PO token | `PoTokenRequiredException` (library explicitly flags URLs containing `&exp=xpe`). |

**Rate limits:** not contractually documented by YouTube. Treat **429 / throttling / TooManyRequests** as **recoverable** best-effort: skip transcript for this link and continue research with body + other links.

---

## Data returned to the app

From the library, each transcript segment is:

`array{text: string, start: float, duration: float}`

**V1 processing:** concatenate `text` in order with single spaces (trim final string). HTML entities may appear in text (e.g. `&#39;`); decoding before storage is recommended so research prompts see apostrophes normally.

Optional metadata to preserve for debugging (not required for v1 research text):

- `language_code` / `is_generated` from the chosen `Transcript` object.

---

## Fallback behavior when transcripts are unavailable

Per product spec (section 7.4): if transcript retrieval fails, **continue without it** and record the failure in **processing metadata** on the stored email / job result.

- Do **not** fail the email thought or the stored email record.
- Newsletter research should still run when body + links are sufficient.
- Multiple links: **best-effort per URL**; one failure must not abort others.

---

## Implementation constraints for tests and production

**Production**

- Add Composer dependency: `mrmysql/youtube-transcript` (pin compatible with Laravel’s Guzzle stack).
- Inject PSR-18 client + factories in `YouTubeTranscriptService` (container bindings) so tests can substitute a mock HTTP layer if needed.
- **No outbound calls** in unit tests by default: mock `TranscriptListFetcher` or the service boundary; use **one** integration test only if the suite already allows HTTP (prefer fakes).

**Tests (from plan Task 5.1)**

- Success path: returns `ok=true` with non-empty `transcript` for a **faked** response.
- Failure paths: `ok=false` with stable `reason` codes (below)—**recoverable** for enrichment.

**Operational**

- Timeouts: set reasonable Guzzle timeouts for user-facing queues (avoid hanging workers).
- Logging: include `video_id`, `reason`, and exception class name in structured logs, not raw HTML bodies.

---

## Spike: manual validation (one-off)

**Environment:** temporary Composer project in `/tmp/youtube-transcript-spike` (not committed). Package: `mrmysql/youtube-transcript` **v0.0.5** + `guzzlehttp/guzzle` **7.10.0**.

**Commands run**

```bash
mkdir -p /tmp/youtube-transcript-spike && cd /tmp/youtube-transcript-spike
composer init --no-interaction --name=spike/test --require="php:^8.2"
composer require mrmysql/youtube-transcript guzzlehttp/guzzle:^7 --no-interaction
php -r '… TranscriptListFetcher with Guzzle Client + HttpFactory …'
```

**1) Successful fetch**

- Video id: `dQw4w9WgXcQ`
- Result: **61** segments, **2412** characters of joined text; preview showed lyric lines (HTML entities present in source text).

**2) Failing fetch (unavailable transcripts)**

- Video id: `not_a_valid_id` (malformed / non-resolving id in practice)
- Result: `MrMySQL\YoutubeTranscript\Exception\TranscriptsDisabledException` with message `not_a_valid_id`

**3) Additional failure (language miss)**

- Video id: `dQw4w9WgXcQ`, `findTranscript(['zz'])`
- Result: `MrMySQL\YoutubeTranscript\Exception\NoTranscriptFoundException`

**Note:** naive `curl` to `https://www.youtube.com/api/timedtext?...` returned **200 with empty body** for sample requests in this environment; **not** used as v1 mechanism.

---

## V1 decision — concrete transcript-fetching mechanism

1. Parse `video_id` from supported YouTube URL shapes; if parsing fails → `ok=false`, `reason=unsupported_youtube_url`.
2. Instantiate `TranscriptListFetcher` with Guzzle `Client` + `HttpFactory` (request + stream factories).
3. `fetch($video_id)` → `findTranscript(['en'])` (or `findGeneratedTranscript(['en'])` if English manual track is absent—implementation detail inside service).
4. `fetch()` segments → join `text` fields → optional HTML entity decode → success payload.

---

## V1 decision — recoverable error categories (enrichment)

All of the following are **recoverable** for `extra_process` research: the pipeline **must continue** without transcript text and **must** record which case occurred in processing metadata.

Map library / app failures to this **closed `reason` set** on failure:

| `reason` | When |
|----------|------|
| `unsupported_youtube_url` | URL does not match supported shapes or video id cannot be extracted. |
| `transcript_unavailable` | `TranscriptsDisabledException`, `NoTranscriptAvailableException`, `NoTranscriptFoundException`. |
| `youtube_rate_limited` | `TooManyRequestsException`. |
| `youtube_fetch_failed` | `YouTubeRequestFailedException`, `FailedToCreateConsentCookieException`, and other transport/parse failures not covered above. |
| `youtube_po_token_required` | `PoTokenRequiredException`. |

---

## V1 decision — exact `YouTubeTranscriptService` return shape

**Success**

```php
[
    'ok' => true,
    'video_id' => 'dQw4w9WgXcQ',
    'language_code' => 'en',
    'transcript' => 'plain text, segments concatenated',
]
```

**Failure**

```php
[
    'ok' => false,
    'reason' => 'transcript_unavailable', // one of the closed set above
    'video_id' => 'dQw4w9WgXcQ',          // null when unsupported_youtube_url before id resolution
    'detail' => 'optional short diagnostic; logs only',
]
```

**Compatibility:** this matches the plan’s minimal examples (`ok` + `transcript` vs `ok` + `reason`) and extends with **`video_id`**, **`language_code`**, and optional **`detail`** for traceability.

---

## Verification for this task

- File present in worktree: `dev/2026-03-21-youtube-transcript-validation.md`
- Spike executed in `/tmp` (no Composer lockfile changes in the repo for this task).

```bash
test -f dev/2026-03-21-youtube-transcript-validation.md && wc -l dev/2026-03-21-youtube-transcript-validation.md
```
