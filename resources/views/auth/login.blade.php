@extends('layouts.auth')

@section('title', 'Sign in — IdeaTub')

@section('content')
    <h2 class="text-xl font-semibold text-deep-indigo text-center mb-6">Sign in to IdeaTub</h2>

    <form method="POST" action="{{ route('login') }}" class="space-y-4">
        @csrf

        <div>
            <label for="email" class="block text-xs font-medium text-slate-brand mb-1">Email</label>
            <input id="email" name="email" type="email" autocomplete="email" required
                   value="{{ old('email') }}"
                   placeholder="you@example.com"
                   class="w-full rounded-lg border border-memory-violet/20 bg-white/60 px-3 py-2.5 text-sm text-deep-indigo placeholder-slate-brand/40 focus:border-memory-violet/50 focus:ring-2 focus:ring-memory-violet/20 outline-none transition">
            @error('email')
                <p class="mt-1 text-xs text-red-500">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label for="password" class="block text-xs font-medium text-slate-brand mb-1">Password</label>
            <input id="password" name="password" type="password" autocomplete="current-password" required
                   placeholder="••••••••"
                   class="w-full rounded-lg border border-memory-violet/20 bg-white/60 px-3 py-2.5 text-sm text-deep-indigo placeholder-slate-brand/40 focus:border-memory-violet/50 focus:ring-2 focus:ring-memory-violet/20 outline-none transition">
            @error('password')
                <p class="mt-1 text-xs text-red-500">{{ $message }}</p>
            @enderror
        </div>

        <div class="flex items-center justify-between">
            <label class="flex items-center gap-2 text-xs text-slate-brand cursor-pointer">
                <input name="remember" type="checkbox" class="rounded border-memory-violet/30 text-memory-violet focus:ring-memory-violet/30">
                Remember me
            </label>
            <a href="{{ route('password.request') }}" class="text-xs text-memory-violet hover:opacity-80 transition">
                Forgot password?
            </a>
        </div>

        <button type="submit"
                class="w-full py-2.5 rounded-lg text-sm font-medium text-white transition hover:opacity-90"
                style="background: linear-gradient(135deg, #6D6AF7, #2A8C8C);">
            Sign in
        </button>
    </form>

    <!-- Divider -->
    <div class="relative my-6">
        <div class="absolute inset-0 flex items-center">
            <div class="w-full border-t border-memory-violet/10"></div>
        </div>
        <div class="relative flex justify-center">
            <span class="bg-white/80 px-3 text-xs text-slate-brand/50">or continue with</span>
        </div>
    </div>

    <!-- OAuth -->
    <div class="grid grid-cols-2 gap-3">
        <a href="{{ route('auth.google') }}"
           class="flex items-center justify-center gap-2 py-2 px-3 rounded-lg border border-memory-violet/15 bg-white/60 text-xs font-medium text-slate-brand hover:bg-white hover:border-memory-violet/30 transition">
            <svg class="h-4 w-4" viewBox="0 0 24 24">
                <path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/>
                <path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/>
                <path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"/>
                <path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"/>
            </svg>
            Google
        </a>
        <a href="{{ route('auth.github') }}"
           class="flex items-center justify-center gap-2 py-2 px-3 rounded-lg border border-memory-violet/15 bg-white/60 text-xs font-medium text-slate-brand hover:bg-white hover:border-memory-violet/30 transition">
            <svg class="h-4 w-4" fill="currentColor" viewBox="0 0 24 24">
                <path fill-rule="evenodd" d="M12 2C6.477 2 2 6.484 2 12.017c0 4.425 2.865 8.18 6.839 9.504.5.092.682-.217.682-.483 0-.237-.008-.868-.013-1.703-2.782.605-3.369-1.343-3.369-1.343-.454-1.158-1.11-1.466-1.11-1.466-.908-.62.069-.608.069-.608 1.003.07 1.531 1.032 1.531 1.032.892 1.53 2.341 1.088 2.91.832.092-.647.35-1.088.636-1.338-2.22-.253-4.555-1.113-4.555-4.951 0-1.093.39-1.988 1.029-2.688-.103-.253-.446-1.272.098-2.65 0 0 .84-.27 2.75 1.026A9.564 9.564 0 0112 6.844c.85.004 1.705.115 2.504.337 1.909-1.296 2.747-1.027 2.747-1.027.546 1.379.202 2.398.1 2.651.64.7 1.028 1.595 1.028 2.688 0 3.848-2.339 4.695-4.566 4.943.359.309.678.92.678 1.855 0 1.338-.012 2.419-.012 2.747 0 .268.18.58.688.482A10.019 10.019 0 0022 12.017C22 6.484 17.522 2 12 2z" clip-rule="evenodd"/>
            </svg>
            GitHub
        </a>
    </div>

    <p class="mt-6 text-center text-xs text-slate-brand/50">
        No account?
        <a href="{{ route('register') }}" class="text-memory-violet hover:opacity-80 transition font-medium">Sign up</a>
    </p>
@endsection
