<?php

namespace App\Services\Import;

use App\Exceptions\FileImportRejectedException;
use App\Models\ImportBatch;
use App\Models\ImportBatchFile;
use App\Models\Thought;
use App\Services\ProjectMembershipService;
use App\Services\ThoughtCaptureService;
use Illuminate\Support\Facades\Log;

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

    public function __construct(
        private ImportStagingStore $staging,
        private ThoughtCaptureService $capture,
        private ProjectMembershipService $projectMembership,
    ) {}

    public function process(ImportBatchFile $row): void
    {
        $batch = $row->batch;
        $row->update([
            'status' => ImportBatchFile::STATUS_PROCESSING,
            'attempts' => $row->attempts + 1,
        ]);

        try {
            $bytes = $this->staging->readStaged($batch, $row);
            if ($bytes === '' || strlen($bytes) !== $row->size_bytes) {
                throw new FileImportRejectedException('size_mismatch');
            }
            $ext = (string) pathinfo($row->original_filename, PATHINFO_EXTENSION);
            if ($ext === '') {
                $ext = 'md';
            }
            $clean = $this->sanitiseBytes($bytes, $ext);
            $sha = hash('sha256', $clean);
            $row->update(['sha256' => $sha]);

            $existing = Thought::query()
                ->where('user_id', $batch->user_id)
                ->where('content_sha256', $sha)
                ->first();

            if ($existing !== null) {
                $this->linkToProject($batch, $existing);
                $row->update([
                    'status' => ImportBatchFile::STATUS_SKIPPED_DUPLICATE,
                    'thought_id' => $existing->id,
                    'processed_at' => now(),
                ]);
            } else {
                $thought = $this->captureThought($batch, $row, $clean);
                $this->linkToProject($batch, $thought);
                $row->update([
                    'status' => ImportBatchFile::STATUS_DONE,
                    'thought_id' => $thought->id,
                    'processed_at' => now(),
                ]);
            }

            $this->staging->deleteStaged($batch, $row);
        } catch (FileImportRejectedException $e) {
            $row->update([
                'status' => ImportBatchFile::STATUS_FAILED,
                'error_code' => $e->errorCode,
                'error_message' => $e->getMessage(),
                'processed_at' => now(),
            ]);
            Log::warning('import.file.rejected', [
                'batch_id' => $batch->id,
                'file_id' => $row->id,
                'user_id' => $batch->user_id,
                'error_code' => $e->errorCode,
                'size' => $row->size_bytes,
            ]);
        } catch (\Throwable $e) {
            $row->update([
                'status' => ImportBatchFile::STATUS_FAILED,
                'error_code' => 'processing_error',
                'error_message' => mb_substr($e->getMessage(), 0, 1024),
                'processed_at' => now(),
            ]);
            Log::error('import.file.unhandled', [
                'batch_id' => $batch->id,
                'file_id' => $row->id,
                'user_id' => $batch->user_id,
                'message' => $e->getMessage(),
            ]);
        }
    }

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

    private function captureThought(ImportBatch $batch, ImportBatchFile $row, string $content): Thought
    {
        $segments = array_values(array_filter(
            explode('/', (string) dirname($row->relative_path)),
            fn ($s) => $s !== '' && $s !== '.'
        ));
        $folderTags = array_map(fn (string $s) => 'folder:'.mb_strtolower($s), $segments);

        $result = $this->capture->create([
            'content' => $content,
            'user_id' => $batch->user_id,
            'source' => 'upload',
            'source_metadata' => [
                'provenance' => 'upload',
                'untrusted_origin' => true,
                'batch_id' => $batch->id,
                'project' => $batch->root_folder_name,
                'file_path' => $row->relative_path,
                'original_filename' => $row->original_filename,
            ],
            'no_chunking' => $batch->no_chunking,
            'skip_ai_metadata' => $batch->skip_ai_metadata,
            'file_path' => $row->relative_path,
            'project' => $batch->root_folder_name,
            'extra_tags' => $folderTags,
        ]);

        /** @var Thought */
        $thought = $result['thought'] ?? $result['root'];

        return $thought;
    }

    private function linkToProject(ImportBatch $batch, Thought $thought): void
    {
        if ($batch->project_id === null) {
            return;
        }
        $project = $batch->project;
        if ($project === null) {
            return;
        }
        $this->projectMembership->addThought($project, $thought);
    }
}
