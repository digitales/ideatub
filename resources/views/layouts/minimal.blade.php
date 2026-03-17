<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Research')</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="font-sans antialiased min-h-screen" style="background: linear-gradient(135deg, #eef2ff 0%, #f3f0ff 50%, #f0f5ff 100%);">
    <main class="max-w-[600px] mx-auto px-6 py-12">
        @yield('content')
    </main>
    @hasSection('footer')
        @yield('footer')
    @else
        <footer class="max-w-[600px] mx-auto px-6 py-4 text-center">
            <p class="text-[11px] text-slate-brand/40">Shared via IdeaTub</p>
        </footer>
    @endif
</body>
</html>
