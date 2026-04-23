@extends('layouts.idea')

@section('title', 'Import — IdeaTub')

@section('content')
<div class="max-w-3xl mx-auto px-6 py-10"
     x-data="importBatch('{{ $batch->id }}', '{{ route('imports.status', $batch) }}', {{ (int) $batch->file_count }}, {{ (int) data_get($batch->options, 'local_asset_ref_count', 0) }})"
     @if (session('success')) data-flash="{{ e(session('success')) }}" @endif
>
    @if (session('success'))
        <div class="mb-4 rounded-xl bg-neural-teal/10 border border-neural-teal/25 px-4 py-3 text-sm text-neural-teal">
            {{ session('success') }}
        </div>
    @endif

    <header class="mb-6" aria-live="polite" role="status">
        <h1 class="text-xl font-semibold text-deep-indigo">
            @if (data_get($batch->options, 'import_kind') === 'microsite')
                Importing research site — {{ $batch->root_folder_name ?? 'files' }}
            @else
                Importing {{ $batch->root_folder_name ?? 'files' }}
            @endif
        </h1>
        @if (data_get($batch->options, 'import_kind') === 'microsite')
            <p class="text-sm text-slate-brand mt-1">
                Numbered markdown site: <strong>{{ (int) $batch->file_count }} pages</strong> (one thought per file). Chunking is off for this batch.
            </p>
        @endif
        <p class="text-sm text-slate-brand mt-1">
            <span x-text="processedCount"></span> / {{ (int) $batch->file_count }} processed · Status <span x-text="batchStatus"></span>
        </p>
        <div
            x-show="localAssetRefCount &gt; 0"
            x-cloak
            class="mt-2 rounded-lg border border-amber-200/80 bg-amber-50/90 px-3 py-2 text-sm text-amber-950/90"
            role="status"
        >
            <span class="font-medium">Local image references in source (not included in v1):</span>
            <span x-text="localAssetRefCount"></span> — the pages were saved as text only.
        </div>
        <div class="mt-3 w-full h-2 bg-memory-violet/10 rounded" aria-hidden="true">
            <div class="h-2 bg-memory-violet rounded transition-all" :style="`width: ${progressWidth}%`"></div>
        </div>
    </header>

    <ul class="divide-y divide-memory-violet/10" role="list" aria-label="Per-file import status">
        <template x-for="file in files" :key="file.id">
            <li class="py-2 flex items-center justify-between text-sm">
                <span class="flex-1 truncate text-deep-indigo" x-text="file.relative_path"></span>
                <span class="ml-3 text-xs text-slate-brand" x-text="file.status"></span>
            </li>
        </template>
    </ul>

    <div class="mt-6 flex flex-wrap gap-2">
        @if ($batch->project_id)
            <a href="{{ route('projects.show', $batch->project_id) }}"
               class="px-3 py-1.5 rounded-lg bg-memory-violet text-white text-sm">View project</a>
        @endif
        <form method="POST" action="{{ route('imports.cancel', $batch) }}">
            @csrf
            <button type="submit" class="px-3 py-1.5 rounded-lg border border-memory-violet/20 text-sm text-deep-indigo hover:bg-memory-violet/5">Cancel batch</button>
        </form>
    </div>
</div>

@push('scripts')
<script>
function importBatch(batchId, statusUrl, fileCount, initialLocalAssets) {
    return {
        batchStatus: @json($batch->status),
        processedCount: 0,
        fileCount: fileCount,
        localAssetRefCount: typeof initialLocalAssets === 'number' ? initialLocalAssets : 0,
        files: @json($batch->files->map(fn ($f) => [
            'id' => $f->id, 'relative_path' => $f->relative_path, 'status' => $f->status,
        ])->values()),
        get progressWidth() {
            if (!this.fileCount) return 0;
            return Math.min(100, (this.processedCount / this.fileCount) * 100);
        },
        init() {
            this.recompute();
            this.poll();
            if (window.Echo) {
                try {
                    window.Echo.private('import.'+batchId)
                        .listen('.App\\Events\\ImportFileProcessed', (e) => {
                            this.mergeFile(e);
                            this.recompute();
                        })
                        .listen('.App\\Events\\ImportBatchCompleted', (e) => {
                            if (e && e.status) {
                                this.batchStatus = e.status;
                            }
                            this.recompute();
                        });
                } catch (err) { /* ignore */ }
            }
        },
        recompute() {
            const t = (this.files || []).filter(
                f => f.status && f.status !== 'pending' && f.status !== 'processing',
            );
            this.processedCount = t.length;
        },
        async poll() {
            if (this.terminal(this.batchStatus)) return;
            try {
                const r = await fetch(statusUrl, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
                if (r.ok) {
                    const data = await r.json();
                    this.batchStatus = data.batch && data.batch.status ? data.batch.status : this.batchStatus;
                    this.fileCount = data.batch && data.batch.file_count != null ? data.batch.file_count : this.fileCount;
                    if (data.files) {
                        this.files = data.files;
                    }
                    this.processedCount = data.batch && data.batch.processed_count != null
                        ? data.batch.processed_count
                        : (this.files || []).filter(
                            f => f.status && f.status !== 'pending' && f.status !== 'processing',
                        ).length;
                    if (data.batch && data.batch.local_asset_ref_count != null) {
                        this.localAssetRefCount = data.batch.local_asset_ref_count;
                    }
                }
            } catch (e) { /* ignore */ }
            if (!this.terminal(this.batchStatus)) {
                setTimeout(() => this.poll(), 3000);
            }
        },
        terminal(s) {
            if (!s) return false;
            return ['completed','completed_with_failures','failed','cancelled'].indexOf(s) >= 0;
        },
        mergeFile(ev) {
            const id = ev.file_id || (ev && ev.id);
            if (!id) return;
            const i = this.files.findIndex(f => f.id === id);
            if (i >= 0) {
                this.files[i] = { ...this.files[i], status: ev.status };
            }
        }
    };
}
</script>
@endpush
@endsection
