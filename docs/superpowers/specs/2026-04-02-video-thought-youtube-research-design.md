# Video Thought And YouTube Research Design

**Date:** 2026-04-02
**Status:** Approved
**Scope:** Add a first-class `video` thought type for YouTube links, support optional transcript capture or retrieval, and allow linked research output with a fixed minimum video-analysis structure.

## Overview

IdeaTub should support YouTube videos as a new source artifact, not just as raw links embedded inside generic thoughts or research.

The product should create a dedicated `video` thought when the user or an agent supplies a supported YouTube URL. That `video` thought should store the normalized source, transcript state, and any transcript text captured for the video. Analysis of the video should remain a linked `research` thought, so the raw source and the generated interpretation stay separate.

On the web, the existing `add thought` composer should detect when the input is just a YouTube URL and progressively switch into a video-aware capture form that reveals transcript and `Research now` controls.

## Goals

- Add a first-class `video` thought type for YouTube sources
- Keep raw source capture separate from generated research output
- Support URL-only capture with optional pasted transcript
- Support automatic transcript retrieval when no transcript is pasted
- Keep capture resilient even when transcript retrieval fails
- Let users start linked research immediately or later
- Reuse the existing `research` output model rather than inventing a second analysis artifact
- Keep the web entry point lightweight by enhancing the existing composer instead of requiring a separate screen
- Expose an explicit MCP/API path for agents to create `video` thoughts
- Allow the research layer to become skill-aware without making skills mandatory for video capture

## Non-Goals

- Downloading or storing video media files in v1
- Supporting non-YouTube video providers in v1
- Letting skills define whether something is a `video` thought
- Replacing the existing `research` thought type with a video-specific output type
- Building a fully custom video workflow editor in v1
- Parsing arbitrary thought text that merely contains a YouTube URL and silently converting it into a `video` thought

## Current State

IdeaTub already treats `research` as a first-class thought type with its own stream and detail handling. It also has a bounded `Research Skills` system that shapes how research runs without letting users define arbitrary execution graphs.

The codebase already references YouTube transcript support in the email research path through `YouTubeTranscriptService` and `EmailLinkExtractor`. That means transcript normalization and retrieval are already part of the application's direction and should be reused rather than redesigned from scratch.

The current web composer saves ideas or thoughts as plain text. There is no first-class `video` type, no dedicated video capture path, and no UI behavior that turns a pasted YouTube URL into a structured capture flow.

## Canonical Input Rules

### Supported YouTube URLs

For v1, the product should treat these URL shapes as valid video inputs when they resolve to a single concrete video ID:

- `https://www.youtube.com/watch?v=...`
- `https://youtu.be/...`
- `https://www.youtube.com/shorts/...`
- `https://www.youtube.com/live/...`

Normalization should ignore incidental query parameters such as timestamps and sharing markers when a concrete video ID can still be resolved.

The product should reject or leave in normal thought mode for:

- playlist URLs
- channel URLs
- user/profile URLs
- search result URLs
- malformed or ambiguous YouTube URLs

## Proposed Solution

Add a new canonical thought metadata type: `video`.

The product should treat a YouTube video as a captured source artifact with its own lifecycle:

1. capture the video source
2. attach or retrieve transcript text
3. optionally queue research immediately
4. save any generated analysis as a linked `research` thought

The core system boundary should be:

- `video` thought = the durable source record
- `research` thought = the durable analysis record

This keeps the app's semantics clear. The `video` thought answers "what source did we capture?" while the `research` thought answers "what did IdeaTub conclude about it?"

## Product Behavior

### Web capture entry point

The existing `add thought` composer should become the v1 web entry point for `video` capture.

Default state:

- the composer behaves exactly as it does today

Video-aware state:

- if the current composer value trims to a single supported YouTube URL, the form switches into video mode
- the UI reveals:
  - a `Transcript` textarea
  - a `Research now` option
  - copy that confirms the input will be saved as a `video` thought

The mode switch should happen only when the input resolves to a lone YouTube URL after trimming. If the text includes additional prose, multiple links, or unsupported URL shapes, the composer should stay in normal thought mode rather than guessing.

Composer detection should be slightly debounced and should not thrash on every keystroke. Once the form enters video mode for a valid lone URL, it may remain sticky until the input is cleared or changed into clearly non-video content.

### Save behavior

When the composer is in video mode:

- `Save` should create a `video` thought
- if transcript text is provided, save it directly and mark transcript state as manual/pasted
- if transcript text is not provided, queue transcript retrieval after capture
- if `Research now` is selected, queue linked research after the `video` thought is created

When transcript retrieval fails, the `video` thought should still be created successfully.

### MCP/API behavior

The MCP/API should expose an explicit video capture path rather than forcing agents to simulate video capture through generic thought creation.

The API contract should accept:

- `url`
- optional `transcript`
- optional `research_now`
- optional metadata fields needed for source attribution

The backend should own all normalization and state transitions so web and MCP capture stay consistent.

