@extends('layouts.idea')

@section('content')
<form method="GET" action="{{ route('job_pipeline.achievements.index') }}" class="flex items-end gap-2 mb-4">
    <div>
        <label class="block text-xs font-medium text-gray-500">Filter by tag</label>
        <input type="text" name="tag" value="{{ request('tag') }}" class="border rounded px-2 py-1 text-sm">
    </div>
    <button type="submit" class="px-3 py-1.5 border rounded text-sm">Filter</button>
    @if (request()->filled('tag'))
        <a href="{{ route('job_pipeline.achievements.index') }}" class="px-3 py-1.5 text-sm text-gray-500">Clear</a>
    @endif
</form>

<table class="w-full text-sm">
    <thead>
        <tr class="text-left text-gray-500">
            <th>Tag</th><th>Bullet</th><th>Times used</th><th>Last used</th><th>Status</th><th>Actions</th>
        </tr>
    </thead>
    @foreach ($achievements as $achievement)
        <tbody x-data="{ editing: false }">
            <tr class="border-t align-top">
                <td>{{ $achievement->tag }}</td>
                <td>{{ $achievement->bullet_text }}</td>
                <td>{{ $achievement->times_used }}</td>
                <td>{{ $achievement->last_used_at?->toDateString() ?? '—' }}</td>
                <td>{{ $achievement->retired_at ? 'Retired' : 'Active' }}</td>
                <td class="flex gap-1">
                    <button type="button" @click="editing = !editing" class="px-2 py-1 border rounded text-xs">Edit</button>
                    @unless ($achievement->retired_at)
                        <form method="POST" action="{{ route('job_pipeline.achievements.retire', $achievement) }}">
                            @csrf
                            <button type="submit" class="px-2 py-1 border rounded text-xs">Retire</button>
                        </form>
                    @endunless
                </td>
            </tr>
            <tr x-show="editing" x-cloak class="border-t bg-gray-50">
                <td colspan="6">
                    <form method="POST" action="{{ route('job_pipeline.achievements.update', $achievement) }}" class="space-y-2 py-2">
                        @csrf
                        @method('PATCH')
                        <div>
                            <label class="block text-xs font-medium text-gray-500">Tag</label>
                            <input type="text" name="tag" value="{{ $achievement->tag }}" class="border rounded w-full text-sm px-2 py-1" required>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-500">Bullet text</label>
                            <textarea name="bullet_text" rows="2" class="border rounded w-full text-sm px-2 py-1" required>{{ $achievement->bullet_text }}</textarea>
                        </div>
                        <button type="submit" class="px-3 py-1.5 border rounded text-xs">Save</button>
                    </form>
                </td>
            </tr>
        </tbody>
    @endforeach
</table>

<h2 class="mt-8 font-semibold">Add achievement</h2>
<form method="POST" action="{{ route('job_pipeline.achievements.store') }}" class="mt-2 space-y-2 max-w-lg">
    @csrf
    <div>
        <label class="block text-xs font-medium text-gray-500">Tag</label>
        <input type="text" name="tag" value="{{ old('tag') }}" class="border rounded w-full text-sm px-2 py-1" required>
    </div>
    <div>
        <label class="block text-xs font-medium text-gray-500">Bullet text</label>
        <textarea name="bullet_text" rows="3" class="border rounded w-full text-sm px-2 py-1" required>{{ old('bullet_text') }}</textarea>
    </div>
    <button type="submit" class="px-4 py-2 bg-gray-900 text-white rounded">Add achievement</button>
</form>
@endsection
