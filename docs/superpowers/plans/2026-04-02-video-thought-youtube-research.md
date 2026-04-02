# Video Thought And YouTube Research Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add first-class `video` thoughts for YouTube URLs, optional transcript capture/fetch, and linked video research without widening the existing idea-research subsystem.

**Architecture:** Keep `video` capture and video research as a dedicated pipeline layered on top of the existing `Thought` model. Reuse `EmailLinkExtractor`, `YouTubeTranscriptService`, `ThoughtCaptureService`, and `OpenRouterService`, but do not force `video` capture through the current idea-only `ResearchRun` workflow. Web and MCP should both delegate to the same video services so URL normalization, transcript state, duplicate handling, and latest-research pointer updates stay consistent.

**Tech Stack:** Laravel 12, PHP 8.2, Blade, lightweight Alpine/inline JS in Blade, Pest/PHPUnit feature and unit tests, existing queue jobs, existing MCP controller.

---

## File Structure

### Create

- `app/Services/Video/VideoCaptureService.php`
  - Own URL normalization, duplicate-per-user reuse, canonical `video_url` persistence, root `video` thought creation, transcript child upsert, and root metadata updates.
- `app/Services/Video/VideoThoughtContentBuilder.php`
  - Build the compact root content shown when a `video` thought is rendered without custom formatting.
- `app/Services/Video/VideoResearchService.php`
  - Build linked `research` thoughts from `video` thoughts, stamp bidirectional metadata on the research side, update the latest pointer only on success, and preserve append-only history.
- `app/Services/Video/VideoResearchPromptBuilder.php`
  - Produce the fixed minimum video research prompt (`Summary`, `Key Points`, `Positives`, `Negatives`, `Source Notes`).
- `app/Jobs/FetchVideoTranscript.php`
  - Run async transcript retrieval, update transcript status, and optionally hand off to video research after transcript reaches a terminal state.
- `app/Jobs/RunVideoResearch.php`
  - Run built-in video research in the background without changing the idea-only `ResearchRun` schema.
- `app/Http/Controllers/VideoController.php`
  - Validate web capture/research actions and delegate to video services.
- `tests/Unit/Services/Video/VideoCaptureServiceTest.php`
  - URL normalization, duplicate reuse, transcript child replace-in-place, metadata transitions.
- `tests/Unit/Services/Video/VideoResearchServiceTest.php`
  - Prompt input assembly, linked research save rules, latest-pointer updates, history preservation.
- `tests/Feature/VideoCaptureWebTest.php`
  - Web capture, transcript/manual flow, queue dispatch, degraded success handling, and smart-composer mode changes on the ideas page.
- `tests/Feature/VideoTranscriptFetchTest.php`
  - Transcript success, unavailable, failed, retryable state handling.
- `tests/Feature/VideoResearchWorkflowTest.php`
  - `Research now` ordering, `research_pending` timing, latest pointer update timing, research-side metadata, and rerun behavior after transcript arrival.
- `tests/Feature/McpCaptureVideoTest.php`
  - MCP `capture_video` happy path, duplicate reuse, `research_now` parity, source-attribution metadata, and partial-success responses.
- `tests/Feature/VideoStreamDisplayTest.php`
  - Stream card assertions for `video` state, transcript status, and linked research affordances.

### Modify

- `app/Services/Email/EmailLinkExtractor.php`
  - Remain the single YouTube URL normalization seam used by email, web, and MCP.
- `app/Http/Controllers/Api/McpController.php`
  - Add `capture_video` to MCP method/tool lists and dispatch it through the shared video service.
- `routes/web.php`
  - Add web routes for saving video thoughts and queuing video research/retry actions.
- `resources/views/idea/ideas.blade.php`
  - Turn the existing idea composer into a smart video-aware composer when the input is a lone YouTube URL.
- `resources/views/idea/stream_thoughts.blade.php`
  - Render compact `video` state and latest linked research affordances on cards.
- `resources/views/idea/show.blade.php`
  - Render transcript state/content and latest linked research affordances on detail pages.
