<x-mail::message>
# Import completed

@if ($isMicrosite)
Research site: **{{ (int) $batch->file_count }}** page files → **{{ $batch->processed_count }}** research pages created @if($batch->failed_count > 0) ({{ $batch->failed_count }} failed) @endif. @if($batch->localAssetRefCount() > 0) The source had **{{ $batch->localAssetRefCount() }}** local image references; images are not included in v1. @endif @if($batch->skipped_count > 0) {{ $batch->skipped_count }} skipped as duplicates. @endif
@else
Imported **{{ $batch->root_folder_name ?? 'your files' }}** — {{ $batch->processed_count }} thoughts created, {{ $batch->failed_count }} failed, {{ $batch->skipped_count }} skipped as duplicates.
@endif

@if ($projectUrl)
<x-mail::button :url="$projectUrl">
View project
</x-mail::button>
@endif

<x-mail::button :url="$importUrl">
View import details
</x-mail::button>

@if ($failedFiles->count() > 0)
## Failed files

@foreach ($failedFiles as $f)
- **{{ $f->relative_path }}** — {{ $f->error_code }}{{ $f->error_message ? ': '.$f->error_message : '' }}
@endforeach
@endif

Thanks,
{{ config('app.name') }}
</x-mail::message>
