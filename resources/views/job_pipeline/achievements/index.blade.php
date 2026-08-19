@extends('layouts.idea')

@section('title', 'Achievements — IdeaTub')

@section('content')
<div class="max-w-7xl mx-auto px-6 pt-10 pb-20 w-full">
    @if (session('success'))
        <div class="mb-6 rounded-2xl bg-neural-teal/10 px-4 py-3 text-sm text-neural-teal ring-1 ring-neural-teal/20">
            {{ session('success') }}
        </div>
    @endif

    <header class="mb-8">
        <p class="text-[11px] font-semibold tracking-[0.14em] uppercase text-memory-violet/90 mb-2">Job pipeline</p>
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div class="min-w-0">
                <h1 class="text-3xl font-semibold tracking-tight text-deep-indigo">Achievements</h1>
                <p class="mt-1.5 text-sm text-slate-brand max-w-[52ch]">Reusable CV bullets, tagged and queried whenever a document needs assembling.</p>
            </div>
            <a href="{{ route('job_pipeline.applications.index') }}" class="ideatub-btn-secondary shrink-0">Applications</a>
        </div>
    </header>

    <form method="GET" action="{{ route('job_pipeline.achievements.index') }}" class="flex items-end gap-2 mb-6">
        <div>
            <label for="tag-filter" class="block text-xs font-medium text-slate-brand/70 mb-1">Filter by tag</label>
            <input id="tag-filter" type="text" name="tag" value="{{ request('tag') }}" class="ideatub-input">
        </div>
        <button type="submit" class="ideatub-btn-secondary">Filter</button>
        @if (request()->filled('tag'))
            <a href="{{ route('job_pipeline.achievements.index') }}" class="ideatub-btn-secondary-sm">Clear</a>
        @endif
    </form>

    @if ($achievements->isEmpty())
        <div class="ideatub-surface-muted px-6 py-12 text-center mb-10">
            <p class="text-sm text-slate-brand/70 max-w-sm mx-auto">
                @if (request()->filled('tag'))
                    No achievements tagged "{{ request('tag') }}".
                @else
                    No achievements yet. Add one below to start building your CV bank.
                @endif
            </p>
        </div>
    @else
        <div class="-mx-6 -my-2 overflow-x-auto px-6 py-2 mb-10">
            <div class="inline-block min-w-full align-middle">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-slate-brand/60">
                            <th class="pb-2 pr-4 font-medium whitespace-nowrap">Tag</th>
                            <th class="pb-2 pr-4 font-medium whitespace-nowrap w-full">Bullet</th>
                            <th class="pb-2 pr-4 font-medium whitespace-nowrap">Times used</th>
                            <th class="pb-2 pr-4 font-medium whitespace-nowrap">Last used</th>
                            <th class="pb-2 pr-4 font-medium whitespace-nowrap">Status</th>
                            <th class="pb-2 font-medium whitespace-nowrap">Actions</th>
                        </tr>
                    </thead>
                    @foreach ($achievements as $achievement)
                        <tbody x-data="{ editing: false }" class="divide-y divide-deep-indigo/[0.05]">
                            <tr>
                                <td class="py-3 pr-4 align-top whitespace-nowrap">
                                    <span class="inline-flex items-center rounded-full border border-memory-violet/20 bg-memory-violet/10 px-2 py-0.5 text-xs font-medium text-memory-violet">
                                        {{ $achievement->tag }}
                                    </span>
                                </td>
                                <td class="py-3 pr-4 align-top text-slate-brand min-w-64">{{ $achievement->bullet_text }}</td>
                                <td class="py-3 pr-4 align-top tabular-nums text-slate-brand whitespace-nowrap">{{ $achievement->times_used }}</td>
                                <td class="py-3 pr-4 align-top text-slate-brand/70 whitespace-nowrap">{{ $achievement->last_used_at?->toDateString() ?? '—' }}</td>
                                <td class="py-3 pr-4 align-top whitespace-nowrap">
                                    @if ($achievement->retired_at)
                                        <span class="inline-flex items-center rounded-full border border-slate-200 bg-slate-50 px-2 py-0.5 text-xs font-medium text-slate-500 dark:border-slate-600/40 dark:bg-slate-900/60 dark:text-slate-300">Retired</span>
                                    @else
                                        <span class="inline-flex items-center rounded-full border border-neural-teal/30 bg-neural-teal/15 px-2 py-0.5 text-xs font-medium text-neural-teal">Active</span>
                                    @endif
                                </td>
                                <td class="py-3 align-top whitespace-nowrap">
                                    <div class="flex flex-wrap gap-1.5">
                                        <button type="button" @click="editing = !editing" class="ideatub-btn-secondary-sm">Edit</button>
                                        @unless ($achievement->retired_at)
                                            <form method="POST" action="{{ route('job_pipeline.achievements.retire', $achievement) }}">
                                                @csrf
                                                <button type="submit" class="ideatub-btn-secondary-sm">Retire</button>
                                            </form>
                                        @endunless
                                    </div>
                                </td>
                            </tr>
                            <tr x-show="editing" x-cloak>
                                <td colspan="6" class="pb-4">
                                    <form method="POST" action="{{ route('job_pipeline.achievements.update', $achievement) }}" class="ideatub-well space-y-3 p-4">
                                        @csrf
                                        @method('PATCH')
                                        <div>
                                            <label for="tag-{{ $achievement->id }}" class="block text-xs font-medium text-slate-brand/70 mb-1">Tag</label>
                                            <input id="tag-{{ $achievement->id }}" type="text" name="tag" value="{{ $achievement->tag }}" class="ideatub-input w-full" required>
                                        </div>
                                        <div>
                                            <label for="bullet_text-{{ $achievement->id }}" class="block text-xs font-medium text-slate-brand/70 mb-1">Bullet text</label>
                                            <textarea id="bullet_text-{{ $achievement->id }}" name="bullet_text" rows="2" class="ideatub-input w-full" required>{{ $achievement->bullet_text }}</textarea>
                                        </div>
                                        <button type="submit" class="ideatub-btn-secondary-sm">Save</button>
                                    </form>
                                </td>
                            </tr>
                        </tbody>
                    @endforeach
                </table>
            </div>
        </div>
    @endif

    <section class="max-w-lg">
        <h2 class="text-sm font-semibold text-deep-indigo mb-3">Add achievement</h2>
        <form method="POST" action="{{ route('job_pipeline.achievements.store') }}" class="space-y-3">
            @csrf
            <div>
                <label for="new-tag" class="block text-xs font-medium text-slate-brand/70 mb-1">Tag</label>
                <input id="new-tag" type="text" name="tag" value="{{ old('tag') }}" class="ideatub-input w-full" required>
            </div>
            <div>
                <label for="new-bullet" class="block text-xs font-medium text-slate-brand/70 mb-1">Bullet text</label>
                <textarea id="new-bullet" name="bullet_text" rows="3" class="ideatub-input w-full" required>{{ old('bullet_text') }}</textarea>
            </div>
            <button type="submit" class="ideatub-btn-primary gap-2">Add achievement</button>
        </form>
    </section>
</div>
@endsection
