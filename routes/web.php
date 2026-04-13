<?php

use App\Http\Controllers\Api\RealtimeCheckController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DemoModeController;
use App\Http\Controllers\DraftController;
use App\Http\Controllers\EmailAccountSettingsController;
use App\Http\Controllers\EmailResearchController;
use App\Http\Controllers\EmailSenderRuleSettingsController;
use App\Http\Controllers\EmailThoughtSenderRuleController;
use App\Http\Controllers\HelpController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\IdeaController;
use App\Http\Controllers\IdeasRevisitSettingsController;
use App\Http\Controllers\InboundEmailController;
use App\Http\Controllers\InboxController;
use App\Http\Controllers\JiraSettingsController;
use App\Http\Controllers\McpKeyController;
use App\Http\Controllers\OAuthServerController;
use App\Http\Controllers\OAuthWellKnownController;
use App\Http\Controllers\PostmarkInboundController;
use App\Http\Controllers\PricingController;
use App\Http\Controllers\ProfileSettingsController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ProjectThoughtController;
use App\Http\Controllers\ResearchSkillSettingsController;
use App\Http\Controllers\SharedResearchController;
use App\Http\Controllers\SharedResearchViewController;
use App\Http\Controllers\SocialAuthController;
use App\Http\Controllers\ThoughtLinkController;
use App\Http\Controllers\ThoughtProjectController;
use App\Http\Controllers\ToolController;
use App\Http\Controllers\VideoController;
use App\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

// OAuth 2.1 / MCP well-known and OAuth server (ChatGPT connector)
if (config('oauth-mcp.enabled', true)) {
    Route::get('.well-known/oauth-protected-resource', [OAuthWellKnownController::class, 'protectedResource']);
    Route::get('.well-known/oauth-authorization-server', [OAuthWellKnownController::class, 'authorizationServer']);
    Route::get('.well-known/jwks.json', [OAuthWellKnownController::class, 'jwks']);
    Route::post('oauth/register', [OAuthServerController::class, 'register'])
        ->middleware('throttle:10,1');
    Route::get('oauth/authorize', [OAuthServerController::class, 'showConsent'])->name('oauth.authorize');
    Route::post('oauth/token', [OAuthServerController::class, 'token']);
}

// Guest landing (optional; IdeaTub primary UI is at / when authenticated)
Route::get('/welcome', [HomeController::class, 'index'])->name('home');

// Public shared research view (no auth; password gate per share)
Route::get('/r/{token}', [SharedResearchViewController::class, 'show'])
    ->name('shared-research.show');
