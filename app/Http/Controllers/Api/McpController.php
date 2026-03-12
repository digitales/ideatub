<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Thought;
use App\Models\User;
use App\Models\UserMcpKey;
use App\Services\OAuthMcpJwtService;
use App\Services\OpenRouterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class McpController extends Controller
{
    public function __construct(
        private OpenRouterService $openRouter,
        private ?OAuthMcpJwtService $oauthJwt = null
    ) {}

    /**
     * GET /api/mcp — Server info for connector discovery/validation (e.g. ChatGPT "Add connector").
     * No auth required so the connector URL can be validated before the user supplies a key.
     */
    public function show(Request $request): JsonResponse
    {
        return response()->json([
            'name' => 'ideatub',
            'version' => '1.0',
            'protocol' => 'json-rpc',
            'auth' => 'Send key via ?key=... or x-ideatub-key header',
            'methods' => ['search_thoughts', 'browse_recent', 'thought_stats', 'capture_thought'],
        ]);
    }

    /**
     * Handle MCP JSON-RPC request: authenticate by per-user key, dispatch by method, return JSON-RPC response.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return $this->unauthorizedResponse();
        }

        $request->setUserResolver(fn () => $user);
        Auth::setUser($user);

        $body = $request->all();
        $method = $body['method'] ?? null;
        $params = is_array($body['params'] ?? null) ? $body['params'] : [];
        $id = $body['id'] ?? null;

        if (! is_string($method) || $method === '') {
            return $this->jsonRpcError(-32600, 'Invalid request: method required', $id);
        }

        // Standard MCP protocol methods (e.g. ChatGPT connector)
        if ($method === 'initialize') {
            return $this->respondInitialize($params, $id);
        }
        if ($method === 'notifications/initialized') {
            return response()->json(['jsonrpc' => '2.0']);
        }
        if ($method === 'tools/list') {
            return $this->respondToolsList($id);
        }
        if ($method === 'tools/call') {
            return $this->respondToolsCall($params, $id);
        }

        // Legacy direct method names (search_thoughts, browse_recent, etc.)
        $knownMethods = ['search_thoughts', 'browse_recent', 'thought_stats', 'capture_thought'];
        if (! in_array($method, $knownMethods, true)) {
            return $this->jsonRpcError(-32601, 'Method not found', $id);
        }

        try {
            $result = $this->dispatch($method, $params);
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

    private function respondInitialize(array $params, mixed $id): JsonResponse
    {
        $requestedVersion = $params['protocolVersion'] ?? '2024-11-05';
        $supported = ['2024-11-05', '2025-03-26'];
        $protocolVersion = in_array($requestedVersion, $supported, true) ? $requestedVersion : '2024-11-05';

        return response()->json([
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => [
                'protocolVersion' => $protocolVersion,
                'capabilities' => [
                    'tools' => (object) [],
                ],
                'serverInfo' => [
                    'name' => 'ideatub',
                    'version' => '1.0',
                ],
            ],
        ]);
    }

    private function respondToolsList(mixed $id): JsonResponse
    {
        $tools = [
            [
                'name' => 'search_thoughts',
                'description' => 'Search your captured thoughts by meaning. Use when the user asks about a topic, person, or idea they have previously captured.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => ['type' => 'string', 'description' => 'What to search for'],
                        'limit' => ['type' => 'integer', 'description' => 'Max results (default 10)', 'default' => 10],
                    ],
                    'required' => ['query'],
                ],
            ],
            [
                'name' => 'browse_recent',
                'description' => 'List your most recently captured thoughts.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'limit' => ['type' => 'integer', 'description' => 'Max results (default 10)', 'default' => 10],
                    ],
                ],
            ],
            [
                'name' => 'thought_stats',
                'description' => 'Get a count of your captured thoughts.',
                'inputSchema' => ['type' => 'object', 'properties' => (object) []],
            ],
            [
                'name' => 'capture_thought',
                'description' => 'Save a new thought to IdeaTub. Use when the user wants to remember something. Optional parent_id to add a comment to an existing thought.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'content' => ['type' => 'string', 'description' => 'The thought to save'],
                        'parent_id' => ['type' => 'string', 'description' => 'Optional UUID of parent thought (for comments)'],
                        'in_reply_to' => ['type' => 'string', 'description' => 'Alias for parent_id'],
                        'source' => ['type' => 'string', 'description' => 'Optional source label (e.g. chatgpt, claude, cursor)'],
                        'source_metadata' => ['type' => 'object', 'description' => 'Optional source-specific metadata'],
                    ],
                    'required' => ['content'],
                ],
            ],
        ];

        return response()->json([
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => ['tools' => $tools],
        ]);
    }

    private function respondToolsCall(array $params, mixed $id): JsonResponse
    {
        $name = $params['name'] ?? null;
        $arguments = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];

        if (! is_string($name) || $name === '') {
            return $this->jsonRpcError(-32602, 'tools/call requires "name"', $id);
        }

        $knownMethods = ['search_thoughts', 'browse_recent', 'thought_stats', 'capture_thought'];
        if (! in_array($name, $knownMethods, true)) {
            return $this->jsonRpcError(-32601, 'Method not found', $id);
        }

        try {
            $result = $this->dispatch($name, $arguments);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'jsonrpc' => '2.0',
                'id' => $id,
                'result' => [
                    'content' => [['type' => 'text', 'text' => 'Error: '.$e->getMessage()]],
                    'isError' => true,
                ],
            ]);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'jsonrpc' => '2.0',
                'id' => $id,
                'result' => [
                    'content' => [['type' => 'text', 'text' => 'Internal error']],
                    'isError' => true,
                ],
            ]);
        }

        $text = is_array($result) ? json_encode($result, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) : (string) $result;

        return response()->json([
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => [
                'content' => [['type' => 'text', 'text' => $text]],
            ],
        ]);
    }

    private function resolveUser(Request $request): ?User
    {
        $auth = $request->header('Authorization');
        if (is_string($auth) && str_starts_with(strtolower($auth), 'bearer ')) {
            $token = trim(substr($auth, 7));
            if ($token !== '' && $this->oauthJwt && config('oauth-mcp.enabled', true)) {
                try {
                    $payload = $this->oauthJwt->verifyAccessToken($token);

                    return User::find($payload['user_id']);
                } catch (\Throwable) {
                    return null;
                }
            }
        }

        $key = $request->query('key') ?? $request->header('x-ideatub-key');
        $key = is_string($key) ? trim($key) : '';
        if ($key === '') {
            return null;
        }

        $mcpKey = UserMcpKey::findByPlainKey($key);
        if ($mcpKey === null) {
            return null;
        }

        $user = $mcpKey->user;
        if ($user !== null) {
            $mcpKey->update(['last_used_at' => now()]);
        }

        return $user;
    }

    private function unauthorizedResponse(): JsonResponse
    {
        $resourceMetadata = config('oauth-mcp.enabled', true)
            ? rtrim(config('app.url'), '/').'/.well-known/oauth-protected-resource'
            : null;

        $response = response()->json([
            'jsonrpc' => '2.0',
            'error' => ['code' => -32001, 'message' => 'Unauthorized: invalid or missing MCP key or token'],
            'id' => null,
        ], 401);

        if ($resourceMetadata !== null) {
            $scope = config('oauth-mcp.scope', 'ideatub:mcp');
            $response->header('WWW-Authenticate', sprintf(
                'Bearer resource_metadata="%s", scope="%s"',
                $resourceMetadata,
                $scope
            ));
        }

        return $response;
    }

    /**
     * Dispatch to tool by method name. All tools are scoped to the resolved user.
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
            ->where('user_id', auth()->id())
            ->nearestTo($embedding, $limit)
            ->get(['id', 'content', 'metadata', 'created_at', 'source', 'source_metadata']);

        return [
            'thoughts' => $thoughts->map(fn (Thought $t) => [
                'id' => $t->id,
                'content' => $t->content,
                'metadata' => $t->metadata,
                'created_at' => $t->created_at->toIso8601String(),
                'source' => $t->source,
                'source_metadata' => $t->source_metadata,
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
            ->where('user_id', auth()->id())
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get(['id', 'content', 'metadata', 'created_at', 'source', 'source_metadata']);

        return [
            'thoughts' => $thoughts->map(fn (Thought $t) => [
                'id' => $t->id,
                'content' => $t->content,
                'metadata' => $t->metadata,
                'created_at' => $t->created_at->toIso8601String(),
                'source' => $t->source,
                'source_metadata' => $t->source_metadata,
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
        $count = Thought::query()->where('user_id', auth()->id())->count();

        return ['count' => $count];
    }

    /**
     * capture_thought: embed + extractMetadata + save Thought. Params: content; optional parent_id or in_reply_to (UUID).
     * When parent_id (or in_reply_to) is provided, parent must exist and belong to the resolved user.
     *
     * @param  array<string, mixed>  $params
     * @return array{id: string}
     */
    private function captureThought(array $params): array
    {
        $v = Validator::make($params, [
            'content' => 'required|string',
            'parent_id' => 'sometimes|nullable|uuid',
            'in_reply_to' => 'sometimes|nullable|uuid',
            'source' => 'sometimes|nullable|string|max:64',
            'source_metadata' => 'sometimes|nullable|array',
        ]);
        if ($v->fails()) {
            throw new \InvalidArgumentException($v->errors()->first());
        }
        $content = (string) $params['content'];
        $parentId = isset($params['parent_id']) && $params['parent_id'] !== '' ? (string) $params['parent_id'] : null;
        if ($parentId === null && isset($params['in_reply_to']) && $params['in_reply_to'] !== '') {
            $parentId = (string) $params['in_reply_to'];
        }

        $source = isset($params['source']) && trim((string) $params['source']) !== ''
            ? mb_substr(trim((string) $params['source']), 0, 64)
            : 'mcp';
        $sourceMetadata = isset($params['source_metadata']) && is_array($params['source_metadata'])
            ? $params['source_metadata']
            : null;

        $parent = null;
        if ($parentId !== null) {
            $parent = Thought::find($parentId);
            if ($parent === null) {
                throw new \InvalidArgumentException('Parent thought not found.');
            }
            if ($parent->user_id !== auth()->id()) {
                throw new \InvalidArgumentException('Parent thought does not belong to you.');
            }
        }

        $embedding = $this->openRouter->embed($content);
        $metadata = \App\Models\Thought::normalizeMetadataTags($this->openRouter->extractMetadata($content));

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
