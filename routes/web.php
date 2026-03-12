<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ExamplePromptsController;
use App\Http\Controllers\HelpController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\IdeaController;
use App\Http\Controllers\McpKeyController;
use App\Http\Controllers\PricingController;
use App\Http\Controllers\SocialAuthController;
use App\Http\Controllers\ToolController;
use App\Http\Controllers\OAuthServerController;
use App\Http\Controllers\OAuthWellKnownController;
use App\Http\Controllers\PostmarkInboundController;
use App\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

// OAuth 2.1 / MCP well-known and OAuth server (ChatGPT connector)
if (config('oauth-mcp.enabled', true)) {
    Route::get('.well-known/oauth-protected-resource', [OAuthWellKnownController::class, 'protectedResource']);
    Route::get('.well-known/oauth-authorization-server', [OAuthWellKnownController::class, 'authorizationServer']);
    Route::get('.well-known/jwks.json', [OAuthWellKnownController::class, 'jwks']);
    Route::post('oauth/register', [OAuthServerController::class, 'register']);
    Route::get('oauth/authorize', [OAuthServerController::class, 'authorize'])->name('oauth.authorize');
    Route::post('oauth/token', [OAuthServerController::class, 'token']);
}

// Guest landing (optional; IdeaTub primary UI is at / when authenticated)
Route::get('/welcome', [HomeController::class, 'index'])->name('home');

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

// Postmark inbound email webhook (secret in path; no auth)
Route::post('/webhooks/postmark/inbound/{token}', [PostmarkInboundController::class, 'handle'])
    ->middleware('postmark.inbound.secret')
    ->name('webhooks.postmark.inbound');

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
    
    // IdeaTub: primary capture — index (with optional ?q= search) and store thought
    Route::get('/', [IdeaController::class, 'index'])->name('idea.index');
    Route::post('/thoughts', [IdeaController::class, 'store'])->name('thoughts.store');
    Route::get('/stream', [IdeaController::class, 'stream'])->name('idea.stream');

    Route::get('/example-prompts', [ExamplePromptsController::class, 'index'])->name('example-prompts');
    Route::get('/help', [HelpController::class, 'index'])->name('help');
    
    // MCP key management (obtain / revoke auth key for AI clients)
    Route::get('/settings/mcp-keys', [McpKeyController::class, 'index'])->name('settings.mcp-keys.index');
    Route::post('/settings/mcp-keys', [McpKeyController::class, 'store'])->name('settings.mcp-keys.store');
    Route::delete('/settings/mcp-keys/{mcpKey}', [McpKeyController::class, 'destroy'])->name('settings.mcp-keys.destroy');
    
    // Dashboard (requires authentication)
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
});
