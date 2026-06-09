<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-appearance="{{ $appearance ?? 'system' }}" @class(['dark' => $appearanceEffectiveDark ?? false])>

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @auth
        <meta name="appearance-store-url" content="{{ route('settings.appearance.store') }}">
    @endauth

    <title>@yield('title', config('app.name', 'IdeaTub') . ' — Your thinking space')</title>

    <!-- Inter font -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap" rel="stylesheet">

    @include('layouts.partials.appearance-head')

    @vite(['resources/css/app.css', 'resources/js/app.js'])
        <style>
        [x-cloak] {
            display: none !important
        }
        </style>
</head>

<body class="ideatub-app ideatub-shell font-sans antialiased">

    <div x-data="ideaShortcuts()" data-query="{{e(old('q', $query ?? ''))}}" data-idea-index-url="{{e(route('idea.index'))}}" data-is-home-page="{{ request()->routeIs('idea.index') ? '1' : '0' }}" @keydown.window="handleKey($event)" @ideatub-open-shortcuts.window="shortcutsOpen = true">
        @php
            $inboxCount = (int) ($inboxActionableCount ?? 0);
        @endphp

        {{-- Nav --}}
        <nav class="ideatub-nav relative">
            {{-- Logo --}}
            <a href="{{route('idea.index')}}" class="text-xs font-semibold tracking-[0.1em] uppercase text-memory-violet flex-shrink-0">
                IdeaTub
            </a>

            {{-- Search overlay (shown when searching) --}}
            <form x-show="searching" x-transition method="GET" action="{{route('idea.index')}}" class="ideatub-nav-search-overlay" @click.away="searching = false">
                <div class="flex flex-col w-full max-w-lg mx-auto gap-4">
                    <div class="flex items-center gap-3">
                        <svg class="w-4 h-4 text-neural-teal flex-shrink-0" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                            <circle cx="11" cy="11" r="8" />
                            <path d="m21 21-4.35-4.35" />
                        </svg>
                        <input type="search" name="q" x-model="query" x-ref="searchInput" x-init="$watch('searching', v => v && $nextTick(() => $refs.searchInput.focus()))" placeholder="Find a memory…" class="ideatub-input flex-1">
                        <button type="button" @click="searching = false" class="text-slate-brand/60 hover:text-slate-brand text-xs">
                            Cancel
                        </button>
                    </div>
                    <p class="ideatub-nav-search-hint text-[11px] text-slate-brand/50 mt-0.5">Escape to close · ⌘K to focus search</p>
                </div>
            </form>

            @php
                $navLinkClass = 'ideatub-nav-link';
                $accountMenuLabel = 'Account menu';

                if ($inboxCount > 99) {
                    $accountMenuLabel = 'Account menu, inbox has more than 99 actionable items';
                } elseif ($inboxCount > 0) {
                    $accountMenuLabel =
                        'Account menu, inbox has ' .
                        $inboxCount .
                        ' actionable ' .
                        ($inboxCount === 1 ? 'item' : 'items');
                }
            @endphp

            {{-- Desktop primary nav (focused cluster; no wrap — mobile uses overflow menu) --}}
            <div data-testid="primary-nav" class="hidden lg:flex flex-1 items-center justify-center gap-1 min-w-0" x-show="!searching">
                <a href="{{route('idea.ideas')}}" class="{{$navLinkClass}}">
                    Ideas
                </a>
                <a href="{{route('idea.stream')}}" class="{{$navLinkClass}}">
                    Stream
                </a>
                <a href="{{route('projects.index')}}" class="{{$navLinkClass}}">
                    Projects
                </a>
                @if (config('features.working_memory_ui'))
                    <a href="{{route('memory.show')}}" class="{{$navLinkClass}}">
                        Memory
                    </a>
                    <a href="{{route('memory.scopes.index')}}" class="{{$navLinkClass}}">
                        All memories
                    </a>
                @endif
                <a href="{{route('help')}}" class="{{$navLinkClass}}">
                    Help
                </a>
            </div>

            {{-- Right: compact / overflow + search + avatar --}}
            <div class="flex min-w-0 items-center gap-1 flex-shrink-0 ml-auto" :class="{ 'hidden': searching }">
                {{-- Small viewports: explicit overflow menu (avoids two-row wrapped nav) --}}
                <div x-data="{ mobileNavOpen: false }" class="relative overflow-visible lg:hidden">
                    <button type="button" data-testid="mobile-nav-trigger" @click="mobileNavOpen = !mobileNavOpen" :aria-expanded="mobileNavOpen.toString()" aria-controls="mobile-nav-panel" class="inline-flex items-center justify-center rounded-lg border border-memory-violet/15 bg-white/60 px-2.5 py-1.5 text-slate-brand hover:bg-memory-violet/8 dark:border-white/10 dark:bg-gray-900/80 dark:text-gray-300 dark:hover:bg-white/5">
                        <span class="sr-only">Open navigation menu</span>
                        <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16" />
                        </svg>
                    </button>
                    <div id="mobile-nav-panel" data-testid="mobile-nav-panel" x-show="mobileNavOpen" x-transition x-cloak @click.away="mobileNavOpen = false" class="ideatub-mobile-nav-panel">
                        <a href="{{route('idea.ideas')}}" class="ideatub-mobile-nav-link" @click="mobileNavOpen = false">
                            Ideas
                        </a>
                        <a href="{{route('idea.stream')}}" class="ideatub-mobile-nav-link" @click="mobileNavOpen = false">
                            Stream
                        </a>
                        <a href="{{route('projects.index')}}" class="ideatub-mobile-nav-link" @click="mobileNavOpen = false">
                            Projects
                        </a>
                        @if (config('features.working_memory_ui'))
                            <a href="{{route('memory.show')}}" class="ideatub-mobile-nav-link" @click="mobileNavOpen = false">
                                Memory
                            </a>
                            <a href="{{route('memory.scopes.index')}}" class="ideatub-mobile-nav-link" @click="mobileNavOpen = false">
                                All memories
                            </a>
                        @endif
                        <div class="border-t border-memory-violet/10 my-1"></div>
                        <a href="{{route('help')}}" class="ideatub-mobile-nav-link" @click="mobileNavOpen = false">
                            Help
                        </a>
                    </div>
                </div>

                <div class="hidden sm:block w-px h-4 bg-memory-violet/20 mx-1"></div>

                {{-- Search pill --}}
                <button type="button" @click="searching = true" class="ideatub-search-pill" aria-label="Find a memory">
                    <svg class="w-3 h-3 text-neural-teal" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                        <circle cx="11" cy="11" r="8" />
                        <path d="m21 21-4.35-4.35" />
                    </svg>
                    <span class="ideatub-search-pill-label">Find a memory</span>
                    <span class="ideatub-search-pill-kbd text-[10px] text-slate-brand/50">⌘K</span>
                </button>

                {{-- Avatar / logout --}}
                @auth
                    <div x-data="{ open: false }" class="relative ml-1">
                        <button type="button" data-inbox-avatar-button @click="open = !open" aria-haspopup="true" :aria-expanded="open.toString()" aria-label="{{$accountMenuLabel}}" class="ideatub-avatar">
                            {{strtoupper(substr(auth()->user()->name ?? auth()->user()->email, 0, 2))}}
                            @if($inboxCount > 0)
                                <span data-inbox-badge data-testid="avatar-inbox-badge" aria-hidden="true" class="pointer-events-none absolute -right-1 -top-1 inline-flex min-h-[1rem] min-w-[1rem] items-center justify-center rounded-full bg-memory-violet px-1 text-[9px] font-bold leading-none text-white ring-2 ring-white dark:ring-gray-900">
                                    {{$inboxCount > 99 ? '99+' : $inboxCount}}
                                </span>
                            @endif
                        </button>
                        <div x-show="open" x-transition @click.away="open = false" class="ideatub-dropdown">
                            <div class="border-b border-memory-violet/10 px-4 py-3 dark:border-white/10">
                                <p class="text-[10px] font-semibold uppercase tracking-[0.1em] text-memory-violet/80 mb-2">Appearance</p>
                                <x-appearance-control :appearance="$appearance ?? 'system'" :compact="true" />
                            </div>
                            <a href="{{route('settings.profile.index')}}" class="block px-4 py-2 text-sm text-slate-brand hover:text-deep-indigo hover:bg-memory-violet/5 transition-colors">
                                Profile
                            </a>
                            <a href="{{route('inbox.index')}}" data-testid="account-menu-inbox-link" class="flex items-center justify-between gap-3 px-4 py-2 text-sm text-slate-brand hover:text-deep-indigo hover:bg-memory-violet/5 transition-colors">
                                <span>Inbox</span>
                                @if($inboxCount > 0)
                                    <span data-inbox-badge data-testid="account-menu-inbox-badge" aria-hidden="true" class="inline-flex min-h-[1rem] min-w-[1rem] items-center justify-center rounded-full bg-memory-violet px-1 text-[9px] font-bold leading-none text-white">
                                        {{$inboxCount > 99 ? '99+' : $inboxCount}}
                                    </span>
                                @endif
                            </a>
                            @if(\App\Support\ThoughtTypeNavigation::isAvailable('jira'))
                                <a href="{{ route('idea.stream.jira') }}" data-testid="account-menu-jira-link" class="block px-4 py-2 text-sm text-slate-brand hover:text-deep-indigo hover:bg-memory-violet/5 transition-colors">
                                    Jira
                                </a>
                            @endif
                            <a href="{{route('shared-research.index')}}" class="block px-4 py-2 text-sm text-slate-brand hover:text-deep-indigo hover:bg-memory-violet/5 transition-colors">
                                Shared documents
                            </a>
                            <form method="POST" action="{{route('logout')}}">
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

        @if(!empty($demoModeEnabled))
            <div data-testid="demo-mode-banner" class="px-6 md:px-8 py-2.5 text-sm text-deep-indigo border-b border-amber-200/80 bg-amber-50/95" role="status">
                Demo mode enabled. Sensitive text is obfuscated.
            </div>
        @endif

        {{-- Page content --}}
        <main>
            @yield('content')
        </main>

        {{-- Shortcut palette (modal) --}}
        <div x-show="shortcutsOpen"
            x-cloak
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="opacity-0"
            x-transition:enter-end="opacity-100"
            x-transition:leave="transition ease-in duration-150"
            x-transition:leave-start="opacity-100"
            x-transition:leave-end="opacity-0"
            @keydown.escape.window="shortcutsOpen = false"
            @click.away="shortcutsOpen = false"
            class="ideatub-modal-backdrop"
            role="dialog"
            aria-modal="true"
            aria-labelledby="shortcuts-modal-title"
            x-ref="shortcutsModal"
            x-effect="shortcutsOpen && $nextTick(() => { const el = $refs.shortcutsModal && $refs.shortcutsModal.querySelector('[autofocus]'); if (el) el.focus(); })">
            <div class="ideatub-modal-panel" @click.stop x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 scale-95" x-transition:enter-end="opacity-100 scale-100" x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100 scale-100" x-transition:leave-end="opacity-0 scale-95">
                <h2 id="shortcuts-modal-title" class="text-lg font-semibold text-deep-indigo mb-4">Keyboard shortcuts
                </h2>
                <table class="w-full text-sm text-deep-indigo">
                    <tbody class="divide-y divide-memory-violet/10">
                        <tr>
                            <td class="py-1.5">Quick capture</td>
                            <td class="py-1.5 text-right text-slate-brand font-medium">⌘/ or Ctrl+/</td>
                        </tr>
                        <tr>
                            <td class="py-1.5 pl-4 text-slate-brand/80" colspan="2">Home: focus capture · Elsewhere: open capture modal</td>
                        </tr>
                        <tr>
                            <td class="py-1.5">Open search</td>
                            <td class="py-1.5 text-right text-slate-brand font-medium">⌘K or Ctrl+K</td>
                        </tr>
                        <tr>
                            <td class="py-1.5">Move down / up thought</td>
                            <td class="py-1.5 text-right text-slate-brand font-medium">j / k</td>
                        </tr>
                        <tr>
                            <td class="py-1.5">Open reply</td>
                            <td class="py-1.5 text-right text-slate-brand font-medium">Enter</td>
                        </tr>
                        <tr>
                            <td class="py-1.5">Cancel reply / close search</td>
                            <td class="py-1.5 text-right text-slate-brand font-medium">Escape</td>
                        </tr>
                        <tr>
                            <td class="py-1.5">Submit thought</td>
                            <td class="py-1.5 text-right text-slate-brand font-medium">⌘+Enter or Ctrl+Enter</td>
                        </tr>
                        <tr>
                            <td class="py-1.5">Show this list</td>
                            <td class="py-1.5 text-right text-slate-brand font-medium">?</td>
                        </tr>
                    </tbody>
                </table>
                <p class="mt-4 text-[11px] text-slate-brand/50">Press Escape or click outside to close</p>
                <button type="button" autofocus @click="shortcutsOpen = false" class="ideatub-gradient-btn mt-5 w-full rounded-lg py-2 text-sm font-medium">
                    Close
                </button>
            </div>
        </div>

        @unless (request()->routeIs('idea.index'))
            @php
                $globalInitialContent = '';
                $globalForceVideoMode = false;
                $globalImportUploadsEnabled = (bool) config('features.file_upload', false)
                    && \Illuminate\Support\Facades\Route::has('imports.quick')
                    && ! app(\App\Services\DemoMode::class)->enabled();
            @endphp
            <div
                id="ideatub-global-capture"
                x-show="captureOpen"
                x-cloak
                x-transition.opacity
                @ideatub-open-capture.window="openGlobalCapture()"
                @ideatub-capture-saved.window="closeGlobalCaptureAfterSave()"
                @keydown.escape.window="handleGlobalCaptureEscape()"
                @click.self="closeGlobalCapture()"
                class="ideatub-modal-backdrop"
                role="dialog"
                aria-modal="true"
                aria-labelledby="global-capture-title"
            >
                <div class="ideatub-modal-panel max-w-2xl w-full max-h-[85vh] flex flex-col overflow-hidden" @click.stop>
                    <div class="flex items-center justify-between gap-3 px-5 pt-5 pb-3 border-b border-memory-violet/10 shrink-0">
                        <h2 id="global-capture-title" class="text-lg font-semibold text-deep-indigo">Capture thought</h2>
                        <button
                            type="button"
                            class="text-sm font-medium text-slate-brand hover:text-deep-indigo"
                            @click="closeGlobalCapture()"
                        >Close</button>
                    </div>
                    <div class="overflow-y-auto px-5 py-4 min-h-0 flex-1" x-ref="globalCaptureMount">
                        @include('idea.partials.capture_box', [
                            'placement' => 'global',
                            'initialContent' => $globalInitialContent,
                            'forceHomeVideoMode' => $globalForceVideoMode,
                            'importUploadsEnabled' => $globalImportUploadsEnabled,
                            'replyingTo' => null,
                            'replyingToPreview' => null,
                        ])
                    </div>
                </div>
            </div>
        @endunless
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