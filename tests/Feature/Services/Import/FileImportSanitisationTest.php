<?php

namespace Tests\Feature\Services\Import;

use App\Exceptions\FileImportRejectedException;
use App\Services\Import\FileImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FileImportSanitisationTest extends TestCase
{
    use RefreshDatabase;

    private function sanitise(string $bytes, string $ext = 'md'): string
    {
        return app(FileImportService::class)->sanitiseBytes($bytes, $ext);
    }

    public function test_it_strips_bom(): void
    {
        $this->assertSame('hello', $this->sanitise("\u{FEFF}hello"));
    }

    public function test_it_normalises_line_endings(): void
    {
        $this->assertSame("a\nb\nc", $this->sanitise("a\r\nb\rc"));
    }

    public function test_it_strips_bidi_override_chars(): void
    {
        $bidi = "clean\u{202E}evil";
        $this->assertSame('cleanevil', $this->sanitise($bidi));
    }

    public function test_it_rejects_binary_content(): void
    {
        $this->expectException(FileImportRejectedException::class);
        $this->sanitise("okay\x00payload");
    }

    public function test_it_transcodes_windows_1252(): void
    {
        $input = mb_convert_encoding('curly quote ’', 'Windows-1252', 'UTF-8');
        $out = $this->sanitise($input);
        $this->assertSame('curly quote ’', $out);
    }

    public function test_it_rejects_oversize_after_sanitisation(): void
    {
        $this->expectException(FileImportRejectedException::class);
        $this->sanitise(str_repeat('x', 1024 * 1024 + 1));
    }
}
