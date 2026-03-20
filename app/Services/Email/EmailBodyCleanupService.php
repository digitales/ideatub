<?php

namespace App\Services\Email;

class EmailBodyCleanupService
{
    public function clean(?string $body): string
    {
        $text = trim((string) $body);
        if ($text === '') {
            return '';
        }

        if ($this->looksLikeHtml($text)) {
            $text = html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>'], "\n", $text)));
        }

        $lines = preg_split("/\r\n|\n|\r/", $text) ?: [];
        $cleaned = [];

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if (preg_match('/^bcc:/i', $trimmed)) {
                continue;
            }

            if (preg_match('/^on .+ wrote:$/i', $trimmed)) {
                break;
            }

            if (preg_match('/^--\s*$/', $trimmed)) {
                break;
            }

            if (str_starts_with($trimmed, '>')) {
                continue;
            }

            $cleaned[] = rtrim($line);
        }

        $normalized = preg_replace("/\n{3,}/", "\n\n", implode("\n", $cleaned)) ?? '';

        return trim($normalized);
    }

    private function looksLikeHtml(string $body): bool
    {
        return $body !== strip_tags($body);
    }
}
