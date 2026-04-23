<x-mail::message>
# Import completed

Imported **{{ $batch->root_folder_name ?? 'your files' }}** — {{ $batch->processed_count }} thoughts created, {{ $batch->failed_count }} failed, {{ $batch->skipped_count }} skipped as duplicates.

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
