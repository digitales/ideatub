{{--
  Expects:
    $formAction (string)
    $commentableType (string)    -- used only for owner form
    $commentableId (string)
    $mode ('owner' | 'guest')
    $disabledMessage (string|null) -- when non-null, shown in place of the form
--}}
@if($disabledMessage)
    <p class="mt-4 text-[12px] text-slate-brand/50">{{ $disabledMessage }}</p>
@else
    <form
        x-data="{}"
        method="POST"
        action="{{ $formAction }}"
        class="mt-4 space-y-3"
        @keydown.meta.enter.prevent="$el.requestSubmit()"
        @keydown.ctrl.enter.prevent="$el.requestSubmit()"
    >
        @csrf
        @if($mode === 'owner')
            <input type="hidden" name="commentable_type" value="{{ $commentableType }}">
            <input type="hidden" name="commentable_id" value="{{ $commentableId }}">
        @else
            <input type="hidden" name="commentable_id" value="{{ $commentableId }}">
            <input type="text" name="website_url" tabindex="-1" autocomplete="off" style="display:none !important" aria-hidden="true">
            <input
                type="text"
                name="author_name"
                required
                maxlength="100"
                placeholder="Your name"
                value="{{ old('author_name') }}"
                class="w-full rounded-xl border border-memory-violet/15 bg-white/80 px-4 py-2 text-sm text-deep-indigo placeholder:text-slate-brand/40"
            >
        @endif
        <textarea
            name="content"
            rows="3"
            maxlength="{{ $mode === 'owner' ? 10000 : 2000 }}"
            required
            placeholder="{{ $mode === 'owner' ? 'Add a comment (markdown supported)' : 'Add a comment' }}"
            class="w-full rounded-xl border border-memory-violet/15 bg-white/80 px-4 py-3 text-sm text-deep-indigo placeholder:text-slate-brand/40 focus:border-memory-violet/40 focus:outline-none focus:ring-2 focus:ring-memory-violet/20"
        >{{ old('content') }}</textarea>
        <div class="flex justify-end">
            <button type="submit" class="text-xs font-medium text-white px-4 py-2 rounded-lg transition-opacity hover:opacity-90" style="background: linear-gradient(135deg, #6D6AF7, #2A8C8C);">
                Post
            </button>
        </div>
        @error('content') <p class="text-[11px] text-rose-600">{{ $message }}</p> @enderror
        @error('author_name') <p class="text-[11px] text-rose-600">{{ $message }}</p> @enderror
    </form>
@endif
