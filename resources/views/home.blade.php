@extends('layouts.app')

@section('title', 'PDF Tool Suite - Free Online PDF Tools')
@section('description', 'Free online PDF tools - merge, split, compress, convert PDFs and more. All processing happens in your browser for privacy and security.')

@section('schema')
<script type="application/ld+json">
{
    "@context": "https://schema.org",
    "@type": "WebApplication",
    "name": "PDF Tool Suite",
    "description": "Free online PDF tools - merge, split, compress, convert PDFs",
    "url": "{{ url('/') }}",
    "applicationCategory": "UtilityApplication",
    "operatingSystem": "Any",
    "offers": {
        "@type": "Offer",
        "price": "0",
        "priceCurrency": "USD"
    }
}
</script>
@endsection

@section('content')
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-12">
    <!-- Hero Section -->
    <div class="text-center mb-16">
        <h1 class="text-4xl font-extrabold text-gray-900 sm:text-5xl md:text-6xl">
            Free PDF Tools
        </h1>
        <p class="mt-3 max-w-md mx-auto text-base text-gray-500 sm:text-lg md:mt-5 md:text-xl md:max-w-3xl">
            All your PDF needs in one place. Merge, split, compress, and convert PDFs - all processing happens in your browser for maximum privacy.
        </p>
    </div>

    <!-- Tools Grid -->
    <div class="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-3">
        @foreach($tools as $tool)
        <a href="{{ route('tools.show', $tool['slug']) }}" class="group relative bg-white rounded-lg shadow-sm hover:shadow-md transition-shadow duration-200 p-6 border border-gray-200 hover:border-indigo-300">
            <div class="flex items-center">
                <div class="text-4xl mr-4">{{ $tool['icon'] }}</div>
                <div>
                    <h3 class="text-lg font-semibold text-gray-900 group-hover:text-indigo-600">
                        {{ $tool['name'] }}
                    </h3>
                    <p class="mt-1 text-sm text-gray-500">
                        {{ $tool['description'] }}
                    </p>
                </div>
            </div>
        </a>
        @endforeach
    </div>

    <!-- Features Section -->
    <div class="mt-16 bg-indigo-50 rounded-lg p-8">
        <h2 class="text-2xl font-bold text-gray-900 text-center mb-8">Why Choose Our PDF Tools?</h2>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
            <div class="text-center">
                <div class="text-3xl mb-2">🔒</div>
                <h3 class="font-semibold text-gray-900 mb-2">100% Private</h3>
                <p class="text-sm text-gray-600">All processing happens in your browser. Your files never leave your device.</p>
            </div>
            <div class="text-center">
                <div class="text-3xl mb-2">⚡</div>
                <h3 class="font-semibold text-gray-900 mb-2">Fast & Free</h3>
                <p class="text-sm text-gray-600">No uploads, no waiting. Process PDFs instantly with our free tools.</p>
            </div>
            <div class="text-center">
                <div class="text-3xl mb-2">📱</div>
                <h3 class="font-semibold text-gray-900 mb-2">Works Everywhere</h3>
                <p class="text-sm text-gray-600">Use on desktop, tablet, or mobile. No software installation required.</p>
            </div>
        </div>
    </div>
</div>
@endsection
