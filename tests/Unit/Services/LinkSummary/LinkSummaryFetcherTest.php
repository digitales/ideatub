<?php

namespace Tests\Unit\Services\LinkSummary;

use App\Services\LinkSummary\LinkSummaryFetcher;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LinkSummaryFetcherTest extends TestCase
{
    #[Test]
    public function fetch_rejects_non_http_schemes(): void
    {
        Http::fake();

        $fetcher = new LinkSummaryFetcher;

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported URL scheme');

        try {
            $fetcher->fetch('file:///etc/passwd');
        } finally {
            Http::assertNothingSent();
        }
    }

    #[Test]
    public function fetch_rejects_localhost_and_loopback_hosts(): void
    {
        Http::fake();

        $fetcher = new LinkSummaryFetcher;

        foreach (['http://localhost/test', 'https://127.0.0.1/test', 'http://[::1]/test'] as $url) {
            try {
                $fetcher->fetch($url);
                $this->fail('Expected unsafe host to be rejected.');
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('Unsafe URL host', $e->getMessage());
            }
        }

        Http::assertNothingSent();
    }

    #[Test]
    public function fetch_rejects_private_and_reserved_ip_targets(): void
    {
        Http::fake();

        $fetcher = new LinkSummaryFetcher;

        foreach (['http://10.0.0.5/test', 'http://192.168.1.20/test', 'http://169.254.169.254/latest'] as $url) {
            try {
                $fetcher->fetch($url);
                $this->fail('Expected private or reserved IP to be rejected.');
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('Unsafe URL host', $e->getMessage());
            }
        }

        Http::assertNothingSent();
    }

    #[Test]
    public function fetch_rejects_suspicious_numeric_and_hex_host_forms(): void
    {
        Http::fake();

        $fetcher = new LinkSummaryFetcher;

        foreach (['http://2130706433/test', 'http://0x7f.0.0.1/test', 'http://0177.0.0.1/test'] as $url) {
            try {
                $fetcher->fetch($url);
                $this->fail('Expected suspicious host form to be rejected.');
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('Unsafe URL host', $e->getMessage());
            }
        }

        Http::assertNothingSent();
    }

    #[Test]
    public function fetch_rejects_redirects_to_unsafe_hosts(): void
    {
        Http::fake([
            'safe.example/*' => Http::response('', 302, [
                'Location' => 'http://127.0.0.1/internal',
            ]),
        ]);

        $fetcher = new LinkSummaryFetcher;

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsafe URL host');

        $fetcher->fetch('https://safe.example/start');
    }

    #[Test]
    public function fetch_rejects_content_length_above_cap_without_buffering_body(): void
    {
        Http::fake([
            'example.com/*' => Http::response('<html></html>', 200, [
                'Content-Length' => (string) (3 * 1024 * 1024),
            ]),
        ]);

        $fetcher = new LinkSummaryFetcher;

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Content-Length exceeds cap');

        $fetcher->fetch('https://example.com/huge');
    }
}
