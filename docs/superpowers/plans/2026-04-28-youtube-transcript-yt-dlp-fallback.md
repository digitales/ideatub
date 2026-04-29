# YouTube Transcript `yt-dlp` Fallback Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a feature-flagged `yt-dlp` fallback for video transcript retrieval so automatic transcript fetch succeeds more often while preserving manual transcript fallback.

**Architecture:** Keep `YouTubeTranscriptService` as the first provider, then run a new `YtDlpTranscriptService` via a `VideoTranscriptOrchestrator` when the primary provider fails and fallback is enabled. Keep `FetchVideoTranscript` persistence behavior unchanged by feeding it the same normalized result shape already handled by `VideoCaptureService::applyTranscriptFetchResult`.

**Tech Stack:** Laravel 12 services/jobs/config, Symfony Process (via Laravel dependency tree), PHPUnit/Pest with Mockery, feature-flagged env configuration.

---

## Scope check

This spec is a single subsystem (video transcript retrieval pipeline) and can be implemented in one plan without splitting.

---

## File map

| Action | Path |
|---|---|
| Create | `app/Services/Video/VideoTranscriptOrchestrator.php` |
| Create | `app/Services/Video/YtDlpTranscriptService.php` |
| Modify | `app/Jobs/FetchVideoTranscript.php` |
| Modify | `config/services.php` |
| Modify | `.env.example` |
| Create | `tests/Unit/Services/Video/VideoTranscriptOrchestratorTest.php` |
| Create | `tests/Unit/Services/Video/YtDlpTranscriptServiceTest.php` |
| Modify | `tests/Feature/VideoTranscriptFetchTest.php` |

---

### Task 1: Build orchestrator chain with feature flag

**Files:**
- Create: `app/Services/Video/VideoTranscriptOrchestrator.php`
- Create: `tests/Unit/Services/Video/VideoTranscriptOrchestratorTest.php`
- Modify: `config/services.php`
- Modify: `.env.example`

- [ ] **Step 1: Write failing orchestrator unit tests**

```php
// tests/Unit/Services/Video/VideoTranscriptOrchestratorTest.php
<?php

namespace Tests\Unit\Services\Video;

use App\Services\Email\YouTubeTranscriptService;
use App\Services\Video\VideoTranscriptOrchestrator;
use App\Services\Video\YtDlpTranscriptService;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class VideoTranscriptOrchestratorTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    #[Test]
    public function it_returns_primary_success_without_calling_fallback(): void
    {
        config()->set('services.transcripts.yt_dlp_enabled', true);

        $youtube = Mockery::mock(YouTubeTranscriptService::class);
        $youtube->shouldReceive('fetchForUrl')->once()->andReturn([
            'ok' => true,
            'video_id' => 'abc12345678',
            'language_code' => 'en',
            'transcript' => 'Primary transcript',
        ]);

        $ytDlp = Mockery::mock(YtDlpTranscriptService::class);
        $ytDlp->shouldNotReceive('fetchForUrl');

        $service = new VideoTranscriptOrchestrator($youtube, $ytDlp);
        $result = $service->fetchForUrl('https://www.youtube.com/watch?v=abc12345678');

        $this->assertTrue($result['ok']);
        $this->assertSame('youtube', $result['provider_used']);
    }

    #[Test]
    public function it_returns_primary_failure_when_fallback_disabled(): void
    {
        config()->set('services.transcripts.yt_dlp_enabled', false);

        $youtube = Mockery::mock(YouTubeTranscriptService::class);
        $youtube->shouldReceive('fetchForUrl')->once()->andReturn([
            'ok' => false,
            'reason' => 'transcript_unavailable',
            'video_id' => 'abc12345678',
        ]);

        $ytDlp = Mockery::mock(YtDlpTranscriptService::class);
        $ytDlp->shouldNotReceive('fetchForUrl');

        $service = new VideoTranscriptOrchestrator($youtube, $ytDlp);
        $result = $service->fetchForUrl('https://www.youtube.com/watch?v=abc12345678');

        $this->assertFalse($result['ok']);
        $this->assertSame('transcript_unavailable', $result['reason']);
        $this->assertSame('youtube', $result['provider_used']);
    }

    #[Test]
    public function it_uses_fallback_on_primary_failure_when_enabled(): void
    {
        config()->set('services.transcripts.yt_dlp_enabled', true);

        $youtube = Mockery::mock(YouTubeTranscriptService::class);
        $youtube->shouldReceive('fetchForUrl')->once()->andReturn([
            'ok' => false,
            'reason' => 'transcript_unavailable',
            'video_id' => 'abc12345678',
        ]);

        $ytDlp = Mockery::mock(YtDlpTranscriptService::class);
        $ytDlp->shouldReceive('fetchForUrl')->once()->andReturn([
            'ok' => true,
            'video_id' => 'abc12345678',
            'language_code' => 'en',
            'transcript' => 'Fallback transcript',
        ]);

        $service = new VideoTranscriptOrchestrator($youtube, $ytDlp);
        $result = $service->fetchForUrl('https://www.youtube.com/watch?v=abc12345678');

        $this->assertTrue($result['ok']);
        $this->assertSame('yt_dlp', $result['provider_used']);
    }
}
```

