<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-appearance="{{ $appearance ?? 'system' }}" @class(['dark' => $appearanceEffectiveDark ?? false])>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>@yield('title', config('app.name', 'IdeaTub'))</title>

    <!-- Inter font -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap" rel="stylesheet">

    @include('layouts.partials.appearance-head')

    @if (file_exists(public_path('build/manifest.json')))
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    @endif
</head>
<body class="ideatub-app ideatub-shell flex min-h-screen flex-col items-center justify-center px-4 py-12">

    <!-- Logo -->
    <a href="{{ route('home') }}" class="mb-8 block text-xs font-semibold uppercase tracking-[0.1em] text-memory-violet">
        IdeaTub
    </a>

    <!-- Card -->
    <div class="ideatub-auth-card w-full max-w-sm">
        @yield('content')
    </div>

</body>
</html>
