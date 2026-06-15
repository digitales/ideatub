<?php

use App\Http\Middleware\EnsureAppearanceInSession;
use App\Http\Middleware\AuthenticateOAuthBearer;
use App\Http\Middleware\CheckOperationLimit;
use App\Http\Middleware\EnsureAttentionPulseEnabled;
use App\Http\Middleware\EnsureWorkingMemoryInsightsEnabled;
use App\Http\Middleware\EnsureWorkingMemoryUiEnabled;
use App\Http\Middleware\RedirectIfAuthenticated;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\ValidatePostmarkInboundSecret;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->validateCsrfTokens(except: [
            'webhooks/postmark/inbound/*',
            // OAuth 2.1 / DCR: called by remote MCP clients (no browser session)
            'oauth/register',
            'oauth/token',
            'oauth/revoke',
        ]);

        $middleware->web(append: [
            EnsureAppearanceInSession::class,
            CheckOperationLimit::class,
            SecurityHeaders::class,
        ]);

        $middleware->alias([
            'auth' => Authenticate::class,
            'auth.oauth.bearer' => AuthenticateOAuthBearer::class,
            'guest' => RedirectIfAuthenticated::class,
            'postmark.inbound.secret' => ValidatePostmarkInboundSecret::class,
            'working.memory.ui' => EnsureWorkingMemoryUiEnabled::class,
            'working.memory.insights' => EnsureWorkingMemoryInsightsEnabled::class,
            'attention.pulse' => EnsureAttentionPulseEnabled::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
