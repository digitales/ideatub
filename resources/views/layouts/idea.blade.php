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

    @if (file_exists(public_path('build/manifest.json')))
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    @endif
</head>
<body class="font-sans antialiased min-h-screen" style="background: linear-gradient(135deg, #eef2ff 0%, #f3f0ff 50%, #f0f5ff 100%);">

    {{-- Nav --}}
    <nav
        x-data="{ searching: false, query: '{{ old('q', $query ?? '') }}' }"
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
            <div class="flex items-center gap-3 w-full max-w-lg mx-auto">
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
                    class="flex-1 bg-transparent border-none outline-none text-deep-indigo placeholder-slate-brand/50 text-sm"
                >
                <button type="button" @click="searching = false" class="text-slate-brand/60 hover:text-slate-brand text-xs">
                    Cancel
                </button>
            </div>
        </form>

        {{-- Right nav items --}}
        <div class="flex items-center gap-1" x-show="!searching">
            <a href="#" class="text-[12.5px] font-medium text-slate-brand hover:text-memory-violet hover:bg-memory-violet/8 px-3 py-1.5 rounded-lg transition-colors">
                Example Prompts
            </a>
            <a href="#" class="text-[12.5px] font-medium text-slate-brand hover:text-memory-violet hover:bg-memory-violet/8 px-3 py-1.5 rounded-lg transition-colors">
                Help
            </a>

            <div class="w-px h-4 bg-memory-violet/20 mx-2"></div>

            {{-- Search pill --}}
            <button
                @click="searching = true"
                class="flex items-center gap-1.5 text-xs text-slate-brand bg-white/70 border border-neural-teal/20 rounded-full px-3.5 py-1.5 hover:bg-white hover:border-neural-teal/40 transition-all"
            >
                <svg class="w-3 h-3 text-neural-teal" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                    <circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/>
                </svg>
                Find a memory
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

</body>
</html>