- `resources/views/idea/partials/thought_detail_header.blade.php`
  - Surface `video`-specific actions such as `Fetch transcript` / `Research now` / `Rerun research`.
- `app/View/Presenters/Thoughts/StreamThoughtCardPresenter.php`
  - Expose `video` status, transcript presence, and latest linked research URL for cards.
- `app/View/Presenters/Thoughts/ThoughtDetailPresenter.php`
  - Expose transcript section content, source notes, and linked research state for the detail page.
- `tests/Unit/Services/EmailLinkExtractorTest.php`
  - Lock the accepted YouTube URL shapes used by the composer and MCP.
- `tests/Feature/McpApiTest.php`
  - Cover MCP tool listing / direct dispatch shape for `capture_video`.
- `tests/Feature/IdeaIdeasTest.php`
  - Keep the existing ideas page behavior stable while the composer gains video-aware mode.
- `tests/Feature/ThoughtShowPageTest.php`
  - Lock the non-email detail rendering behavior for `video` thoughts.

## Task 1: Build The Video Capture Domain

**Files:**
- Create: `app/Services/Video/VideoCaptureService.php`
- Create: `app/Services/Video/VideoThoughtContentBuilder.php`
- Modify: `app/Services/Email/EmailLinkExtractor.php`
- Test: `tests/Unit/Services/Video/VideoCaptureServiceTest.php`
- Test: `tests/Unit/Services/EmailLinkExtractorTest.php`

- [ ] **Step 1: Write the failing unit tests for URL normalization, duplicate reuse, and transcript child upsert**

```php
public function test_capture_reuses_existing_video_thought_for_same_user_and_video_id(): void
{
    $existing = Thought::factory()->create([
        'user_id' => $user->id,
        'metadata' => ['type' => 'video', 'video_id' => 'dQw4w9WgXcQ'],
    ]);

    $captured = $service->captureFromUrl($user->id, 'https://youtu.be/dQw4w9WgXcQ', null);

    $this->assertTrue($captured->is($existing));
}

public function test_upsert_transcript_replaces_existing_transcript_child(): void
{
    $service->upsertTranscript($video, 'first transcript', 'pasted');
    $service->upsertTranscript($video, 'replacement transcript', 'pasted');

    $this->assertSame(1, $video->comments()->count());
    $this->assertStringContainsString('replacement transcript', $video->comments()->first()->content);
    $this->assertSame('transcript', $video->comments()->first()->metadata['video_section_type'] ?? null);
}

public function test_capture_persists_canonical_video_url_and_pending_transcript_state(): void
{
    $video = $service->captureFromUrl($user->id, 'https://youtu.be/dQw4w9WgXcQ?t=10', null);

    $this->assertSame('https://www.youtube.com/watch?v=dQw4w9WgXcQ', $video->metadata['video_url']);
    $this->assertSame('pending', $video->metadata['transcript_status']);
    $this->assertSame('none', $video->metadata['transcript_source']);
}
```

- [ ] **Step 2: Run the targeted unit tests and confirm they fail for missing video service behavior**

Run: `php artisan test tests/Unit/Services/Video/VideoCaptureServiceTest.php tests/Unit/Services/EmailLinkExtractorTest.php`

Expected: FAIL with missing `VideoCaptureService` class and/or unsupported assertions for reuse/transcript child behavior.

- [ ] **Step 3: Implement `VideoCaptureService` and `VideoThoughtContentBuilder` with the minimal capture contract**

```php
public function captureFromUrl(int $userId, string $url, ?string $transcript): Thought
{
    $videoId = $this->linkExtractor->extractYouTubeVideoId($url);
    if ($videoId === null) {
        throw new InvalidArgumentException('Unsupported YouTube URL.');
    }

    $video = $this->findExistingVideoThought($userId, $videoId)
        ?? $this->createVideoRoot($userId, $videoId);

    $this->setCanonicalUrl($video, 'https://www.youtube.com/watch?v='.$videoId);

    if ($transcript !== null && trim($transcript) !== '') {
        $this->upsertTranscript($video, $transcript, 'pasted');
        $this->setTranscriptState($video, 'manual', 'pasted');
    } elseif (! $this->hasTranscriptSection($video)) {
        $this->setTranscriptState($video, 'pending', 'none');
    }

    return $video->fresh(['comments']);
}
```

