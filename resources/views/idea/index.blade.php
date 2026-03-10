@extends('layouts.app')

@section('title', 'Ideas')

@section('content')
<div class="max-w-3xl mx-auto px-4 py-8">
    <h1 class="text-2xl font-semibold text-gray-900 mb-6">Ideas</h1>

    <form action="{{ route('thoughts.store') }}" method="POST" class="mb-8">
        @csrf
        <input type="hidden" name="parent_id" value="{{ request('parent_id', '') }}">
        @if (isset($replyingTo) && $replyingTo)
            <p class="text-sm text-gray-600 mb-2">
                Replying to: {{ Str::limit($replyingTo->content, 80) }}
                <a href="{{ route('ideas.index') }}" class="text-indigo-600 hover:underline ml-1">Cancel</a>
            </p>
        @endif
        <label for="content" class="block text-sm font-medium text-gray-700">New thought</label>
        <textarea name="content" id="content" rows="3" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm" required></textarea>
        <button type="submit" class="mt-2 px-4 py-2 bg-indigo-600 text-white rounded-md">Save</button>
    </form>

    @if (session('status'))
        <p class="text-green-600 mb-4">{{ session('status') }}</p>
    @endif

    <h2 class="text-lg font-medium text-gray-900 mb-4">Recent</h2>
    <ul class="space-y-4">
        @forelse ($thoughts as $thought)
            <li class="border border-gray-200 rounded p-4">
                <p class="text-gray-800">{{ Str::limit($thought->content, 200) }}</p>
                <p class="text-sm text-gray-500 mt-1">
                    {{ $thought->created_at->diffForHumans() }}
                    <a href="{{ route('ideas.index', ['parent_id' => $thought->id]) }}" class="text-indigo-600 hover:underline ml-2">Reply</a>
                </p>
            </li>
        @empty
            <li class="text-gray-500">No thoughts yet.</li>
        @endforelse
    </ul>
</div>
@endsection
