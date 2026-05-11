@extends('layouts.idea')

@section('title', 'Articles — IdeaTub')

@section('content')
<div class="max-w-[700px] mx-auto px-6 pt-16 pb-24">

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

    <h1 class="text-center text-[28px] font-semibold text-deep-indigo leading-snug mb-6">Articles</h1>

    <div class="rounded-2xl border border-memory-violet/20 bg-white/80 backdrop-blur p-4 shadow-[0_4px_24px_rgba(109,106,247,0.08)] mb-6">
        <h2 class="text-[11px] font-semibold tracking-[0.1em] uppercase text-memory-violet/80 mb-3">Capture article</h2>
        <form method="POST" action="{{ route('articles.store') }}" class="flex gap-2">
            @csrf
            <input
                type="url"
                name="url"
                placeholder="Paste article URL..."
                required
                value="{{ old('url') }}"
                class="flex-1 rounded-lg border border-memory-violet/20 bg-white/80 px-3 py-2 text-sm text-deep-indigo placeholder-slate-brand/40 focus:ring-2 focus:ring-memory-violet/30 focus:border-memory-violet/50"
            >
            <button
                type="submit"
                class="inline-flex items-center rounded-lg bg-memory-violet px-4 py-2 text-sm font-medium text-white hover:bg-memory-violet/90 transition-colors"
            >Capture</button>
        </form>
        @error('url')
            <p class="mt-2 text-xs text-red-500">{{ $message }}</p>
        @enderror
    </div>

    @forelse ($articles as $article)
        @php
            $sm = $article->source_metadata ?? [];
            $status = $sm['status'] ?? 'unknown';
            $title = $sm['title'] ?? $article->content;
            $domain = $sm['domain'] ?? '';
            $url = $sm['url'] ?? '';
            $linkCount = $sm['editorial_link_count'] ?? $sm['link_count'] ?? 0;
            $statusColor = match ($status) {
                'complete' => 'bg-neural-teal/15 text-neural-teal',
                'queued', 'scraping', 'links_processing' => 'bg-amber-100 text-amber-700',
                'scraped' => 'bg-blue-100 text-blue-700',
                default => str_contains($status, 'failed') ? 'bg-red-100 text-red-600' : 'bg-slate-100 text-slate-500',
            };
        @endphp
        <div class="rounded-xl border border-memory-violet/10 bg-white/60 p-4 mb-3 hover:bg-white/80 transition-colors">
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0 flex-1">
                    <a href="{{ route('thoughts.show', $article) }}" class="text-sm font-medium text-deep-indigo hover:text-memory-violet truncate block">
                        {{ Str::limit($title, 80) }}
                    </a>
                    <div class="flex items-center gap-2 mt-1 text-xs text-slate-brand/60">
                        @if ($domain)
                            <span>{{ $domain }}</span>
                            <span>&middot;</span>
                        @endif
                        <span>{{ $article->created_at->diffForHumans() }}</span>
                        @if ($linkCount > 0)
                            <span>&middot;</span>
                            <span>{{ $linkCount }} {{ Str::plural('link', $linkCount) }}</span>
                        @endif
                    </div>
                </div>
                <span class="inline-flex shrink-0 items-center rounded-full px-2 py-0.5 text-[10px] font-medium {{ $statusColor }}">
                    {{ str_replace('_', ' ', $status) }}
                </span>
            </div>
        </div>
    @empty
        <p class="text-center text-sm text-slate-brand/50 py-12">No articles captured yet. Paste a URL above to get started.</p>
    @endforelse

    <div class="mt-6">
        {{ $articles->links() }}
    </div>
</div>
@endsection
