@extends('layouts.app')

@section('title', 'Dashboard - PDF Tool Suite')

@section('content')
<div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
    <div class="mb-8">
        <h1 class="text-3xl font-bold text-gray-900">Dashboard</h1>
        <p class="mt-2 text-gray-600">Manage your account and subscription</p>
    </div>

    <div class="grid grid-cols-1 gap-6">
        <!-- Subscription Status -->
        <div class="bg-white rounded-lg shadow-sm p-6">
            <h2 class="text-xl font-semibold text-gray-900 mb-4">Subscription Status</h2>
            <div class="space-y-4">
                @if($subscriptionStatus === 'lifetime')
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <svg class="h-5 w-5 text-green-500" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                            </svg>
                        </div>
                        <div class="ml-3">
                            <p class="text-sm font-medium text-gray-900">Lifetime Access</p>
                            <p class="text-sm text-gray-500">You have unlimited access forever!</p>
                        </div>
                    </div>
                @elseif($subscriptionStatus === 'pro')
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <svg class="h-5 w-5 text-green-500" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                            </svg>
                        </div>
                        <div class="ml-3">
                            <p class="text-sm font-medium text-gray-900">Pro Subscription</p>
                            <p class="text-sm text-gray-500">Unlimited operations</p>
                        </div>
                    </div>
                @else
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <svg class="h-5 w-5 text-gray-400" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd" />
                            </svg>
                        </div>
                        <div class="ml-3">
                            <p class="text-sm font-medium text-gray-900">Free Plan</p>
                            <p class="text-sm text-gray-500">3 operations per day</p>
                        </div>
                    </div>
                    <div class="mt-4">
                        <a href="{{ route('pricing') }}" class="inline-block bg-indigo-600 text-white px-4 py-2 rounded-lg font-semibold hover:bg-indigo-700">
                            Upgrade to Pro
                        </a>
                    </div>
                @endif
            </div>
        </div>

        <!-- Usage Statistics -->
        <div class="bg-white rounded-lg shadow-sm p-6">
            <h2 class="text-xl font-semibold text-gray-900 mb-4">Today's Usage</h2>
            <div class="space-y-4">
                <div>
                    <div class="flex justify-between text-sm">
                        <span class="text-gray-600">Operations today</span>
                        <span class="font-medium text-gray-900">{{ $operationsToday }}</span>
                    </div>
                    @if($operationsRemaining >= 0)
                        <div class="mt-2">
                            <div class="flex justify-between text-sm mb-1">
                                <span class="text-gray-600">Remaining</span>
                                <span class="font-medium text-gray-900">{{ $operationsRemaining }}</span>
                            </div>
                            <div class="w-full bg-gray-200 rounded-full h-2">
                                <div class="bg-indigo-600 h-2 rounded-full" style="width: {{ ($operationsRemaining / 3) * 100 }}%"></div>
                            </div>
                        </div>
                    @else
                        <p class="mt-2 text-sm text-green-600 font-medium">Unlimited operations</p>
                    @endif
                </div>
            </div>
        </div>

        <!-- Account Information -->
        <div class="bg-white rounded-lg shadow-sm p-6">
            <h2 class="text-xl font-semibold text-gray-900 mb-4">Account Information</h2>
            <div class="space-y-2">
                <div>
                    <span class="text-sm text-gray-600">Name:</span>
                    <span class="ml-2 text-sm font-medium text-gray-900">{{ auth()->user()->name }}</span>
                </div>
                <div>
                    <span class="text-sm text-gray-600">Email:</span>
                    <span class="ml-2 text-sm font-medium text-gray-900">{{ auth()->user()->email }}</span>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
