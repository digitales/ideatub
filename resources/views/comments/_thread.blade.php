{{--
  Expects:
    $rows (array<array>)
    $formAction (string)
    $commentableType (string|null)
    $commentableId (string)
    $mode ('owner' | 'guest')
    $disabledMessage (string|null)
    $title (string, default 'Comments')
    $showControls (bool, default true)
--}}
@php
    $title = $title ?? 'Comments';
    $showControls = $showControls ?? true;
@endphp
<section class="rounded-2xl border border-memory-violet/20 bg-white/80 backdrop-blur p-6 shadow-[0_4px_24px_rgba(109,106,247,0.08)]">
    <p class="text-[11px] font-semibold tracking-[0.1em] uppercase text-memory-violet/80">{{ $title }}</p>
    @if (count($rows) > 0)
        <ul class="mt-4 space-y-3">
            @foreach ($rows as $row)
                @include('comments._row', ['row' => $row, 'showControls' => $showControls])
            @endforeach
        </ul>
    @else
        <p class="mt-4 text-sm text-slate-brand/50">No comments yet.</p>
    @endif

    @include('comments._form', [
        'formAction' => $formAction,
        'commentableType' => $commentableType ?? 'thought',
        'commentableId' => $commentableId,
        'mode' => $mode,
        'disabledMessage' => $disabledMessage ?? null,
    ])
</section>