- [ ] **Step 3a: Add the reuse/no-requeue guard to the capture tests**

Add a failing test for:
- recapturing the same `video_id` with no new transcript
- when a transcript child already exists, do not reset `transcript_status` to `pending`
- do not queue a fresh transcript fetch unless the user explicitly chooses retry

- [ ] **Step 4: Re-run the targeted unit tests and confirm they pass**

Run: `php artisan test tests/Unit/Services/Video/VideoCaptureServiceTest.php tests/Unit/Services/EmailLinkExtractorTest.php`

Expected: PASS with accepted URL shapes limited to `watch`, `youtu.be`, `shorts`, and `live`, and duplicate capture reusing the same root thought.

- [ ] **Step 5: Commit the capture foundation**

```bash
git add app/Services/Video/VideoCaptureService.php app/Services/Video/VideoThoughtContentBuilder.php app/Services/Email/EmailLinkExtractor.php tests/Unit/Services/Video/VideoCaptureServiceTest.php tests/Unit/Services/EmailLinkExtractorTest.php
git commit -m "feat: add video capture service"
```

## Task 2: Add Background Transcript Fetch And State Transitions

**Files:**
- Create: `app/Jobs/FetchVideoTranscript.php`
- Modify: `app/Services/Video/VideoCaptureService.php`
- Modify: `app/Services/Email/YouTubeTranscriptService.php`
- Test: `tests/Feature/VideoTranscriptFetchTest.php`

- [ ] **Step 1: Write the failing feature tests for transcript status transitions**

```php
public function test_fetch_video_transcript_marks_video_available_and_creates_transcript_child(): void
{
    Bus::fake();

    $video = Thought::factory()->create([
        'user_id' => $user->id,
        'metadata' => ['type' => 'video', 'video_id' => 'dQw4w9WgXcQ', 'transcript_status' => 'pending'],
    ]);

    $yt->shouldReceive('fetchForUrl')->andReturn([
        'ok' => true,
        'video_id' => 'dQw4w9WgXcQ',
        'language_code' => 'en',
        'transcript' => 'hello world',
    ]);

    dispatch_sync(new FetchVideoTranscript($video->id, false));

    $video->refresh();
    $this->assertSame('available', $video->metadata['transcript_status']);
}

public function test_fetch_video_transcript_keeps_research_pending_true_when_research_now_was_requested(): void
{
    $video = Thought::factory()->create([
        'user_id' => $user->id,
        'metadata' => ['type' => 'video', 'video_id' => 'dQw4w9WgXcQ', 'transcript_status' => 'pending', 'research_pending' => true],
    ]);

    dispatch_sync(new FetchVideoTranscript($video->id, true));

    $video->refresh();
    $this->assertTrue((bool) ($video->metadata['research_pending'] ?? false));
}
```

- [ ] **Step 2: Run the transcript feature test and confirm it fails**

Run: `php artisan test tests/Feature/VideoTranscriptFetchTest.php`

Expected: FAIL because `FetchVideoTranscript` and transcript state update behavior do not exist yet.

- [ ] **Step 3: Implement `FetchVideoTranscript` to classify terminal states and respect manual transcripts**

```php
if ($video->metadata['transcript_source'] === 'pasted') {
    return;
}

$result = $this->youtubeTranscript->fetchForUrl($canonicalUrl);

if (($result['ok'] ?? false) === true) {
    $capture->upsertTranscript($video, $result['transcript'], 'youtube');
    $capture->setTranscriptState($video, 'available', 'youtube');
} elseif (($result['reason'] ?? null) === 'transcript_unavailable') {
    $capture->setTranscriptState($video, 'unavailable', 'none');
} else {
    $capture->setTranscriptState($video, 'failed', 'none', $result['reason'] ?? 'youtube_fetch_failed');
}

if ($researchNow) {
    $capture->markResearchReadyAfterTranscript($video);
}
```

