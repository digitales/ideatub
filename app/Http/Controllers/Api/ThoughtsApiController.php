<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Thought;
use App\Services\OpenRouterService;
use App\Services\ThoughtCaptureService;
use App\Services\ThoughtSearchService;
use App\Services\WorkingMemory\WorkingMemoryAssembler;
use App\Services\WorkingMemory\WorkingMemoryUpsertService;
use App\Services\WorkingMemory\WorkingMemoryVersionCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ThoughtsApiController extends Controller
{
    public function __construct(
        private OpenRouterService $openRouter,
        private ThoughtCaptureService $captureService,
        private ThoughtSearchService $searchService,
        private WorkingMemoryAssembler $workingMemoryAssembler,
        private WorkingMemoryVersionCatalog $workingMemoryVersionCatalog,
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

        $result = $this->searchService->search($query, (int) auth()->id(), [
            'max_distance' => 0.5,
            'tag_limit' => 100,
            'semantic_limit' => 100,
        ]);
        $thoughts = $result['thoughts']->take($limit);

        return response()->json([
            'thoughts' => $thoughts->map(fn (Thought $t) => [
                'id' => $t->id,
                'content' => $t->getDecodedContent(),
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
            ->visibleInStream()
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get(['id', 'content', 'metadata', 'created_at', 'source', 'source_metadata']);

        return response()->json([
            'thoughts' => $thoughts->map(fn (Thought $t) => [
                'id' => $t->id,
                'content' => $t->getDecodedContent(),
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
        $count = Thought::query()
            ->where('user_id', auth()->id())
            ->visibleInStream()
            ->count();

        return response()->json(['count' => $count]);
    }

    /**
     * GET /api/thoughts/working-memory — Scoped working memory snapshot.
     * Query params: scope_type (required, global|project), scope_key (required, max 191).
     */
    public function workingMemory(Request $request): JsonResponse
    {
        $input = $request->only(['scope_type', 'scope_key']);
        foreach (['scope_type', 'scope_key'] as $key) {
            if (isset($input[$key]) && is_string($input[$key])) {
                $input[$key] = trim($input[$key]);
            }
        }

        $v = Validator::make($input, [
            'scope_type' => 'required|string|in:global,project,insights,tag',
            'scope_key' => 'required|string|max:191',
        ]);
        if ($v->fails()) {
            return response()->json(['error' => 'validation_error', 'message' => $v->errors()->first()], 422);
        }

        /** @var array{scope_type: string, scope_key: string} $validated */
        $validated = $v->validated();

        try {
            $payload = $this->workingMemoryAssembler->forScope((int) auth()->id(), $validated['scope_type'], $validated['scope_key']);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => 'validation_error', 'message' => $e->getMessage()], 422);
        }

        return response()->json($payload);
    }

    /**
     * GET /api/thoughts/working-memory/versions — Paginated version history for a scope.
     * Query params: scope_type (required), scope_key (required), include_compactions (optional bool), per_page (optional, max 50).
     */
    public function workingMemoryVersions(Request $request): JsonResponse
    {
        $input = $request->only(['scope_type', 'scope_key', 'include_compactions', 'per_page']);
        foreach (['scope_type', 'scope_key'] as $key) {
            if (isset($input[$key]) && is_string($input[$key])) {
                $input[$key] = trim($input[$key]);
            }
        }

        $v = Validator::make($input, [
            'scope_type' => 'required|string|in:global,project,insights,tag',
            'scope_key' => 'required|string|max:191',
            'include_compactions' => 'sometimes|boolean',
            'per_page' => 'sometimes|integer|min:1|max:50',
        ]);
        if ($v->fails()) {
            return response()->json(['error' => 'validation_error', 'message' => $v->errors()->first()], 422);
        }

        /** @var array{scope_type: string, scope_key: string, include_compactions?: bool, per_page?: int} $validated */
        $validated = $v->validated();

        try {
            $paginator = $this->workingMemoryVersionCatalog->listForScope(
                (int) auth()->id(),
                $validated['scope_type'],
                $validated['scope_key'],
                (bool) ($validated['include_compactions'] ?? false),
                (int) ($validated['per_page'] ?? 20),
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => 'validation_error', 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data' => collect($paginator->items())
                ->map(fn ($version) => $this->workingMemoryVersionCatalog->toListItem($version))
                ->values()
                ->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    /**
     * GET /api/thoughts/working-memory/versions/{version} — Full version payload.
     */
    public function workingMemoryVersion(Request $request, string $version): JsonResponse
    {
        $versionModel = $this->workingMemoryVersionCatalog->showForUser((int) auth()->id(), $version);

        return response()->json($this->workingMemoryVersionCatalog->toDetailPayload($versionModel));
    }

    /**
     * POST /api/thoughts/working-memory/upsert — Upsert external working memory.
     */
    public function upsertWorkingMemory(Request $request): JsonResponse
    {
        $input = $request->only(['scope_type', 'scope_key', 'content', 'source_label']);
        foreach (['scope_type', 'scope_key', 'content', 'source_label'] as $key) {
            if (isset($input[$key]) && is_string($input[$key])) {
                $input[$key] = trim($input[$key]);
            }
        }

        $v = Validator::make($input, [
            'scope_type' => 'required|string|in:global,project,insights,tag',
            'scope_key' => 'required|string|max:191',
            'content' => 'required|string|min:1',
            'source_label' => 'nullable|string|max:191',
        ]);
        if ($v->fails()) {
            return response()->json(['error' => 'validation_error', 'message' => $v->errors()->first()], 422);
        }

        /** @var array{scope_type: string, scope_key: string, content: string, source_label: ?string} $validated */
        $validated = $v->validated();

        try {
            $version = app(WorkingMemoryUpsertService::class)->upsert(
                (int) auth()->id(),
                $validated['scope_type'],
                $validated['scope_key'],
                $validated['content'],
                $validated['source_label'] ?? null,
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => 'validation_error', 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'build_type' => $version->build_type,
            'version_id' => (string) $version->id,
            'scope_type' => $version->workingMemory->scope_type,
            'scope_key' => $version->workingMemory->scope_key,
            'freshness_state' => $version->workingMemory->freshness_state,
        ]);
    }

    /**
     * POST /api/thoughts — Create a thought (capture_thought).
     * Body: content (required); parent_id or in_reply_to (optional UUID); source, source_metadata, no_chunking (optional).
     * When content is over 500 words and no_chunking is not set, creates chunked thoughts (root + sections).
     */
    public function store(Request $request): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'content' => 'required|string|max:65535',
            'parent_id' => 'sometimes|nullable|uuid',
            'in_reply_to' => 'sometimes|nullable|uuid',
            'source' => 'sometimes|nullable|string|max:64',
            'source_metadata' => 'sometimes|nullable|array',
            'no_chunking' => 'sometimes|nullable|boolean',
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
        $noChunking = $request->boolean('no_chunking');

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
            $result = $this->captureService->create([
                'content' => $content,
                'user_id' => auth()->id(),
                'parent_id' => $parent?->id,
                'source' => $source,
                'source_metadata' => $sourceMetadata,
                'no_chunking' => $noChunking,
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => 'validation_error', 'message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['error' => 'Unable to save thought. Please try again.'], 503);
        }

        if ($result['chunked']) {
            return response()->json([
                'id' => $result['root']->id,
                'chunked' => true,
                'section_ids' => $result['section_ids'],
            ], 201);
        }

        return response()->json(['id' => $result['thought']->id], 201);
    }
}
