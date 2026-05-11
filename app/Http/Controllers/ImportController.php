<?php

namespace App\Http\Controllers;

use App\Exceptions\FileImportRejectedException;
use App\Http\Requests\BatchImportRequest;
use App\Http\Requests\QuickImportRequest;
use App\Jobs\FinaliseImportBatch;
use App\Jobs\ProcessImportFile;
use App\Jobs\ProcessMicrositeImportBatch;
use App\Models\ImportBatch;
use App\Models\ImportBatchFile;
use App\Models\Project;
use App\Models\Thought;
use App\Services\DemoMode;
use App\Services\Import\FileImportService;
use App\Services\Import\ImportStagingStore;
use App\Services\Import\MicrositeImportDetector;
use App\Services\ThoughtCaptureService;
use App\Support\MarkdownDisplayHelper;
use App\Support\SafeCommonMarkConverter;
use Illuminate\Bus\Batch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Illuminate\View\View;

class ImportController extends Controller
{
    public function quick(
        QuickImportRequest $request,
        DemoMode $demo,
        FileImportService $fileService,
        ThoughtCaptureService $capture,
    ): RedirectResponse {
        if (! config('features.file_upload', false)) {
            abort(404);
        }
        if ($demo->enabled()) {
            abort(403, 'Uploads are disabled in demo mode.');
        }

        $files = $request->file('files', []);
        $created = 0;

        foreach ($files as $i => $file) {
            $ext = mb_strtolower($file->getClientOriginalExtension());
            if ($ext === '') {
                $ext = 'md';
            }
            try {
                $clean = $fileService->sanitiseBytes($file->get(), $ext);
            } catch (FileImportRejectedException $e) {
                return back()->withErrors([
                    "files.{$i}" => 'File rejected: '.$e->errorCode,
                ]);
            }

            $capture->create([
                'content' => $clean,
                'user_id' => (int) $request->user()->id,
                'source' => 'upload',
                'source_metadata' => [
                    'provenance' => 'upload',
                    'untrusted_origin' => true,
                    'original_filename' => $file->getClientOriginalName(),
                    'file_path' => $file->getClientOriginalName(),
                ],
                'file_path' => $file->getClientOriginalName(),
            ]);
            $created++;
        }

        return redirect()
            ->route('idea.index')
            ->with('success', "Imported {$created} file".($created !== 1 ? 's' : '').'.');
    }