- [ ] **Step 3a: Leave transcript chunking explicitly deferred unless a real size limit is hit**

Note in implementation:
- keep one primary transcript child in v1
- if a real payload/DB limit blocks this, stop and add the chunking extension from the spec before proceeding
- do not silently invent transcript chunking behavior mid-task

- [ ] **Step 3b: Do not dispatch `RunVideoResearch` from Task 2**

Implementation rule:
- Task 2 only moves the `video` thought into a terminal transcript state
- if `research_now` was requested, persist a durable “ready for research” marker on the `video` thought
- Task 5 is the first task that creates and dispatches `RunVideoResearch`

- [ ] **Step 4: Re-run the transcript feature test and confirm it passes**

Run: `php artisan test tests/Feature/VideoTranscriptFetchTest.php`

Expected: PASS for `available`, `unavailable`, and `failed`, while existing pasted transcripts are preserved.

- [ ] **Step 5: Commit the transcript-fetch job**

```bash
git add app/Jobs/FetchVideoTranscript.php app/Services/Video/VideoCaptureService.php app/Services/Email/YouTubeTranscriptService.php tests/Feature/VideoTranscriptFetchTest.php
git commit -m "feat: add video transcript fetch job"
```

## Task 3: Add Web Capture Endpoints And The Smart Ideas Composer

**Files:**
- Create: `app/Http/Controllers/VideoController.php`
- Modify: `routes/web.php`
- Modify: `resources/views/idea/ideas.blade.php`
- Modify: `app/Services/Video/VideoCaptureService.php`
- Test: `tests/Feature/VideoCaptureWebTest.php`
- Test: `tests/Feature/IdeaIdeasTest.php`

- [ ] **Step 1: Write the failing web feature tests for video capture and smart composer behavior**

```php
public function test_posting_a_lone_youtube_url_creates_video_thought_and_queues_transcript_fetch(): void
{
    Queue::fake();

    $response = $this->actingAs($user)->post(route('videos.store'), [
        'url' => 'https://youtu.be/dQw4w9WgXcQ',
        'transcript' => '',
        'research_now' => false,
    ]);

    $response->assertRedirect(route('idea.ideas'));
    $this->assertDatabaseHas('thoughts', [
        'user_id' => $user->id,
    ]);
    Queue::assertPushed(FetchVideoTranscript::class);
}

public function test_ideas_page_reveals_video_fields_only_for_a_lone_youtube_url(): void
{
    $response = $this->actingAs($user)->get(route('idea.ideas'));

    $response->assertOk();
    $response->assertSee('Add idea');
    $response->assertDontSee('Paste transcript');
}
```

- [ ] **Step 2: Run the web feature tests and confirm they fail**

Run: `php artisan test tests/Feature/VideoCaptureWebTest.php tests/Feature/IdeaIdeasTest.php`

Expected: FAIL because the route/controller/composer behavior is not implemented.

- [ ] **Step 3: Implement `VideoController@store` and upgrade `ideas.blade.php` into a smart video-aware composer**

```php
Route::post('/videos', [VideoController::class, 'store'])->name('videos.store');

// Blade behavior:
// - keep the current idea form default
// - detect when textarea value is a lone YouTube URL
// - reveal transcript + "Research now"
// - reveal a hidden videos.store target only in video mode
// - keep "Research now" UI disabled until Task 5 wires the dedicated job ordering
// - show confirmation copy such as "This will be saved as a video thought"
```

- [ ] **Step 3a: Lock the smart-composer acceptance criteria in the test file**

Add assertions for:
- lone URL with surrounding whitespace still switches to video mode
- URL plus prose stays in normal mode
- switching into video mode is sticky until the field is cleared or clearly non-video content replaces it
- detection is debounced enough that the form does not thrash while typing
- the composer shows explicit copy that the entry will be saved as a video thought

- [ ] **Step 4: Re-run the web feature tests and confirm they pass**

Run: `php artisan test tests/Feature/VideoCaptureWebTest.php tests/Feature/IdeaIdeasTest.php`

