<?php

namespace App\Services\Import;

use App\Exceptions\FileImportRejectedException;

class FileImportService
{
    private const MAX_BYTES = 1048576;

    public const ALLOWED_EXT = ['txt', 'md'];

    private const ALLOWED_MIME = [
        'text/plain',
        'text/markdown',
        'text/x-markdown',
        'application/octet-stream',
    ];

    private const BIDI_CHARS = ["\u{202A}", "\u{202B}", "\u{202C}", "\u{202D}", "\u{202E}",
        "\u{2066}", "\u{2067}", "\u{2068}", "\u{2069}"];

    public function sanitiseBytes(string $bytes, string $extension = 'md'): string
    {
        if (! in_array(mb_strtolower($extension), self::ALLOWED_EXT, true)) {
            throw new FileImportRejectedException('unsupported_extension');
        }

        if ($this->looksBinary($bytes)) {
            throw new FileImportRejectedException('binary_detected');
        }

        if (mb_check_encoding($bytes, 'UTF-8')) {
            $encoding = 'UTF-8';
        } else {
            $encoding = mb_detect_encoding(
                $bytes,
                ['UTF-8', 'UTF-16LE', 'UTF-16BE', 'Windows-1252', 'ISO-8859-1'],
                true
            );
        }
        if ($encoding === false) {
            throw new FileImportRejectedException('encoding');
        }
        if ($encoding !== 'UTF-8') {
            $bytes = mb_convert_encoding($bytes, 'UTF-8', $encoding);
        }

        if (str_starts_with($bytes, "\u{FEFF}")) {
            $bytes = substr($bytes, strlen("\u{FEFF}"));
        }

        $bytes = (string) preg_replace("/\r\n|\r/", "\n", $bytes);
        $bytes = str_replace(self::BIDI_CHARS, '', $bytes);
        $bytes = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $bytes);

        if (strlen($bytes) > self::MAX_BYTES) {
            throw new FileImportRejectedException('too_large');
        }

        return $bytes;
    }

    private function looksBinary(string $bytes): bool
    {
        $sample = substr($bytes, 0, 8192);
        if (str_contains($sample, "\x00")) {
            return true;
        }
        $nonPrintable = preg_match_all('/[\x01-\x08\x0E-\x1F\x7F]/', $sample);

        return $nonPrintable > 0 && (strlen($sample) > 0 && $nonPrintable / strlen($sample) > 0.1);
    }
}