If the backend creates or reuses a `video` thought successfully but transcript queueing fails, the API should still treat capture as successful and return the created or reused thought plus a warning describing the degraded state.

## Data Model

### `video` thought

The root `video` thought should be stored as a normal thought record with canonical metadata type `video`.

Recommended metadata fields:

- `type: video`
- `video_id`
- `video_url`
- `transcript_status`
- `transcript_source`
- `transcript_error_reason`
- `research_thought_id`
- `research_pending`

Recommended transcript status values:

- `pending`
- `available`
- `manual`
- `unavailable`
- `failed`

Recommended transcript source values:

- `youtube`
- `pasted`
- `none`

Status/source expectations:

- `pending` + `none` means retrieval has been queued and no transcript text exists yet
- `manual` + `pasted` means the user supplied the transcript text directly
- `available` + `youtube` means automatic retrieval succeeded
- `unavailable` + `none` means retrieval completed but no usable transcript exists
- `failed` + `none` means retrieval failed for an operational reason such as provider or network issues

### Transcript storage

Transcript text should not be stored in JSON metadata.

For v1, the best fit is:

- keep the root `video` thought content concise and source-oriented
- store the transcript text in a child thought/section when transcript content exists

This keeps stream cards readable, avoids large metadata blobs, and matches the app's existing root-plus-sections pattern for long-form content.

The root `video` thought content should remain compact, for example a short markdown block containing the canonical URL and current transcript/research status. The precise wording can be implementation-defined, but the content should be useful even if custom presenters are unavailable.

The transcript section contract should be fixed:

- at most one primary transcript child per `video` thought
- a stable section heading such as `## Transcript`
- metadata marking the child as the transcript section, for example `video_section_type: transcript`
- replace-in-place behavior when the transcript is refreshed or manually replaced

If transcript content exceeds safe single-thought limits, the app may split it into ordered transcript child sections while still treating them as one transcript payload conceptually. In that case, the first chunk is the primary transcript child and later chunks should carry ordered metadata such as `video_section_type: transcript_chunk` and `transcript_chunk_index`.

### Linked research

Linked analysis should continue to use canonical `research` thoughts.

The linkage should be bidirectional where practical:

- `video` metadata stores `research_thought_id` for the latest linked research
- linked `research` source metadata stores `video_thought_id`

This supports easy navigation in both directions and enables rerun flows later.

Research history should be append-only in v1. The single `research_thought_id` on the `video` metadata is only a convenience pointer to the latest linked research result. Earlier linked research thoughts remain intact and discoverable by querying `research` thoughts whose source metadata points back to the `video` thought.

Every research thought created from a `video` thought should store at least:

- `video_thought_id`
- `video_id`
- whether transcript context was available when the run started

The `research_thought_id` pointer on the `video` thought should be updated only after a new linked research thought has been created successfully. Queueing or running a research job must not replace the pointer optimistically.

## System Flow

### 1. Capture source

Capture accepts:

- YouTube URL
- optional pasted transcript
- optional `Research now`

The system should:

1. normalize the YouTube URL into a canonical `video_id` and canonical watch URL
2. detect whether the current user already has a `video` thought for that `video_id`
3. create the root `video` thought if none exists, otherwise reuse the existing source thought
4. if transcript text was pasted, create/update the transcript section and mark transcript state `manual`
5. if no transcript text was pasted and no transcript section already exists, queue transcript retrieval and mark transcript state `pending`
6. if `Research now` was selected, queue research against the `video` thought

Repeat capture of the same `video_id` for the same user should reuse the existing source record in v1 rather than creating duplicate top-level `video` thoughts. Re-capture may enrich the record by adding a transcript, retrying transcript fetch, or queuing new research.

### 2. Retrieve transcript

Transcript retrieval should run asynchronously so capture does not block on YouTube or network variability.

If retrieval succeeds:

- save the transcript section
- set transcript state to `available`
- set transcript source to `youtube`

If retrieval returns no transcript:

- set transcript state to `unavailable`

If retrieval fails unexpectedly:

- set transcript state to `failed`
- record a bounded failure reason for debugging and UI feedback

If the user already supplied a transcript:

- do not overwrite it automatically
- preserve transcript source as `pasted`

Automatic transcript retrieval should make one best-effort attempt during the initial capture flow. Further attempts should be user-triggered through an explicit `Fetch transcript` or retry action rather than automatic background looping.

User-visible classification should be:

- private/deleted/blocked/no-caption cases map to `unavailable`
- transient provider/network/rate-limit issues map to `failed`

### 3. Run linked research

Research should be a separate action from source capture, even when triggered immediately after capture.

The linked research workflow should read from the `video` thought and its transcript section, then save a normal linked `research` thought.

When `Research now` is requested and no manual transcript was supplied, the system should not start research in parallel with transcript retrieval. Instead:

1. queue transcript retrieval first
2. wait for transcript retrieval to reach a terminal state: `available`, `unavailable`, or `failed`
3. then queue exactly one research run using the best available source context