Expected: PASS with the existing idea flow unchanged for normal text and the new video flow active only for a lone YouTube URL.

- [ ] **Step 5: Commit the web capture flow**

```bash
git add app/Http/Controllers/VideoController.php routes/web.php resources/views/idea/ideas.blade.php app/Services/Video/VideoCaptureService.php tests/Feature/VideoCaptureWebTest.php tests/Feature/IdeaIdeasTest.php
git commit -m "feat: add web video capture flow"
```

## Task 4: Add MCP `capture_video`

**Files:**
- Modify: `app/Http/Controllers/Api/McpController.php`
- Modify: `app/Services/Video/VideoCaptureService.php`
- Test: `tests/Feature/McpApiTest.php`
- Create: `tests/Feature/McpCaptureVideoTest.php`

- [ ] **Step 1: Write the failing MCP tests for tool listing and capture behavior**

```php
public function test_tools_list_includes_capture_video(): void
{
    $response = $this->postJson('/api/mcp?key='.$key, [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/list',
    ]);

    $response->assertOk();
    $response->assertSee('capture_video');
}

public function test_capture_video_returns_existing_id_for_duplicate_video(): void
{
    $existing = Thought::factory()->create([
        'user_id' => $user->id,
        'metadata' => ['type' => 'video', 'video_id' => 'dQw4w9WgXcQ'],
    ]);

    $response = $this->postJson('/api/mcp?key='.$key, [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'capture_video',
        'params' => ['url' => 'https://youtu.be/dQw4w9WgXcQ'],
    ]);

    $response->assertJsonPath('result.id', $existing->id);
}

public function test_capture_video_supports_research_now_and_source_attribution(): void
{
    Queue::fake();

    $response = $this->postJson('/api/mcp?key='.$key, [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'capture_video',
        'params' => [
            'url' => 'https://youtu.be/dQw4w9WgXcQ',
            'research_now' => true,
            'source_metadata' => ['captured_by' => 'cursor'],
        ],
    ]);

    $response->assertOk();
    Queue::assertPushed(FetchVideoTranscript::class);
    $video = Thought::find($response->json('result.id'));
    $this->assertSame('cursor', data_get($video->source_metadata, 'captured_by'));
}
```

- [ ] **Step 2: Run the MCP tests and confirm they fail**

Run: `php artisan test tests/Feature/McpApiTest.php tests/Feature/McpCaptureVideoTest.php`

Expected: FAIL because `capture_video` is not yet exposed from the MCP controller.

- [ ] **Step 3: Implement MCP validation, dispatch, and partial-success warnings**

```php
'capture_video' => $this->captureVideo($params),

private function captureVideo(array $params): array
{
    $video = $this->videoCapture->captureFromUrl(
        auth()->id(),
        $params['url'],
        $params['transcript'] ?? null,
        researchNow: (bool) ($params['research_now'] ?? false),
        sourceMetadata: is_array($params['source_metadata'] ?? null) ? $params['source_metadata'] : null,
    );

    return [
        'id' => $video->id,
        'video_id' => $video->metadata['video_id'] ?? null,
        'transcript_status' => $video->metadata['transcript_status'] ?? null,
        'research_pending' => (bool) ($video->metadata['research_pending'] ?? false),
        'warning' => $degraded ? 'Video saved, but transcript fetch could not be queued.' : null,
    ];
}
```

- [ ] **Step 3a: Update all three MCP registration points and their brittle tests**

Implementation rule:
- add `capture_video` to `mcpMethodNames()`
- add `capture_video` to the `dispatch()` match
- add the full tool schema row to `respondToolsList()`
- update `tests/Feature/McpApiTest.php` assertions that hard-code method/tool ordering so they keep passing after the new method is inserted

- [ ] **Step 3a: Persist MCP source attribution on the root `video` thought**

Implementation rule:
- merge MCP `source_metadata` into the `video` thought `source_metadata`
- preserve existing source metadata when a duplicate video is reused
- do not store transcript text in source metadata

