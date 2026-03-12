<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Thought;
use App\Services\OpenRouterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ThoughtsApiController extends Controller
{
    public function __construct(
        private OpenRouterService $openRouter
    ) {}

    /**
     * GET /api/thoughts/search — Semantic search (search_thoughts).
     * Query params: query (required), limit (optional, 1–100, default 10).
     */
    public function search(Request $request): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'query' => 'required|string',
            'limit' => 'sometimes|integer|min:1|max:100',
        ]);
        if ($v->fails()) {
            return response()->json(['error' => 'validation_error', 'message' => $v->errors()->first()], 422);
        }

        $query = $request->input('query');
        $limit = (int) $request->input('limit', 10);

        $embedding = $this->openRouter->embed($query);
        $thoughts = Thought::query()
            ->where('user_id', auth()->id())
            ->nearestTo($embedding, $limit)
            ->get(['id', 'content', 'metadata', 'created_at', 'source', 'source_metadata']);

        return response()->json([
            'thoughts' => $thoughts->map(fn (Thought $t) => [
                'id' => $t->id,
                'content' => $t->content,
                'metadata' => $t->metadata,
                'created_at' => $t->created_at->toIso8601String(),
                'source' => $t->source,
                'source_metadata' => $t->source_metadata,
            ])->values()->all(),
        ]);
    }

    /**
     * GET /api/thoughts/recent — List recent thoughts (browse_recent).
     * Query params: limit (optional, 1–100, default 10).
     */
    public function recent(Request $request): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'limit' => 'sometimes|integer|min:1|max:100',
        ]);
        if ($v->fails()) {
            return response()->json(['error' => 'validation_error', 'message' => $v->errors()->first()], 422);
        }

        $limit = (int) $request->input('limit', 10);

        $thoughts = Thought::query()
            ->where('user_id', auth()->id())
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get(['id', 'content', 'metadata', 'created_at', 'source', 'source_metadata']);

        return response()->json([
            'thoughts' => $thoughts->map(fn (Thought $t) => [
                'id' => $t->id,
                'content' => $t->content,
                'metadata' => $t->metadata,
                'created_at' => $t->created_at->toIso8601String(),
                'source' => $t->source,
                'source_metadata' => $t->source_metadata,
            ])->values()->all(),
        ]);
    }

    /**
     * GET /api/thoughts/stats — Count of thoughts (thought_stats).
     */
    public function stats(): JsonResponse
    {
        $count = Thought::query()->where('user_id', auth()->id())->count();

        return response()->json(['count' => $count]);
    }

    /**
     * POST /api/thoughts — Create a thought (capture_thought).
     * Body: content (required); parent_id or in_reply_to (optional UUID); source, source_metadata (optional).
     */
    public function store(Request $request): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'content' => 'required|string',
            'parent_id' => 'sometimes|nullable|uuid',
            'in_reply_to' => 'sometimes|nullable|uuid',
            'source' => 'sometimes|nullable|string|max:64',
            'source_metadata' => 'sometimes|nullable|array',
        ]);
        if ($v->fails()) {
            return response()->json(['error' => 'validation_error', 'message' => $v->errors()->first()], 422);
        }

        $content = (string) $request->input('content');
        $parentId = $request->input('parent_id');
        if (empty($parentId) && $request->filled('in_reply_to')) {
            $parentId = $request->input('in_reply_to');
        }
        $parentId = $parentId ? (string) $parentId : null;

        $source = $request->filled('source')
            ? mb_substr(trim((string) $request->input('source')), 0, 64)
            : 'api';
        $sourceMetadata = $request->input('source_metadata');
        if (! is_array($sourceMetadata)) {
            $sourceMetadata = null;
        }

        $parent = null;
        if ($parentId !== null) {
            $parent = Thought::find($parentId);
            if ($parent === null) {
                return response()->json(['error' => 'Parent thought not found.'], 404);
            }
            if ($parent->user_id !== auth()->id()) {
                return response()->json(['error' => 'Parent thought does not belong to you.'], 403);
            }
        }

        try {
            $embedding = $this->openRouter->embed($content);
            $metadata = Thought::normalizeMetadataTags($this->openRouter->extractMetadata($content));
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['error' => 'Unable to save thought. Please try again.'], 503);
        }

        $payload = [
            'content' => $content,
            'embedding' => $embedding,
            'metadata' => $metadata,
            'user_id' => auth()->id(),
            'source' => $source,
            'source_metadata' => $sourceMetadata,
        ];
        if ($parent !== null) {
            $payload['parent_id'] = $parent->id;
        }

        $thought = Thought::create($payload);

        return response()->json(['id' => $thought->id], 201);
    }
}
