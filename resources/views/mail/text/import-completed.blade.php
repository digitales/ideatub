Import completed
================

Imported "{{ $batch->root_folder_name ?? 'your files' }}"
- {{ $batch->processed_count }} thoughts created
- {{ $batch->failed_count }} failed
- {{ $batch->skipped_count }} skipped as duplicates

@if ($projectUrl)
Project: {{ $projectUrl }}
@endif
Import details: {{ $importUrl }}

@if ($failedFiles->count() > 0)
Failed files:
@foreach ($failedFiles as $f)
- {{ $f->relative_path }} — {{ $f->error_code }}
@endforeach
@endif