- [ ] **Step 2: Run tests and confirm failure**

Run: `php artisan test tests/Unit/Services/Video/VideoTranscriptOrchestratorTest.php`

Expected: FAIL with class not found for `VideoTranscriptOrchestrator`.

- [ ] **Step 3: Implement orchestrator service**

```php
// app/Services/Video/VideoTranscriptOrchestrator.php
<?php

namespace App\Services\Video;

use App\Services\Email\YouTubeTranscriptService;
use Psr\Log\LoggerInterface;

class VideoTranscriptOrchestrator
{
    public function __construct(
        private readonly YouTubeTranscriptService $youtube,
        private readonly YtDlpTranscriptService $ytDlp,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function fetchForUrl(string $url): array
    {
        $primary = $this->youtube->fetchForUrl($url);
        if (($primary['ok'] ?? false) === true) {
            $primary['provider_used'] = 'youtube';

            return $primary;
        }

        if (! $this->ytDlpEnabled()) {
            $primary['provider_used'] = 'youtube';

            return $primary;
        }

        $fallback = $this->ytDlp->fetchForUrl($url);
        if (($fallback['ok'] ?? false) === true) {
            $fallback['provider_used'] = 'yt_dlp';
            $this->logger?->info('video_transcript.fallback_succeeded', [
                'provider' => 'yt_dlp',
                'video_id' => $fallback['video_id'] ?? $primary['video_id'] ?? null,
                'primary_reason' => $primary['reason'] ?? null,
            ]);

            return $fallback;
        }

        $normalized = $this->normalizeFallbackFailure($primary, $fallback);
        $normalized['provider_used'] = 'none';
        $normalized['provider_attempts'] = ['youtube', 'yt_dlp'];

        return $normalized;
    }

    private function ytDlpEnabled(): bool
    {
        return (bool) config('services.transcripts.yt_dlp_enabled', false);
    }

    /**
     * @param  array<string, mixed>  $primary
     * @param  array<string, mixed>  $fallback
     * @return array<string, mixed>
     */
    private function normalizeFallbackFailure(array $primary, array $fallback): array
    {
        $primaryReason = (string) ($primary['reason'] ?? 'youtube_fetch_failed');
        $fallbackReason = (string) ($fallback['reason'] ?? 'youtube_fetch_failed');
        $videoId = $primary['video_id'] ?? $fallback['video_id'] ?? null;

        if ($primaryReason === 'transcript_unavailable' && $fallbackReason === 'transcript_unavailable') {
            return [
                'ok' => false,
                'reason' => 'transcript_unavailable',
                'video_id' => $videoId,
            ];
        }

        return [
            'ok' => false,
            'reason' => 'youtube_fetch_failed',
            'video_id' => $videoId,
            'detail' => trim($primaryReason.'|'.$fallbackReason, '|'),
        ];
    }
}
```

- [ ] **Step 4: Add configuration keys for feature flag and command settings**

```php
// config/services.php (append near bottom of return array)
'transcripts' => [
    'yt_dlp_enabled' => filter_var(env('TRANSCRIPTS_YT_DLP_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
    'yt_dlp_bin' => env('TRANSCRIPTS_YT_DLP_BIN', 'yt-dlp'),
    'yt_dlp_timeout_seconds' => (int) env('TRANSCRIPTS_YT_DLP_TIMEOUT_SECONDS', 25),
],
```

