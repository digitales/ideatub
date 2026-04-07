<?php

namespace App\Services\Video;

/**
 * Splits plain transcript text into chunks whose stored markdown fits MySQL TEXT (~64 KiB)
 * and keeps embedding requests within typical model input limits.
 */
final class VideoTranscriptChunker
{
    /**
     * Max byte length for the full stored thought {@see VideoThoughtContentBuilder::transcriptContent} output.
     */
    public const MAX_STORED_CONTENT_BYTES = 62000;

    private const TRANSCRIPT_HEADING_PREFIX = "## Transcript\n\n";

    /**
     * @return list<non-empty-string>
     */
    public function splitPlainText(string $text): array
    {
        $text = trim($text);
        if ($text === '') {
            return [];
        }

        $maxBodyBytes = max(1024, self::MAX_STORED_CONTENT_BYTES - strlen(self::TRANSCRIPT_HEADING_PREFIX));

        $paragraphs = preg_split('/\R{2,}/u', $text) ?: [];
        $chunks = [];
        $current = '';

        foreach ($paragraphs as $paragraph) {
            $paragraph = trim($paragraph);
            if ($paragraph === '') {
                continue;
            }

            if (strlen($paragraph) > $maxBodyBytes) {
                if ($current !== '') {
                    $chunks[] = $current;
                    $current = '';
                }
                foreach ($this->hardSplitUtf8($paragraph, $maxBodyBytes) as $piece) {
                    if ($piece !== '') {
                        $chunks[] = $piece;
                    }
                }

                continue;
            }

            $separator = $current === '' ? '' : "\n\n";
            $candidate = $current.$separator.$paragraph;
            if (strlen($candidate) <= $maxBodyBytes) {
                $current = $candidate;
            } else {
                if ($current !== '') {
                    $chunks[] = $current;
                }
                $current = $paragraph;
            }
        }

        if ($current !== '') {
            $chunks[] = $current;
        }

        /** @var list<non-empty-string> $out */
        $out = [];
        foreach ($chunks as $chunk) {
            $t = trim($chunk);
            if ($t !== '') {
                $out[] = $t;
            }
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private function hardSplitUtf8(string $text, int $maxBytes): array
    {
        $out = [];
        $offset = 0;
        $len = strlen($text);
        while ($offset < $len) {
            $piece = mb_strcut($text, $offset, $maxBytes, 'UTF-8');
            if ($piece === false || $piece === '') {
                break;
            }
            $out[] = $piece;
            $offset += strlen($piece);
        }

        return $out;
    }
}
