@extends('layouts.idea')

@section('title', 'Skills — Settings — IdeaTub')

@section('content')
<div class="max-w-[720px] mx-auto px-6 pt-16 pb-24">
    <h1 class="text-[28px] font-semibold text-deep-indigo leading-snug mb-2">Skills</h1>
    <p class="text-sm text-slate-brand mb-8">Configure research and meeting processing presets for your account. Only you can see and edit these skills.</p>

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

    <div class="rounded-2xl border border-memory-violet/20 bg-white/80 backdrop-blur p-6 shadow-[0_4px_24px_rgba(109,106,247,0.08)] mb-6">
        <h2 class="text-lg font-semibold text-deep-indigo mb-3">Automation</h2>
        <p class="text-sm text-slate-brand mb-4">Global toggles: when enabled, IdeaTub may start processing automatically when your defaults and per-skill settings allow it.</p>
        <form method="POST" action="{{ route('settings.skills.preferences') }}" class="space-y-4">
            @csrf
            @method('PUT')
            <input type="hidden" name="research_auto_run_enabled" value="0" />
            <input type="hidden" name="meeting_auto_run_enabled" value="0" />
            <label class="flex items-center gap-2 text-sm text-deep-indigo">
                <input
                    type="checkbox"
                    name="research_auto_run_enabled"
                    value="1"
                    class="rounded border-memory-violet/30"
                    @checked(old('research_auto_run_enabled', ($researchAutoRunEnabled ? '1' : '0')) === '1' || old('research_auto_run_enabled') === true)
                />
                Enable research auto-run
            </label>
            <label class="flex items-center gap-2 text-sm text-deep-indigo">
                <input
                    type="checkbox"
                    name="meeting_auto_run_enabled"
                    value="1"
                    class="rounded border-memory-violet/30"
                    @checked(old('meeting_auto_run_enabled', ($meetingAutoRunEnabled ? '1' : '0')) === '1' || old('meeting_auto_run_enabled') === true)
                />
                Enable meeting auto-run
            </label>
            <button
                type="submit"
                class="text-xs font-medium text-white px-4 py-2 rounded-lg transition-opacity hover:opacity-90"
                style="background: linear-gradient(135deg, #6D6AF7, #2A8C8C);"
            >
                Save preferences
            </button>
        </form>
        @error('research_auto_run_enabled')
            <p class="mt-2 text-xs text-red-500">{{ $message }}</p>
        @enderror
        @error('meeting_auto_run_enabled')
            <p class="mt-2 text-xs text-red-500">{{ $message }}</p>
        @enderror
    </div>

    <div id="research-skills" class="rounded-2xl border border-memory-violet/20 bg-white/80 backdrop-blur p-6 shadow-[0_4px_24px_rgba(109,106,247,0.08)] mb-6 scroll-mt-8">
        <div class="flex flex-wrap items-center justify-between gap-4 mb-4">
            <h2 class="text-lg font-semibold text-deep-indigo">Research skills</h2>
            <a
                href="{{ route('settings.skills.research.create') }}"
                class="text-xs font-medium text-white px-4 py-2 rounded-lg transition-opacity hover:opacity-90"
                style="background: linear-gradient(135deg, #6D6AF7, #2A8C8C);"
            >
                New research skill
            </a>
        </div>

        @if ($researchSkills->isEmpty())
            <p class="text-sm text-slate-brand">No research skills yet. Create one to customize how research runs.</p>
        @else
            <ul class="space-y-4">
                @foreach ($researchSkills as $skill)
                    <li class="rounded-xl border border-memory-violet/10 bg-white/60 p-4">
                        <div class="flex flex-wrap items-start justify-between gap-4">
                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-medium text-deep-indigo">{{ $skill->name }}</p>
                                @if ($skill->description)
                                    <p class="text-xs text-slate-brand mt-1">{{ $skill->description }}</p>
                                @endif
                                <p class="text-[11px] text-slate-brand/70 mt-2">
                                    Workflow: quick brief
                                    @if ($skill->latestVersion)
                                        · Intensity: {{ $skill->latestVersion->intensity }}
                                    @endif
                                    @if ($skill->is_default)
                                        <span class="ml-1 text-memory-violet font-semibold">· Default</span>
                                    @endif
                                    @if (! $skill->is_active)
                                        <span class="ml-1 text-amber-700">· Inactive</span>
                                    @endif
                                </p>
                            </div>
                            <div class="flex flex-wrap items-center gap-2">
                                @if (! $skill->is_default)
                                    <form method="POST" action="{{ route('settings.skills.research.default', $skill) }}" class="inline">
                                        @csrf
                                        <button
                                            type="submit"
                                            class="text-xs font-medium text-memory-violet hover:underline"
                                        >
                                            Set default
                                        </button>
                                    </form>
                                @endif
                                <a
                                    href="{{ route('settings.skills.research.edit', $skill) }}"
                                    class="text-xs font-medium text-white px-3 py-1.5 rounded-lg transition-opacity hover:opacity-90"
                                    style="background: linear-gradient(135deg, #6D6AF7, #2A8C8C);"
                                >
                                    Edit
                                </a>
                            </div>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>

    <div id="meeting-skills" class="rounded-2xl border border-memory-violet/20 bg-white/80 backdrop-blur p-6 shadow-[0_4px_24px_rgba(109,106,247,0.08)] scroll-mt-8">
        <div class="flex flex-wrap items-center justify-between gap-4 mb-4">
            <h2 class="text-lg font-semibold text-deep-indigo">Meeting skills</h2>
            <a
                href="{{ route('settings.skills.meeting.create') }}"
                class="text-xs font-medium text-white px-4 py-2 rounded-lg transition-opacity hover:opacity-90"
                style="background: linear-gradient(135deg, #6D6AF7, #2A8C8C);"
            >
                New meeting skill
            </a>
        </div>

        @if ($meetingSkills->isEmpty())
            <p class="text-sm text-slate-brand">No meeting skills yet. Create one to customize summaries and categories for meeting notes.</p>
        @else
            <ul class="space-y-4">
                @foreach ($meetingSkills as $skill)
                    <li class="rounded-xl border border-memory-violet/10 bg-white/60 p-4">
                        <div class="flex flex-wrap items-start justify-between gap-4">
                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-medium text-deep-indigo">{{ $skill->name }}</p>
                                @if ($skill->description)
                                    <p class="text-xs text-slate-brand mt-1">{{ $skill->description }}</p>
                                @endif
                                <p class="text-[11px] text-slate-brand/70 mt-2">
                                    Workflow: meeting brief
                                    @if ($skill->latestVersion)
                                        · Intensity: {{ $skill->latestVersion->intensity }}
                                    @endif
                                    @if ($skill->is_default)
                                        <span class="ml-1 text-memory-violet font-semibold">· Default</span>
                                    @endif
                                    @if (! $skill->is_active)
                                        <span class="ml-1 text-amber-700">· Inactive</span>
                                    @endif
                                </p>
                            </div>
                            <div class="flex flex-wrap items-center gap-2">
                                @if (! $skill->is_default)
                                    <form method="POST" action="{{ route('settings.skills.meeting.default', $skill) }}" class="inline">
                                        @csrf
                                        <button
                                            type="submit"
                                            class="text-xs font-medium text-memory-violet hover:underline"
                                        >
                                            Set default
                                        </button>
                                    </form>
                                @endif
                                <a
                                    href="{{ route('settings.skills.meeting.edit', $skill) }}"
                                    class="text-xs font-medium text-white px-3 py-1.5 rounded-lg transition-opacity hover:opacity-90"
                                    style="background: linear-gradient(135deg, #6D6AF7, #2A8C8C);"
                                >
                                    Edit
                                </a>
                            </div>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</div>
@endsection