    public function batch(
        BatchImportRequest $request,
        DemoMode $demo,
        ImportStagingStore $staging,
    ): RedirectResponse {
        if (! config('features.file_upload', false)) {
            abort(404);
        }
        if ($demo->enabled()) {
            abort(403, 'Uploads are disabled in demo mode.');
        }

        $user = $request->user();
        $files = $request->file('files', []);
        $paths = $request->input('relative_paths', []);
        $title = trim((string) $request->input('project_title'));
        $dedupeMode = (string) $request->input('dedupe_mode');
        $noChunking = filter_var($request->input('no_chunking'), FILTER_VALIDATE_BOOL);
        $skipAi = filter_var($request->input('skip_ai_metadata'), FILTER_VALIDATE_BOOL);

        $project = Project::query()
            ->where('user_id', $user->id)
            ->whereRaw('LOWER(title) = ?', [mb_strtolower($title)])
            ->first();

        if ($project === null || $dedupeMode === 'new') {
            if ($project !== null && $dedupeMode === 'new') {
                $title = $title.' (2)';
            }
            $project = Project::create([
                'user_id' => $user->id,
                'title' => $title,
            ]);
        }

        $totalBytes = (int) array_sum(array_map(fn ($f) => $f->getSize(), $files));
        $pathNorm = (string) str_replace('\\', '/', (string) ($paths[0] ?? ''));
        $segments = array_values(array_filter(explode('/', $pathNorm), fn (string $s) => $s !== ''));
        $source = count($segments) > 1 ? 'upload_folder' : 'upload_multi';
        $rootFolder = count($segments) > 1 ? $segments[0] : $title;

        $isMicrosite = MicrositeImportDetector::shouldUseMicrosite($paths, $files);
        $batchOptions = $isMicrosite ? ['import_kind' => 'microsite'] : null;

        $batch = new ImportBatch;
        $batch->id = (string) Str::uuid();
        $batch->forceFill([
            'user_id' => $user->id,
            'project_id' => $project->id,
            'root_folder_name' => $rootFolder,
            'source' => $source,
            'status' => ImportBatch::STATUS_PROCESSING,
            'file_count' => count($files),
            'total_bytes' => $totalBytes,
            'no_chunking' => $noChunking,
            'skip_ai_metadata' => $skipAi,
            'staging_path' => "imports/{$user->id}/{$batch->id}",
            'options' => $batchOptions,
        ]);
        $batch->save();

        $rows = [];
        foreach ($files as $i => $file) {
            $row = ImportBatchFile::create([
                'import_batch_id' => $batch->id,
                'relative_path' => $paths[$i],
                'original_filename' => $file->getClientOriginalName(),
                'size_bytes' => $file->getSize(),
                'status' => ImportBatchFile::STATUS_PENDING,
            ]);
            $staging->store($file, $batch, $row);
            $rows[] = $row;
        }

        if ($isMicrosite) {
            $jobs = [new ProcessMicrositeImportBatch($batch->id)];
        } else {
            $jobs = array_map(fn (ImportBatchFile $r) => new ProcessImportFile($r->id), $rows);
        }

        $laravelBatch = Bus::batch($jobs)
            ->name('import:'.$batch->id)
            ->finally(function (Batch $b) use ($batch): void {
                if ($b->cancelled()) {
                    return;
                }
                FinaliseImportBatch::dispatch($batch->id);
            })
            ->dispatch();

        $batch->update(['laravel_batch_id' => $laravelBatch->id]);

        return redirect()->route('imports.show', $batch);
    }

    public function show(ImportBatch $batch): View
    {
        if (! config('features.file_upload', false)) {
            abort(404);
        }
        $this->authorize('view', $batch);
        $batch->load(['project', 'files' => fn ($q) => $q->orderBy('relative_path')]);

        return view('imports.show', ['batch' => $batch]);
    }

    public function status(ImportBatch $batch): JsonResponse
    {
        if (! config('features.file_upload', false)) {
            abort(404);
        }
        $this->authorize('view', $batch);
        $batch->load(['files' => fn ($q) => $q->orderBy('relative_path')]);
        $processed = $batch->files->whereNotIn('status', [ImportBatchFile::STATUS_PENDING, ImportBatchFile::STATUS_PROCESSING])->count();

        return response()->json([
            'batch' => [
                'id' => $batch->id,
                'status' => $batch->status,
                'processed_count' => $processed,
                'failed_count' => $batch->failed_count,
                'skipped_count' => $batch->skipped_count,
                'file_count' => $batch->file_count,
                'import_kind' => data_get($batch->options, 'import_kind'),
                'local_asset_ref_count' => (int) data_get($batch->options, 'local_asset_ref_count', 0),
            ],
            'files' => $batch->files->map(fn (ImportBatchFile $f) => [
                'id' => $f->id,
                'relative_path' => $f->relative_path,
                'size_bytes' => $f->size_bytes,
                'status' => $f->status,
                'thought_id' => $f->thought_id,
                'error_code' => $f->error_code,
                'error_message' => $f->error_message,
            ]),
        ]);
    }

    public function cancel(ImportBatch $batch): RedirectResponse
    {
        if (! config('features.file_upload', false)) {
            abort(404);
        }
        $this->authorize('cancel', $batch);

        $batch->files()
            ->where('status', ImportBatchFile::STATUS_PENDING)
            ->update(['status' => ImportBatchFile::STATUS_CANCELLED]);
        $batch->update(['status' => ImportBatch::STATUS_CANCELLED]);

        if ($batch->laravel_batch_id) {
            $b = Bus::findBatch($batch->laravel_batch_id);
            $b?->cancel();
        }

        return back()->with('success', 'Batch cancelled.');
    }

