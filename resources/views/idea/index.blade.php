@extends('layouts.app')

@section('title', $query ? 'Search - IdeaTub' : 'IdeaTub')

@section('content')
<div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
    <h1 class="text-2xl font-bold text-gray-900 mb-8">Ideas</h1>

    @if (session('success'))
        <div class="mb-6 rounded-lg bg-green-50 border border-green-200 px-4 py-3 text-green-700" role="alert">
            {{ session('success') }}
        </div>
    @endif
    @if (session('error'))
        <div class="mb-6 rounded-lg bg-red-50 border border-red-200 px-4 py-3 text-red-700" role="alert">
            {{ session('error') }}
        </div>
    @endif

    {{-- Search: GET /?q= --}}
    <section class="mb-8" aria-labelledby="search-heading">
        <h2 id="search-heading" class="text-sm font-semibold text-gray-500 uppercase tracking-wider mb-3">Search</h2>
        <form method="GET" action="{{ route('idea.index') }}" class="flex flex-wrap items-end gap-3">
            <label for="q" class="sr-only">Search thoughts</label>
            <input type="search" name="q" id="q" value="{{ old('q', $query ?? '') }}" maxlength="2000"
                   placeholder="Search thoughts…"
                   class="flex-1 min-w-[200px] max-w-md rounded-lg border border-gray-300 px-4 py-2.5 text-gray-900 placeholder-gray-500 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500 focus:ring-opacity-50 shadow-sm">
            <button type="submit"
                    class="inline-flex items-center px-4 py-2.5 rounded-lg bg-gray-800 text-white text-sm font-medium hover:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-gray-800 shadow-sm">
                Search
            </button>
        </form>
    </section>

    {{-- Capture: POST /thoughts --}}
    <section class="mb-10" aria-labelledby="capture-heading">
        <h2 id="capture-heading" class="text-sm font-semibold text-gray-500 uppercase tracking-wider mb-3">Capture</h2>
        <form method="POST" action="{{ route('thoughts.store') }}" class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
            @csrf
            <label for="content" class="block text-sm font-medium text-gray-700 mb-2">New thought</label>
            <textarea name="content" id="content" rows="3" required
                      class="block w-full rounded-lg border border-gray-300 px-4 py-3 text-gray-900 placeholder-gray-500 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500 focus:ring-opacity-50 shadow-sm"
                      placeholder="Type a thought…">{{ old('content') }}</textarea>
            @error('content')
                <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
            @enderror
            <button type="submit"
                    class="mt-3 inline-flex items-center px-4 py-2.5 rounded-lg bg-indigo-600 text-white text-sm font-medium hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 shadow-sm">
                Save
            </button>
        </form>
    </section>

    {{-- Recent / Search results --}}
    <section aria-labelledby="list-heading">
        <h2 id="list-heading" class="text-sm font-semibold text-gray-500 uppercase tracking-wider mb-4">
            @if ($query)
                Search results for “{{ e($query) }}”
            @else
                Recent thoughts
            @endif
        </h2>
        <ul class="space-y-3">
            @forelse ($thoughts as $thought)
                <li class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
                    <p class="text-gray-900">{{ e($thought->content) }}</p>
                    @if (!empty($thought->metadata))
                        <p class="mt-2 text-sm text-gray-500">{{ json_encode($thought->metadata) }}</p>
                    @endif
                    <p class="mt-2 text-xs text-gray-400">{{ $thought->created_at->diffForHumans() }}</p>
                </li>
            @empty
                <li class="rounded-lg border border-gray-200 bg-gray-50 px-4 py-6 text-center text-gray-500">
                    @if ($query)
                        No thoughts match your search. Try different words or add a new thought above.
                    @else
                        No thoughts yet. Add one above.
                    @endif
                </li>
            @endforelse
        </ul>
    </section>
</div>
@endsection