- [ ] **Step 4: Re-run the MCP tests and confirm they pass**

Run: `php artisan test tests/Feature/McpApiTest.php tests/Feature/McpCaptureVideoTest.php`

Expected: PASS for tool schema visibility, direct method dispatch, duplicate reuse, and degraded-but-successful capture responses.

- [ ] **Step 5: Commit the MCP video capture endpoint**

```bash
git add app/Http/Controllers/Api/McpController.php app/Services/Video/VideoCaptureService.php tests/Feature/McpApiTest.php tests/Feature/McpCaptureVideoTest.php
git commit -m "feat: add mcp video capture"
```

## Task 5: Add Dedicated Video Research Orchestration

**Files:**
- Create: `app/Services/Video/VideoResearchPromptBuilder.php`
- Create: `app/Services/Video/VideoResearchService.php`
- Create: `app/Jobs/RunVideoResearch.php`
- Modify: `app/Jobs/FetchVideoTranscript.php`
- Modify: `app/Http/Controllers/VideoController.php`
- Test: `tests/Unit/Services/Video/VideoResearchServiceTest.php`
- Test: `tests/Feature/VideoResearchWorkflowTest.php`

- [ ] **Step 1: Write the failing unit and feature tests for transcript-before-research ordering and latest-pointer updates**

```php
public function test_research_now_waits_for_transcript_terminal_state_before_running_research(): void
{
    Queue::fake();

    $this->actingAs($user)->post(route('videos.store'), [
        'url' => 'https://youtu.be/dQw4w9WgXcQ',
        'transcript' => '',
        'research_now' => true,
    ]);

    Queue::assertPushed(FetchVideoTranscript::class);
    Queue::assertNotPushed(RunVideoResearch::class);
}

public function test_successful_video_research_updates_latest_pointer_without_deleting_prior_research(): void
{
    $first = $service->saveResearch($video, "## Summary\nold");
    $second = $service->saveResearch($video, "## Summary\nnew");

    $video->refresh();
    $this->assertSame($second->id, $video->metadata['research_thought_id']);
    $this->assertSame(2, Thought::query()->where('source_metadata->video_thought_id', $video->id)->count());
}

public function test_video_research_stamps_bidirectional_metadata_and_fixed_section_headings(): void
{
    $research = $service->saveResearch($video, "## Summary\n...\n## Key Points\n...\n## Positives\n...\n## Negatives\n...\n## Source Notes\n...");

    $this->assertSame($video->id, data_get($research->source_metadata, 'video_thought_id'));
    $this->assertSame($video->metadata['video_id'] ?? null, data_get($research->source_metadata, 'video_id'));
    $this->assertNotNull(data_get($research->source_metadata, 'transcript_context_available'));
    $this->assertStringContainsString('## Source Notes', $research->content);
}

public function test_failed_video_research_clears_research_pending_without_moving_latest_pointer(): void
{
    $video->update(['metadata' => array_merge($video->metadata ?? [], ['research_pending' => true, 'research_thought_id' => $existingResearch->id])]);

    $this->expectException(\RuntimeException::class);

    try {
        $service->runResearch($video);
    } finally {
        $video->refresh();
        $this->assertFalse((bool) ($video->metadata['research_pending'] ?? false));
        $this->assertSame($existingResearch->id, $video->metadata['research_thought_id']);
    }
}
```

- [ ] **Step 2: Run the dedicated video research tests and confirm they fail**

Run: `php artisan test tests/Unit/Services/Video/VideoResearchServiceTest.php tests/Feature/VideoResearchWorkflowTest.php`

Expected: FAIL because no dedicated video research job/service exists and the current idea-only runner cannot satisfy these behaviors.

- [ ] **Step 3: Implement a narrow video research pipeline using `OpenRouterService::researchFromPrompt()`**

```php
$prompt = $this->promptBuilder->build(
    video: $video,
    transcript: $transcriptText,
);

$markdown = $this->openRouter->researchFromPrompt($prompt);
$research = $this->saveLinkedResearch($video, $markdown);

$this->capture->updateLatestResearchPointer($video, $research->id);
$this->capture->clearResearchPending($video);
```