```dotenv
# .env.example (append near other feature flags)
TRANSCRIPTS_YT_DLP_ENABLED=false
TRANSCRIPTS_YT_DLP_BIN=yt-dlp
TRANSCRIPTS_YT_DLP_TIMEOUT_SECONDS=25
```

- [ ] **Step 5: Re-run orchestrator unit tests**

Run: `php artisan test tests/Unit/Services/Video/VideoTranscriptOrchestratorTest.php`

Expected: PASS (all orchestrator cases green).

- [ ] **Step 6: Commit Task 1**

```bash
git add app/Services/Video/VideoTranscriptOrchestrator.php tests/Unit/Services/Video/VideoTranscriptOrchestratorTest.php config/services.php .env.example
git commit -m "add transcript orchestrator with yt-dlp feature flag"
```

---

### Task 2: Implement `yt-dlp` provider with robust failure handling

**Files:**
- Create: `app/Services/Video/YtDlpTranscriptService.php`
- Create: `tests/Unit/Services/Video/YtDlpTranscriptServiceTest.php`

- [ ] **Step 1: Write failing `YtDlpTranscriptService` tests**

```php
// tests/Unit/Services/Video/YtDlpTranscriptServiceTest.php
<?php

namespace Tests\Unit\Services\Video;

use App\Services\Email\EmailLinkExtractor;
use App\Services\Video\YtDlpTranscriptService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class YtDlpTranscriptServiceTest extends TestCase
{
    #[Test]
    public function it_returns_transcript_when_runner_succeeds(): void
    {
        $runner = function (array $command, int $timeoutSeconds): array {
            return [
                'exit_code' => 0,
                'stdout' => "WEBVTT\n\n00:00:00.000 --> 00:00:01.000\nHello\n\n00:00:01.000 --> 00:00:02.000\nworld\n",
                'stderr' => '',
            ];
        };

        $service = new YtDlpTranscriptService(new EmailLinkExtractor, $runner);
        $result = $service->fetchForUrl('https://www.youtube.com/watch?v=abc12345678');

        $this->assertTrue($result['ok']);
        $this->assertSame('abc12345678', $result['video_id']);
        $this->assertSame('Hello world', $result['transcript']);
    }

    #[Test]
    public function it_returns_unavailable_for_subtitle_missing_exit(): void
    {
        $runner = function (array $command, int $timeoutSeconds): array {
            return [
                'exit_code' => 1,
                'stdout' => '',
                'stderr' => 'WARNING: There are no subtitles for the requested languages',
            ];
        };

        $service = new YtDlpTranscriptService(new EmailLinkExtractor, $runner);
        $result = $service->fetchForUrl('https://www.youtube.com/watch?v=abc12345678');

        $this->assertFalse($result['ok']);
        $this->assertSame('transcript_unavailable', $result['reason']);
    }

    #[Test]
    public function it_returns_fetch_failed_when_binary_missing(): void
    {
        $runner = function (array $command, int $timeoutSeconds): array {
            return [
                'exit_code' => 127,
                'stdout' => '',
                'stderr' => 'yt-dlp: command not found',
            ];
        };

        $service = new YtDlpTranscriptService(new EmailLinkExtractor, $runner);
        $result = $service->fetchForUrl('https://www.youtube.com/watch?v=abc12345678');

        $this->assertFalse($result['ok']);
        $this->assertSame('youtube_fetch_failed', $result['reason']);
        $this->assertStringContainsString('yt_dlp_unavailable', (string) ($result['detail'] ?? ''));
    }
}
```

- [ ] **Step 2: Run tests and confirm failure**

Run: `php artisan test tests/Unit/Services/Video/YtDlpTranscriptServiceTest.php`

Expected: FAIL with class not found for `YtDlpTranscriptService`.

- [ ] **Step 3: Implement `YtDlpTranscriptService`**

