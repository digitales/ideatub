{{--
  Expects:
    $row (array from ResearchCommentsPresenter::row())
  Optional:
    $showControls (bool, default true)
--}}
@php($showControls = $showControls ?? true)
<li id="comment-{{ $row['id'] }}" class="rounded-xl border border-memory-violet/15 bg-white/70 px-4 py-3">
    <div class="flex items-baseline justify-between gap-3">
        <p class="text-[12px] font-semibold text-deep-indigo">{{ $row['author_name'] }}</p>
        <p class="text-[10px] text-slate-brand/40">
            {{ $row['created_at_human'] }}@if($row['updated_label']) {{ $row['updated_label'] }}@endif
        </p>
    </div>
    <div class="mt-2 prose prose-sm max-w-none text-[13px] text-slate-brand">{!! $row['content_html'] !!}</div>
    @if($showControls && $row['can_delete'])
        <div class="mt-2 flex gap-2">
            <form method="POST" action="{{ route('comments.destroy', $row['id']) }}">
                @csrf
                @method('DELETE')
                <button type="submit" class="text-[11px] text-rose-600 hover:underline">Delete</button>
            </form>
        </div>
    @endif
</li>
