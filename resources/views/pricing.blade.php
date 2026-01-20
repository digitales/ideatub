@extends('layouts.app')

@section('title', 'Pricing - PDF Tool Suite')
@section('description', 'Choose the perfect plan for your PDF needs. Free tier with 3 operations per day, or upgrade to Pro for unlimited access.')

@section('content')
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-12">
    <div class="text-center mb-12">
        <h1 class="text-4xl font-extrabold text-gray-900">Simple, Transparent Pricing</h1>
        <p class="mt-4 text-xl text-gray-600">Choose the plan that works for you</p>
    </div>

    <div class="grid grid-cols-1 gap-8 lg:grid-cols-3 lg:gap-8">
        <!-- Free Tier -->
        <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-8">
            <h3 class="text-2xl font-bold text-gray-900">Free</h3>
            <div class="mt-4">
                <span class="text-4xl font-extrabold text-gray-900">$0</span>
                <span class="text-gray-600">/month</span>
            </div>
            <ul class="mt-6 space-y-4">
                <li class="flex items-start">
                    <svg class="flex-shrink-0 h-5 w-5 text-green-500" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                    </svg>
                    <span class="ml-3 text-gray-700">3 operations per day</span>
                </li>
                <li class="flex items-start">
                    <svg class="flex-shrink-0 h-5 w-5 text-green-500" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                    </svg>
                    <span class="ml-3 text-gray-700">All basic tools</span>
                </li>
                <li class="flex items-start">
                    <svg class="flex-shrink-0 h-5 w-5 text-green-500" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                    </svg>
                    <span class="ml-3 text-gray-700">100% private processing</span>
                </li>
            </ul>
            @auth
                <div class="mt-8 text-center text-sm text-gray-500">Current plan</div>
            @else
                <a href="{{ route('register') }}" class="mt-8 block w-full bg-gray-200 text-gray-900 text-center py-3 px-4 rounded-lg font-semibold hover:bg-gray-300">
                    Get Started
                </a>
            @endauth
        </div>

        <!-- Pro Tier -->
        <div class="bg-indigo-600 rounded-lg shadow-lg border-2 border-indigo-700 p-8 transform scale-105">
            <div class="text-center">
                <span class="inline-block bg-indigo-500 text-white text-xs font-semibold px-3 py-1 rounded-full">POPULAR</span>
            </div>
            <h3 class="text-2xl font-bold text-white mt-4">Pro</h3>
            <div class="mt-4">
                <span class="text-4xl font-extrabold text-white">$4.99</span>
                <span class="text-indigo-200">/month</span>
            </div>
            <ul class="mt-6 space-y-4">
                <li class="flex items-start">
                    <svg class="flex-shrink-0 h-5 w-5 text-white" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                    </svg>
                    <span class="ml-3 text-white">Unlimited operations</span>
                </li>
                <li class="flex items-start">
                    <svg class="flex-shrink-0 h-5 w-5 text-white" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                    </svg>
                    <span class="ml-3 text-white">All tools</span>
                </li>
                <li class="flex items-start">
                    <svg class="flex-shrink-0 h-5 w-5 text-white" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                    </svg>
                    <span class="ml-3 text-white">No ads</span>
                </li>
                <li class="flex items-start">
                    <svg class="flex-shrink-0 h-5 w-5 text-white" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                    </svg>
                    <span class="ml-3 text-white">Priority support</span>
                </li>
            </ul>
            @auth
                @if(auth()->user()->subscribed('default'))
                    <div class="mt-8 text-center text-sm text-indigo-200">Current plan</div>
                @else
                    <form method="POST" action="{{ route('stripe.checkout.pro') }}">
                        @csrf
                        <button type="submit" class="mt-8 block w-full bg-white text-indigo-600 text-center py-3 px-4 rounded-lg font-semibold hover:bg-gray-50">
                            Upgrade to Pro
                        </button>
                    </form>
                @endif
            @else
                <a href="{{ route('register') }}" class="mt-8 block w-full bg-white text-indigo-600 text-center py-3 px-4 rounded-lg font-semibold hover:bg-gray-50">
                    Get Started
                </a>
            @endauth
        </div>

        <!-- Lifetime Tier -->
        <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-8">
            <h3 class="text-2xl font-bold text-gray-900">Lifetime</h3>
            <div class="mt-4">
                <span class="text-4xl font-extrabold text-gray-900">$29</span>
                <span class="text-gray-600"> one-time</span>
            </div>
            <ul class="mt-6 space-y-4">
                <li class="flex items-start">
                    <svg class="flex-shrink-0 h-5 w-5 text-green-500" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                    </svg>
                    <span class="ml-3 text-gray-700">Everything in Pro</span>
                </li>
                <li class="flex items-start">
                    <svg class="flex-shrink-0 h-5 w-5 text-green-500" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                    </svg>
                    <span class="ml-3 text-gray-700">Pay once, use forever</span>
                </li>
                <li class="flex items-start">
                    <svg class="flex-shrink-0 h-5 w-5 text-green-500" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                    </svg>
                    <span class="ml-3 text-gray-700">No recurring charges</span>
                </li>
                <li class="flex items-start">
                    <svg class="flex-shrink-0 h-5 w-5 text-green-500" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                    </svg>
                    <span class="ml-3 text-gray-700">Best value</span>
                </li>
            </ul>
            @auth
                @if(auth()->user()->lifetime_access)
                    <div class="mt-8 text-center text-sm text-gray-500">Current plan</div>
                @else
                    <form method="POST" action="{{ route('stripe.checkout.lifetime') }}">
                        @csrf
                        <button type="submit" class="mt-8 block w-full bg-indigo-600 text-white text-center py-3 px-4 rounded-lg font-semibold hover:bg-indigo-700">
                            Buy Lifetime
                        </button>
                    </form>
                @endif
            @else
                <a href="{{ route('register') }}" class="mt-8 block w-full bg-indigo-600 text-white text-center py-3 px-4 rounded-lg font-semibold hover:bg-indigo-700">
                    Get Started
                </a>
            @endauth
        </div>
    </div>
</div>
@endsection