```php
// app/Services/Video/YtDlpTranscriptService.php
<?php

namespace App\Services\Video;

use App\Services\Email\EmailLinkExtractor;
use Closure;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;
use Throwable;

class YtDlpTranscriptService
{
    /**
     * @param  Closure(array<int, string>, int): array{exit_code:int,stdout:string,stderr:string}|null  $runner
     */
    public function __construct(
        private readonly EmailLinkExtractor $linkExtractor,
        private readonly ?Closure $runner = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function fetchForUrl(string $url): array
    {
        $videoId = $this->linkExtractor->extractYouTubeVideoId($url);
        if ($videoId === null) {
            return ['ok' => false, 'reason' => 'unsupported_youtube_url', 'video_id' => null];
        }

        $command = [
            (string) config('services.transcripts.yt_dlp_bin', 'yt-dlp'),
            '--skip-download',
            '--write-auto-subs',
            '--write-subs',
            '--sub-langs',
            'all',
            '--sub-format',
            'vtt',
            '--convert-subs',
            'vtt',
            '--output',
            '-',
            $url,
        ];

        $timeout = (int) config('services.transcripts.yt_dlp_timeout_seconds', 25);

        try {
            $result = $this->runCommand($command, $timeout);
            $stderr = $result['stderr'] ?? '';
            $stdout = $result['stdout'] ?? '';
            $exitCode = (int) ($result['exit_code'] ?? 1);

            if ($exitCode !== 0) {
                return $this->failureFromCommand($videoId, $stderr, $exitCode);
            }

            $transcript = $this->normalizeSubtitleText($stdout);
            if ($transcript === '') {
                return ['ok' => false, 'reason' => 'transcript_unavailable', 'video_id' => $videoId];
            }

            return [
                'ok' => true,
                'video_id' => $videoId,
                'language_code' => 'unknown',
                'transcript' => $transcript,
            ];
        } catch (Throwable $e) {
            return [
                'ok' => false,
                'reason' => 'youtube_fetch_failed',
                'video_id' => $videoId,
                'detail' => $e::class,
            ];
        }
    }

    /**
     * @param  array<int, string>  $command
     * @return array{exit_code:int,stdout:string,stderr:string}
     */
    private function runCommand(array $command, int $timeoutSeconds): array
    {
        if ($this->runner !== null) {
            return ($this->runner)($command, $timeoutSeconds);
        }

        $process = new Process($command);
        $process->setTimeout($timeoutSeconds);
        $process->run();

        return [
            'exit_code' => $process->getExitCode() ?? 1,
            'stdout' => $process->getOutput(),
            'stderr' => $process->getErrorOutput(),
        ];
    }

    private function normalizeSubtitleText(string $raw): string
    {
        $lines = preg_split("/\r\n|\n|\r/", $raw) ?: [];
        $filtered = [];

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, 'WEBVTT') || str_contains($trimmed, '-->')) {
                continue;
            }
            if (preg_match('/^\d+$/', $trimmed) === 1) {
                continue;
            }
            $filtered[] = $trimmed;
        }

        return trim(preg_replace('/\s+/', ' ', implode(' ', $filtered)) ?? '');
    }

    private function failureFromCommand(string $videoId, string $stderr, int $exitCode): array
    {
        $lower = mb_strtolower($stderr);
        if (str_contains($lower, 'no subtitles')) {
            return ['ok' => false, 'reason' => 'transcript_unavailable', 'video_id' => $videoId];
        }

        if ($exitCode === 127 || str_contains($lower, 'command not found')) {
            return ['ok' => false, 'reason' => 'youtube_fetch_failed', 'video_id' => $videoId, 'detail' => 'yt_dlp_unavailable'];
        }

        return ['ok' => false, 'reason' => 'youtube_fetch_failed', 'video_id' => $videoId, 'detail' => 'yt_dlp_failed'];
    }
}
```

- [ ] **Step 4: Run `yt-dlp` provider unit tests**

Run: `php artisan test tests/Unit/Services/Video/YtDlpTranscriptServiceTest.php`

Expected: PASS.

- [ ] **Step 5: Commit Task 2**

```bash
git add app/Services/Video/YtDlpTranscriptService.php tests/Unit/Services/Video/YtDlpTranscriptServiceTest.php
git commit -m "add yt-dlp transcript fallback provider"
```

---

### Task 3: Wire orchestrator into transcript job and preserve behavior

**Files:**
- Modify: `app/Jobs/FetchVideoTranscript.php`
- Modify: `tests/Feature/VideoTranscriptFetchTest.php`

- [ ] **Step 1: Update feature tests to mock orchestrator instead of direct YouTube service**

```php
// tests/Feature/VideoTranscriptFetchTest.php (imports)
use App\Services\Video\VideoTranscriptOrchestrator;
```