- [ ] **Step 3a: Wire `research_pending` exactly as the spec defines it**

Implementation rule:
- set `research_pending = true` as soon as a `Research now` action is accepted
- leave it true while waiting for transcript terminal state
- leave it true while `RunVideoResearch` is queued/running
- clear it only after terminal `completed` / `failed` / `cancelled`
- never move `research_thought_id` until the new research thought has been saved successfully

- [ ] **Step 4: Re-run the dedicated video research tests and confirm they pass**

Run: `php artisan test tests/Unit/Services/Video/VideoResearchServiceTest.php tests/Feature/VideoResearchWorkflowTest.php`

Expected: PASS with exactly one research run queued after transcript reaches `available` / `unavailable` / `failed`, append-only linked research history, and latest-pointer updates only after successful save.

- [ ] **Step 5: Commit the video research pipeline**

```bash
git add app/Services/Video/VideoResearchPromptBuilder.php app/Services/Video/VideoResearchService.php app/Jobs/RunVideoResearch.php app/Jobs/FetchVideoTranscript.php app/Http/Controllers/VideoController.php tests/Unit/Services/Video/VideoResearchServiceTest.php tests/Feature/VideoResearchWorkflowTest.php
git commit -m "feat: add video research workflow"
```

## Task 6: Render Video State On Stream And Detail Surfaces

**Files:**
- Modify: `app/View/Presenters/Thoughts/StreamThoughtCardPresenter.php`
- Modify: `app/View/Presenters/Thoughts/ThoughtDetailPresenter.php`
- Modify: `resources/views/idea/stream_thoughts.blade.php`
- Modify: `resources/views/idea/show.blade.php`
- Modify: `resources/views/idea/partials/thought_detail_header.blade.php`
- Test: `tests/Feature/ThoughtShowPageTest.php`
- Test: `tests/Feature/VideoCaptureWebTest.php`
- Create: `tests/Feature/VideoStreamDisplayTest.php`

- [ ] **Step 1: Write the failing display tests for `video` thought badges, transcript state, and linked research actions**

```php
public function test_video_thought_detail_shows_transcript_status_and_research_link(): void
{
    $video = Thought::factory()->create([
        'user_id' => $user->id,
        'metadata' => [
            'type' => 'video',
            'video_id' => 'dQw4w9WgXcQ',
            'transcript_status' => 'available',
            'research_thought_id' => $research->id,
        ],
    ]);

    $response = $this->actingAs($user)->get(route('thoughts.show', $video));

    $response->assertSee('Transcript available');
    $response->assertSee('View research');
}

public function test_stream_card_shows_video_status_without_using_view_formatted_link(): void
{
    $response = $this->actingAs($user)->get(route('idea.stream'));

    $response->assertOk();
    $response->assertSee('Transcript available');
    $response->assertSee('Video');
}
```

- [ ] **Step 2: Run the display tests and confirm they fail**

Run: `php artisan test tests/Feature/ThoughtShowPageTest.php tests/Feature/VideoCaptureWebTest.php tests/Feature/VideoStreamDisplayTest.php`

Expected: FAIL because the presenter/view layer does not yet expose `video`-specific state.

- [ ] **Step 3: Extend presenters and views to surface compact `video` metadata without a new dedicated page**

```php
public function isVideoThought(): bool
{
    return ($this->thought->metadata['type'] ?? null) === 'video';
}

public function transcriptStatusLabel(): ?string
{
    return match ($this->thought->metadata['transcript_status'] ?? null) {
        'available' => 'Transcript available',
        'manual' => 'Transcript added manually',
        'pending' => 'Fetching transcript',
        'unavailable' => 'Transcript unavailable',
        'failed' => 'Transcript fetch failed',
        default => null,
    };
}
```

- [ ] **Step 4: Re-run the display tests and confirm they pass**

Run: `php artisan test tests/Feature/ThoughtShowPageTest.php tests/Feature/VideoCaptureWebTest.php tests/Feature/VideoStreamDisplayTest.php`

