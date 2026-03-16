<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>@yield('title', config('app.name', 'IdeaTub') . ' — Your thinking space')</title>

    <!-- Inter font -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap" rel="stylesheet">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="font-sans antialiased min-h-screen" style="background: linear-gradient(135deg, #eef2ff 0%, #f3f0ff 50%, #f0f5ff 100%);">

    <div
        x-data="ideaShortcuts()"
        data-query="{{ e(old('q', $query ?? '')) }}"
        data-idea-index-url="{{ e(route('idea.index')) }}"
        @keydown.window="handleKey($event)"
    >
    {{-- Nav --}}
    <nav
        class="sticky top-0 z-20 flex items-center justify-between px-6 md:px-8 py-4 border-b border-memory-violet/10"
        style="background: rgba(238,242,255,0.82); backdrop-filter: blur(12px);"
    >
        {{-- Logo --}}
        <a href="{{ route('idea.index') }}" class="text-xs font-semibold tracking-[0.1em] uppercase text-memory-violet flex-shrink-0">
            IdeaTub
        </a>

        {{-- Search overlay (shown when searching) --}}
        <form
            x-show="searching"
            x-transition
            method="GET"
            action="{{ route('idea.index') }}"
            class="absolute inset-x-0 top-0 bottom-0 flex items-center px-6 md:px-8 z-10"
            style="background: rgba(238,242,255,0.95); backdrop-filter: blur(12px);"
            @click.away="searching = false"
        >
            <div class="flex flex-col gap-2 w-full max-w-lg mx-auto">
                <div class="flex items-center gap-3">
                    <svg class="w-4 h-4 text-neural-teal flex-shrink-0" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                        <circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/>
                    </svg>
                    <input
                    type="search"
                    name="q"
                    x-model="query"
                    x-ref="searchInput"
                    x-init="$watch('searching', v => v && $nextTick(() => $refs.searchInput.focus()))"
                    placeholder="Find a memory…"
                    class="flex-1 bg-white rounded-md px-3 py-2 border border-slate-200/80 outline-none text-deep-indigo placeholder-slate-brand/50 text-sm focus:ring-2 focus:ring-memory-violet/30 focus:border-memory-violet/50"
                >
                <button type="button" @click="searching = false" class="text-slate-brand/60 hover:text-slate-brand text-xs">
                    Cancel
                </button>
                </div>
                <p class="text-[11px] text-slate-brand/50">Escape to close · ⌘K to focus search</p>
            </div>
        </form>

        {{-- Right nav items (visible by default; :class so nav shows before Alpine inits) --}}
        <div class="flex items-center gap-1" :class="{ 'hidden': searching }">
            <a href="{{ route('idea.ideas') }}" class="text-[12.5px] font-medium text-slate-brand hover:text-memory-violet hover:bg-memory-violet/8 px-3 py-1.5 rounded-lg transition-colors">
                Ideas
            </a>
            <a href="{{ route('idea.revisit') }}" class="text-[12.5px] font-medium text-slate-brand hover:text-memory-violet hover:bg-memory-violet/8 px-3 py-1.5 rounded-lg transition-colors">
                Ideas to revisit
            </a>
            <a href="{{ route('idea.stream') }}" class="text-[12.5px] font-medium text-slate-brand hover:text-memory-violet hover:bg-memory-violet/8 px-3 py-1.5 rounded-lg transition-colors">
                Stream
            </a>
            <a href="{{ route('example-prompts') }}" class="text-[12.5px] font-medium text-slate-brand hover:text-memory-violet hover:bg-memory-violet/8 px-3 py-1.5 rounded-lg transition-colors">
                Example Prompts
            </a>
            <a href="{{ route('help') }}" class="text-[12.5px] font-medium text-slate-brand hover:text-memory-violet hover:bg-memory-violet/8 px-3 py-1.5 rounded-lg transition-colors">
                Help
            </a>
            <button type="button" @click="shortcutsOpen = true" class="text-[12.5px] font-medium text-slate-brand hover:text-memory-violet hover:bg-memory-violet/8 px-3 py-1.5 rounded-lg transition-colors">
                Keyboard shortcuts
            </button>

            <div class="w-px h-4 bg-memory-violet/20 mx-2"></div>

            {{-- Search pill --}}
            <button
                type="button"
                @click="searching = true"
                class="flex items-center gap-1.5 text-xs text-slate-brand bg-white/70 border border-neural-teal/20 rounded-full px-3.5 py-1.5 hover:bg-white hover:border-neural-teal/40 transition-all"
            >
                <svg class="w-3 h-3 text-neural-teal" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                    <circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/>
                </svg>
                Find a memory
                <span class="text-[10px] text-slate-brand/50">⌘K</span>
            </button>

            {{-- Avatar / logout --}}
            @auth
                <div x-data="{ open: false }" class="relative ml-1">
                    <button
                        @click="open = !open"
                        class="w-8 h-8 rounded-full text-white text-[11px] font-semibold flex items-center justify-center flex-shrink-0"
                        style="background: linear-gradient(135deg, #6D6AF7, #2A8C8C);"
                    >
                        {{ strtoupper(substr(auth()->user()->name ?? auth()->user()->email, 0, 2)) }}
                    </button>
                    <div
                        x-show="open"
                        x-transition
                        @click.away="open = false"
                        class="absolute right-0 mt-2 w-40 bg-white rounded-xl shadow-lg border border-memory-violet/10 py-1 z-30"
                    >
                        <a href="{{ route('settings.mcp-keys.index') }}" class="block px-4 py-2 text-sm text-slate-brand hover:text-deep-indigo hover:bg-memory-violet/5 transition-colors">
                            MCP key
                        </a>
                        <a href="{{ route('settings.inbound-emails.index') }}" class="block px-4 py-2 text-sm text-slate-brand hover:text-deep-indigo hover:bg-memory-violet/5 transition-colors">
                            Inbound email
                        </a>
                        <a href="{{ route('settings.ideas-revisit.index') }}" class="block px-4 py-2 text-sm text-slate-brand hover:text-deep-indigo hover:bg-memory-violet/5 transition-colors">
                            Ideas to revisit
                        </a>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit" class="w-full text-left px-4 py-2 text-sm text-slate-brand hover:text-deep-indigo hover:bg-memory-violet/5 transition-colors">
                                Log out
                            </button>
                        </form>
                    </div>
                </div>
            @endauth
        </div>
    </nav>

    {{-- Page content --}}
    <main>
        @yield('content')
    </main>

    {{-- Shortcut palette (modal) --}}
    <div
        x-show="shortcutsOpen"
        x-transition:enter="transition ease-out duration-200"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="transition ease-in duration-150"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
        @keydown.escape.window="shortcutsOpen = false"
        @click.away="shortcutsOpen = false"
        class="fixed inset-0 z-40 flex items-center justify-center p-4"
        style="background: rgba(30, 37, 71, 0.4); backdrop-filter: blur(6px);"
        role="dialog"
        aria-modal="true"
        aria-labelledby="shortcuts-modal-title"
        x-ref="shortcutsModal"
        x-effect="shortcutsOpen && $nextTick(() => { const el = $refs.shortcutsModal && $refs.shortcutsModal.querySelector('[autofocus]'); if (el) el.focus(); })"
    >
        <div
            class="rounded-2xl border border-memory-violet/25 bg-white shadow-2xl max-w-md w-full p-6"
            @click.stop
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="opacity-0 scale-95"
            x-transition:enter-end="opacity-100 scale-100"
            x-transition:leave="transition ease-in duration-150"
            x-transition:leave-start="opacity-100 scale-100"
            x-transition:leave-end="opacity-0 scale-95"
        >
            <h2 id="shortcuts-modal-title" class="text-lg font-semibold text-deep-indigo mb-4">Keyboard shortcuts</h2>
            <table class="w-full text-sm text-deep-indigo">
                <tbody class="divide-y divide-memory-violet/10">
                    <tr><td class="py-1.5">Focus capture</td><td class="py-1.5 text-right text-slate-brand font-medium">⌘/ or Ctrl+/</td></tr>
                    <tr><td class="py-1.5">Open search</td><td class="py-1.5 text-right text-slate-brand font-medium">⌘K or Ctrl+K</td></tr>
                    <tr><td class="py-1.5">Move down / up thought</td><td class="py-1.5 text-right text-slate-brand font-medium">j / k</td></tr>
                    <tr><td class="py-1.5">Open reply</td><td class="py-1.5 text-right text-slate-brand font-medium">Enter</td></tr>
                    <tr><td class="py-1.5">Cancel reply / close search</td><td class="py-1.5 text-right text-slate-brand font-medium">Escape</td></tr>
                    <tr><td class="py-1.5">Submit thought</td><td class="py-1.5 text-right text-slate-brand font-medium">⌘+Enter or Ctrl+Enter</td></tr>
                    <tr><td class="py-1.5">Show this list</td><td class="py-1.5 text-right text-slate-brand font-medium">?</td></tr>
                </tbody>
            </table>
            <p class="mt-4 text-[11px] text-slate-brand/50">Press Escape or click outside to close</p>
            <button
                type="button"
                autofocus
                @click="shortcutsOpen = false"
                class="mt-5 w-full text-sm font-medium text-white py-2 rounded-lg transition-opacity hover:opacity-90"
                style="background: linear-gradient(135deg, #6D6AF7, #2A8C8C);"
            >
                Close
            </button>
        </div>
    </div>
    </div>

    @auth
    @php
        $realtimeConfig = [
            'driver' => config('realtime.driver'),
            'reverb_key' => config('broadcasting.connections.reverb.key') ?? null,
            'reverb_host' => config('broadcasting.connections.reverb.options.host') ?? null,
            'reverb_port' => config('broadcasting.connections.reverb.options.port') ?? null,
            'reverb_scheme' => config('broadcasting.connections.reverb.options.scheme') ?? 'https',
            'user_id' => auth()->id(),
            'recent_url' => route('idea.index'),
            'stream_url' => route('idea.stream'),
            'ideas_url' => route('idea.ideas'),
            'realtime_check_url' => route('api.thoughts.realtime-check'),
        ];
    @endphp
    <script>
        window.ideatub = window.ideatub || {};
        window.ideatub.realtime = @json($realtimeConfig);
    </script>
    @endauth

    @stack('scripts')
</body>
</html>
