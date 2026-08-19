<?php

use App\Http\Controllers\AchievementController;
use App\Http\Controllers\Api\RealtimeCheckController;
use App\Http\Controllers\AppearanceController;
use App\Http\Controllers\ArticleController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\CommentController;
use App\Http\Controllers\CommitmentController;
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
use App\Http\Controllers\ImportController;
use App\Http\Controllers\InboundEmailController;
use App\Http\Controllers\InboxController;
use App\Http\Controllers\JiraSettingsController;
use App\Http\Controllers\JobApplicationController;
use App\Http\Controllers\JobProspectController;
use App\Http\Controllers\Learning\LearningCaptureController;
use App\Http\Controllers\Learning\LearningLessonController;
use App\Http\Controllers\Learning\LearningLessonNoteController;
use App\Http\Controllers\Learning\LearningLessonProgressController;
use App\Http\Controllers\Learning\LearningProjectController;
use App\Http\Controllers\Learning\LearningQuizAttemptController;
use App\Http\Controllers\Learning\LearningResearchController;
use App\Http\Controllers\McpKeyController;
use App\Http\Controllers\MeetingSkillSettingsController;
use App\Http\Controllers\MemoryCompactionController;
use App\Http\Controllers\MemoryController;
use App\Http\Controllers\MemoryInsightsController;
use App\Http\Controllers\MemoryScopesController;
use App\Http\Controllers\OAuthServerController;
use App\Http\Controllers\OAuthWellKnownController;
use App\Http\Controllers\PostmarkInboundController;
use App\Http\Controllers\PricingController;
use App\Http\Controllers\ProfileSettingsController;
use App\Http\Controllers\ProjectContextController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ProjectGraphController;
use App\Http\Controllers\ProjectShareController;
use App\Http\Controllers\ProjectThoughtController;
use App\Http\Controllers\PulseController;
use App\Http\Controllers\ResearchSkillSettingsController;
use App\Http\Controllers\Settings\ConnectedAppsController;
use App\Http\Controllers\SharedProjectViewController;
use App\Http\Controllers\SharedResearchCommentController;
use App\Http\Controllers\SharedResearchController;
use App\Http\Controllers\SharedResearchViewController;
use App\Http\Controllers\SkillSettingsController;
use App\Http\Controllers\SocialAuthController;
use App\Http\Controllers\StreamLayoutController;
use App\Http\Controllers\TagGraphController;
use App\Http\Controllers\ThoughtLinkController;
use App\Http\Controllers\ThoughtLocalGraphController;
use App\Http\Controllers\ThoughtProjectController;
use App\Http\Controllers\ThoughtSemanticGraphController;
use App\Http\Controllers\ThoughtSuggestedLinkController;
use App\Http\Controllers\ToolController;
use App\Http\Controllers\VaultGraphController;
use App\Http\Controllers\VideoController;
use App\Http\Controllers\WebhookController;
use App\Http\Controllers\WorkingMemoryRefreshController;
use App\Http\Controllers\WorkingMemorySettingsController;
use App\Models\ResearchSkill;
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
    Route::post('oauth/revoke', [OAuthServerController::class, 'revoke']);
}

// Guest landing (optional; IdeaTub primary UI is at / when authenticated)
Route::get('/welcome', [HomeController::class, 'index'])->name('home');

// Public shared research view (no auth; password gate per share)
Route::get('/r/{token}/p/{page}', [SharedResearchViewController::class, 'showPage'])
    ->where('page', '[0-9A-Za-z._-]+')
    ->name('shared-research.page');
// Public shared research view (no auth; password gate per share)
Route::get('/r/{token}', [SharedResearchViewController::class, 'show'])
    ->name('shared-research.show');
Route::post('/r/{token}', [SharedResearchViewController::class, 'show'])
    ->middleware('throttle:shared-research-password');
Route::post('/r/{token}/p/{page}', [SharedResearchViewController::class, 'showPage'])
    ->where('page', '[0-9A-Za-z._-]+')
    ->middleware('throttle:shared-research-password');