```php
// tests/Feature/VideoTranscriptFetchTest.php (example per-test replacement)
$orchestrator = Mockery::mock(VideoTranscriptOrchestrator::class);
$orchestrator->shouldReceive('fetchForUrl')
    ->once()
    ->with('https://www.youtube.com/watch?v=abc12345678')
    ->andReturn([
        'ok' => true,
        'video_id' => 'abc12345678',
        'language_code' => 'en',
        'transcript' => 'Line one. Line two.',
        'provider_used' => 'yt_dlp',
    ]);
$this->app->instance(VideoTranscriptOrchestrator::class, $orchestrator);
```

- [ ] **Step 2: Run targeted feature test and confirm failure**

Run: `php artisan test tests/Feature/VideoTranscriptFetchTest.php`

Expected: FAIL because `FetchVideoTranscript` still resolves `YouTubeTranscriptService`.

- [ ] **Step 3: Switch job dependency to orchestrator**

```php
// app/Jobs/FetchVideoTranscript.php (imports)
use App\Services\Video\VideoTranscriptOrchestrator;
```

```php
// app/Jobs/FetchVideoTranscript.php (handle signature and call site)
public function handle(
    VideoCaptureService $videoCapture,
    VideoTranscriptOrchestrator $transcriptOrchestrator,
    VideoResearchService $videoResearch,
): void {
    // ...existing root/noop/url guards unchanged...
    $result = $transcriptOrchestrator->fetchForUrl($url);
    // ...existing applyTranscriptFetchResult transaction unchanged...
}
```

- [ ] **Step 4: Run transcript feature tests again**

Run: `php artisan test tests/Feature/VideoTranscriptFetchTest.php`

Expected: PASS with existing status transitions still intact.

- [ ] **Step 5: Run the full transcript-related test set**

Run: `php artisan test tests/Unit/Services/Video/VideoTranscriptOrchestratorTest.php tests/Unit/Services/Video/YtDlpTranscriptServiceTest.php tests/Unit/Services/YouTubeTranscriptServiceTest.php tests/Feature/VideoTranscriptFetchTest.php`

Expected: PASS.

- [ ] **Step 6: Commit Task 3**

```bash
git add app/Jobs/FetchVideoTranscript.php tests/Feature/VideoTranscriptFetchTest.php
git commit -m "route video transcript fetches through provider chain"
```

---

### Task 4: Smoke-check configuration and rollout notes in code

**Files:**
- Modify: `tests/Unit/Services/Video/VideoTranscriptOrchestratorTest.php`

- [ ] **Step 1: Add explicit test for disabled fallback preserving provider_used**

```php
#[Test]
public function it_marks_provider_as_youtube_when_fallback_disabled(): void
{
    config()->set('services.transcripts.yt_dlp_enabled', false);

    $youtube = Mockery::mock(YouTubeTranscriptService::class);
    $youtube->shouldReceive('fetchForUrl')->once()->andReturn([
        'ok' => false,
        'reason' => 'youtube_fetch_failed',
        'video_id' => 'abc12345678',
    ]);

    $ytDlp = Mockery::mock(YtDlpTranscriptService::class);
    $ytDlp->shouldNotReceive('fetchForUrl');

    $service = new VideoTranscriptOrchestrator($youtube, $ytDlp);
    $result = $service->fetchForUrl('https://www.youtube.com/watch?v=abc12345678');

    $this->assertSame('youtube', $result['provider_used']);
}
```

- [ ] **Step 2: Run orchestrator test file**

Run: `php artisan test tests/Unit/Services/Video/VideoTranscriptOrchestratorTest.php`

Expected: PASS.

- [ ] **Step 3: Commit Task 4**

```bash
git add tests/Unit/Services/Video/VideoTranscriptOrchestratorTest.php
git commit -m "cover transcript fallback feature-flag behavior"
```

---

## Final verification checklist

- [ ] Run lint/style checks if required by hooks:
  - `./vendor/bin/pint --test`
- [ ] Run full targeted suite again:
  - `php artisan test tests/Unit/Services/Video/VideoTranscriptOrchestratorTest.php tests/Unit/Services/Video/YtDlpTranscriptServiceTest.php tests/Feature/VideoTranscriptFetchTest.php`
- [ ] Manual staging check with flag off:
  - `TRANSCRIPTS_YT_DLP_ENABLED=false` and confirm previous behavior.
- [ ] Manual staging check with flag on:
  - `TRANSCRIPTS_YT_DLP_ENABLED=true` and verify known failure ID attempts fallback.

