<?php

namespace App\Services\Import;

use App\Events\ImportFileProcessed;
use App\Models\ImportBatch;
use App\Models\ImportBatchFile;
use App\Models\Project;
use App\Models\Thought;
use App\Services\ProjectMembershipService;
use App\Services\ThoughtCaptureService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MicrositeImportService
{
    public function __construct(
        private FileImportService $fileImport,
        private ThoughtCaptureService $capture,
        private ProjectMembershipService $projectMembership,
        private ImportStagingStore $staging,
        private MicrositeMarkdownLinkRewriter $linkRewriter,
    ) {}

    public function process(ImportBatch $batch): void
    {
        $rows = $batch->files()
            ->orderBy('relative_path')
            ->get();
        if ($rows->isEmpty()) {
            return;
        }

        $relativeInput = $rows
            ->map(fn (ImportBatchFile $f) => ['relative_path' => (string) $f->relative_path])
            ->all();
        $sorted = MicrositeFilename::sortedSiteRowsFromRelativePaths($relativeInput);
        if ($sorted === [] || count($sorted) < $rows->count()) {
            $this->markFilesFailed(
                $rows,
                'microsite_shape',
                'Batch is not a valid microsite file set (every file must be a matching numbered .md page).'
            );

            return;
        }

        $ordered = [];
        foreach ($sorted as $i => $meta) {
            $row = $rows->first(
                fn (ImportBatchFile $f) => (string) $f->relative_path === (string) $meta['relative_path'],
            );
            if ($row === null) {
                $this->markFilesFailed(
                    $rows,
                    'microsite_match',
                    'Could not map sorted microsite paths to import rows.'
                );

                return;
            }
            $ordered[] = [
                'row' => $row,
                'import_order' => $i,
                'segment' => (string) $meta['page_path_segment'],
            ];
        }

        $pathKeyToSegment = [];
        foreach ($ordered as $o) {
            $k = $this->linkRewriter->pathKeyForRelativePath((string) $o['row']->relative_path);
            if ($k === '') {
                $this->markFilesFailed($rows, 'microsite_path', 'Invalid import path in microsite.');

                return;
            }
            $pathKeyToSegment[$k] = (string) $o['segment'];
        }

        $read = [];
        foreach ($ordered as $o) {
            $row = $o['row'];
            try {
                $bytes = $this->staging->readStaged($batch, $row);
                if ($bytes === '' || strlen($bytes) !== $row->size_bytes) {
                    $this->markFilesFailed($rows, 'size_mismatch', 'Staged file size mismatch.');

                    return;
                }
                $ext = (string) pathinfo($row->original_filename, PATHINFO_EXTENSION);
                if ($ext === '') {
                    $ext = 'md';
                }
                $clean = $this->fileImport->sanitiseBytes($bytes, $ext);
                $read[] = array_merge($o, ['clean' => $clean]);
            } catch (\Throwable $e) {
                Log::warning('import.microsite.read', [
                    'batch_id' => $batch->id,
                    'file' => $row->relative_path,
                    'message' => $e->getMessage(),
                ]);
                $this->markFilesFailed(
                    $rows,
                    'microsite_staging',
                    'Could not read or sanitise a file: '.$e->getMessage()
                );

                return;
            }
        }

        $perFileAssets = [];
        $toProcess = [];
        $finalShas = [];
        foreach ($read as $r) {
            $rew = $this->linkRewriter->rewrite(
                (string) $r['clean'],
                (string) $r['row']->relative_path,
                $pathKeyToSegment,
            );
            $perFileAssets[] = (int) $rew['localAssetRefCount'];
            $content = (string) $rew['markdown'];
            $toProcess[] = array_merge($r, ['content' => $content]);
            $finalShas[] = hash('sha256', $content);
        }
        if (count($finalShas) !== count(array_unique($finalShas, SORT_REGULAR))) {
            $this->markFilesFailed(
                $rows,
                'microsite_duplicate',
                'Microsite import requires every page to have different content; two files would hash to the same result.',
            );

            return;
        }
        $userId = (int) $batch->user_id;
        foreach ($finalShas as $sha) {
            if (Thought::query()->where('user_id', $userId)->where('content_sha256', $sha)->exists()) {
                $this->markFilesFailed(
                    $rows,
                    'microsite_duplicate',
                    'Microsite import requires all files to be new; a page matches content you already saved.',
                );

                return;
            }
        }
        $assetCount = $this->linkRewriter->countAllLocalAssetRefsInBatch($perFileAssets);
        if ($assetCount > 0) {
            $opts = (array) ($batch->options ?? []);
            $opts['local_asset_ref_count'] = $assetCount;
            $batch->forceFill(['options' => $opts]);
            $batch->save();
        }

        $src = (string) $batch->source;
        if (! in_array($src, ['upload_folder', 'upload_multi', 'upload'], true)) {
            $src = 'upload';
        }
        $idPlain = str_replace('-', '', (string) $batch->id);
        $planSlug = 'ms-'.strtolower(substr($idPlain, 0, 8));

        $root = null;
        try {
            DB::transaction(function () use ($toProcess, $src, $planSlug, $batch, $userId, &$root, $finalShas) {
                $n = count($toProcess);
                for ($i = 0; $i < $n; $i++) {
                    $item = $toProcess[$i];
                    $row = $item['row'];
                    $isRoot = $i === 0;
                    $parentId = $isRoot ? null : (string) $root?->id;
                    $t = $this->createPageThought(
                        $batch,
                        (string) $item['content'],
                        $row,
                        $userId,
                        (int) $item['import_order'],
                        (string) $item['segment'],
                        $parentId,
                        $isRoot ? null : (string) $root?->id,
                        $src,
                        $planSlug,
                        (bool) $batch->skip_ai_metadata,
                    );
                    if ($isRoot) {
                        $root = $t;
                    }
                    $row->update([
                        'status' => ImportBatchFile::STATUS_DONE,
                        'sha256' => $finalShas[$i],
                        'thought_id' => $t->id,
                        'processed_at' => now(),
                    ]);
                    $p = $this->getBatchProject($batch);
                    if ($p !== null) {
                        $this->projectMembership->addThought($p, $t);
                    }
                }
            });
        } catch (\Throwable $e) {
            Log::error('import.microsite.transaction', [
                'batch_id' => $batch->id,
                'message' => $e->getMessage(),
            ]);
            $this->markFilesFailed(
                $rows,
                'microsite_failed',
                'Microsite import failed: '.mb_substr($e->getMessage(), 0, 200),
            );

            return;
        }

        if ($root !== null) {
            $o = (array) ($batch->options ?? []);
            $o['root_thought_id'] = (string) $root->id;
            if ($assetCount > 0) {
                $o['local_asset_ref_count'] = $assetCount;
            }
            $batch->forceFill(['options' => $o]);
            $batch->save();
        }
        $freshBatch = $batch->fresh() ?? $batch;
        foreach ($toProcess as $item) {
            $f = ImportBatchFile::query()->whereKey($item['row']->id)->first();
            if ($f === null) {
                continue;
            }
            $this->staging->deleteStaged($freshBatch, $f);
            event(new ImportFileProcessed($f->fresh()));
        }
    }

    private function createPageThought(
        ImportBatch $batch,
        string $content,
        ImportBatchFile $row,
        int $userId,
        int $importOrder,
        string $pagePathSegment,
        ?string $parentId,
        ?string $micrositeRootId,
        string $source,
        string $planSlug,
        bool $skipAiMetadata,
    ): Thought {
        $isRoot = $parentId === null;
        $sourceMetadata = array_merge(
            $this->baseSourceMetadata($batch, $row),
            [
                'document_layout' => 'microsite',
                'page_path_segment' => $pagePathSegment,
                'import_order' => $importOrder,
            ],
        );
        if (! $isRoot && $micrositeRootId !== null) {
            $sourceMetadata['microsite_root_id'] = $micrositeRootId;
        }
        $out = $this->capture->create([
            'content' => $content,
            'user_id' => $userId,
            'source' => $source,
            'source_metadata' => $sourceMetadata,
            'parent_id' => $parentId,
            'no_chunking' => true,
            'skip_ai_metadata' => $skipAiMetadata,
            'file_path' => (string) $row->relative_path,
            'project' => (string) $batch->root_folder_name,
            'doc_type' => 'research',
            'plan_slug' => $planSlug,
            'extra_tags' => $this->folderFolderTags($row),
            'idea_metadata' => ['type' => 'research'],
        ]);
        $thought = $out['thought'] ?? $out['root'] ?? null;
        if (! $thought instanceof Thought) {
            throw new \RuntimeException('Expected a thought from ThoughtCaptureService::create.');
        }

        return $thought;
    }

    private function baseSourceMetadata(ImportBatch $batch, ImportBatchFile $row): array
    {
        return [
            'provenance' => 'upload',
            'untrusted_origin' => true,
            'batch_id' => (string) $batch->id,
            'project' => (string) $batch->root_folder_name,
            'file_path' => (string) $row->relative_path,
            'original_filename' => (string) $row->original_filename,
        ];
    }

    /**
     * @return list<string>
     */
    private function folderFolderTags(ImportBatchFile $row): array
    {
        $path = (string) $row->relative_path;
        $dir = (string) dirname($path);
        if ($dir === '' || $dir === '.' || $dir === '\.') {
            return [];
        }
        $segments = array_values(array_filter(
            explode('/', str_replace('\\', '/', $dir)),
            fn ($s) => $s !== '' && $s !== '.',
        ));

        return array_map(fn (string $s) => 'folder:'.mb_strtolower($s), $segments);
    }

    private function getBatchProject(ImportBatch $batch): ?Project
    {
        if ($batch->project_id === null) {
            return null;
        }
        if (! $batch->relationLoaded('project')) {
            $batch->load('project');
        }

        return $batch->project;
    }

    /**
     * @param  Collection<int, ImportBatchFile>  $rows
     */
    private function markFilesFailed($rows, string $code, string $message): void
    {
        foreach ($rows as $f) {
            if ($f->status === ImportBatchFile::STATUS_DONE) {
                continue;
            }
            $f->update([
                'status' => ImportBatchFile::STATUS_FAILED,
                'error_code' => $code,
                'error_message' => mb_substr($message, 0, 1024),
                'processed_at' => now(),
            ]);
        }
    }
}
