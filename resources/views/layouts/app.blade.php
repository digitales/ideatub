<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>@yield('title', 'PDF Tool Suite - Free Online PDF Tools')</title>
    <meta name="description" content="@yield('description', 'Free online PDF tools - merge, split, compress, convert PDFs and more. All processing happens in your browser for privacy.')">
    <meta name="keywords" content="@yield('keywords', 'pdf tools, merge pdf, split pdf, compress pdf, pdf converter')">

    <!-- Open Graph -->
    <meta property="og:title" content="@yield('title', 'PDF Tool Suite')">
    <meta property="og:description" content="@yield('description', 'Free online PDF tools')">
    <meta property="og:type" content="website">
    <meta property="og:url" content="{{ url()->current() }}">

    <!-- Schema.org WebApplication -->
    @yield('schema')

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="font-sans antialiased bg-gray-50">
    <div class="min-h-screen flex flex-col">
        <!-- Navigation -->
        <nav class="bg-white shadow-sm border-b border-gray-200">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="flex justify-between h-16">
                    <div class="flex">
                        <div class="flex-shrink-0 flex items-center">
                            <a href="{{ route('home') }}" class="text-2xl font-bold text-indigo-600">
                                PDF Tools
                            </a>
                        </div>
                        <div class="hidden sm:ml-6 sm:flex sm:space-x-8">
                            <a href="{{ route('home') }}" class="border-indigo-500 text-gray-900 inline-flex items-center px-1 pt-1 border-b-2 text-sm font-medium">
                                Home
                            </a>
                            <a href="{{ route('pricing') }}" class="border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700 inline-flex items-center px-1 pt-1 border-b-2 text-sm font-medium">
                                Pricing
                            </a>
                        </div>
                    </div>
                    <div class="flex items-center">
                        @auth
                            <a href="{{ route('dashboard') }}" class="text-gray-700 hover:text-gray-900 px-3 py-2 rounded-md text-sm font-medium">
                                Dashboard
                            </a>
                            <form method="POST" action="{{ route('logout') }}" class="ml-4">
                                @csrf
                                <button type="submit" class="text-gray-700 hover:text-gray-900 px-3 py-2 rounded-md text-sm font-medium">
                                    Logout
                                </button>
                            </form>
                        @else
                            <a href="{{ route('login') }}" class="text-gray-700 hover:text-gray-900 px-3 py-2 rounded-md text-sm font-medium">
                                Login
                            </a>
                            <a href="{{ route('register') }}" class="ml-4 bg-indigo-600 text-white px-4 py-2 rounded-md text-sm font-medium hover:bg-indigo-700">
                                Sign Up
                            </a>
                        @endauth
                    </div>
                </div>
            </div>
        </nav>

        <!-- Main Content -->
        <main class="flex-grow">
            @yield('content')
        </main>

        <!-- Footer -->
        <footer class="bg-white border-t border-gray-200 mt-auto">
            <div class="max-w-7xl mx-auto py-12 px-4 sm:px-6 lg:px-8">
                <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
                    <div>
                        <h3 class="text-sm font-semibold text-gray-400 tracking-wider uppercase">About</h3>
                        <p class="mt-4 text-base text-gray-500">
                            Free online PDF tools. All processing happens in your browser - your files never leave your device.
                        </p>
                    </div>
                    <div>
                        <h3 class="text-sm font-semibold text-gray-400 tracking-wider uppercase">Tools</h3>
                        <ul class="mt-4 space-y-4">
                            <li><a href="{{ route('tools.show', 'merge') }}" class="text-base text-gray-500 hover:text-gray-900">Merge PDF</a></li>
                            <li><a href="{{ route('tools.show', 'split') }}" class="text-base text-gray-500 hover:text-gray-900">Split PDF</a></li>
                            <li><a href="{{ route('tools.show', 'compress') }}" class="text-base text-gray-500 hover:text-gray-900">Compress PDF</a></li>
                        </ul>
                    </div>
                    <div>
                        <h3 class="text-sm font-semibold text-gray-400 tracking-wider uppercase">Legal</h3>
                        <ul class="mt-4 space-y-4">
                            <li><a href="#" class="text-base text-gray-500 hover:text-gray-900">Privacy Policy</a></li>
                            <li><a href="#" class="text-base text-gray-500 hover:text-gray-900">Terms of Service</a></li>
                        </ul>
                    </div>
                </div>
                <div class="mt-8 border-t border-gray-200 pt-8">
                    <p class="text-base text-gray-400 text-center">
                        &copy; {{ date('Y') }} PDF Tool Suite. All rights reserved.
                    </p>
                </div>
            </div>
        </footer>
    </div>
</body>
</html>