This avoids duplicate runs, produces deterministic output, and prevents "premature" research from being silently superseded.

The minimum v1 output contract should always include:

- summary
- key points
- positives
- negatives
- transcript/source caveats

The default research markdown should use stable section headings in the root research thought:

- `## Summary`
- `## Key Points`
- `## Positives`
- `## Negatives`
- `## Source Notes`

If research runs before transcript retrieval succeeds, the output should clearly state that it was generated without transcript context or with limited transcript context.

If transcript retrieval succeeds later, the user should be able to rerun the research from the `video` thought.

## Skill Role

Video capture should be product behavior, not skill behavior.

That means:

- YouTube link detection is product behavior
- `video` thought creation is product behavior
- transcript capture and retrieval are product behavior
- the minimum structure of video analysis is product behavior

Research skills should be an optional extension point for the analysis layer only.

V1 behavior:

- if no research skill is selected, IdeaTub uses a built-in video research workflow
- the v1 web composer does not need a per-video skill selector
- if a compatible research skill is selected in a later surface, it may shape the depth, focus, or tone of the analysis
- skills must not decide whether an item is stored as `video`
- skills must not control transcript retrieval rules
- skills must not remove the minimum required output sections

This answers the product question directly: the research part should not be introduced primarily as a new skill. It should be a built-in product workflow that can become skill-aware later.

## UX Surfaces

### Composer behavior

The existing composer should progressively disclose fields rather than forcing type selection first.

Recommended web interaction:

1. user pastes a YouTube URL into the existing composer
2. the UI detects that the input is a lone YouTube URL
3. the form switches into video mode
4. transcript and `Research now` controls appear
5. submit creates a `video` thought instead of a generic thought

### Video thought display

The `video` thought card/detail experience should clearly surface:

- canonical video link
- transcript state
- whether transcript text is present
- latest linked research result
- actions such as `Fetch transcript`, `Research now`, and `Rerun research`

The UI does not need a wholly bespoke page in v1, but it should render enough structured state that a `video` thought is visibly different from a plain text thought.

### Stream and navigation

Because `video` is a new first-class thought type, the app should decide whether to:

- include it in the main stream only at first, or
- add a typed video stream later

For v1, it is acceptable to ship `video` thoughts without a dedicated typed stream page if the main stream and detail view render them well enough.

## Error Handling

- Capture must succeed even if transcript retrieval fails
- Transcript failure must not block later manual research
- Transcript retrieval should be retryable when failure is transient
- Research failure must not corrupt the source `video` thought
- Re-running research should not destroy prior linked research history unless the product explicitly chooses latest-only linking
- Manual pasted transcript should win over later automatic fetch attempts unless the user explicitly replaces it
- `research_pending` should be true from the moment a `Research now` action commits to a linked research attempt, including any transcript-wait window before the research job starts, and should be cleared on terminal states such as `completed`, `failed`, or `cancelled`

## Testing

Add or update coverage for:

1. composer detection of a lone YouTube URL
2. composer staying in normal mode when extra text surrounds the URL
3. web capture creating a `video` thought instead of a generic thought
4. pasted transcript being stored without automatic overwrite
5. transcript retrieval being queued when no transcript is provided
6. transcript success updating status and storing transcript content
7. transcript unavailable and failed states
8. `Research now` queuing linked research from a `video` thought
9. linked research output keeping the fixed minimum sections
10. MCP/API video capture behavior matching the web path
11. rerun behavior after transcript becomes available
12. duplicate capture of the same `video_id` reusing the source record
13. partial-success API responses when thought creation succeeds but transcript queueing degrades

## Operational Notes

- Transcript retrieval should continue to follow the constraints of the app's chosen provider/library rather than introducing custom scraping logic in this feature
- V1 should preserve source attribution through canonical URL and video ID, but should not attempt to store video media
- If provider or policy constraints later require changes to transcript retrieval, the `video` thought model should remain valid even when only manual transcripts are available

Prefer focused feature and service tests around normalization, capture, transcript state transitions, and linked research creation.

## Risks

- Composer auto-detection could become confusing if it converts mixed-content input too aggressively
- Transcript text can be large and should not be stuffed into JSON metadata
- Video capture and research capture could drift if they use separate normalization rules
- If video-specific display remains too generic, users may not understand what was captured or whether transcript retrieval succeeded

## Mitigations

- Only switch into video mode when the input is clearly a single supported YouTube URL
- Store transcript text in thought content/sections, not metadata
- Centralize YouTube normalization and transcript retrieval behind shared services
- Keep a fixed minimum research output contract even when skills are later allowed to shape the analysis
- Surface transcript state and linked research state directly in the `video` UI

## Recommendation

Implement `video` as a first-class source type and keep linked analysis in the existing `research` model.

On the web, enhance the existing `add thought` composer so that a lone YouTube URL progressively turns the form into a video capture flow with transcript and `Research now` options.

On the backend, reuse the existing YouTube transcript extraction direction, keep transcript retrieval asynchronous, and make research skill-aware only as a secondary extension point.
