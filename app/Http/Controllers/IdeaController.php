<?php

namespace App\Http\Controllers;

use App\Models\Thought;
use App\Services\OpenRouterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;
use Illuminate\View\View;

class IdeaController extends Controller
{
    private const RECENT_LIMIT = 20;

    /** Max number of search results to return (only those within similarity threshold). */
    private const SEARCH_LIMIT = 20;

    /** Max cosine distance for search results; only thoughts within this distance are shown. If none match, we fall back to top N by distance. */
    private const SEARCH_MAX_DISTANCE = 0.9;

    private const SEARCH_QUERY_MAX_LENGTH = 2000;

    private const STREAM_PAGE_SIZE = 20;

    public function __construct(
        private OpenRouterService $openRouter
    ) {}

    /**
     * Idea index: semantic search when ?q= present, otherwise recent top-level thoughts (with comments).
     * When parent_id is in request, pass replyingTo for the capture form context.
     */
    public function index(Request $request): View|RedirectResponse
    {
        $query = $request->input('q');
        $query = is_string($query) ? trim($query) : '';
        if (mb_strlen($query) > self::SEARCH_QUERY_MAX_LENGTH) {
            $query = mb_substr($query, 0, self::SEARCH_QUERY_MAX_LENGTH);
        }

        if ($query !== '') {
            try {
                $embedding = $this->openRouter->embed($query);
                $page = (int) $request->input('page', 1);
                $baseQuery = Thought::query()
                    ->where('user_id', auth()->id())
                    ->with(['comments' => fn ($q) => $q->orderBy('created_at'), 'parent']);

                $thoughts = (clone $baseQuery)
                    ->nearestWithin($embedding, self::SEARCH_MAX_DISTANCE)
                    ->paginate(self::SEARCH_LIMIT, ['*'], 'page', $page);

                // If threshold filtered everything out, fall back to top N by distance (no threshold)
                if ($thoughts->total() === 0) {
                    $fallback = (clone $baseQuery)->nearestTo($embedding, self::SEARCH_LIMIT)->get();
                    $thoughts = new LengthAwarePaginator(
                        $fallback,
                        $fallback->count(),
                        self::SEARCH_LIMIT,
                        1,
                        ['path' => $request->url(), 'query' => $request->query()]
                    );
                }

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
                ->with(['comments' => fn ($q) => $q->orderBy('created_at')])
                ->orderByDesc('created_at')
                ->limit(self::RECENT_LIMIT)
                ->get();
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
        ]);
        $content = $validated['content'];
        $parentId = $validated['parent_id'] ?? null;

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
            $embedding = $this->openRouter->embed($content);
            $metadata = Thought::normalizeMetadataTags($this->openRouter->extractMetadata($content));

            $payload = [
                'content' => $content,
                'embedding' => $embedding,
                'metadata' => $metadata,
                'user_id' => auth()->id(),
                'source' => 'web',
                'source_metadata' => null,
            ];
            if ($parent !== null) {
                $payload['parent_id'] = $parent->id;
            }

            $thought = Thought::create($payload);
        } catch (\Throwable $e) {
            report($e);

            if ($request->expectsJson()) {
                return response()->json(['message' => 'Unable to save thought. Please try again.'], 503);
            }

            return redirect()->back()->withInput()->with('error', 'Unable to save thought. Please try again.');
        }

        if ($request->expectsJson()) {
            $thought->load('parent');
            return response()->json([
                'message' => 'Thought saved.',
                'thought' => [
                    'id' => $thought->id,
                    'content' => $thought->content,
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
            ->with(['comments' => fn ($q) => $q->orderBy('created_at')]);

        if ($canonicalTag !== null) {
            $query->whereJsonContains('metadata->tags', $canonicalTag);
        } elseif ($tagSlug !== null) {
            $query->whereRaw('0 = 1');
        }

        // Tag view = linked document: oldest first (section 1 at top). No tag = general stream: newest first.
        $orderAsc = $canonicalTag !== null;
        $query->orderBy('created_at', $orderAsc ? 'asc' : 'desc');

        $page = (int) $request->input('page', 1);
        $thoughts = $query->paginate(self::STREAM_PAGE_SIZE, ['*'], 'page', $page);

        if ($request->ajax()) {
            $html = view('idea.stream_thoughts', ['thoughts' => $thoughts])->render();

            return response()->json([
                'html' => $html,
                'has_more' => $thoughts->hasMorePages(),
                'next_page' => $thoughts->currentPage() + 1,
                'count' => $thoughts->count(),
            ]);
        }

        return view('idea.stream', [
            'thoughts' => $thoughts,
            'tag' => $tagForDisplay,
            'tagSlug' => $tagSlug,
        ]);
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
