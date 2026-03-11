<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>@yield('title', config('app.name', 'IdeaTub'))</title>

    <!-- Inter font -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap" rel="stylesheet">

    @if (file_exists(public_path('build/manifest.json')))
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    @endif
</head>
<body class="font-sans antialiased min-h-screen flex flex-col items-center justify-center px-4 py-12"
      style="background: linear-gradient(135deg, #eef2ff 0%, #f3f0ff 50%, #f0f5ff 100%);">

    <!-- Logo -->
    <a href="{{ route('home') }}" class="text-xs font-semibold tracking-[0.1em] uppercase text-memory-violet mb-8 block">
        IdeaTub
    </a>

    <!-- Card -->
    <div class="w-full max-w-sm bg-white/80 backdrop-blur rounded-2xl border border-memory-violet/20 shadow-[0_4px_24px_rgba(109,106,247,0.08)] p-8">
        @yield('content')
    </div>

</body>
</html>
