<?php

namespace Tests\Unit\Services;

use App\Services\Email\EmailBodyCleanupService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EmailBodyCleanupServiceTest extends TestCase
{
    #[Test]
    public function cleanup_converts_html_to_text(): void
    {
        $service = new EmailBodyCleanupService;

        $result = $service->clean('<p>Hello <strong>world</strong></p><p>Second line</p>');

        $this->assertStringContainsString('Hello world', $result);
        $this->assertStringContainsString('Second line', $result);
    }

    #[Test]
    public function cleanup_removes_quoted_reply_chain(): void
    {
        $service = new EmailBodyCleanupService;

        $result = $service->clean("Latest reply\n\nOn Tue, Mar 20, 2026 at 10:00 AM Alice wrote:\n> Older text");

        $this->assertSame('Latest reply', $result);
    }

    #[Test]
    public function cleanup_removes_signature_block(): void
    {
        $service = new EmailBodyCleanupService;

        $result = $service->clean("Hello there\n\n-- \nRoss Tweedie\nIdeaTub");

        $this->assertSame('Hello there', $result);
    }

    #[Test]
    public function cleanup_removes_obvious_bcc_header_text(): void
    {
        $service = new EmailBodyCleanupService;

        $result = $service->clean("Hello\nBcc: hidden@example.com\nThanks");

        $this->assertStringNotContainsString('Bcc:', $result);
        $this->assertStringContainsString('Hello', $result);
        $this->assertStringContainsString('Thanks', $result);
    }
}