// Public guest comments on a shared research view (rate-limited, honeypot-protected)
Route::post('/r/{token}/comments', [SharedResearchCommentController::class, 'store'])
    ->middleware('throttle:shared-research-comment')
    ->name('shared-research.comment');

Route::get('/shared/projects/{token}', [SharedProjectViewController::class, 'hub'])->name('shared-projects.hub');
Route::post('/shared/projects/{token}', [SharedProjectViewController::class, 'hub'])
    ->middleware('throttle:project-share-password');
Route::get('/shared/projects/{token}/read', [SharedProjectViewController::class, 'readAll'])->name('shared-projects.read');
Route::post('/shared/projects/{token}/read', [SharedProjectViewController::class, 'readAll'])
    ->middleware('throttle:project-share-password');
Route::get('/shared/projects/{token}/thoughts/{thoughtId}', [SharedProjectViewController::class, 'thought'])
    ->whereUuid('thoughtId')
    ->name('shared-projects.thought');
Route::post('/shared/projects/{token}/thoughts/{thoughtId}', [SharedProjectViewController::class, 'thought'])
    ->whereUuid('thoughtId')
    ->middleware('throttle:project-share-password');

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
Route::middleware('guest')->group(function () {
    Route::post('/auth/google/start', [SocialAuthController::class, 'startGoogle'])->name('auth.google.start');
    Route::post('/auth/github/start', [SocialAuthController::class, 'startGithub'])->name('auth.github.start');
});
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

    Route::post('/stream/layout', [StreamLayoutController::class, 'store'])->name('stream.layout.store');
    Route::post('/settings/appearance', [AppearanceController::class, 'store'])->name('settings.appearance.store');

    Route::get('/api/thoughts/realtime-check', [RealtimeCheckController::class, 'realtimeCheck'])->name('api.thoughts.realtime-check');

    // IdeaTub: primary capture — index (with optional ?q= search) and store thought
    Route::get('/', [IdeaController::class, 'index'])->name('idea.index');
    Route::get('/thoughts/{thought}', [IdeaController::class, 'show'])->name('thoughts.show');

    Route::middleware('memory.graph:local')->group(function () {
        Route::get('/thoughts/{thought}/graph', [ThoughtLocalGraphController::class, 'show'])->name('thoughts.graph');
        Route::get('/thoughts/{thought}/graph/data', [ThoughtLocalGraphController::class, 'data'])->name('thoughts.graph.data');
    });

    Route::middleware('memory.graph:semantic')->group(function () {
        Route::get('/thoughts/{thought}/semantic-graph', [ThoughtSemanticGraphController::class, 'show'])->name('thoughts.semantic_graph');
        Route::get('/thoughts/{thought}/semantic-graph/data', [ThoughtSemanticGraphController::class, 'data'])->name('thoughts.semantic_graph.data');
    });

    Route::middleware('memory.graph:tag')->group(function () {
        Route::get('/graph/tags', [TagGraphController::class, 'show'])->name('graph.tags');
        Route::get('/graph/tags/data', [TagGraphController::class, 'data'])->name('graph.tags.data');
    });

    Route::middleware('memory.graph:vault')->group(function () {
        Route::get('/graph', [VaultGraphController::class, 'show'])->name('graph.vault');
        Route::get('/graph/data', [VaultGraphController::class, 'data'])->name('graph.vault.data');
    });

    Route::middleware('memory.graph:suggestions')->group(function () {
        Route::post('/thoughts/{thought}/suggestions/{suggestion}/dismiss', [ThoughtSuggestedLinkController::class, 'dismiss'])
            ->name('thoughts.suggestions.dismiss');
    });

    Route::post('/thoughts/{thought}/links', [ThoughtLinkController::class, 'store'])->name('thoughts.links.store');
    Route::delete('/thoughts/{thought}/links/{thoughtLink}', [ThoughtLinkController::class, 'destroy'])->name('thoughts.links.destroy');
    Route::post('/thoughts/{thought}/projects', [ThoughtProjectController::class, 'store'])->name('thoughts.projects.store');
    Route::delete('/thoughts/{thought}/projects/{project}', [ThoughtProjectController::class, 'destroy'])->name('thoughts.projects.destroy');
    Route::post('/thoughts/{thought}/sender-rules', [EmailThoughtSenderRuleController::class, 'store'])
        ->name('thoughts.sender-rules.store');
    Route::delete('/thoughts/{thought}/sender-rules', [EmailThoughtSenderRuleController::class, 'destroy'])
        ->name('thoughts.sender-rules.destroy');
    Route::post('/thoughts', [IdeaController::class, 'store'])->name('thoughts.store');

    Route::post('/comments', [CommentController::class, 'store'])->name('comments.store');
    Route::patch('/comments/{comment}', [CommentController::class, 'update'])->name('comments.update');
    Route::delete('/comments/{comment}', [CommentController::class, 'destroy'])->name('comments.destroy');

    Route::get('/stream/jira', [IdeaController::class, 'streamJira'])->name('idea.stream.jira');
    Route::get('/stream/emails', [IdeaController::class, 'streamEmails'])->name('idea.stream.emails');
    Route::get('/stream/research', [IdeaController::class, 'streamResearch'])->name('idea.stream.research');
    Route::get('/stream/plans', [IdeaController::class, 'streamPlans'])->name('idea.stream.plans');
    Route::get('/stream/meetings', [IdeaController::class, 'streamMeetings'])->name('idea.stream.meetings');
    Route::get('/stream/articles', [IdeaController::class, 'streamArticles'])->name('idea.stream.articles');
    Route::get('/stream/videos', [IdeaController::class, 'streamVideos'])->name('idea.stream.videos');
    Route::get('/stream', [IdeaController::class, 'stream'])->name('idea.stream');

    Route::get('/inbox', [InboxController::class, 'index'])->name('inbox.index');
    Route::post('/inbox/groups/{generatorType}/bulk', [InboxController::class, 'bulkGroupAction'])
        ->where('generatorType', '[a-z0-9_]+')
        ->name('inbox.groups.bulk');
    Route::post('/inbox/{inboxItem}/done', [InboxController::class, 'markDone'])->name('inbox.done');
    Route::post('/inbox/{inboxItem}/snooze', [InboxController::class, 'snooze'])->name('inbox.snooze');
    Route::post('/inbox/{inboxItem}/save-thought', [InboxController::class, 'saveAsThought'])->name('inbox.save-thought');
    Route::post('/inbox/{inboxItem}/email-review/action', [InboxController::class, 'applyEmailReviewAction'])->name('inbox.email-review.action');

    Route::middleware('attention.pulse')->group(function () {
        Route::get('/pulse', [PulseController::class, 'show'])->name('pulse.show');
        Route::post('/commitments/{commitmentItem}/done', [CommitmentController::class, 'markDone'])->name('commitments.done');
        Route::post('/commitments/{commitmentItem}/snooze', [CommitmentController::class, 'snooze'])->name('commitments.snooze');
    });

    // Ideas list and store
    Route::get('/ideas', [IdeaController::class, 'ideas'])->name('idea.ideas');
    Route::get('/ideas/revisit', [IdeaController::class, 'revisit'])->name('idea.revisit');
    Route::get('/ideas/completed', [IdeaController::class, 'completed'])->name('idea.completed');
    Route::post('/ideas', [IdeaController::class, 'storeIdea'])->name('ideas.store');
    Route::post('/videos', [VideoController::class, 'store'])->name('videos.store');
    Route::get('/articles', [ArticleController::class, 'index'])->name('articles.index');
    Route::post('/articles', [ArticleController::class, 'store'])->name('articles.store');
    Route::patch('/ideas/{thought}/completed', [IdeaController::class, 'toggleCompleted'])->name('ideas.toggle-completed');
    Route::patch('/ideas/{thought}/tags', [IdeaController::class, 'updateTags'])->name('ideas.update-tags');
    Route::patch('/ideas/{thought}/content', [IdeaController::class, 'updateContent'])->name('ideas.update-content');
    Route::patch('/ideas/{thought}/title', [IdeaController::class, 'updateTitle'])->name('ideas.update-title');
    Route::delete('/ideas/{thought}', [IdeaController::class, 'destroy'])->name('ideas.destroy');
    Route::post('/ideas/research', [IdeaController::class, 'researchNew'])->name('ideas.research-new');
    Route::post('/ideas/{thought}/research', [IdeaController::class, 'research'])->name('ideas.research');
    Route::get('/research/{thought}/p/{page}', [IdeaController::class, 'showResearch'])
        ->where('page', '[0-9A-Za-z._-]+')
        ->name('idea.research.page');
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
    Route::post('/projects/{project}/context', [ProjectContextController::class, 'store'])->name('projects.context.store');
    Route::delete('/projects/{project}/context', [ProjectContextController::class, 'destroy'])->name('projects.context.destroy');
    Route::middleware('memory.graph:project')->group(function () {
        Route::get('/projects/{project}/graph', [ProjectGraphController::class, 'show'])->name('projects.graph');
        Route::get('/projects/{project}/graph/data', [ProjectGraphController::class, 'data'])->name('projects.graph.data');
    });

    Route::prefix('learn')->name('learn.')->group(function () {
        Route::get('projects', [LearningProjectController::class, 'index'])->name('projects.index');
        Route::get('projects/{learning_project}', [LearningProjectController::class, 'show'])->name('projects.show');

        Route::get('projects/{learning_project}/research', [LearningResearchController::class, 'index'])->name('research.index');
        Route::get('projects/{learning_project}/research/{slug}', [LearningResearchController::class, 'show'])->name('research.show');

        Route::get('projects/{learning_project}/lessons/{slug}', [LearningLessonController::class, 'show'])->name('lessons.show');
        Route::post('projects/{learning_project}/lessons/{slug}/capture', [LearningCaptureController::class, 'store'])
            ->name('lessons.capture');
        Route::post('projects/{learning_project}/lessons/{slug}/quiz', [LearningQuizAttemptController::class, 'store'])
            ->name('lessons.quiz.store');
        Route::post('projects/{learning_project}/lessons/{slug}/progress', [LearningLessonProgressController::class, 'update'])
            ->name('lessons.progress.update');
        Route::post('projects/{learning_project}/lessons/{slug}/notes', [LearningLessonNoteController::class, 'store'])
            ->name('lessons.notes.store');
    });
    if (config('features.file_upload')) {
        Route::prefix('imports')->name('imports.')->group(function () {
            Route::post('/quick', [ImportController::class, 'quick'])
                ->middleware('throttle:import-upload')->name('quick');
            Route::post('/batch', [ImportController::class, 'batch'])
                ->middleware('throttle:import-upload')->name('batch');
            Route::post('/preview-markdown', [ImportController::class, 'previewMarkdown'])
                ->name('preview-markdown');
            Route::get('/{batch}', [ImportController::class, 'show'])
                ->middleware('can:view,batch')->name('show');
            Route::get('/{batch}/status', [ImportController::class, 'status'])
                ->middleware(['can:view,batch', 'throttle:60,1'])->name('status');
            Route::post('/{batch}/cancel', [ImportController::class, 'cancel'])
                ->middleware('can:cancel,batch')->name('cancel');
            Route::post('/{batch}/retry-failed', [ImportController::class, 'retryFailed'])
                ->middleware('can:retryFailed,batch')->name('retry-failed');
            Route::delete('/{batch}/thoughts', [ImportController::class, 'destroyThoughts'])
                ->middleware('can:deleteThoughts,batch')->name('thoughts.destroy');
        });
        Route::post('/projects/{project}/import-markdown', [ImportController::class, 'importMarkdown'])
            ->name('projects.import-markdown');
    }
    Route::get('/projects/{project}/shares', [ProjectShareController::class, 'index'])->name('projects.shares.index');
    Route::post('/projects/{project}/shares', [ProjectShareController::class, 'store'])->name('projects.shares.store');
    Route::patch('/project-shares/{projectShare}', [ProjectShareController::class, 'update'])->name('project-shares.update');
    Route::delete('/project-shares/{projectShare}', [ProjectShareController::class, 'destroy'])->name('project-shares.destroy');

    Route::redirect('/example-prompts', '/help#example-prompts')->name('example-prompts');
    Route::get('/help/third-party/ob1', [HelpController::class, 'thirdPartyOb1'])->name('help.third-party.ob1');
    Route::get('/help/panning-for-gold/zip', [HelpController::class, 'panningForGoldDownloadZip'])->name('help.panning-for-gold.zip');
    Route::get('/help/panning-for-gold/{prompt}/download', [HelpController::class, 'panningForGoldDownload'])
        ->where('prompt', '[a-z0-9-]+')
        ->name('help.panning-for-gold.download-one');
    Route::get('/help/panning-for-gold/{prompt}', [HelpController::class, 'panningForGoldShow'])
        ->where('prompt', '[a-z0-9-]+')
        ->name('help.panning-for-gold.show');
    Route::get('/help/panning-for-gold', [HelpController::class, 'panningForGoldIndex'])->name('help.panning-for-gold.index');
    Route::get('/help/research-to-decision/skills/zip', [HelpController::class, 'researchToDecisionSkillsDownloadZip'])->name('help.research-to-decision.skills.zip');
    Route::get('/help/research-to-decision/skills/{skill}/download', [HelpController::class, 'researchToDecisionSkillDownload'])
        ->where('skill', '[a-z0-9-]+')
        ->name('help.research-to-decision.skills.download-one');
    Route::get('/help/research-to-decision/skills/{skill}', [HelpController::class, 'researchToDecisionSkillShow'])
        ->where('skill', '[a-z0-9-]+')
        ->name('help.research-to-decision.skills.show');
    Route::get('/help/research-to-decision/skills', [HelpController::class, 'researchToDecisionSkillsIndex'])->name('help.research-to-decision.skills.index');
    Route::get('/help/research-to-decision', [HelpController::class, 'researchToDecision'])->name('help.research-to-decision');
    Route::get('/help/repo-learning-coach', [HelpController::class, 'repoLearningCoach'])->name('help.repo-learning-coach');
    Route::get('/help/working-memory-corpus-sync', [HelpController::class, 'workingMemoryCorpusSync'])->name('help.working-memory-corpus-sync');
    Route::get('/help/working-memory-authoring/{prompt}/download', [HelpController::class, 'workingMemoryAuthoringDownload'])
        ->where('prompt', 'core|agent')
        ->name('help.working-memory-authoring.download-one');
    Route::get('/help/working-memory-authoring/{prompt}', [HelpController::class, 'workingMemoryAuthoringShow'])
        ->where('prompt', 'core|agent')
        ->name('help.working-memory-authoring.show');
    Route::get('/help/working-memory-authoring', [HelpController::class, 'workingMemoryAuthoringIndex'])->name('help.working-memory-authoring.index');
    Route::get('/help/attention-pulse', [HelpController::class, 'attentionPulse'])->name('help.attention-pulse');
    Route::get('/help/memory-graph', [HelpController::class, 'memoryGraph'])->name('help.memory-graph');
    Route::get('/help', [HelpController::class, 'index'])->name('help');

    // MCP key management (obtain / revoke auth key for AI clients)
    Route::get('/settings/mcp-keys', [McpKeyController::class, 'index'])->name('settings.mcp-keys.index');
    Route::post('/settings/mcp-keys', [McpKeyController::class, 'store'])->name('settings.mcp-keys.store');
    Route::patch('/settings/mcp-keys/{mcpKey}', [McpKeyController::class, 'update'])->name('settings.mcp-keys.update');
    Route::delete('/settings/mcp-keys/{mcpKey}', [McpKeyController::class, 'destroy'])->name('settings.mcp-keys.destroy');

    // OAuth MCP connected apps (Claude, ChatGPT, etc.)
    Route::get('/settings/connected-apps', [ConnectedAppsController::class, 'index'])
        ->name('settings.connected-apps.index');
    Route::delete('/settings/connected-apps/{family}', [ConnectedAppsController::class, 'destroy'])
        ->name('settings.connected-apps.destroy');
    Route::delete('/settings/connected-apps', [ConnectedAppsController::class, 'destroyAll'])
        ->name('settings.connected-apps.destroy-all');

    Route::get('/settings/profile', [ProfileSettingsController::class, 'index'])->name('settings.profile.index');
    Route::put('/settings/profile', [ProfileSettingsController::class, 'update'])->name('settings.profile.update');
    Route::post('/settings/notifications', [ProfileSettingsController::class, 'updateNotifications'])->name('settings.profile.notifications');

    Route::get('/settings/ideas-revisit', [IdeasRevisitSettingsController::class, 'index'])->name('settings.ideas-revisit.index');
    Route::put('/settings/ideas-revisit', [IdeasRevisitSettingsController::class, 'update'])->name('settings.ideas-revisit.update');

    Route::get('/settings/working-memory', [WorkingMemorySettingsController::class, 'index'])->name('settings.working-memory.index');
    Route::put('/settings/working-memory', [WorkingMemorySettingsController::class, 'update'])->name('settings.working-memory.update');
    Route::post('/settings/working-memory/build-now', [WorkingMemorySettingsController::class, 'buildNow'])->name('settings.working-memory.build-now');
    Route::middleware('working.memory.ui')->group(function () {
        Route::post('/memory/refresh', [WorkingMemoryRefreshController::class, 'refreshGlobal'])->name('working-memory.refresh.global');
        Route::post('/projects/{project}/memory/refresh', [WorkingMemoryRefreshController::class, 'refreshProject'])->name('working-memory.refresh.project');
        Route::post('/stream/tag/memory/refresh', [WorkingMemoryRefreshController::class, 'refreshTag'])
            ->middleware('signed')
            ->name('working-memory.refresh.tag');
    });

    Route::middleware(['auth', 'working.memory.ui'])->group(function () {
        Route::get('/memory/scopes', [MemoryScopesController::class, 'index'])->name('memory.scopes.index');
        Route::get('/memory', [MemoryController::class, 'show'])->name('memory.show');
        Route::get('/memory/tag', [MemoryController::class, 'showTag'])->name('memory.tag.show');
        Route::get('/memory/project/{scopeKey}', [MemoryController::class, 'showProjectScope'])->where('scopeKey', '[a-z0-9._/-]+')->name('memory.project-scope.show');
        Route::get('/projects/{project}/memory', [MemoryController::class, 'showProject'])->name('projects.memory.show');
        Route::get('/memory/versions', [MemoryController::class, 'historyGlobal'])->name('memory.versions');
        Route::get('/memory/versions/{version}', [MemoryController::class, 'showVersion'])
            ->whereUuid('version')
            ->name('memory.version.show');
        Route::get('/projects/{project}/memory/versions', [MemoryController::class, 'historyProject'])
            ->name('projects.memory.versions');
        Route::get(
            '/memory/{scopeType}/{scopeKey}/compactions/{versionId}',
            [MemoryCompactionController::class, 'show']
        )->name('memory.compactions.show');
    });

    Route::middleware(['auth', 'working.memory.insights'])->group(function () {
        Route::get('/memory/insights', [MemoryInsightsController::class, 'show'])->name('memory.insights');
    });

    Route::get('/settings/skills', [SkillSettingsController::class, 'index'])->name('settings.skills.index');
    Route::put('/settings/skills/preferences', [SkillSettingsController::class, 'updatePreferences'])->name('settings.skills.preferences');
    Route::put('/settings/research-skills/preferences', [SkillSettingsController::class, 'updatePreferences'])->name('settings.research-skills.preferences');

    Route::get('/settings/skills/research/create', [ResearchSkillSettingsController::class, 'create'])->name('settings.skills.research.create');
    Route::post('/settings/skills/research', [ResearchSkillSettingsController::class, 'store'])->name('settings.skills.research.store');
    Route::get('/settings/skills/research/{researchSkill}/edit', [ResearchSkillSettingsController::class, 'edit'])->name('settings.skills.research.edit');
    Route::put('/settings/skills/research/{researchSkill}', [ResearchSkillSettingsController::class, 'update'])->name('settings.skills.research.update');
    Route::post('/settings/skills/research/{researchSkill}/default', [ResearchSkillSettingsController::class, 'setDefault'])->name('settings.skills.research.default');

    Route::get('/settings/skills/meeting/create', [MeetingSkillSettingsController::class, 'create'])->name('settings.skills.meeting.create');
    Route::post('/settings/skills/meeting', [MeetingSkillSettingsController::class, 'store'])->name('settings.skills.meeting.store');
    Route::get('/settings/skills/meeting/{meetingSkill}/edit', [MeetingSkillSettingsController::class, 'edit'])->name('settings.skills.meeting.edit');
    Route::put('/settings/skills/meeting/{meetingSkill}', [MeetingSkillSettingsController::class, 'update'])->name('settings.skills.meeting.update');
    Route::post('/settings/skills/meeting/{meetingSkill}/default', [MeetingSkillSettingsController::class, 'setDefault'])->name('settings.skills.meeting.default');

    Route::post('/settings/research-skills', [ResearchSkillSettingsController::class, 'store'])->name('settings.research-skills.store');
    Route::post('/settings/research-skills/{researchSkill}/default', [ResearchSkillSettingsController::class, 'setDefault'])->name('settings.research-skills.default');
    Route::put('/settings/research-skills/{researchSkill}', [ResearchSkillSettingsController::class, 'update'])->name('settings.research-skills.update');

    Route::get('/settings/research-skills', function () {
        return redirect()->route('settings.skills.index');
    })->name('settings.research-skills.index');
    Route::get('/settings/research-skills/create', function () {
        return redirect()->route('settings.skills.research.create');
    })->name('settings.research-skills.create');
    Route::get('/settings/research-skills/{researchSkill}/edit', function (ResearchSkill $researchSkill) {
        return redirect()->route('settings.skills.research.edit', $researchSkill);
    })->name('settings.research-skills.edit');

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

    Route::middleware('job.search')->prefix('job-pipeline')->name('job_pipeline.')->group(function () {
        Route::get('/applications', [JobApplicationController::class, 'index'])->name('applications.index');
        Route::get('/applications/{application}', [JobApplicationController::class, 'show'])->name('applications.show');
        Route::patch('/applications/{application}', [JobApplicationController::class, 'update'])->name('applications.update');
        Route::post('/applications/{application}/export/{document}', [JobApplicationController::class, 'export'])
            ->where('document', 'cv|cover_letter')->name('applications.export');
        Route::get('/applications/{application}/download/{document}', [JobApplicationController::class, 'download'])
            ->where('document', 'cv|cover_letter')->name('applications.download');

        Route::get('/prospects', [JobProspectController::class, 'index'])->name('prospects.index');
        Route::patch('/prospects/{prospect}', [JobProspectController::class, 'update'])->name('prospects.update');
        Route::post('/prospects/{prospect}/shortlist', [JobProspectController::class, 'shortlist'])->name('prospects.shortlist');
        Route::post('/prospects/{prospect}/mark-applied', [JobProspectController::class, 'markApplied'])->name('prospects.mark-applied');
        Route::post('/prospects/{prospect}/dismiss', [JobProspectController::class, 'dismiss'])->name('prospects.dismiss');

        Route::get('/achievements', [AchievementController::class, 'index'])->name('achievements.index');
        Route::post('/achievements', [AchievementController::class, 'store'])->name('achievements.store');
        Route::patch('/achievements/{achievement}', [AchievementController::class, 'update'])->name('achievements.update');
        Route::post('/achievements/{achievement}/retire', [AchievementController::class, 'retire'])->name('achievements.retire');
    });
});
