@extends('layouts.idea')

@section('title', 'Inbox — IdeaTub')

@section('content')
<div class="max-w-4xl mx-auto px-6 pt-16 pb-24">
    @if (session('success'))
        <div class="mb-6 rounded-xl bg-neural-teal/10 border border-neural-teal/25 px-4 py-3 text-sm text-neural-teal">
            {{ session('success') }}
        </div>
    @endif
    @if (session('error'))
        <div class="mb-6 rounded-xl bg-red-50 border border-red-200 px-4 py-3 text-sm text-red-600">
            {{ session('error') }}
        </div>
    @endif

    <div class="mb-8">
        <h1 class="text-[28px] font-semibold text-deep-indigo leading-snug">Inbox</h1>
        <p class="mt-2 text-sm text-slate-brand">Agent-generated prompts that need triage.</p>
        @if (config('services.email_sender_policy.enabled'))
            <a href="{{ route('settings.email-sender-rules.index') }}" class="mt-2 inline-block text-xs text-neural-teal hover:underline">Manage sender rules →</a>
        @endif
    </div>

    @if ($items->isEmpty())
        <div class="rounded-2xl border border-memory-violet/20 bg-white/80 p-8 text-sm text-slate-brand shadow-[0_4px_24px_rgba(109,106,247,0.08)]">
            No inbox items right now.
        </div>
    @else
        <div class="space-y-4">
            @foreach ($items as $item)
                <article class="rounded-2xl border border-memory-violet/20 bg-white/90 p-5 shadow-[0_4px_24px_rgba(109,106,247,0.08)]">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <p class="text-[11px] font-semibold uppercase tracking-[0.1em] text-memory-violet/80">{{ str_replace('_', ' ', $item->generator_type) }}</p>
                            <h2 class="mt-1 text-lg font-semibold text-deep-indigo">{{ $item->title }}</h2>
                        </div>
                        <p class="text-xs text-slate-brand/60">{{ $item->generated_at?->diffForHumans() }}</p>
                    </div>

                    <div class="prose prose-sm mt-3 max-w-none text-slate-brand prose-headings:text-deep-indigo prose-p:text-slate-brand prose-strong:text-deep-indigo prose-li:text-slate-brand">
                        {!! \Illuminate\Support\Str::markdown($item->body ?? '', [
                            'html_input' => 'strip',
                            'allow_unsafe_links' => false,
                        ]) !!}
                    </div>

                    @if (($item->generator_type ?? '') === 'email_sender_review')
                        <div class="mt-4 flex flex-wrap gap-2">
                            <form method="POST" action="{{ route('inbox.email-review.action', $item) }}">
                                @csrf
                                <input type="hidden" name="action" value="allow">
                                <button type="submit" class="rounded-lg bg-neural-teal px-3 py-1.5 text-xs font-medium text-white">Allow sender</button>
                            </form>

                            <form method="POST" action="{{ route('inbox.email-review.action', $item) }}">
                                @csrf
                                <input type="hidden" name="action" value="ignore">
                                <button type="submit" class="rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-medium text-slate-brand">Ignore sender</button>
                            </form>

                            <form method="POST" action="{{ route('inbox.email-review.action', $item) }}">
                                @csrf
                                <input type="hidden" name="action" value="extra_process">
                                <button type="submit" class="rounded-lg border border-memory-violet/20 px-3 py-1.5 text-xs font-medium text-memory-violet">Extra process sender</button>
                            </form>

                            <form method="POST" action="{{ route('inbox.email-review.action', $item) }}">
                                @csrf
                                <input type="hidden" name="action" value="save_thought">
                                <button type="submit" class="rounded-lg border border-memory-violet/20 px-3 py-1.5 text-xs font-medium text-memory-violet">Save as thought</button>
                            </form>
                        </div>
                    @else
                        <div class="mt-4 flex flex-wrap gap-2">
                            <form method="POST" action="{{ route('inbox.done', $item) }}">
                                @csrf
                                <button type="submit" class="rounded-lg bg-neural-teal px-3 py-1.5 text-xs font-medium text-white">Done</button>
                            </form>

                            <form method="POST" action="{{ route('inbox.snooze', $item) }}">
                                @csrf
                                <input type="hidden" name="preset" value="tomorrow">
                                <button type="submit" class="rounded-lg border border-memory-violet/20 px-3 py-1.5 text-xs font-medium text-slate-brand">Tomorrow</button>
                            </form>

                            <form method="POST" action="{{ route('inbox.snooze', $item) }}">
                                @csrf
                                <input type="hidden" name="preset" value="next_week">
                                <button type="submit" class="rounded-lg border border-memory-violet/20 px-3 py-1.5 text-xs font-medium text-slate-brand">Next week</button>
                            </form>

                            <form method="POST" action="{{ route('inbox.save-thought', $item) }}">
                                @csrf
                                <button type="submit" class="rounded-lg border border-memory-violet/20 px-3 py-1.5 text-xs font-medium text-memory-violet">Save as thought</button>
                            </form>
                        </div>
                    @endif
                </article>
            @endforeach
        </div>

        <div class="mt-8">
            {{ $items->links() }}
        </div>
    @endif
</div>
@endsection
