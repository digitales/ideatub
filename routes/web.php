<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\DraftController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\HelpController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\IdeaController;
use App\Http\Controllers\McpKeyController;
use App\Http\Controllers\PricingController;
use App\Http\Controllers\SocialAuthController;
use App\Http\Controllers\ToolController;
use App\Http\Controllers\OAuthServerController;
use App\Http\Controllers\OAuthWellKnownController;
use App\Http\Controllers\IdeasRevisitSettingsController;
use App\Http\Controllers\InboundEmailController;
use App\Http\Controllers\JiraSettingsController;
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

// Public shared research view (no auth; password gate per share)
Route::get('/r/{token}', [App\Http\Controllers\SharedResearchViewController::class, 'show'])
    ->name('shared-research.show');

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

    Route::get('/api/thoughts/realtime-check', [App\Http\Controllers\Api\RealtimeCheckController::class, 'realtimeCheck'])->name('api.thoughts.realtime-check');

    // IdeaTub: primary capture — index (with optional ?q= search) and store thought
    Route::get('/', [IdeaController::class, 'index'])->name('idea.index');
    Route::post('/thoughts', [IdeaController::class, 'store'])->name('thoughts.store');
    Route::get('/stream/jira', [IdeaController::class, 'streamJira'])->name('idea.stream.jira');
    Route::get('/stream', [IdeaController::class, 'stream'])->name('idea.stream');

    // Ideas list and store
    Route::get('/ideas', [IdeaController::class, 'ideas'])->name('idea.ideas');
    Route::get('/ideas/revisit', [IdeaController::class, 'revisit'])->name('idea.revisit');
    Route::post('/ideas', [IdeaController::class, 'storeIdea'])->name('ideas.store');
    Route::patch('/ideas/{thought}/completed', [IdeaController::class, 'toggleCompleted'])->name('ideas.toggle-completed');
    Route::patch('/ideas/{thought}/tags', [IdeaController::class, 'updateTags'])->name('ideas.update-tags');
    Route::delete('/ideas/{thought}', [IdeaController::class, 'destroy'])->name('ideas.destroy');
    Route::post('/ideas/research', [IdeaController::class, 'researchNew'])->name('ideas.research-new');
    Route::post('/ideas/{thought}/research', [IdeaController::class, 'research'])->name('ideas.research');

    // Drafts for thought capture (list, create, show, update, delete)
    Route::get('/ideas/drafts', [DraftController::class, 'index'])->name('ideas.drafts.index');
    Route::post('/ideas/drafts', [DraftController::class, 'store'])->name('ideas.drafts.store');
    Route::get('/ideas/drafts/{draft}', [DraftController::class, 'show'])->name('ideas.drafts.show');
    Route::patch('/ideas/drafts/{draft}', [DraftController::class, 'update'])->name('ideas.drafts.update');
    Route::delete('/ideas/drafts/{draft}', [DraftController::class, 'destroy'])->name('ideas.drafts.destroy');

    Route::redirect('/example-prompts', '/help#example-prompts')->name('example-prompts');
    Route::get('/help', [HelpController::class, 'index'])->name('help');
    
    // MCP key management (obtain / revoke auth key for AI clients)
    Route::get('/settings/mcp-keys', [McpKeyController::class, 'index'])->name('settings.mcp-keys.index');
    Route::post('/settings/mcp-keys', [McpKeyController::class, 'store'])->name('settings.mcp-keys.store');
    Route::delete('/settings/mcp-keys/{mcpKey}', [McpKeyController::class, 'destroy'])->name('settings.mcp-keys.destroy');

    Route::get('/settings/ideas-revisit', [IdeasRevisitSettingsController::class, 'index'])->name('settings.ideas-revisit.index');
    Route::put('/settings/ideas-revisit', [IdeasRevisitSettingsController::class, 'update'])->name('settings.ideas-revisit.update');

    Route::get('/settings/inbound-emails', [InboundEmailController::class, 'index'])->name('settings.inbound-emails.index');
    Route::post('/settings/inbound-emails', [InboundEmailController::class, 'store'])->name('settings.inbound-emails.store');
    Route::delete('/settings/inbound-emails/{userInboundAddress}', [InboundEmailController::class, 'destroy'])->name('settings.inbound-emails.destroy');

    if (config('services.jira.enabled', true)) {
        Route::get('/settings/jira', [JiraSettingsController::class, 'index'])->name('settings.jira.index');
        Route::get('/settings/jira/status', [JiraSettingsController::class, 'status'])->name('settings.jira.status');
        Route::post('/settings/jira', [JiraSettingsController::class, 'store'])->name('settings.jira.store');
        Route::delete('/settings/jira', [JiraSettingsController::class, 'destroy'])->name('settings.jira.destroy');
        Route::post('/settings/jira/sync', [JiraSettingsController::class, 'sync'])->name('settings.jira.sync');
    }

    // Dashboard (requires authentication)
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
});