Route::post('/r/{token}', [SharedResearchViewController::class, 'show'])
    ->middleware('throttle:shared-research-password');

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
    Route::post('/login', [AuthenticatedSessionController::class, 'store'])
        ->middleware('throttle:login');

    Route::get('/forgot-password', [PasswordResetLinkController::class, 'create'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetLinkController::class, 'store'])->name('password.email');
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');

    Route::post('/demo-mode/enable', [DemoModeController::class, 'enable'])->name('demo-mode.enable');
    Route::post('/demo-mode/disable', [DemoModeController::class, 'disable'])->name('demo-mode.disable');

    Route::get('/api/thoughts/realtime-check', [RealtimeCheckController::class, 'realtimeCheck'])->name('api.thoughts.realtime-check');

    // IdeaTub: primary capture — index (with optional ?q= search) and store thought
    Route::get('/', [IdeaController::class, 'index'])->name('idea.index');
    Route::get('/thoughts/{thought}', [IdeaController::class, 'show'])->name('thoughts.show');
    Route::post('/thoughts/{thought}/links', [ThoughtLinkController::class, 'store'])->name('thoughts.links.store');
    Route::delete('/thoughts/{thought}/links/{thoughtLink}', [ThoughtLinkController::class, 'destroy'])->name('thoughts.links.destroy');
    Route::post('/thoughts/{thought}/projects', [ThoughtProjectController::class, 'store'])->name('thoughts.projects.store');
    Route::delete('/thoughts/{thought}/projects/{project}', [ThoughtProjectController::class, 'destroy'])->name('thoughts.projects.destroy');
    Route::post('/thoughts/{thought}/sender-rules', [EmailThoughtSenderRuleController::class, 'store'])
        ->name('thoughts.sender-rules.store');
    Route::delete('/thoughts/{thought}/sender-rules', [EmailThoughtSenderRuleController::class, 'destroy'])
        ->name('thoughts.sender-rules.destroy');
    Route::post('/thoughts', [IdeaController::class, 'store'])->name('thoughts.store');
    Route::get('/stream/jira', [IdeaController::class, 'streamJira'])->name('idea.stream.jira');
    Route::get('/stream/emails', [IdeaController::class, 'streamEmails'])->name('idea.stream.emails');
    Route::get('/stream/research', [IdeaController::class, 'streamResearch'])->name('idea.stream.research');
    Route::get('/stream/plans', [IdeaController::class, 'streamPlans'])->name('idea.stream.plans');
    Route::get('/stream/meetings', [IdeaController::class, 'streamMeetings'])->name('idea.stream.meetings');
    Route::get('/stream', [IdeaController::class, 'stream'])->name('idea.stream');

    Route::get('/inbox', [InboxController::class, 'index'])->name('inbox.index');
    Route::post('/inbox/{inboxItem}/done', [InboxController::class, 'markDone'])->name('inbox.done');
    Route::post('/inbox/{inboxItem}/snooze', [InboxController::class, 'snooze'])->name('inbox.snooze');
    Route::post('/inbox/{inboxItem}/save-thought', [InboxController::class, 'saveAsThought'])->name('inbox.save-thought');
    Route::post('/inbox/{inboxItem}/email-review/action', [InboxController::class, 'applyEmailReviewAction'])->name('inbox.email-review.action');

    // Ideas list and store
    Route::get('/ideas', [IdeaController::class, 'ideas'])->name('idea.ideas');
    Route::get('/ideas/revisit', [IdeaController::class, 'revisit'])->name('idea.revisit');
    Route::get('/ideas/completed', [IdeaController::class, 'completed'])->name('idea.completed');
    Route::post('/ideas', [IdeaController::class, 'storeIdea'])->name('ideas.store');
    Route::post('/videos', [VideoController::class, 'store'])->name('videos.store');
    Route::patch('/ideas/{thought}/completed', [IdeaController::class, 'toggleCompleted'])->name('ideas.toggle-completed');
    Route::patch('/ideas/{thought}/tags', [IdeaController::class, 'updateTags'])->name('ideas.update-tags');
    Route::patch('/ideas/{thought}/content', [IdeaController::class, 'updateContent'])->name('ideas.update-content');
    Route::delete('/ideas/{thought}', [IdeaController::class, 'destroy'])->name('ideas.destroy');
    Route::post('/ideas/research', [IdeaController::class, 'researchNew'])->name('ideas.research-new');
    Route::post('/ideas/{thought}/research', [IdeaController::class, 'research'])->name('ideas.research');
    Route::get('/research/{thought}', [IdeaController::class, 'showResearch'])->name('idea.research.show');

    // Email research actions
    Route::post('/emails/{thought}/idea-research', [EmailResearchController::class, 'ideaResearch'])->name('emails.idea-research');
    Route::post('/emails/{thought}/newsletter-research', [EmailResearchController::class, 'newsletterResearch'])->name('emails.newsletter-research');

    // Drafts for thought capture (list, create, show, update, delete)
    Route::get('/ideas/drafts', [DraftController::class, 'index'])->name('ideas.drafts.index');
    Route::post('/ideas/drafts', [DraftController::class, 'store'])->name('ideas.drafts.store');
    Route::get('/ideas/drafts/{draft}', [DraftController::class, 'show'])->name('ideas.drafts.show');
    Route::patch('/ideas/drafts/{draft}', [DraftController::class, 'update'])->name('ideas.drafts.update');
    Route::delete('/ideas/drafts/{draft}', [DraftController::class, 'destroy'])->name('ideas.drafts.destroy');

    Route::get('/projects', [ProjectController::class, 'index'])->name('projects.index');
    Route::get('/projects/create', [ProjectController::class, 'create'])->name('projects.create');
    Route::post('/projects', [ProjectController::class, 'store'])->name('projects.store');
    Route::get('/projects/{project}', [ProjectController::class, 'show'])->name('projects.show');
    Route::get('/projects/{project}/edit', [ProjectController::class, 'edit'])->name('projects.edit');
    Route::put('/projects/{project}', [ProjectController::class, 'update'])->name('projects.update');
    Route::delete('/projects/{project}', [ProjectController::class, 'destroy'])->name('projects.destroy');
    Route::post('/projects/{project}/thoughts', [ProjectThoughtController::class, 'store'])->name('projects.thoughts.store');
    Route::delete('/projects/{project}/thoughts/{thought}', [ProjectThoughtController::class, 'destroy'])->name('projects.thoughts.destroy');

    Route::redirect('/example-prompts', '/help#example-prompts')->name('example-prompts');
    Route::get('/help', [HelpController::class, 'index'])->name('help');

    // MCP key management (obtain / revoke auth key for AI clients)
    Route::get('/settings/mcp-keys', [McpKeyController::class, 'index'])->name('settings.mcp-keys.index');
    Route::post('/settings/mcp-keys', [McpKeyController::class, 'store'])->name('settings.mcp-keys.store');
    Route::patch('/settings/mcp-keys/{mcpKey}', [McpKeyController::class, 'update'])->name('settings.mcp-keys.update');
    Route::delete('/settings/mcp-keys/{mcpKey}', [McpKeyController::class, 'destroy'])->name('settings.mcp-keys.destroy');

    Route::get('/settings/profile', [ProfileSettingsController::class, 'index'])->name('settings.profile.index');
    Route::put('/settings/profile', [ProfileSettingsController::class, 'update'])->name('settings.profile.update');

    Route::get('/settings/ideas-revisit', [IdeasRevisitSettingsController::class, 'index'])->name('settings.ideas-revisit.index');
    Route::put('/settings/ideas-revisit', [IdeasRevisitSettingsController::class, 'update'])->name('settings.ideas-revisit.update');

    Route::get('/settings/research-skills/create', [ResearchSkillSettingsController::class, 'create'])->name('settings.research-skills.create');
    Route::post('/settings/research-skills', [ResearchSkillSettingsController::class, 'store'])->name('settings.research-skills.store');
    Route::put('/settings/research-skills/preferences', [ResearchSkillSettingsController::class, 'updatePreferences'])->name('settings.research-skills.preferences');
    Route::get('/settings/research-skills', [ResearchSkillSettingsController::class, 'index'])->name('settings.research-skills.index');
    Route::post('/settings/research-skills/{researchSkill}/default', [ResearchSkillSettingsController::class, 'setDefault'])->name('settings.research-skills.default');
    Route::get('/settings/research-skills/{researchSkill}/edit', [ResearchSkillSettingsController::class, 'edit'])->name('settings.research-skills.edit');
    Route::put('/settings/research-skills/{researchSkill}', [ResearchSkillSettingsController::class, 'update'])->name('settings.research-skills.update');

    Route::get('/settings/inbound-emails', [InboundEmailController::class, 'index'])->name('settings.inbound-emails.index');
    Route::post('/settings/inbound-emails', [InboundEmailController::class, 'store'])->name('settings.inbound-emails.store');
    Route::delete('/settings/inbound-emails/{userInboundAddress}', [InboundEmailController::class, 'destroy'])->name('settings.inbound-emails.destroy');

    Route::get('/settings/email-sender-rules', [EmailSenderRuleSettingsController::class, 'index'])->name('settings.email-sender-rules.index');
    Route::post('/settings/email-sender-rules', [EmailSenderRuleSettingsController::class, 'store'])->name('settings.email-sender-rules.store');
    Route::patch('/settings/email-sender-rules/{emailSenderRule}', [EmailSenderRuleSettingsController::class, 'update'])->name('settings.email-sender-rules.update');
    Route::delete('/settings/email-sender-rules/{emailSenderRule}', [EmailSenderRuleSettingsController::class, 'destroy'])->name('settings.email-sender-rules.destroy');

    Route::get('/settings/email-accounts', [EmailAccountSettingsController::class, 'index'])->name('settings.email-accounts.index');
    Route::post('/settings/email-accounts', [EmailAccountSettingsController::class, 'store'])->name('settings.email-accounts.store');
    Route::delete('/settings/email-accounts/{mailAccount}', [EmailAccountSettingsController::class, 'destroy'])->name('settings.email-accounts.destroy');
    Route::post('/settings/email-accounts/{mailAccount}/backfill', [EmailAccountSettingsController::class, 'backfill'])->name('settings.email-accounts.backfill');
    Route::post('/settings/email-accounts/{mailAccount}/sync', [EmailAccountSettingsController::class, 'syncNow'])->name('settings.email-accounts.sync');

    // Shared research (owner CRUD)
    Route::get('/shared-research', [SharedResearchController::class, 'index'])->name('shared-research.index');
    Route::post('/shared-research', [SharedResearchController::class, 'store'])->name('shared-research.store');
    Route::patch('/shared-research/{researchShare}', [SharedResearchController::class, 'update'])->name('shared-research.update');
    Route::delete('/shared-research/{researchShare}', [SharedResearchController::class, 'destroy'])->name('shared-research.destroy');

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
