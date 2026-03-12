<?php

namespace App\Http\Controllers;

use App\Models\Thought;
use App\Services\OpenRouterService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class IdeaController extends Controller
{
    private const RECENT_LIMIT = 20;

    private const SEARCH_LIMIT = 20;

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
                $thoughts = Thought::query()
                    ->where('user_id', auth()->id())
                    ->nearestTo($embedding, self::SEARCH_LIMIT)
                    ->get();
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
            $metadata = $this->openRouter->extractMetadata($content);

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

            Thought::create($payload);
        } catch (\Throwable $e) {
            report($e);

            if ($request->expectsJson()) {
                return response()->json(['message' => 'Unable to save thought. Please try again.'], 503);
            }

            return redirect()->back()->withInput()->with('error', 'Unable to save thought. Please try again.');
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Thought saved.']);
        }

        return redirect()->route('idea.index')->with('success', 'Thought saved.');
    }

    /**
     * Stream: all top-level thoughts for the user, optionally filtered by tag. Paginated.
     */
    public function stream(Request $request): View
    {
        $request->validate(['tag' => 'nullable|string|max:100']);
        $tag = $request->input('tag');
        $tag = is_string($tag) ? trim($tag) : '';
        $tag = $tag !== '' ? $tag : null;

        $query = Thought::query()
            ->where('user_id', auth()->id())
            ->topLevel()
            ->with(['comments' => fn ($q) => $q->orderBy('created_at')]);

        if ($tag !== null) {
            $query->whereJsonContains('metadata->tags', $tag);
        }

        $thoughts = $query->orderByDesc('created_at')->paginate(self::STREAM_PAGE_SIZE);

        return view('idea.stream', [
            'thoughts' => $thoughts,
            'tag' => $tag,
        ]);
    }
}
