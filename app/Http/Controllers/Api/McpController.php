<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\SyncUserJiraActivity;
use App\Models\Thought;
use App\Models\User;
use App\Models\UserMcpKey;
use App\Services\IdeasToRevisitService;
use App\Services\OAuthMcpJwtService;
use App\Services\OpenRouterService;
use App\Services\ResearchService;
use App\Services\ThoughtCaptureService;
use App\Services\ThoughtSearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class McpController extends Controller
{
    public function __construct(
        private OpenRouterService $openRouter,
        private ThoughtCaptureService $captureService,
        private IdeasToRevisitService $ideasToRevisit,
        private ResearchService $researchService,
        private ThoughtSearchService $searchService,
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
            'methods' => $this->mcpMethodNames(),
        ]);
    }

    /**
     * @return array<int, string>
     */
    private function mcpMethodNames(): array
    {
        $base = ['search_thoughts', 'browse_recent', 'thought_stats', 'capture_thought', 'capture_plan', 'capture_idea', 'get_ideas', 'research_idea'];
        if (config('services.jira.enabled', true)) {
            $base[] = 'sync_jira';
        }

        return $base;
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
        if (! in_array($method, $this->mcpMethodNames(), true)) {
            return $this->jsonRpcError(-32601, 'Method not found', $id);
        }

        try {
            $result = $this->dispatch($method, $params);
        } catch (\InvalidArgumentException $e) {
            return $this->jsonRpcError(-32602, $e->getMessage(), $id);
        } catch (\Throwable $e) {
            Log::error('MCP dispatch failed', [
                'method' => $method,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
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
                        'no_chunking' => ['type' => 'boolean', 'description' => 'If true, do not auto-chunk long content (default: content over 500 words is split at markdown headings)'],
                    ],
                    'required' => ['content'],
                ],
            ],
            [
                'name' => 'capture_plan',
                'description' => 'Save a plan, decision, dev note, support doc, spec, or research as a thought. Use doc_type to set source (plan, decision, dev, support, spec, research). Use plan_slug to tag all sections for long-form view (Stream filter by tag). Use project to record which code project or research topic this belongs to.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'content' => ['type' => 'string', 'description' => 'Document content (full doc or one section)'],
                        'doc_type' => ['type' => 'string', 'description' => 'One of: plan, decision, dev, support, spec, research. Default plan. Sets source and tag prefix (e.g. decision:slug, research:slug).'],
                        'file_path' => ['type' => 'string', 'description' => 'Optional path (e.g. decisions/project-spec.md, dev/notes.md, support/investigation.md, specs/example-feature-spec.md)'],
                        'plan_slug' => ['type' => 'string', 'description' => 'Optional slug for this document (e.g. project-spec). Adds tag <doc_type>:<slug> so Stream can show all sections.'],
                        'parent_id' => ['type' => 'string', 'description' => 'Optional UUID of root thought to attach this section to (for hierarchy)'],
                        'section_title' => ['type' => 'string', 'description' => 'Optional title of this section (stored in source_metadata)'],
                        'project' => ['type' => 'string', 'description' => 'Optional code project name (e.g. workspace or repo name). Stored in source_metadata so you can filter by project.'],
                        'tags' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Optional extra tags to merge with extracted and doc tag'],
                        'no_chunking' => ['type' => 'boolean', 'description' => 'If true, do not auto-chunk long documents (default: documents over 500 words are split at markdown headings into linked sections).'],
                    ],
                    'required' => ['content'],
                ],
            ],
            [
                'name' => 'capture_idea',
                'description' => 'Save an idea (thought with type idea, optional logged_date). Same as capture_thought plus idea metadata.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'content' => ['type' => 'string', 'description' => 'The idea to save'],
                        'logged_date' => ['type' => 'string', 'description' => 'Optional ISO date (YYYY-MM-DD) for when the idea was logged'],
                    ],
                    'required' => ['content'],
                ],
            ],
            [
                'name' => 'get_ideas',
                'description' => 'Return the same list as the Ideas to revisit page: incomplete ideas weighted by age, bounded by user preferences.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'limit' => ['type' => 'integer', 'description' => 'Optional max number of ideas to return (overrides user preference)'],
                    ],
                ],
            ],
            [
                'name' => 'research_idea',
                'description' => 'Run AI research for an idea. Provide idea_id (UUID of existing idea) or content (new idea text); creates linked research thought.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'idea_id' => ['type' => 'string', 'description' => 'UUID of an existing idea thought to run research for'],
                        'content' => ['type' => 'string', 'description' => 'New idea text; creates idea and runs research (use when no idea_id)'],
                    ],
                    'required' => [],
                ],
            ],
        ];
        if (config('services.jira.enabled', true)) {
            $tools[] = [
                'name' => 'sync_jira',
                'description' => 'Sync your Jira activity into IdeaTub for the last N days. Use when the user wants to refresh Jira tickets or before a meeting.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'days' => ['type' => 'integer', 'description' => 'Number of days to sync (default from app config, e.g. 14)'],
                    ],
                ],
            ];
        }

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

        Log::warning('MCP tools/call', ['tool' => $name]);

        if (! is_string($name) || $name === '') {
            return $this->jsonRpcError(-32602, 'tools/call requires "name"', $id);
        }

        if (! in_array($name, $this->mcpMethodNames(), true)) {
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
            Log::error('MCP tools/call failed', [
                'tool' => $name,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
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
            'capture_plan' => $this->capturePlan($params),
            'capture_idea' => $this->captureIdea($params),
            'get_ideas' => $this->getIdeas($params),
            'research_idea' => $this->researchIdea($params),
            'sync_jira' => $this->syncJira($params),
            default => throw new \InvalidArgumentException("Unknown method: {$method}"),
        };
    }

    /**
     * sync_jira: Dispatch job to sync user's Jira activity into thoughts. Optional days param.
     *
     * @param  array<string, mixed>  $params
     * @return array{message: string}
     */
    private function syncJira(array $params): array
    {
        $days = isset($params['days']) ? (int) $params['days'] : (int) config('services.jira.default_days', 14);
        $user = Auth::user();
        if ($user === null) {
            throw new \InvalidArgumentException('Not authenticated.');
        }
        SyncUserJiraActivity::dispatch($user->id, $days);

        return [
            'message' => "Jira sync started for the last {$days} days. You can search or browse recent thoughts for your Jira activity.",
        ];
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

        Log::warning('MCP search_thoughts start', ['query' => $query, 'limit' => $limit]);

        $result = $this->searchService->search($query, (int) auth()->id(), [
            'max_distance' => 0.5,
            'tag_limit' => 100,
            'semantic_limit' => 100,
        ]);
        $thoughts = $result['thoughts']->take($limit);

        Log::warning('MCP search_thoughts query ok', ['count' => $thoughts->count()]);

        return [
            'thoughts' => $thoughts->map(fn (Thought $t) => [
                'id' => $t->id,
                'content' => $t->getDecodedContent(),
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
                'content' => $t->getDecodedContent(),
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
     * get_ideas: incomplete ideas to revisit (same as Ideas to revisit page), age-ordered, bounded by user preferences.
     *
     * @param  array<string, mixed>  $params
     * @return array{ideas: array<int, array{id: string, content: string, logged_date: string, created_at: string}>}
     */
    private function getIdeas(array $params): array
    {
        $thoughts = $this->ideasToRevisit->forUser(auth()->user());

        $ideas = array_map(function (Thought $thought): array {
            return [
                'id' => $thought->id,
                'content' => Str::limit($thought->getDecodedContent(), 200),
                'logged_date' => $thought->getLoggedDate(),
                'created_at' => $thought->created_at->toIso8601String(),
            ];
        }, $thoughts);

        return ['ideas' => $ideas];
    }

    /**
     * research_idea: Run AI research for an idea. Either idea_id (existing idea) or content (new idea), or both (content creates new idea+research).
     *
     * @param  array<string, mixed>  $params
     * @return array{idea_id: string, research_id: string|null}
     */
    private function researchIdea(array $params): array
    {
        $ideaId = isset($params['idea_id']) && trim((string) $params['idea_id']) !== ''
            ? trim((string) $params['idea_id'])
            : null;
        $content = isset($params['content']) && trim((string) $params['content']) !== ''
            ? trim((string) $params['content'])
            : null;

        if ($ideaId === null && $content === null) {
            throw new \InvalidArgumentException('At least one of idea_id or content is required.');
        }

        if ($ideaId !== null) {
            $uuidValidator = Validator::make(['idea_id' => $ideaId], ['idea_id' => 'uuid']);
            if ($uuidValidator->fails()) {
                throw new \InvalidArgumentException('idea_id must be a valid UUID.');
            }
        }

        // If content provided, create idea and run research (idea_id is ignored when content is present).
        if ($content !== null) {
            $result = $this->researchService->createIdeaAndResearch($content, 'mcp');

            return [
                'idea_id' => $result['idea']->id,
                'research_id' => $result['research']?->id,
            ];
        }

        // idea_id only: run research for existing idea.
        $thought = Thought::find($ideaId);
        if ($thought === null) {
            throw new \InvalidArgumentException('Idea not found.');
        }
        if ($thought->user_id !== auth()->id()) {
            throw new \InvalidArgumentException('Idea not found.');
        }
        $type = $thought->metadata['type'] ?? null;
        if ($type !== 'idea') {
            throw new \InvalidArgumentException('Thought is not an idea.');
        }

        $researchThought = $this->researchService->runResearchForIdea($thought, 'mcp');

        return [
            'idea_id' => $thought->id,
            'research_id' => $researchThought->id,
        ];
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
            'no_chunking' => 'sometimes|nullable|boolean',
            'no-chunking' => 'sometimes|nullable|boolean',
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
        $noChunking = ! empty($params['no_chunking']) || ! empty($params['no-chunking']);

        $result = $this->captureService->create([
            'content' => $content,
            'user_id' => auth()->id(),
            'parent_id' => $parentId,
            'source' => $source,
            'source_metadata' => $sourceMetadata,
            'no_chunking' => $noChunking,
        ]);

        if ($result['chunked']) {
            return [
                'id' => $result['root']->id,
                'chunked' => true,
                'section_ids' => $result['section_ids'],
            ];
        }

        return ['id' => $result['thought']->id];
    }

    /**
     * capture_idea: Save an idea (thought with type idea, optional logged_date). Same as capture_thought plus idea metadata.
     *
     * @param  array<string, mixed>  $params
     * @return array{id: string, chunked?: bool, section_ids?: array<int, string>}
     */
    private function captureIdea(array $params): array
    {
        $v = Validator::make($params, [
            'content' => 'required|string',
            'logged_date' => 'sometimes|nullable|string|date_format:Y-m-d',
            'completed' => 'sometimes|boolean',
        ]);
        if ($v->fails()) {
            throw new \InvalidArgumentException($v->errors()->first());
        }
        $loggedDate = isset($params['logged_date']) && trim((string) $params['logged_date']) !== ''
            ? trim((string) $params['logged_date'])
            : now()->toDateString();
        $completed = isset($params['completed']) ? (bool) $params['completed'] : false;

        $result = $this->captureService->create([
            'content' => $params['content'],
            'user_id' => auth()->id(),
            'source' => 'mcp',
            'idea_metadata' => [
                'type' => 'idea',
                'completed' => $completed,
                'logged_date' => $loggedDate,
            ],
        ]);

        if ($result['chunked']) {
            return [
                'id' => $result['root']->id,
                'chunked' => true,
                'section_ids' => $result['section_ids'],
            ];
        }

        return ['id' => $result['thought']->id];
    }

    /**
     * capture_plan: Save a document (plan, decision, dev, support, spec, research) or section as a thought.
     * doc_type sets source and tag prefix (e.g. decision:slug, spec:slug). When plan_slug is provided,
     * adds tag <doc_type>:<slug> so all sections can be viewed via Stream ?tag=... (slug form e.g. decision-project-spec).
     * Optional parent_id links this thought to a root for hierarchy.
     *
     * @param  array<string, mixed>  $params
     * @return array{id: string, plan_slug?: string, doc_type?: string}
     */
    private function capturePlan(array $params): array
    {
        $allowedDocTypes = ['plan', 'decision', 'dev', 'support', 'spec', 'research'];
        $v = Validator::make($params, [
            'content' => 'required|string',
            'doc_type' => 'sometimes|nullable|string|in:'.implode(',', $allowedDocTypes),
            'file_path' => 'sometimes|nullable|string|max:512',
            'plan_slug' => 'sometimes|nullable|string|max:128',
            'parent_id' => 'sometimes|nullable|uuid',
            'section_title' => 'sometimes|nullable|string|max:256',
            'project' => 'sometimes|nullable|string|max:256',
            'tags' => 'sometimes|nullable|array',
            'tags.*' => 'string|max:128',
            'no_chunking' => 'sometimes|nullable|boolean',
            'no-chunking' => 'sometimes|nullable|boolean',
        ]);
        if ($v->fails()) {
            throw new \InvalidArgumentException($v->errors()->first());
        }

        $content = (string) $params['content'];
        $docType = isset($params['doc_type']) && in_array($params['doc_type'], $allowedDocTypes, true)
            ? $params['doc_type']
            : 'plan';
        $filePath = isset($params['file_path']) && trim((string) $params['file_path']) !== ''
            ? mb_substr(trim((string) $params['file_path']), 0, 512)
            : null;
        $planSlug = isset($params['plan_slug']) && trim((string) $params['plan_slug']) !== ''
            ? mb_substr(trim((string) $params['plan_slug']), 0, 128)
            : null;
        $parentId = isset($params['parent_id']) && $params['parent_id'] !== '' ? (string) $params['parent_id'] : null;
        $sectionTitle = isset($params['section_title']) && trim((string) $params['section_title']) !== ''
            ? mb_substr(trim((string) $params['section_title']), 0, 256)
            : null;
        $project = isset($params['project']) && trim((string) $params['project']) !== ''
            ? mb_substr(trim((string) $params['project']), 0, 256)
            : null;
        $extraTags = isset($params['tags']) && is_array($params['tags'])
            ? array_values(array_filter(array_map(fn ($t) => is_string($t) ? trim($t) : '', $params['tags'])))
            : [];

        $sourceMetadata = array_filter([
            'doc_type' => $docType,
            'file_path' => $filePath,
            'plan_slug' => $planSlug,
            'section_title' => $sectionTitle,
            'project' => $project,
        ], fn ($v) => $v !== null && $v !== '');

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

        $noChunking = ! empty($params['no_chunking']) || ! empty($params['no-chunking']);
        $result = $this->captureService->create([
            'content' => $content,
            'user_id' => auth()->id(),
            'parent_id' => $parent?->id,
            'source' => $docType,
            'source_metadata' => $sourceMetadata ?: null,
            'no_chunking' => $noChunking,
            'plan_slug' => $planSlug,
            'doc_type' => $docType,
            'file_path' => $filePath,
            'project' => $project,
            'extra_tags' => $extraTags,
        ]);

        $out = $result['chunked']
            ? ['id' => $result['root']->id, 'chunked' => true, 'section_ids' => $result['section_ids']]
            : ['id' => $result['thought']->id];
        if ($planSlug !== null) {
            $out['plan_slug'] = $planSlug;
        }
        if ($docType !== 'plan') {
            $out['doc_type'] = $docType;
        }

        return $out;
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
