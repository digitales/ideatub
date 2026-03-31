<?php

namespace App\Http\Controllers;

use App\Events\IdeaResearchRequested;
use App\Models\CapturedInboundEmail;
use App\Models\ImportedEmail;
use App\Models\ResearchShare;
use App\Models\Thought;
use App\Models\ThoughtLinkSummary;
use App\Services\DemoMode;
use App\Services\DemoObfuscator;
use App\Services\Email\ThoughtEmailSenderContextResolver;
use App\Services\IdeasToRevisitService;
use App\Services\OpenRouterService;
use App\Services\ResearchService;
use App\Services\ThoughtCaptureService;
use App\Services\ThoughtSearchService;
use App\Support\IdeaCompletedAtSql;
use App\Support\TagSlug;
use App\View\Presenters\Email\EmailMetadataPresenter;
use App\View\Presenters\Email\NewsletterResearchStatusPresenter;
use App\View\Presenters\Ideas\CompletedIdeaPresenter;
use App\View\Presenters\Ideas\IdeaListItemPresenter;
use App\View\Presenters\Thoughts\IdeaIndexCardPresenter;
use App\View\Presenters\Thoughts\StreamThoughtCardPresenter;
use App\View\Presenters\Thoughts\ThoughtDetailPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use League\CommonMark\CommonMarkConverter;
use Symfony\Component\HttpFoundation\Response;

class IdeaController extends Controller
{
    private const RECENT_LIMIT = 20;

    /** Max number of search results to return (only those within similarity threshold). */
    private const SEARCH_LIMIT = 20;

    /** Max cosine distance for search results; only thoughts within this distance are shown. If none match, we fall back to top N by distance. */
    private const SEARCH_MAX_DISTANCE = 0.5;

    private const SEARCH_QUERY_MAX_LENGTH = 2000;

    private const STREAM_PAGE_SIZE = 20;

    public function __construct(
        private OpenRouterService $openRouter,
        private ThoughtCaptureService $captureService,
        private ResearchService $researchService,
        private ThoughtSearchService $searchService,
        private ThoughtEmailSenderContextResolver $thoughtEmailSenderContextResolver,
    ) {}

    /**
     * Idea index: semantic search when ?q= present, otherwise recent top-level thoughts (with comments).
     * When parent_id is in request, pass replyingTo for the capture form context.
     */
    public function index(Request $request): View|RedirectResponse|JsonResponse
    {
        $query = $request->input('q');
        $query = is_string($query) ? trim($query) : '';
        if (mb_strlen($query) > self::SEARCH_QUERY_MAX_LENGTH) {
            $query = mb_substr($query, 0, self::SEARCH_QUERY_MAX_LENGTH);
        }

        if ($query !== '') {
            try {
                $result = $this->searchService->search($query, (int) auth()->id(), [
                    'max_distance' => self::SEARCH_MAX_DISTANCE,
                    'tag_limit' => 100,
                    'semantic_limit' => 100,
                ]);
                $all = $result['thoughts'];
                $total = $result['total'];
                $page = (int) $request->input('page', 1);
                $pageItems = $all->slice(($page - 1) * self::SEARCH_LIMIT, self::SEARCH_LIMIT)->values();
                $thoughts = new LengthAwarePaginator($pageItems, $total, self::SEARCH_LIMIT, $page, [
                    'path' => $request->url(),
                    'query' => $request->query(),
                ]);
                $thoughts->getCollection()->load(['comments' => fn ($q) => $q->orderBy('created_at'), 'parent']);

                if ($request->ajax()) {
                    $replyableOffset = (int) $request->input('replyable_offset', 0);
                    $newsletterResearchStatusPresenters = $this->buildEmailNewsletterResearchStatusPresenters($thoughts->getCollection());
                    $html = view('idea.index_thought_cards', [
                        'cards' => $this->buildIdeaIndexCardPresenters($thoughts, $replyableOffset, $newsletterResearchStatusPresenters),
                    ])->render();

                    return response()->json([
                        'html' => $html,
                        'has_more' => $thoughts->hasMorePages(),
                        'next_page' => $thoughts->currentPage() + 1,
                        'count' => $thoughts->count(),
                    ]);
                }
            } catch (\Throwable $e) {
                report($e);

                return redirect()->route('idea.index')
                    ->with('error', 'Search is temporarily unavailable. Please try again.');
            }
        } else {
            $thoughts = Thought::query()
                ->where('user_id', auth()->id())
                ->visibleInStream()
                ->topLevel()
                ->excludingResearch()
                ->excludingJira()
                ->with(['comments' => fn ($q) => $q->orderBy('created_at')])
                ->orderByDesc('created_at')
                ->limit(self::RECENT_LIMIT)
                ->get();

            if ($request->ajax()) {
                $newsletterResearchStatusPresenters = $this->buildEmailNewsletterResearchStatusPresenters($thoughts);
                $html = view('idea.index_thought_cards', [
                    'cards' => $this->buildIdeaIndexCardPresenters($thoughts, 0, $newsletterResearchStatusPresenters),
                ])->render();
                $latest = $thoughts->isEmpty() ? null : $thoughts->first()->created_at->toIso8601String();

                return response()->json([
                    'html' => $html,
                    'total' => $thoughts->count(),
                    'latest_created_at' => $latest,
                ]);
            }
        }

        $replyingTo = null;
        if ($request->filled('parent_id')) {
            $parent = Thought::query()
                ->where('user_id', auth()->id())
                ->find($request->parent_id);
            if ($parent !== null) {
                $replyingTo = $parent;
            }
        }

        $replyingToPreview = null;
        if ($replyingTo !== null) {
            $limited = Str::limit((string) $replyingTo->content, 80);
            $replyingToPreview = app(DemoMode::class)->enabled()
                ? app(DemoObfuscator::class)->obfuscate($limited, 'idea_index_replying_to_preview')
                : $limited;
        }

        $indexThoughtCollection = $thoughts instanceof LengthAwarePaginator
            ? $thoughts->getCollection()
            : collect($thoughts);
        $newsletterResearchStatusPresenters = $this->buildEmailNewsletterResearchStatusPresenters($indexThoughtCollection);

        return view('idea.index', [
            'thoughts' => $thoughts,
            'query' => $query !== '' ? $query : null,
            'replyingTo' => $replyingTo,
            'replyingToPreview' => $replyingToPreview,
            'cards' => $this->buildIdeaIndexCardPresenters($thoughts, 0, $newsletterResearchStatusPresenters),
        ]);
    }

