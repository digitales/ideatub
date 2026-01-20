<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\PricingController;
use App\Http\Controllers\SocialAuthController;
use App\Http\Controllers\ToolController;
use App\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

// Homepage
Route::get('/', [HomeController::class, 'index'])->name('home');

// Tool pages
Route::get('/tools/{tool}', [ToolController::class, 'show'])
    ->where('tool', 'merge|split|compress|pdf-to-image|image-to-pdf|rotate|reorder')
    ->name('tools.show');

// Operation tracking
Route::post('/operations/track', [ToolController::class, 'track'])
    ->middleware('auth')
    ->name('operations.track');

// Pricing
Route::get('/pricing', [PricingController::class, 'index'])->name('pricing');
Route::post('/stripe/checkout/pro', [PricingController::class, 'checkoutPro'])
    ->middleware('auth')
    ->name('stripe.checkout.pro');
Route::post('/stripe/checkout/lifetime', [PricingController::class, 'checkoutLifetime'])
    ->middleware('auth')
    ->name('stripe.checkout.lifetime');

// Stripe webhook
Route::post('/stripe/webhook', [WebhookController::class, 'handleWebhook'])
    ->name('stripe.webhook');

// OAuth routes
Route::get('/auth/google', [SocialAuthController::class, 'redirectToGoogle'])->name('auth.google');
Route::get('/auth/google/callback', [SocialAuthController::class, 'handleGoogleCallback']);
Route::get('/auth/github', [SocialAuthController::class, 'redirectToGithub'])->name('auth.github');
Route::get('/auth/github/callback', [SocialAuthController::class, 'handleGithubCallback']);

// Authentication Routes
Route::middleware('guest')->group(function () {
    Route::get('/register', [RegisteredUserController::class, 'create'])->name('register');
    Route::post('/register', [RegisteredUserController::class, 'store']);
    
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store']);
    
    Route::get('/forgot-password', [PasswordResetLinkController::class, 'create'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetLinkController::class, 'store'])->name('password.email');
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
    
    // Dashboard (requires authentication)
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
});
