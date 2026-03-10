@extends('layouts.app')

@section('title', $query ? 'Search - IdeaTub' : 'IdeaTub')

@section('content')
<div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
    <h1 class="text-2xl font-bold text-gray-900 mb-6">Ideas</h1>

    @if (session('success'))
        <p class="mb-4 text-green-600">{{ session('success') }}</p>
    @endif

    {{-- Search: GET /?q= --}}
    <form method="GET" action="{{ route('idea.index') }}" class="mb-6">
        <label for="q" class="sr-only">Search</label>
        <input type="search" name="q" id="q" value="{{ old('q', $query ?? '') }}"
               placeholder="Search thoughts…" class="rounded border-gray-300 w-full max-w-md">
        <button type="submit" class="mt-2 px-4 py-2 bg-gray-800 text-white rounded">Search</button>
    </form>

    @if ($query)
        <p class="text-gray-600 mb-4">Results for “{{ e($query) }}”</p>
    @else
        <p class="text-gray-600 mb-4">Recent thoughts</p>
    @endif

    {{-- Capture: POST /thoughts --}}
    <form method="POST" action="{{ route('thoughts.store') }}" class="mb-8">
        @csrf
        <label for="content" class="block text-sm font-medium text-gray-700 mb-1">New thought</label>
        <textarea name="content" id="content" rows="3" required
                  class="rounded border-gray-300 w-full max-w-xl"
                  placeholder="Type a thought…"></textarea>
        @error('content')
            <p class="text-red-600 text-sm mt-1">{{ $message }}</p>
        @enderror
        <button type="submit" class="mt-2 px-4 py-2 bg-indigo-600 text-white rounded">Save</button>
    </form>

    <ul class="space-y-4">
        @forelse ($thoughts as $thought)
            <li class="bg-white rounded shadow-sm p-4">
                <p class="text-gray-900">{{ e($thought->content) }}</p>
                @if (!empty($thought->metadata))
                    <p class="text-sm text-gray-500 mt-2">{{ json_encode($thought->metadata) }}</p>
                @endif
                <p class="text-xs text-gray-400 mt-1">{{ $thought->created_at->diffForHumans() }}</p>
            </li>
        @empty
            <li class="text-gray-500">No thoughts yet. Add one above.</li>
        @endforelse
    </ul>
</div>
@endsection
