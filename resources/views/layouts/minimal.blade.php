<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-appearance="{{ $appearance ?? 'system' }}" @class(['dark' => $appearanceEffectiveDark ?? false])>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>@yield('title', 'Research')</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    @include('layouts.partials.appearance-head')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="ideatub-app ideatub-shell font-sans antialiased">
    <main class="max-w-4xl mx-auto px-6 md:px-8 py-12 md:py-16">
        @yield('content')
    </main>
    @hasSection('footer')
        @yield('footer')
    @else
        <footer class="max-w-4xl mx-auto px-6 md:px-8 py-6 text-center border-t border-memory-violet/10 mt-8">
            <p class="text-[11px] font-medium tracking-[0.05em] text-memory-violet/70">Shared via IdeaTub</p>
        </footer>
    @endif
</body>
</html>
