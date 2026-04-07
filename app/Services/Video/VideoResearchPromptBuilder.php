<?php

namespace App\Services\Video;

use App\Models\Thought;

class VideoResearchPromptBuilder
{
    /**
     * @param  array{status: string, source: string}  $transcriptState
     */
    public function build(Thought $videoRoot, bool $transcriptContextAvailable, string $transcriptSectionMarkdown, array $transcriptState): string
    {
        $videoId = (string) (data_get($videoRoot->metadata, 'video_id') ?? '');
        $videoUrl = (string) (data_get($videoRoot->metadata, 'video_url') ?? '');
        $rootContent = trim($videoRoot->getDecodedContent());

        $transcriptSection = $this->formatTranscriptSection($transcriptContextAvailable, $transcriptSectionMarkdown, $transcriptState);

        $contract = <<<'MD'
Your response MUST be Markdown and MUST include these level-2 headings in this exact order (each at the start of a line):
## Summary
## Key Points
## Positives
## Negatives
## Source Notes

Under each heading, write substantive bullets or short paragraphs. Do not omit any heading.
MD;

        $contextLabel = $transcriptContextAvailable
            ? 'Transcript context for this run: AVAILABLE (full transcript text is included below).'
            : 'Transcript context for this run: NOT AVAILABLE or LIMITED. The automatic transcript could not be retrieved or no transcript text is present. Base your analysis mainly on the video title/URL and any root summary shown; state limitations clearly in ## Source Notes.';

        $parts = [
            'You are researching a YouTube video the user saved in IdeaTub.',
            $contextLabel,
            '',
            'Video metadata:',
            '- video_id: '.$videoId,
            '- video_url: '.$videoUrl,
            '- transcript_status: '.$transcriptState['status'],
            '- transcript_source: '.$transcriptState['source'],
            '',
            'Video root thought content (may include status line):',
            $rootContent,
            '',
            $transcriptSection,
            '',
            $contract,
        ];

        return trim(implode("\n", $parts));
    }

    /**
     * @param  array{status: string, source: string}  $transcriptState
     */
    private function formatTranscriptSection(bool $transcriptContextAvailable, string $transcriptSectionMarkdown, array $transcriptState): string
    {
        if (! $transcriptContextAvailable || trim($transcriptSectionMarkdown) === '') {
            return "## Transcript context\n\n_No usable transcript text was available at research time (status: {$transcriptState['status']}, source: {$transcriptState['source']}). Proceed with limited source context._";
        }

        return trim($transcriptSectionMarkdown);
    }
}