    /**
     * Show a single thought with its comments (replies). Owner only.
     */
    public function show(Thought $thought): View
    {
        $this->authorize('view', $thought);

        $thought->load(['comments' => fn ($q) => $q->orderBy('created_at')]);
        $importedEmail = $thought->source === 'email' ? $thought->importedEmail() : null;
        if ($importedEmail !== null) {
            $importedEmail->loadMissing('mailAccount');
        }
        $emailDetailPreloadedImport = $thought->source === 'email';
        $senderRuleContext = $emailDetailPreloadedImport
            ? $this->thoughtEmailSenderContextResolver->resolve($thought, $importedEmail, usePreloadedImportedEmail: true)
            : null;
        $contentHtml = null;

        if ($thought->source !== 'email') {
            $contentHtml = $this->renderDemoSafeMarkdown(
                new CommonMarkConverter,
                $thought->content,
                'thought_content'
            );
        }

        $linkedResearchUrl = $this->resolveEmailLinkedResearchUrl(
            $thought,
            $importedEmail,
            $emailDetailPreloadedImport
        );
        $emailResearchPreview = $emailDetailPreloadedImport
            ? $this->buildEmailResearchPreview($thought, $importedEmail, usePreloadedImportedEmail: true)
            : null;
        $newsletterResearchStatus = $emailDetailPreloadedImport
            ? NewsletterResearchStatusPresenter::fromArray(
                $this->demoSafeNewsletterResearchStatusPayload(
                    $this->buildEmailNewsletterResearchStatus($thought, $importedEmail, usePreloadedImportedEmail: true)
                ),
                domIdSuffix: (string) $thought->id
            )
            : null;
        $emailMetadata = $thought->source === 'email'
            ? EmailMetadataPresenter::from($thought, $importedEmail)
            : null;

        $thoughtDetail = ThoughtDetailPresenter::forShow(
            thought: $thought,
            contentHtml: $contentHtml,
            linkedResearchUrl: $linkedResearchUrl,
            emailResearchPreview: $emailResearchPreview,
            newsletterResearchStatus: $newsletterResearchStatus,
            senderRuleContext: $senderRuleContext,
            emailMetadata: $emailMetadata,
            importedEmailForBody: $importedEmail,
        );

        return view('idea.show', [
            'thoughtDetail' => $thoughtDetail,
        ]);
    }

    /**
     * Store a new thought: validate, embed, extract metadata, save. Redirect back with success or JSON.
     * When parent_id is present, authorizes comment on the parent and sets parent_id on the new thought.
     */
    public function store(Request $request): RedirectResponse|JsonResponse
    {
        $validated = $request->validate([
            'content' => 'required|string|max:65535',
            'parent_id' => 'sometimes|nullable|uuid|exists:thoughts,id',
            'no_chunking' => 'sometimes|nullable|boolean',
        ]);
        $content = $validated['content'];
        $parentId = $validated['parent_id'] ?? null;
        $noChunking = ! empty($validated['no_chunking']);

        $parent = null;
        if ($parentId !== null) {
            $parent = Thought::find($parentId);
            if ($parent === null) {
                if ($request->expectsJson()) {
                    return response()->json(['message' => 'Parent thought not found.'], 404);
                }

                return redirect()->back()->withInput()->with('error', 'Parent thought not found.');
            }
            $this->authorize('comment', $parent);
        }

        try {
            $result = $this->captureService->create([
                'content' => $content,
                'user_id' => auth()->id(),
                'parent_id' => $parent?->id,
                'source' => 'web',
                'source_metadata' => null,
                'no_chunking' => $noChunking,
            ]);
        } catch (\Throwable $e) {
            report($e);
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Unable to save thought. Please try again.'], 503);
            }

            return redirect()->back()->withInput()->with('error', 'Unable to save thought. Please try again.');
        }

        if ($result['chunked']) {
            $root = $result['root'];
            if ($request->expectsJson()) {
                $root->load('parent');

                return response()->json([
                    'message' => 'Thought saved as '.$result['count'].' sections.',
                    'thought' => [
                        'id' => $root->id,
                        'content' => $root->getDecodedContent(),
                        'parent_id' => null,
                        'created_at' => $root->created_at->toIso8601String(),
                        'created_at_human' => $root->created_at->diffForHumans(),
                    ],
                    'chunked' => true,
                    'section_ids' => $result['section_ids'],
                ]);
            }

            return redirect()->route('idea.index')
                ->with('success', 'Saved as '.$result['count'].' sections.');
        }

        $thought = $result['thought'];
        if ($request->expectsJson()) {
            $thought->load('parent');

            return response()->json([
                'message' => 'Thought saved.',
                'thought' => [
                    'id' => $thought->id,
                    'content' => $thought->getDecodedContent(),
                    'parent_id' => $thought->parent_id,
                    'created_at' => $thought->created_at->toIso8601String(),
                    'created_at_human' => $thought->created_at->diffForHumans(),
                ],
            ]);
        }

