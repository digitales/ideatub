<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Thought;
use App\Services\OpenRouterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class McpController extends Controller
{
    public function __construct(
        private OpenRouterService $openRouter
    ) {}

    /**
     * Handle MCP JSON-RPC request: authenticate by key, dispatch by method, return JSON-RPC response.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $key = $request->query('key') ?? $request->header('x-brain-key');
        $expected = config('services.mcp.access_key');

        if ($expected === null || $expected === '' || ! hash_equals((string) ($key ?? ''), $expected)) {
            return response()->json([
                'jsonrpc' => '2.0',
                'error' => ['code' => -32001, 'message' => 'Unauthorized: invalid or missing MCP key'],
                'id' => null,
            ], 401);
        }

        $body = $request->all();
        $method = $body['method'] ?? null;
        $params = $body['params'] ?? [];
        $id = $body['id'] ?? null;

        if (! is_string($method) || $method === '') {
            return $this->jsonRpcError(-32600, 'Invalid request: method required', $id);
        }

        $knownMethods = ['search_thoughts', 'browse_recent', 'thought_stats', 'capture_thought'];
        if (! in_array($method, $knownMethods, true)) {
            return $this->jsonRpcError(-32601, 'Method not found', $id);
        }

        try {
            $result = $this->dispatch($method, is_array($params) ? $params : []);
        } catch (\InvalidArgumentException $e) {
            return $this->jsonRpcError(-32602, $e->getMessage(), $id);
        } catch (\Throwable $e) {
            report($e);

            return $this->jsonRpcError(-32603, 'Internal error', $id);
        }

        return response()->json([
            'jsonrpc' => '2.0',
            'result' => $result,
            'id' => $id,
        ]);
    }

    /**
     * Dispatch to tool by method name. Phase 0: no user_id filter.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function dispatch(string $method, array $params): array
    {
        return match ($method) {
            'search_thoughts' => $this->searchThoughts($params),
            'browse_recent' => $this->browseRecent($params),
            'thought_stats' => $this->thoughtStats($params),
            'capture_thought' => $this->captureThought($params),
            default => throw new \InvalidArgumentException("Unknown method: {$method}"),
        };
    }

    /**
     * search_thoughts: embed query, then Thought::nearestTo(embedding, limit). Params: query, limit?.
     *
     * @param  array<string, mixed>  $params
     * @return array{thoughts: array<int, array{id: string, content: string, metadata: array|null, created_at: string}>}
     */
    private function searchThoughts(array $params): array
    {
        $v = Validator::make($params, [
            'query' => 'required|string',
            'limit' => 'sometimes|integer|min:1|max:100',
        ]);
        if ($v->fails()) {
            throw new \InvalidArgumentException($v->errors()->first());
        }
        $query = (string) $params['query'];
        $limit = (int) ($params['limit'] ?? 10);

        $embedding = $this->openRouter->embed($query);
        $thoughts = Thought::query()
            ->nearestTo($embedding, $limit)
            ->get(['id', 'content', 'metadata', 'created_at']);

        return [
            'thoughts' => $thoughts->map(fn (Thought $t) => [
                'id' => $t->id,
                'content' => $t->content,
                'metadata' => $t->metadata,
                'created_at' => $t->created_at->toIso8601String(),
            ])->values()->all(),
        ];
    }

    /**
     * browse_recent: recent thoughts order by created_at desc, limit N. Params: limit?.
     *
     * @param  array<string, mixed>  $params
     * @return array{thoughts: array<int, array{id: string, content: string, metadata: array|null, created_at: string}>}
     */
    private function browseRecent(array $params): array
    {
        $v = Validator::make($params, [
            'limit' => 'sometimes|integer|min:1|max:100',
        ]);
        if ($v->fails()) {
            throw new \InvalidArgumentException($v->errors()->first());
        }
        $limit = (int) ($params['limit'] ?? 10);

        $thoughts = Thought::query()
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get(['id', 'content', 'metadata', 'created_at']);

        return [
            'thoughts' => $thoughts->map(fn (Thought $t) => [
                'id' => $t->id,
                'content' => $t->content,
                'metadata' => $t->metadata,
                'created_at' => $t->created_at->toIso8601String(),
            ])->values()->all(),
        ];
    }

    /**
     * thought_stats: count of thoughts. Params: none.
     *
     * @param  array<string, mixed>  $params
     * @return array{count: int}
     */
    private function thoughtStats(array $params): array
    {
        $count = Thought::query()->count();

        return ['count' => $count];
    }

    /**
     * capture_thought: embed + extractMetadata + save Thought. Params: content. Phase 0: user_id null.
     *
     * @param  array<string, mixed>  $params
     * @return array{id: string}
     */
    private function captureThought(array $params): array
    {
        $v = Validator::make($params, [
            'content' => 'required|string',
        ]);
        if ($v->fails()) {
            throw new \InvalidArgumentException($v->errors()->first());
        }
        $content = (string) $params['content'];

        $embedding = $this->openRouter->embed($content);
        $metadata = $this->openRouter->extractMetadata($content);

        $thought = Thought::create([
            'content' => $content,
            'embedding' => $embedding,
            'metadata' => $metadata,
            'user_id' => null,
        ]);

        return ['id' => $thought->id];
    }

    private function jsonRpcError(int $code, string $message, mixed $id): JsonResponse
    {
        return response()->json([
            'jsonrpc' => '2.0',
            'error' => ['code' => $code, 'message' => $message],
            'id' => $id,
        ], 200);
    }
}