Expected: PASS with clear transcript state on cards/detail pages and `Research now` / `Rerun research` affordances visible when appropriate.

- [ ] **Step 5: Commit the presenter/view changes**

```bash
git add app/View/Presenters/Thoughts/StreamThoughtCardPresenter.php app/View/Presenters/Thoughts/ThoughtDetailPresenter.php resources/views/idea/stream_thoughts.blade.php resources/views/idea/show.blade.php resources/views/idea/partials/thought_detail_header.blade.php tests/Feature/ThoughtShowPageTest.php tests/Feature/VideoCaptureWebTest.php tests/Feature/VideoStreamDisplayTest.php
git commit -m "feat: show video thought state in ui"
```

## Task 7: Run Cross-Feature Verification

**Files:**
- Modify: `docs/superpowers/specs/2026-04-02-video-thought-youtube-research-design.md` (only if implementation forces a spec correction)
- Test: `tests/Unit/Services/Video/VideoCaptureServiceTest.php`
- Test: `tests/Feature/VideoTranscriptFetchTest.php`
- Test: `tests/Feature/VideoCaptureWebTest.php`
- Test: `tests/Feature/VideoResearchWorkflowTest.php`
- Test: `tests/Feature/McpCaptureVideoTest.php`
- Test: `tests/Feature/McpApiTest.php`
- Test: `tests/Feature/ThoughtShowPageTest.php`
- Test: `tests/Feature/VideoStreamDisplayTest.php`

- [ ] **Step 1: Run the full focused regression suite**

Run: `php artisan test tests/Unit/Services/Video/VideoCaptureServiceTest.php tests/Feature/VideoTranscriptFetchTest.php tests/Feature/VideoCaptureWebTest.php tests/Feature/VideoResearchWorkflowTest.php tests/Feature/McpCaptureVideoTest.php tests/Feature/McpApiTest.php tests/Feature/ThoughtShowPageTest.php tests/Feature/VideoStreamDisplayTest.php`

Expected: PASS for the new video flow and no regression in MCP capture/tool listing or generic thought detail rendering.

- [ ] **Step 2: Run a targeted existing regression suite around ideas research entry points**

Run: `php artisan test tests/Feature/IdeaIdeasTest.php tests/Feature/ResearchRunWorkflowTest.php`

Expected: PASS to confirm the idea-specific research path was not broken by the dedicated video pipeline.

- [ ] **Step 3: Manually verify the smart composer in the browser**

Manual check:
- paste plain text and confirm the form stays in normal idea mode
- paste a lone YouTube URL and confirm transcript + `Research now` controls appear
- paste a URL plus extra prose and confirm the form stays in normal mode
- submit URL-only and confirm a `video` thought appears
- submit with `Research now` and confirm transcript is fetched before research is queued

- [ ] **Step 4: Commit any final cleanup**

```bash
git add tests/Unit/Services/Video/VideoCaptureServiceTest.php tests/Feature/VideoTranscriptFetchTest.php tests/Feature/VideoCaptureWebTest.php tests/Feature/VideoResearchWorkflowTest.php tests/Feature/McpCaptureVideoTest.php tests/Feature/McpApiTest.php tests/Feature/ThoughtShowPageTest.php tests/Feature/VideoStreamDisplayTest.php
git commit -m "test: verify video thought workflow"
```

## Notes For The Implementer

- Do not widen `research_runs.idea_thought_id` into a generic source-thought foreign key in this plan. That is a larger architectural change than this feature needs.
- Keep transcript text out of JSON metadata. Use child thoughts for transcript content.
- Use the existing `EmailLinkExtractor` as the single normalization seam for accepted YouTube URL shapes.
- Keep `video` display changes additive. The main stream/detail pages should remain the canonical v1 surfaces; a dedicated typed video stream can wait.
- Preserve the existing idea flow in `resources/views/idea/ideas.blade.php`. The smart composer should only switch when the input is clearly a lone YouTube URL.
- In v1, use the built-in video research prompt path only. Do not add a skill selector or thread video capture into the existing idea `ResearchRun` UI.
