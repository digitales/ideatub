<?php

namespace App\Http\Controllers;

use App\Events\IdeaResearchRequested;
use App\Models\Thought;
use App\Services\IdeasToRevisitService;
use App\Services\OpenRouterService;
use App\Services\ResearchService;
use App\Services\ThoughtCaptureService;
use App\Services\ThoughtSearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;
use Illuminate\View\View;
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
        private ThoughtSearchService $searchService
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
                    $html = view('idea.index_thought_cards', [
                        'thoughts' => $thoughts,
                        'replyableIndexStart' => $replyableOffset,
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
                ->topLevel()
                ->excludingResearch()
                ->excludingJira()
                ->with(['comments' => fn ($q) => $q->orderBy('created_at')])
                ->orderByDesc('created_at')
                ->limit(self::RECENT_LIMIT)
                ->get();

            if ($request->ajax()) {
                $html = view('idea.index_thought_cards', ['thoughts' => $thoughts, 'replyableIndexStart' => 0])->render();
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

        return view('idea.index', [
            'thoughts' => $thoughts,
            'query' => $query !== '' ? $query : null,
            'replyingTo' => $replyingTo,
        ]);
    }

    /**
     * Store a new thought: validate, embed, extract metadata, save. Redirect back with success or JSON.
     * When parent_id is present, authorizes comment on the parent and sets parent_id on the new thought.
     */
    public function store(Request $request): RedirectResponse|\Illuminate\Http\JsonResponse
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

        if ($request->ajax()) {
            $html = view('idea.stream_thoughts', [
                'thoughts' => $thoughts,
                'showFullSections' => $tagForDisplay !== null,
            ])->render();

            $orderAsc = $canonicalTag !== null;
            $latestCreatedAt = $thoughts->isNotEmpty()
                ? ($orderAsc ? $thoughts->last() : $thoughts->first())->created_at->toIso8601String()
                : null;

            return response()->json([
                'html' => $html,
                'has_more' => $thoughts->hasMorePages(),
                'next_page' => $thoughts->currentPage() + 1,
                'count' => $thoughts->count(),
                'total' => $thoughts->total(),
                'latest_created_at' => $latestCreatedAt,
            ]);
        }

        return view('idea.stream', [
            'thoughts' => $thoughts,
            'tag' => $tagForDisplay,
            'tagSlug' => $tagSlug,
            'streamJira' => false,
        ]);
    }

    /**
     * Jira stream: top-level thoughts with source = 'jira' only. Paginated; same view as stream.
     */
    public function streamJira(Request $request): View|JsonResponse
    {
        $request->validate(['page' => 'nullable|integer|min:1']);
        $page = (int) $request->input('page', 1);

        $thoughts = Thought::query()
            ->where('user_id', auth()->id())
            ->topLevel()
            ->where('source', 'jira')
            ->with(['comments' => fn ($q) => $q->orderBy('created_at')])
            ->orderByDesc('created_at')
            ->paginate(self::STREAM_PAGE_SIZE, ['*'], 'page', $page);

        if ($request->ajax()) {
            $html = view('idea.stream_thoughts', [
                'thoughts' => $thoughts,
                'showFullSections' => false,
            ])->render();
            $latestCreatedAt = $thoughts->isNotEmpty()
                ? $thoughts->first()->created_at->toIso8601String()
                : null;

            return response()->json([
                'html' => $html,
                'has_more' => $thoughts->hasMorePages(),
                'next_page' => $thoughts->currentPage() + 1,
                'count' => $thoughts->count(),
                'total' => $thoughts->total(),
                'latest_created_at' => $latestCreatedAt,
            ]);
        }

        return view('idea.stream', [
            'thoughts' => $thoughts,
            'tag' => null,
            'tagSlug' => null,
            'streamJira' => true,
        ]);
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
     * Ideas list: thoughts with metadata.type = 'idea', paginated. Add-idea form at top.
     * Loads research thoughts for each idea (newest first) for display.
     * For AJAX requests, returns JSON with first-page HTML for realtime refetch.
     */
    public function ideas(Request $request): View|JsonResponse
    {
        $ideas = Thought::query()
            ->where('user_id', auth()->id())
            ->ideas()
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

        if ($request->ajax()) {
            $html = view('idea.partials.ideas_list', ['ideas' => $ideas, 'researchByIdea' => $researchByIdea])->render();
            $latest = $ideas->isEmpty() ? null : $ideas->first()->created_at->toIso8601String();

            return response()->json(['html' => $html, 'latest_created_at' => $latest]);
        }

        return view('idea.ideas', [
            'ideas' => $ideas,
            'researchByIdea' => $researchByIdea,
        ]);
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

        $completed = ! ($thought->metadata['completed'] ?? false);
        $metadata = array_merge($thought->metadata ?? [], [
            'type' => 'idea',
            'completed' => $completed,
            'logged_date' => $thought->metadata['logged_date'] ?? $thought->created_at->toDateString(),
        ]);
        if (isset($thought->metadata['tags']) && is_array($thought->metadata['tags'])) {
            $metadata['tags'] = $thought->metadata['tags'];
        }
        $thought->update(['metadata' => $metadata]);

        if ($request->expectsJson()) {
            return response()->json(['completed' => $completed]);
        }

        return redirect()->route('idea.ideas')->with('success', $completed ? 'Marked as complete.' : 'Marked as incomplete.');
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
            if (Str::slug($t, '_') === $tagSlug) {
                return $t;
            }
        }

        return null;
    }
}