        return redirect()->route('idea.index')->with('success', 'Thought saved.');
    }

    /**
     * Stream: all top-level thoughts for the user, optionally filtered by tag. Paginated.
     * Tag in URL is a slug (e.g. web_development); we resolve it to the canonical tag for querying.
     * For AJAX requests (infinite scroll), returns JSON with HTML fragment and pagination state.
     */
    public function stream(Request $request): View|JsonResponse
    {
        $request->validate(['tag' => 'nullable|string|max:100', 'page' => 'nullable|integer|min:1']);
        $tagSlug = $request->input('tag');
        $tagSlug = is_string($tagSlug) ? trim($tagSlug) : '';
        $tagSlug = $tagSlug !== '' ? $tagSlug : null;

        $canonicalTag = $tagSlug !== null ? $this->resolveTagSlugToCanonical($tagSlug) : null;
        $tagForDisplay = $tagSlug !== null ? ($canonicalTag ?? $tagSlug) : null;

        $query = Thought::query()
            ->where('user_id', auth()->id())
            ->visibleInStream()
            ->topLevel()
            ->excludingJira()
            ->with(['comments' => fn ($q) => $q->orderBy('created_at')]);

        if ($canonicalTag !== null) {
            // Include top-level thoughts that have the tag OR that have any child (section) with the tag,
            // so document roots show even if only section thoughts were tagged.
            $query->where(function ($q) use ($canonicalTag) {
                $q->whereJsonContains('metadata->tags', $canonicalTag)
                    ->orWhereHas('comments', fn ($cq) => $cq->whereJsonContains('metadata->tags', $canonicalTag));
            });
        } elseif ($tagSlug !== null) {
            $query->whereRaw('0 = 1');
        }

        // Tag view = linked document: oldest first (section 1 at top). No tag = general stream: newest first.
        $orderAsc = $canonicalTag !== null;
        $query->orderBy('created_at', $orderAsc ? 'asc' : 'desc');

        $page = (int) $request->input('page', 1);
        $thoughts = $query->paginate(self::STREAM_PAGE_SIZE, ['*'], 'page', $page);

        $shareByThoughtId = ResearchShare::whereIn('thought_id', $thoughts->pluck('id'))
            ->where('user_id', auth()->id())
            ->get()
            ->keyBy('thought_id');

        if ($request->ajax()) {
            $newsletterResearchStatusPresenters = $this->buildEmailNewsletterResearchStatusPresenters($thoughts->getCollection());
            $html = view('idea.stream_thoughts', [
                'cards' => $this->buildStreamThoughtCardPresenters(
                    $thoughts,
                    $shareByThoughtId,
                    $tagForDisplay !== null,
                    $newsletterResearchStatusPresenters
                ),
            ])->render();

            $streamSince = $this->firstPageCreatedAtCursor($thoughts, $canonicalTag !== null);

            return response()->json([
                'html' => $html,
                'has_more' => $thoughts->hasMorePages(),
                'next_page' => $thoughts->currentPage() + 1,
                'count' => $thoughts->count(),
                'total' => $thoughts->total(),
                'latest_created_at' => $streamSince,
            ]);
        }

        $streamNewsletterPresenters = $this->buildEmailNewsletterResearchStatusPresenters($thoughts->getCollection());

        return view('idea.stream', [
            'thoughts' => $thoughts,
            'tag' => $tagForDisplay,
            'tagSlug' => $tagSlug,
            'streamJira' => false,
            'streamCollectionKey' => null,
            'streamSince' => $this->firstPageCreatedAtCursor($thoughts, $canonicalTag !== null),
            'shareByThoughtId' => $shareByThoughtId,
            'cards' => $this->buildStreamThoughtCardPresenters(
                $thoughts,
                $shareByThoughtId,
                $tagForDisplay !== null,
                $streamNewsletterPresenters
            ),
        ]);
    }

    /**
     * Jira stream: top-level thoughts matching the canonical Jira type. Paginated; same view as stream.
     */
    public function streamJira(Request $request): View|JsonResponse
    {
        $request->validate(['page' => 'nullable|integer|min:1']);
        $page = (int) $request->input('page', 1);

        $thoughts = Thought::query()
            ->where('user_id', auth()->id())
            ->visibleInStream()
            ->topLevel()
            ->matchingCanonicalSourceType('jira')
            ->with(['comments' => fn ($q) => $q->orderBy('created_at')])
            ->orderByRaw("COALESCE((source_metadata->>'jira_updated_at')::timestamptz, created_at) DESC")
            ->paginate(self::STREAM_PAGE_SIZE, ['*'], 'page', $page);

        return $this->streamCollectionResponse(
            $request,
            $thoughts,
            'jira',
            function (LengthAwarePaginator $thoughts) {
                if ($thoughts->isEmpty()) {
                    return null;
                }
                $first = $thoughts->first();

                return $first->source_metadata['jira_updated_at'] ?? $first->created_at?->toIso8601String();
            }
        );
    }

    /**
     * Email thoughts matching the canonical email type, including stored aliases.
     */
    public function streamEmails(Request $request): View|JsonResponse
    {
        $request->validate(['page' => 'nullable|integer|min:1']);
        $page = (int) $request->input('page', 1);

        $thoughts = Thought::query()
            ->where('user_id', auth()->id())
            ->visibleInStream()
            ->topLevel()
            ->matchingCanonicalSourceType('email')
            ->with(['comments' => fn ($q) => $q->orderBy('created_at')])
            ->orderByDesc('created_at')
            ->paginate(self::STREAM_PAGE_SIZE, ['*'], 'page', $page);

        return $this->streamCollectionResponse(
            $request,
            $thoughts,
            'email',
            fn (LengthAwarePaginator $thoughts) => $thoughts->isNotEmpty()
                ? $thoughts->first()->created_at->toIso8601String()
                : null
        );
    }

    /**
     * Research thoughts matching the canonical research type.
     */
    public function streamResearch(Request $request): View|JsonResponse
    {
        $request->validate(['page' => 'nullable|integer|min:1']);
        $page = (int) $request->input('page', 1);

        $thoughts = Thought::query()
            ->where('user_id', auth()->id())
            ->visibleInStream()
            ->topLevel()
            ->matchingCanonicalMetadataType('research')
            ->with(['comments' => fn ($q) => $q->orderBy('created_at')])
            ->orderByDesc('created_at')
            ->paginate(self::STREAM_PAGE_SIZE, ['*'], 'page', $page);

        return $this->streamCollectionResponse(
            $request,
            $thoughts,
            'research',
            fn (LengthAwarePaginator $thoughts) => $thoughts->isNotEmpty()
                ? $thoughts->first()->created_at->toIso8601String()
                : null
        );
    }

    /**
     * Plan thoughts matching the canonical plan type, including stored aliases.
     */
    public function streamPlans(Request $request): View|JsonResponse
    {
        $request->validate(['page' => 'nullable|integer|min:1']);
        $page = (int) $request->input('page', 1);

        $thoughts = Thought::query()
            ->where('user_id', auth()->id())
            ->visibleInStream()
            ->topLevel()
            ->matchingCanonicalMetadataType('plan')
            ->with(['comments' => fn ($q) => $q->orderBy('created_at')])
            ->orderByDesc('created_at')
            ->paginate(self::STREAM_PAGE_SIZE, ['*'], 'page', $page);

        return $this->streamCollectionResponse(
            $request,
            $thoughts,
            'plan',
            fn (LengthAwarePaginator $thoughts) => $thoughts->isNotEmpty()
                ? $thoughts->first()->created_at->toIso8601String()
                : null
        );
    }

    /**
     * Shared HTML/JSON response for typed stream collection pages (Jira, Emails, Research, Plans).
     *
     * @param  callable(LengthAwarePaginator<int, Thought>): string|null  $latestForAjax
     */
    private function streamCollectionResponse(
        Request $request,
        LengthAwarePaginator $thoughts,
        string $streamCollectionKey,
        callable $latestForAjax
    ): View|JsonResponse {
        $shareByThoughtId = ResearchShare::whereIn('thought_id', $thoughts->pluck('id'))
            ->where('user_id', auth()->id())
            ->get()
            ->keyBy('thought_id');

        if ($request->ajax()) {
            $newsletterResearchStatusPresenters = $this->buildEmailNewsletterResearchStatusPresenters($thoughts->getCollection());
            $html = view('idea.stream_thoughts', [
                'cards' => $this->buildStreamThoughtCardPresenters(
                    $thoughts,
                    $shareByThoughtId,
                    false,
                    $newsletterResearchStatusPresenters
                ),
            ])->render();
            $streamSince = $latestForAjax($thoughts);

            return response()->json([
                'html' => $html,
                'has_more' => $thoughts->hasMorePages(),
                'next_page' => $thoughts->currentPage() + 1,
                'count' => $thoughts->count(),
                'total' => $thoughts->total(),
                'latest_created_at' => $streamSince,
            ]);
        }

        $typedStreamNewsletterPresenters = $this->buildEmailNewsletterResearchStatusPresenters($thoughts->getCollection());

        return view('idea.stream', [
            'thoughts' => $thoughts,
            'tag' => null,
            'tagSlug' => null,
            'streamJira' => $streamCollectionKey === 'jira',
            'streamCollectionKey' => $streamCollectionKey,
            'streamSince' => $latestForAjax($thoughts),
            'shareByThoughtId' => $shareByThoughtId,
            'cards' => $this->buildStreamThoughtCardPresenters(
                $thoughts,
                $shareByThoughtId,
                false,
                $typedStreamNewsletterPresenters
            ),
        ]);
    }

    /**
     * @param  LengthAwarePaginator<int, Thought>|\Illuminate\Database\Eloquent\Collection<int, Thought>|Collection<int, Thought>  $thoughts
     * @param  array<string, NewsletterResearchStatusPresenter|null>  $newsletterPresenters
     * @return Collection<int, IdeaIndexCardPresenter>
     */
    private function buildIdeaIndexCardPresenters(
        LengthAwarePaginator|Collection $thoughts,
        int $replyableIndexStart,
        array $newsletterPresenters,
    ): Collection {
        $collection = $thoughts instanceof LengthAwarePaginator
            ? $thoughts->getCollection()
            : collect($thoughts);

        $replyableIndex = $replyableIndexStart;

        return $collection->map(function (Thought $thought) use (&$replyableIndex, $newsletterPresenters) {
            if (! $thought->parent_id) {
                $currentReplyable = $replyableIndex;
                $replyableIndex++;
            } else {
                $currentReplyable = -1;
            }

            return IdeaIndexCardPresenter::fromThought(
                $thought,
                $currentReplyable,
                $newsletterPresenters[$thought->id] ?? null
            );
        });
    }

    /**
     * @param  LengthAwarePaginator<int, Thought>  $thoughts
     * @param  array<string, NewsletterResearchStatusPresenter|null>  $newsletterPresenters
     * @return Collection<int, StreamThoughtCardPresenter>
     */
    private function buildStreamThoughtCardPresenters(
        LengthAwarePaginator $thoughts,
        Collection $shareByThoughtId,
        bool $showFullSections,
        array $newsletterPresenters,
    ): Collection {
        return $thoughts->getCollection()->map(function (Thought $thought) use ($shareByThoughtId, $showFullSections, $newsletterPresenters) {
            /** @var ResearchShare|null $share */
            $share = $shareByThoughtId->get($thought->id);

            return StreamThoughtCardPresenter::fromThought(
                $thought,
                $share,
                $showFullSections,
                $newsletterPresenters[$thought->id] ?? null
            );
        });
    }

    private function firstPageCreatedAtCursor(LengthAwarePaginator $thoughts, bool $ascending): ?string
    {
        if ($thoughts->isEmpty()) {
            return null;
        }

        $cursorThought = $ascending ? $thoughts->last() : $thoughts->first();

        return $cursorThought?->created_at?->toIso8601String();
    }

    /**
     * Ideas to revisit: incomplete ideas ordered by age (oldest first), limited by user preferences.
     */
    public function revisit(IdeasToRevisitService $revisitService): View
    {
        $ideas = $revisitService->forUser(auth()->user());

        return view('idea.revisit', ['ideas' => $ideas]);
    }

    /**
     * Completed ideas: metadata.type idea and completed, ordered via IdeaCompletedAtSql (timestamped first, then legacy).
     */
    public function completed(): View
    {
        $ideas = IdeaCompletedAtSql::applyCompletedIdeaOrdering(
            Thought::query()
                ->where('user_id', auth()->id())
                ->completedIdeas()
        )->paginate(20);

        $completedRows = $this->buildCompletedIdeaPresenters($ideas);

        return view('idea.completed', [
            'ideas' => $ideas,
            'completedRows' => $completedRows,
        ]);
    }

    /**
     * Ideas list: thoughts with metadata.type = 'idea', paginated. Add-idea form at top.
     * Loads research thoughts for each idea (newest first) for display.
     * For AJAX requests, returns JSON with first-page HTML for realtime refetch.
     */
    public function ideas(Request $request): View|JsonResponse
    {
        $ideas = Thought::query()
            ->where('user_id', auth()->id())
            ->incompleteIdeas()
            ->orderByDesc('created_at')
            ->paginate(20);

        $ideaIds = $ideas->pluck('id');
        $researchByIdea = collect();
        if ($ideaIds->isNotEmpty()) {
            $researchThoughts = Thought::query()
                ->where('user_id', auth()->id())
                ->where('metadata->type', 'research')
                ->where(function ($q) use ($ideaIds) {
                    foreach ($ideaIds as $id) {
                        $q->orWhere('metadata->idea_id', $id);
                    }
                })
                ->orderByDesc('created_at')
                ->get();
            $researchByIdea = $researchThoughts->groupBy(fn (Thought $t) => $t->metadata['idea_id'] ?? '');
        }

        $ideaRows = $this->buildIdeaListItemPresenters($ideas, $researchByIdea);

        if ($request->ajax()) {
            $html = view('idea.partials.ideas_list', [
                'ideas' => $ideas,
                'ideaRows' => $ideaRows,
            ])->render();
            $latest = $ideas->isEmpty() ? null : $ideas->first()->created_at->toIso8601String();

            return response()->json(['html' => $html, 'latest_created_at' => $latest]);
        }

        return view('idea.ideas', [
            'ideas' => $ideas,
            'ideaRows' => $ideaRows,
        ]);
    }

    /**
     * @param  LengthAwarePaginator<int, Thought>  $ideas
     * @return Collection<int, IdeaListItemPresenter>
     */
    private function buildIdeaListItemPresenters(LengthAwarePaginator $ideas, Collection $researchByIdea): Collection
    {
        return $ideas->getCollection()->map(function (Thought $thought) use ($researchByIdea) {
            $researchList = $researchByIdea->get($thought->id, collect());

            return IdeaListItemPresenter::from($thought, $researchList);
        })->values();
    }

    /**
     * @param  LengthAwarePaginator<int, Thought>  $ideas
     * @return Collection<int, CompletedIdeaPresenter>
     */
    private function buildCompletedIdeaPresenters(LengthAwarePaginator $ideas): Collection
    {
        return $ideas->getCollection()->map(fn (Thought $thought) => CompletedIdeaPresenter::from($thought))->values();
    }

    /**
     * Store a new idea (thought with metadata.type = 'idea'). Validates content, optional logged_date (Y-m-d), and optional completed (boolean).
     */
    public function storeIdea(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'content' => 'required|string|max:65535',
            'logged_date' => 'nullable|date_format:Y-m-d',
            'completed' => 'sometimes|boolean',
        ]);
        $content = $validated['content'];
        $loggedDate = $validated['logged_date'] ?? now()->toDateString();

        try {
            $this->captureService->create([
                'content' => $content,
                'user_id' => auth()->id(),
                'parent_id' => null,
                'source' => 'web',
                'source_metadata' => null,
                'idea_metadata' => [
                    'type' => 'idea',
                    'completed' => $validated['completed'] ?? false,
                    'logged_date' => $loggedDate,
                ],
            ]);
        } catch (\Throwable $e) {
            report($e);

            return redirect()->route('idea.ideas')->withInput()->with('error', 'Unable to save idea. Please try again.');
        }

        return redirect()->route('idea.ideas')->with('success', 'Idea saved.');
    }

    /**
     * Toggle idea completed state. Authorizes update on thought; returns 404 if not an idea.
     */
    public function toggleCompleted(Request $request, Thought $thought): RedirectResponse|JsonResponse
    {
        $this->authorize('update', $thought);

        if (($thought->metadata['type'] ?? null) !== 'idea') {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Not an idea.'], 422);
            }

            return redirect()->route('idea.ideas')->with('error', 'Not an idea.')->setStatusCode(Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $metadata = $thought->metadata ?? [];
        $wasCompleted = $thought->isIdeaCompleted();
        $completed = ! $wasCompleted;

        $metadata['type'] = 'idea';
        $metadata['completed'] = $completed;
        $metadata['logged_date'] = $metadata['logged_date'] ?? $thought->created_at->toDateString();

        if ($completed) {
            $metadata['completed_at'] = now()->toIso8601String();
        } else {
            unset($metadata['completed_at']);
        }

        $thought->update(['metadata' => $metadata]);
        $thought->refresh();

        if ($request->expectsJson()) {
            return response()->json([
                'completed' => $completed,
                'completed_at' => $completed ? ($thought->metadata['completed_at'] ?? null) : null,
            ]);
        }

        return redirect()
            ->back(fallback: route('idea.ideas'))
            ->with('success', $completed ? 'Marked as complete.' : 'Marked as incomplete.');
    }

    /**
     * Update tags on a thought. Authorizes update; validates tags array; normalizes and deduplicates.
     */
    public function updateTags(Request $request, Thought $thought): RedirectResponse|JsonResponse
    {
        $this->authorize('update', $thought);

        $validated = $request->validate([
            'tags' => 'present|array',
            'tags.*' => 'string|max:100',
        ]);
        $tags = $validated['tags'];
        $tags = array_map(fn ($t) => trim((string) $t), $tags);
        $normalized = Thought::normalizeMetadataTags(['tags' => $tags]);
        $tags = array_values(array_unique($normalized['tags']));

        $metadata = array_merge($thought->metadata ?? [], ['tags' => $tags]);
        $thought->update(['metadata' => $metadata]);

        if ($request->expectsJson()) {
            return response()->json(['tags' => $tags]);
        }

        return redirect()->back()->with('success', 'Tags updated.');
    }

    /**
     * Update thought content only. Tags and metadata stay untouched.
     */
    public function updateContent(Request $request, Thought $thought): RedirectResponse|JsonResponse
    {
        $this->authorize('update', $thought);

        $validated = $request->validate([
            'content' => ['required', 'string', 'max:65535'],
        ]);

        $content = trim($validated['content']);
        if ($content === '') {
            throw ValidationException::withMessages([
                'content' => ['Content cannot be empty.'],
            ]);
        }

        $thought->update(['content' => $content]);

        if ($request->expectsJson() || $request->ajax()) {
            return response()->json(['content' => $thought->content]);
        }

        return redirect()->back()->with('success', 'Thought updated.');
    }

    /**
     * Delete a thought. Owner only; blocked if the thought has comments.
     */
    public function destroy(Request $request, Thought $thought): RedirectResponse|JsonResponse
    {
        $this->authorize('delete', $thought);

        if ($thought->comments()->exists()) {
            if ($request->expectsJson() || $request->ajax()) {
                return response()->json(
                    ['message' => 'This thought has comments. Remove them first.'],
                    Response::HTTP_UNPROCESSABLE_ENTITY
                );
            }

            return redirect()->back()
                ->with('error', 'This thought has comments. Remove them first.')
                ->setStatusCode(Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $thought->delete();

        if ($request->expectsJson() || $request->ajax()) {
            return response()->json(null, Response::HTTP_NO_CONTENT);
        }

        return redirect()->back()->with('success', 'Thought deleted.');
    }

    /**
     * Run research for an existing idea in the background. Authorizes that the user owns the thought.
     */
    public function research(Thought $thought): RedirectResponse
    {
        $this->authorize('update', $thought);

        if (($thought->metadata['type'] ?? null) !== 'idea') {
            return redirect()->route('idea.ideas')->with('error', 'Not an idea.');
        }

        $metadata = array_merge($thought->metadata ?? [], ['research_pending' => true]);
        $thought->update(['metadata' => $metadata]);

        IdeaResearchRequested::dispatch($thought, 'web');

        return redirect()->back()->with('success', 'Research started. This may take a moment — refresh to see results.');
    }

    /**
     * Create a new idea and run research in the background (body: content).
     */
    public function researchNew(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'content' => 'required|string|max:65535',
        ]);
        $content = $validated['content'];

        try {
            $idea = $this->researchService->createIdeaOnly($content, 'web');
        } catch (\Throwable $e) {
            report($e);

            return redirect()->route('idea.ideas')
                ->withInput()
                ->with('error', 'Unable to save idea. Please try again.');
        }

        $metadata = array_merge($idea->metadata ?? [], ['research_pending' => true]);
        $idea->update(['metadata' => $metadata]);

        IdeaResearchRequested::dispatch($idea, 'web');

        return redirect()->route('idea.ideas')
            ->with('success', 'Idea saved. Research started — refresh in a moment to see results.');
    }

    /**
     * Show a single thought (research document) with formatted markdown. Full app chrome.
     * If the thought has a parent, redirect to the parent so the full document is shown.
     */
    public function showResearch(Thought $thought): View|RedirectResponse
    {
        $this->authorize('view', $thought);

        if ($thought->parent_id !== null) {
            return redirect()->route('idea.research.show', ['thought' => $thought->parent_id]);
        }

        $isResearchRoot = Thought::query()
            ->whereKey($thought->id)
            ->where('user_id', auth()->id())
            ->matchingCanonicalMetadataType('research')
            ->exists();

        if (! $isResearchRoot) {
            return redirect()->route('thoughts.show', $thought);
        }

        $sections = $thought->comments()->orderBy('created_at')->get();
        $converter = new CommonMarkConverter;

        $rootHtml = $converter->convert($thought->content)->getContent();
        $sectionsWithHtml = $sections->map(function ($section) use ($converter) {
            return (object) [
                'content_html' => $converter->convert($section->content)->getContent(),
            ];
        });

        $relatedEmail = $this->resolveResearchRelatedEmailCard($thought);
        $editorialLinkSummaries = $this->buildResearchEditorialLinkSummaryViewModel($thought);

        return view('idea.research_show', [
            'root' => $thought,
            'root_html' => $rootHtml,
            'sections' => $sectionsWithHtml,
            'relatedEmail' => $relatedEmail,
            'editorialLinkSummaries' => $editorialLinkSummaries,
        ]);
    }

    /**
     * View model for editorial link summaries on the research page: grouped by newsletter section, ordered by usefulness.
     *
     * @return array{
     *     show: bool,
     *     sections: list<array{label: string, section_order: int, items: list<array<string, mixed>}>},
     *     pending_count: int,
     *     failed_count: int
     * }
     */
    private function buildResearchEditorialLinkSummaryViewModel(Thought $root): array
    {
        $rows = ThoughtLinkSummary::query()
            ->where('parent_research_thought_id', $root->id)
            ->where('user_id', $root->user_id)
            ->get();

        $editorial = $rows->filter(fn (ThoughtLinkSummary $row) => $row->classification === 'editorial')->values();

        $pendingCount = $editorial
            ->filter(fn (ThoughtLinkSummary $row) => in_array($row->processing_status, ['queued', 'fetching'], true))
            ->count();
        $failedCount = $editorial
            ->filter(fn (ThoughtLinkSummary $row) => $row->processing_status === 'failed')
            ->count();

        $show = $editorial->isNotEmpty() || $pendingCount > 0 || $failedCount > 0;

        if (! $show) {
            return [
                'show' => false,
                'sections' => [],
                'pending_count' => 0,
                'failed_count' => 0,
            ];
        }

        $groups = $editorial->groupBy(fn (ThoughtLinkSummary $row) => $row->newsletter_section_label ?? '');

        $sections = $groups
            ->map(function (Collection $items, string $labelKey) {
                $minOrder = $items->pluck('newsletter_section_order')->filter(fn ($v) => $v !== null)->min();
                $sectionOrder = $minOrder === null ? PHP_INT_MAX : (int) $minOrder;
                $displayLabel = $labelKey === '' ? 'Other links' : $labelKey;

                $sortedItems = $items
                    ->sort(fn (ThoughtLinkSummary $a, ThoughtLinkSummary $b) => $this->compareEditorialLinkSummariesForDisplay($a, $b))
                    ->values();

                $mappedItems = $sortedItems
                    ->map(fn (ThoughtLinkSummary $row) => $this->mapEditorialLinkSummaryRowForResearchView($row))
                    ->all();

                return [
                    'label' => $displayLabel,
                    'section_order' => $sectionOrder,
                    'items' => $mappedItems,
                ];
            })
            ->values()
            ->sortBy('section_order')
            ->values()
            ->all();

        return [
            'show' => true,
            'sections' => $sections,
            'pending_count' => $pendingCount,
            'failed_count' => $failedCount,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mapEditorialLinkSummaryRowForResearchView(ThoughtLinkSummary $row): array
    {
        $title = $row->resolved_title;
        if ($title === null || trim($title) === '') {
            $title = $row->original_url;
        }

        return [
            'title' => $title,
            'url' => $row->original_url,
            'summary_text' => $row->summary_text,
            'relation_label' => $row->support_judgment,
            'why_it_matters' => $row->why_it_matters,
            'quality_notes' => $row->quality_notes,
            'processing_status' => $row->processing_status,
        ];
    }

    private function compareEditorialLinkSummariesForDisplay(ThoughtLinkSummary $a, ThoughtLinkSummary $b): int
    {
        $scoreA = $a->usefulness_score;
        $scoreB = $b->usefulness_score;

        if ($scoreA === null && $scoreB === null) {
            return $this->compareEditorialLinkSummarySectionRank($a, $b);
        }
        if ($scoreA === null) {
            return 1;
        }
        if ($scoreB === null) {
            return -1;
        }
        if ($scoreA !== $scoreB) {
            return $scoreB <=> $scoreA;
        }

        return $this->compareEditorialLinkSummarySectionRank($a, $b);
    }

    private function compareEditorialLinkSummarySectionRank(ThoughtLinkSummary $a, ThoughtLinkSummary $b): int
    {
        $rankA = $a->section_rank;
        $rankB = $b->section_rank;

        if ($rankA === null && $rankB === null) {
            return 0;
        }
        if ($rankA === null) {
            return 1;
        }
        if ($rankB === null) {
            return -1;
        }

        return $rankA <=> $rankB;
    }

    /**
     * Resolve a URL for the research document linked from an email thought (metadata + durable stored email only).
     *
     * @param  ?ImportedEmail  $preloadedImportedEmail  When $usePreloadedImportedEmail is true, use this row (or null) for durable imported-email linkage instead of calling {@see Thought::importedEmail()}.
     */
    private function resolveEmailLinkedResearchUrl(
        Thought $thought,
        ?ImportedEmail $preloadedImportedEmail = null,
        bool $usePreloadedImportedEmail = false,
    ): ?string {
        $research = $this->resolveEmailLinkedResearchThought($thought, $preloadedImportedEmail, $usePreloadedImportedEmail);

        return $research !== null ? route('idea.research.show', $research) : null;
    }

    /**
     * Resolve the linked research thought for an email (metadata + durable stored email rows), or null when ambiguous or missing.
     *
     * @param  ?ImportedEmail  $preloadedImportedEmail  When $usePreloadedImportedEmail is true, use this row (or null) instead of calling {@see Thought::importedEmail()}.
     */
    private function resolveEmailLinkedResearchThought(
        Thought $thought,
        ?ImportedEmail $preloadedImportedEmail = null,
        bool $usePreloadedImportedEmail = false,
    ): ?Thought {
        if ($thought->source !== 'email') {
            return null;
        }

        $metaId = $this->normalizeResearchThoughtId(data_get($thought->source_metadata, 'research_thought_id'));

        $imported = $usePreloadedImportedEmail
            ? $preloadedImportedEmail
            : $thought->importedEmail();
        $importedResearchId = $this->normalizeResearchThoughtId($imported?->research_thought_id);

        $captured = $this->resolveCapturedInboundEmailForThought($thought);
        $capturedResearchId = $this->normalizeResearchThoughtId($captured?->research_thought_id);

        if ($importedResearchId !== null && $capturedResearchId !== null && $importedResearchId !== $capturedResearchId) {
            return null;
        }

        $storedIds = array_values(array_unique(array_filter([$importedResearchId, $capturedResearchId])));
        $storedId = null;
        if (count($storedIds) === 1) {
            $storedId = $storedIds[0];
        }

        if ($metaId !== null && $storedId !== null && $metaId !== $storedId) {
            return null;
        }

        $candidateId = $metaId ?? $storedId;
        if ($candidateId === null) {
            return null;
        }

        return Thought::query()
            ->whereKey($candidateId)
            ->where('user_id', auth()->id())
            ->matchingCanonicalMetadataType('research')
            ->first();
    }

    /**
     * @param  Collection<int, Thought>  $thoughts
     * @return array<string, array<string, string|bool>|null>
     */
    private function buildEmailNewsletterResearchStatuses(Collection $thoughts): array
    {
        $statuses = [];

        foreach ($thoughts as $thought) {
            $statuses[$thought->id] = $this->buildEmailNewsletterResearchStatus($thought);
        }

        return $statuses;
    }

    /**
     * @param  Collection<int, Thought>  $thoughts
     * @return array<string, NewsletterResearchStatusPresenter|null>
     */
    private function buildEmailNewsletterResearchStatusPresenters(Collection $thoughts): array
    {
        $payloads = $this->buildEmailNewsletterResearchStatuses($thoughts);
        $presenters = [];

        foreach ($thoughts as $thought) {
            $presenters[$thought->id] = NewsletterResearchStatusPresenter::fromArray(
                $this->demoSafeNewsletterResearchStatusPayload($payloads[$thought->id] ?? null),
                domIdSuffix: (string) $thought->id
            );
        }

        return $presenters;
    }

    /**
     * @param  ?ImportedEmail  $preloadedImportedEmail  When $usePreloadedImportedEmail is true, use this row (or null) instead of calling {@see Thought::importedEmail()}.
     * @return array{status: string, research_thought_id: string|null, skip_reason: string, show_research_link: bool, show_skip_info: bool}|null
     */
    private function buildEmailNewsletterResearchStatus(
        Thought $thought,
        ?ImportedEmail $preloadedImportedEmail = null,
        bool $usePreloadedImportedEmail = false,
    ): ?array {
        if ($thought->source !== 'email') {
            return null;
        }

        $newsletterResearch = data_get($thought->source_metadata, 'newsletter_research');
        $metadataStatus = is_array($newsletterResearch) ? trim((string) ($newsletterResearch['status'] ?? '')) : '';
        $metadataResearchThoughtId = is_array($newsletterResearch)
            ? $this->normalizeResearchThoughtId($newsletterResearch['research_thought_id'] ?? null)
            : null;
        $reasonRaw = is_array($newsletterResearch) ? ($newsletterResearch['reason'] ?? null) : null;
        $skipReason = is_string($reasonRaw)
            ? trim($reasonRaw)
            : (is_scalar($reasonRaw) ? trim((string) $reasonRaw) : '');

        $linkedResearch = $this->resolveEmailLinkedResearchThought($thought, $preloadedImportedEmail, $usePreloadedImportedEmail);
        $researchThoughtId = $linkedResearch?->id ?? $metadataResearchThoughtId;

        $effectiveStatus = $metadataStatus !== '' ? $metadataStatus : null;
        if ($linkedResearch !== null) {
            $effectiveStatus = $metadataStatus === 'research_partial'
                ? 'research_partial'
                : 'research_completed';
        }

        if (! is_string($effectiveStatus) || $effectiveStatus === '') {
            return null;
        }

        return [
            'status' => $effectiveStatus,
            'research_thought_id' => $researchThoughtId,
            'skip_reason' => $skipReason,
            'show_research_link' => $researchThoughtId !== null && in_array($effectiveStatus, ['research_completed', 'research_partial'], true),
            'show_skip_info' => $effectiveStatus === 'research_skipped' && $skipReason !== '',
        ];
    }

    /**
     * Preview payload for the email thought detail page: full research URL, rendered root HTML, and up to two section HTML chunks (child thoughts, same order as the full research page).
     *
     * @param  ?ImportedEmail  $preloadedImportedEmail  When $usePreloadedImportedEmail is true, use this row (or null) instead of calling {@see Thought::importedEmail()}.
     * @return array{full_research_url: string, root_html: string, section_html_chunks: array<int, string>}|null
     */
    private function buildEmailResearchPreview(
        Thought $emailThought,
        ?ImportedEmail $preloadedImportedEmail = null,
        bool $usePreloadedImportedEmail = false,
    ): ?array {
        $resolved = $this->resolveEmailLinkedResearchThought($emailThought, $preloadedImportedEmail, $usePreloadedImportedEmail);
        if ($resolved === null) {
            return null;
        }

        $documentRoot = $this->resolveResearchDocumentRootForPreview($resolved);
        if ($documentRoot === null) {
            return null;
        }

        $isResearchRoot = Thought::query()
            ->whereKey($documentRoot->id)
            ->where('user_id', auth()->id())
            ->matchingCanonicalMetadataType('research')
            ->exists();

        if (! $isResearchRoot) {
            return null;
        }

        $converter = new CommonMarkConverter;
        $rootHtml = $this->renderDemoSafeMarkdown(
            $converter,
            $documentRoot->content,
            'email_research_preview_root'
        );
        $sections = $documentRoot->comments()->orderBy('created_at')->get();
        $sectionHtmlChunks = $sections->take(2)->map(function (Thought $section) use ($converter) {
            return $this->renderDemoSafeMarkdown(
                $converter,
                $section->content,
                'email_research_preview_section'
            );
        })->values()->all();

        if (! $this->researchEmailPreviewHasRenderableBody($rootHtml, $sectionHtmlChunks)) {
            return null;
        }

        return [
            'full_research_url' => route('idea.research.show', $documentRoot),
            'root_html' => $rootHtml,
            'section_html_chunks' => $sectionHtmlChunks,
        ];
    }

    private function resolveResearchDocumentRootForPreview(Thought $resolvedResearch): ?Thought
    {
        if ($resolvedResearch->parent_id === null) {
            return $resolvedResearch;
        }

        return Thought::query()
            ->whereKey($resolvedResearch->parent_id)
            ->where('user_id', auth()->id())
            ->matchingCanonicalMetadataType('research')
            ->first();
    }

    /**
     * @param  array<int, string>  $sectionHtmlChunks
     */
    private function researchEmailPreviewHasRenderableBody(string $rootHtml, array $sectionHtmlChunks): bool
    {
        $hasText = fn (string $html): bool => trim(strip_tags($html)) !== '';

        if ($hasText($rootHtml)) {
            return true;
        }

        foreach ($sectionHtmlChunks as $chunk) {
            if ($hasText($chunk)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array{status: string, research_thought_id: string|null, skip_reason: string, show_research_link: bool, show_skip_info: bool}|null  $payload
     * @return array{status: string, research_thought_id: string|null, skip_reason: string, show_research_link: bool, show_skip_info: bool}|null
     */
    private function demoSafeNewsletterResearchStatusPayload(?array $payload): ?array
    {
        if ($payload === null || ! app(DemoMode::class)->enabled()) {
            return $payload;
        }

        $skip = $payload['skip_reason'] ?? '';
        if (! is_string($skip) || $skip === '') {
            return $payload;
        }

        $payload['skip_reason'] = app(DemoObfuscator::class)->obfuscate($skip, 'newsletter_research_skip_reason')
            ?? 'Demo content hidden';

        return $payload;
    }

    private function renderDemoSafeMarkdown(CommonMarkConverter $converter, ?string $markdown, string $context): string
    {
        $displayMarkdown = $markdown ?? '';

        if (app(DemoMode::class)->enabled()) {
            try {
                $displayMarkdown = app(DemoObfuscator::class)->obfuscate($displayMarkdown, $context) ?? 'Demo content hidden';
            } catch (\Throwable $e) {
                Log::warning('Demo obfuscation failed before markdown render.', [
                    'boundary' => 'idea_controller.render_demo_safe_markdown',
                    'context' => $context,
                    'exception' => $e::class,
                ]);
                $displayMarkdown = 'Demo content hidden';
            }
        }

        return $converter->convert($displayMarkdown)->getContent();
    }

    /**
     * @return array{subject: string, sender: string, url: string}|null
     */
    private function resolveResearchRelatedEmailCard(Thought $researchRoot): ?array
    {
        $merged = $this->mergeResearchRootEmailLinkageFields(
            $researchRoot->metadata ?? [],
            $researchRoot->source_metadata ?? []
        );

        if ($merged === null) {
            return null;
        }

        $emailThoughtId = $merged['email_thought_id'];
        $subject = $merged['email_subject'];
        $sender = $merged['email_sender'];

        $emailThought = Thought::query()
            ->whereKey($emailThoughtId)
            ->where('user_id', auth()->id())
            ->matchingCanonicalSourceType('email')
            ->first();

        if ($emailThought === null) {
            return null;
        }

        return [
            'subject' => $subject,
            'sender' => $sender,
            'url' => route('thoughts.show', $emailThought),
        ];
    }

    /**
     * Merge email linkage fields from research root metadata and source_metadata.
     * Uses values from either bag when the other omits a field; when both supply a non-empty value for the same field, they must agree or the card is omitted.
     *
     * @return array{email_thought_id: string, email_subject: string, email_sender: string}|null
     */
    private function mergeResearchRootEmailLinkageFields(array $metadata, array $sourceMetadata): ?array
    {
        $keys = ['email_thought_id', 'email_subject', 'email_sender'];
        $merged = [];

        foreach ($keys as $key) {
            $rawM = data_get($metadata, $key);
            $rawS = data_get($sourceMetadata, $key);
            $normM = $key === 'email_thought_id'
                ? $this->normalizeResearchThoughtId($rawM)
                : $this->normalizeResearchEmailCardTextField($rawM);
            $normS = $key === 'email_thought_id'
                ? $this->normalizeResearchThoughtId($rawS)
                : $this->normalizeResearchEmailCardTextField($rawS);

            if ($normM !== null && $normS !== null && $normM !== $normS) {
                return null;
            }

            if ($normM !== null) {
                $merged[$key] = trim((string) $rawM);
            } elseif ($normS !== null) {
                $merged[$key] = trim((string) $rawS);
            } else {
                return null;
            }
        }

        return $merged;
    }

    private function normalizeResearchEmailCardTextField(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function normalizeResearchThoughtId(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $id = strtolower(trim((string) $value));

        return $id === '' ? null : $id;
    }

    private function resolveCapturedInboundEmailForThought(Thought $thought): ?CapturedInboundEmail
    {
        $capturedId = data_get($thought->source_metadata, 'captured_inbound_email_id');
        if ($capturedId !== null && (string) $capturedId !== '') {
            $row = CapturedInboundEmail::query()
                ->where('user_id', $thought->user_id)
                ->find($capturedId);
            if ($row !== null) {
                return $row;
            }
        }

        return CapturedInboundEmail::query()
            ->where('user_id', $thought->user_id)
            ->where('thought_id', $thought->id)
            ->first();
    }

    /**
     * Resolve a URL slug (e.g. web_development) to the canonical tag value stored in metadata (e.g. "web development").
     */
    private function resolveTagSlugToCanonical(string $tagSlug): ?string
    {
        $tags = Thought::query()
            ->where('user_id', auth()->id())
            ->select('metadata')
            ->get()
            ->pluck('metadata')
            ->pluck('tags')
            ->flatten()
            ->unique()
            ->filter()
            ->values();

        foreach ($tags as $t) {
            if (TagSlug::from((string) $t) === $tagSlug) {
                return $t;
            }
        }

        return null;
    }
}