    public function retryFailed(ImportBatch $batch): RedirectResponse
    {
        if (! config('features.file_upload', false)) {
            abort(404);
        }
        $this->authorize('retryFailed', $batch);

        $failed = $batch->files()->where('status', ImportBatchFile::STATUS_FAILED)->get();
        if ($failed->isEmpty()) {
            return back()->with('info', 'No failed files to retry.');
        }

        if (data_get($batch->options, 'import_kind') === 'microsite') {
            $failed->each(
                fn (ImportBatchFile $f) => $f->update([
                    'status' => ImportBatchFile::STATUS_PENDING,
                    'error_code' => null,
                    'error_message' => null,
                ])
            );
            $jobs = [new ProcessMicrositeImportBatch($batch->id)];
        } else {
            foreach ($failed as $f) {
                $f->update(['status' => ImportBatchFile::STATUS_PENDING, 'error_code' => null, 'error_message' => null]);
            }
            $jobs = $failed->map(fn (ImportBatchFile $f) => new ProcessImportFile($f->id))->all();
        }

        $laravelBatch = Bus::batch($jobs)
            ->name('import-retry:'.$batch->id)
            ->finally(function () use ($batch): void {
                FinaliseImportBatch::dispatch($batch->id);
            })
            ->dispatch();

        $batch->update([
            'laravel_batch_id' => $laravelBatch->id,
            'status' => ImportBatch::STATUS_PROCESSING,
        ]);

        return back()->with('success', 'Retrying failed files.');
    }

    public function destroyThoughts(ImportBatch $batch): RedirectResponse
    {
        if (! config('features.file_upload', false)) {
            abort(404);
        }
        $this->authorize('deleteThoughts', $batch);

        $thoughtIds = $batch->files()->whereNotNull('thought_id')->pluck('thought_id');
        Thought::query()->whereIn('id', $thoughtIds)->delete();

        return redirect()
            ->route('imports.show', $batch)
            ->with('success', 'Imported thoughts deleted.');
    }

    public function importMarkdown(
        Request $request,
        Project $project,
        DemoMode $demo,
        FileImportService $fileService,
    ): JsonResponse {
        if (! config('features.file_upload', false)) {
            abort(404);
        }
        if ($demo->enabled()) {
            abort(403, 'Uploads are disabled in demo mode.');
        }
        if ($project->user_id !== $request->user()->id) {
            abort(403, 'You do not own this project.');
        }

        $validated = $request->validate([
            'type' => ['required', 'string', 'in:thought,meeting,research,plan,decision,spec'],
            'files' => ['required', 'array', 'min:1'],
            'files.*.title' => ['required', 'string', 'max:255'],
            'files.*.content' => ['required', 'string', 'max:1048576'],
        ]);

        $imported = [];
        $failed = [];

        foreach ($validated['files'] as $file) {
            try {
                $thought = $fileService->importMarkdownWithMetadata(
                    content: $file['content'],
                    title: $file['title'],
                    type: $validated['type'],
                    project: $project,
                    user: $request->user(),
                );

                $imported[] = [
                    'id' => $thought->id,
                    'title' => $file['title'],
                    'status' => 'success',
                ];
            } catch (\Throwable $e) {
                $failed[] = [
                    'title' => $file['title'],
                    'status' => 'failed',
                    'error' => $e->getMessage(),
                ];
            }
        }

        return response()->json([
            'imported' => $imported,
            'failed' => $failed,
        ]);
    }

    public function previewMarkdown(Request $request): JsonResponse
    {
        if (! config('features.file_upload', false)) {
            abort(404);
        }

        $validated = $request->validate([
            'content' => ['required', 'string', 'max:1048576'],
        ]);

        $cleaned = MarkdownDisplayHelper::stripPreambleForMarkdownDisplay($validated['content']);
        $html = SafeCommonMarkConverter::toHtml($cleaned);

        return response()->json(['html' => $html]);
    }
}
