<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\RunVideoResearch;
use App\Jobs\SyncUserJiraActivity;
use App\Models\Thought;
use App\Models\User;
use App\Models\UserMcpKey;
use App\Services\IdeasToRevisitService;
use App\Services\McpSessionService;
use App\Services\Meetings\MeetingService;
use App\Services\OAuthMcpJwtService;
use App\Services\OpenRouterService;
use App\Services\ResearchService;
use App\Services\ThoughtCaptureService;
use App\Services\ThoughtSearchService;
use App\Services\Video\VideoCaptureService;
use App\Services\WorkingMemory\WorkingMemoryAssembler;
use App\Support\BearerTokenExtractor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class McpController extends Controller
{
    public function __construct(
        private OpenRouterService $openRouter,
        private ThoughtCaptureService $captureService,
        private IdeasToRevisitService $ideasToRevisit,
        private ResearchService $researchService,
        private MeetingService $meetingService,
        private ThoughtSearchService $searchService,
        private VideoCaptureService $videoCaptureService,
        private McpSessionService $mcpSessions,
        private OAuthMcpJwtService $oauthJwt,
        private WorkingMemoryAssembler $workingMemoryAssembler,
    ) {}

    /**
     * GET /api/mcp — Server info for connector discovery/validation (e.g. ChatGPT "Add connector").
     * No auth required so the connector URL can be validated before the user supplies a key.
     *
     * Streamable HTTP clients (Accept: text/event-stream) receive 405; the MCP endpoint may offer GET SSE later.
     */
    public function show(Request $request): JsonResponse|Response
    {
        $accept = strtolower((string) $request->headers->get('Accept', ''));
        if (str_contains($accept, 'text/event-stream')) {
            if ($deny = $this->rejectInvalidStreamableOrigin($request)) {
                return $deny;
            }

            return response('', Response::HTTP_METHOD_NOT_ALLOWED)
                ->header('Allow', 'DELETE, GET, POST');
        }

        return response()->json([
            'name' => 'ideatub',
            'version' => '1.0',
            'protocol' => 'json-rpc',
            'auth' => 'Send key via x-ideatub-key header or OAuth Bearer token',
            'methods' => $this->mcpMethodNames(),
        ]);
    }

    /**
     * DELETE /api/mcp — Terminate Streamable HTTP session (Mcp-Session-Id).
     */
    public function destroy(Request $request): JsonResponse|Response
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return $this->unauthorizedResponse();
        }

        $sessionId = $request->header('Mcp-Session-Id');
        if (! is_string($sessionId) || $sessionId === '') {
            return response('', Response::HTTP_METHOD_NOT_ALLOWED);
        }

        $uid = $this->mcpSessions->userId($sessionId);
        if ($uid !== $user->id) {
            return response()->json(['message' => 'Invalid or expired session'], Response::HTTP_NOT_FOUND);
        }

        $this->mcpSessions->destroy($sessionId);

        return response('', Response::HTTP_NO_CONTENT);
    }

    /**
     * @return array<int, string>
     */
    private function mcpMethodNames(): array
    {
        $base = [
            'search_thoughts',
            'browse_recent',
            'thought_stats',
            'capture_thought',
            'capture_plan',
            'capture_meeting',
            'add_meeting',
            'add_meeting_notes',
            'capture_idea',
            'get_ideas',
            'research_idea',
            'process_meeting',
            'capture_video',
            'get_working_memory',
        ];
        if (config('services.jira.enabled', true)) {
            $base[] = 'sync_jira';
        }

        return $base;
    }

    /**
     * Handle MCP JSON-RPC: legacy clients (typical Accept: application/json) use JSON-RPC 2.0 only.
     * Streamable HTTP (MCP 2025-03-26): Accept includes application/json and text/event-stream.
     */
    public function __invoke(Request $request): JsonResponse|Response
    {
        if ($this->wantsStreamableHttpPost($request)) {
            return $this->handleStreamablePost($request);
        }

        return $this->handleLegacyPost($request);
    }

    private function wantsStreamableHttpPost(Request $request): bool
    {
        $accept = strtolower((string) $request->headers->get('Accept', ''));

        return str_contains($accept, 'application/json')
            && str_contains($accept, 'text/event-stream');
    }

    private function handleLegacyPost(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return $this->unauthorizedResponse();
        }

        return $this->processSingleJsonRpcRequest($request, $user, $request->all(), legacyTransport: true);
    }

    private function handleStreamablePost(Request $request): JsonResponse|Response
    {
        if ($deny = $this->rejectInvalidStreamableOrigin($request)) {
            return $deny;
        }

        $messages = $this->normalizeMcpMessages($request->all());
        if ($messages === null) {
            return response()->json(['message' => 'Invalid JSON-RPC body'], Response::HTTP_BAD_REQUEST);
        }

        if ($messages === []) {
            return response()->json(['message' => 'Empty body'], Response::HTTP_BAD_REQUEST);
        }

        if (count($messages) > 1) {
            return response()->json(['message' => 'Batched requests are not supported'], Response::HTTP_BAD_REQUEST);
        }

        $msg = $messages[0];
        if (! array_key_exists('id', $msg)) {
            return $this->handleStreamableNotification($request, $msg);
        }

        $user = $this->resolveUser($request);
        if ($user === null) {
            return $this->unauthorizedResponse();
        }

        $method = $msg['method'] ?? null;
        if ($method !== 'initialize') {
            $sessionId = $request->header('Mcp-Session-Id');
            if (! is_string($sessionId) || $sessionId === '') {
                return response()->json(['message' => 'Mcp-Session-Id required'], Response::HTTP_BAD_REQUEST);
            }
            $uid = $this->mcpSessions->userId($sessionId);
            if ($uid !== $user->id) {
                return response()->json(['message' => 'Invalid or expired session'], Response::HTTP_NOT_FOUND);
            }
        }

        return $this->processSingleJsonRpcRequest($request, $user, $msg, legacyTransport: false);
    }

    /**
     * @param  array<string, mixed>  $msg
     */
    private function handleStreamableNotification(Request $request, array $msg): JsonResponse|Response
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return $this->unauthorizedResponse();
        }

        $sessionId = $request->header('Mcp-Session-Id');
        if (! is_string($sessionId) || $sessionId === '') {
            return response()->json(['message' => 'Mcp-Session-Id required'], Response::HTTP_BAD_REQUEST);
        }

        $uid = $this->mcpSessions->userId($sessionId);
        if ($uid !== $user->id) {
            return response()->json(['message' => 'Invalid or expired session'], Response::HTTP_NOT_FOUND);
        }

        return response('', Response::HTTP_ACCEPTED);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return ?array<int, array<string, mixed>>
     */
    private function normalizeMcpMessages(array $body): ?array
    {
        if ($body === []) {
            return null;
        }

        if (isset($body['method'])) {
            return [$body];
        }

        if (! array_is_list($body)) {
            return null;
        }

        foreach ($body as $item) {
            if (! is_array($item) || ! isset($item['method'])) {
                return null;
            }
        }

        return $body;
    }

    /**
     * @return ?JsonResponse Non-null means reject request (403 JSON).
     */
    private function rejectInvalidStreamableOrigin(Request $request): ?JsonResponse
    {
        $origin = $request->headers->get('Origin');
        if ($origin === null || $origin === '') {
            return null;
        }

        $host = parse_url($origin, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            return response()->json(['message' => 'Invalid Origin'], Response::HTTP_FORBIDDEN);
        }

        foreach ($this->streamableAllowedHosts() as $allowed) {
            if ($host === $allowed || str_ends_with($host, '.'.$allowed)) {
                return null;
            }
        }

        return response()->json(['message' => 'Origin not allowed'], Response::HTTP_FORBIDDEN);
    }

    /**
     * @return array<int, string>
     */
    private function streamableAllowedHosts(): array
    {
        $hosts = [
            'claude.ai',
            'claude.com',
            'chatgpt.com',
            'chat.openai.com',
            'platform.openai.com',
            'cursor.sh',
            'cursor.com',
        ];

        $appHost = parse_url((string) config('app.url'), PHP_URL_HOST);
        if (is_string($appHost) && $appHost !== '') {
            $hosts[] = $appHost;
        }

        $extra = config('mcp.streamable_allowed_hosts_extra', '');
        if (is_string($extra) && $extra !== '') {
            foreach (array_map('trim', explode(',', $extra)) as $h) {
                if ($h !== '') {
                    $hosts[] = $h;
                }
            }
        }

        return array_values(array_unique($hosts));
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function processSingleJsonRpcRequest(Request $request, User $user, array $body, bool $legacyTransport): JsonResponse|Response
    {
        $method = $body['method'] ?? null;
        $params = is_array($body['params'] ?? null) ? $body['params'] : [];
        $id = $body['id'] ?? null;

        if (! is_string($method) || $method === '') {
            return $this->jsonRpcError(-32600, 'Invalid request: method required', $id);
        }

        $request->setUserResolver(fn () => $user);
        Auth::setUser($user);

        if ($method === 'initialize') {
            return $this->respondInitialize($params, $id, $legacyTransport, $user);
        }
        if ($method === 'notifications/initialized') {
            return $legacyTransport
                ? response()->json(['jsonrpc' => '2.0'])
                : response('', Response::HTTP_ACCEPTED);
        }
        if ($method === 'tools/list') {
            return $this->respondToolsList($id);
        }
        if ($method === 'tools/call') {
            return $this->respondToolsCall($params, $id);
        }

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

    private function respondInitialize(array $params, mixed $id, bool $legacyTransport = true, ?User $user = null): JsonResponse
    {
        $requestedVersion = $params['protocolVersion'] ?? '2024-11-05';
        $supported = ['2024-11-05', '2025-03-26'];
        $protocolVersion = in_array($requestedVersion, $supported, true) ? $requestedVersion : '2024-11-05';

        $response = response()->json([
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

        if (! $legacyTransport && $user !== null) {
            $response->headers->set('Mcp-Session-Id', $this->mcpSessions->create($user->id));
        }

        return $response;
    }

    /**
     * JSON Schema object for capture_plan and meeting-alias tools (same fields except doc_type when omitted).
     *
     * @return array<string, mixed>
     */
    private function buildCapturePlanLikeInputSchema(bool $includeDocTypeProperty): array
    {
        $properties = [
            'content' => ['type' => 'string', 'description' => 'Document content (full doc or one section)'],
            'file_path' => ['type' => 'string', 'description' => 'Optional path (e.g. decisions/project-spec.md, dev/notes.md, support/investigation.md, specs/example-feature-spec.md)'],
            'plan_slug' => ['type' => 'string', 'description' => 'Optional slug (e.g. project-spec or 2026-04-01-standup). Adds tag <doc_type>:<slug> (capture_plan) or meeting:<slug> (meeting tools) so Stream can show all sections.'],
            'parent_id' => ['type' => 'string', 'description' => 'Optional UUID of root thought to attach this section to (for hierarchy)'],
            'section_title' => ['type' => 'string', 'description' => 'Optional title of this section (stored in source_metadata)'],
            'project' => ['type' => 'string', 'description' => 'Optional code project name (e.g. workspace or repo name). Stored in source_metadata so you can filter by project.'],
            'tags' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Optional extra tags to merge with extracted and doc tag'],
            'no_chunking' => ['type' => 'boolean', 'description' => 'If true, do not auto-chunk long documents (default: documents over 500 words are split at markdown headings into linked sections).'],
        ];
        if ($includeDocTypeProperty) {
            $properties = array_merge(
                [
                    'doc_type' => ['type' => 'string', 'description' => 'One of: plan, decision, dev, support, spec, research, meeting. Default plan. Sets source and tag prefix (e.g. decision:slug, research:slug, meeting:slug).'],
                ],
                $properties
            );
        }

        return [
            'type' => 'object',
            'properties' => $properties,
            'required' => ['content'],
        ];
    }

    private function respondToolsList(mixed $id): JsonResponse
    {
        $meetingAliasesNote = 'Same as capture_plan with doc_type fixed to meeting (Stream → Meetings). Equivalent tool names: capture_meeting, add_meeting, add_meeting_notes.';

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
                'description' => 'Save a plan, decision, dev note, support doc, spec, research, or meeting notes as a thought. Use doc_type to set source (plan, decision, dev, support, spec, research, meeting). Use plan_slug to tag all sections for long-form view (Stream filter by tag). Use project to record which code project or research topic this belongs to. For meetings only, you can also call capture_meeting, add_meeting, or add_meeting_notes (same parameters except doc_type is implied).',
                'inputSchema' => $this->buildCapturePlanLikeInputSchema(true),
            ],
            [
                'name' => 'capture_meeting',
                'description' => 'Save meeting notes. '.$meetingAliasesNote,
                'inputSchema' => $this->buildCapturePlanLikeInputSchema(false),
            ],
            [
                'name' => 'add_meeting',
                'description' => 'Add meeting notes (alias of capture_meeting). '.$meetingAliasesNote,
                'inputSchema' => $this->buildCapturePlanLikeInputSchema(false),
            ],
            [
                'name' => 'add_meeting_notes',
                'description' => 'Add meeting notes (alias of capture_meeting). '.$meetingAliasesNote,
                'inputSchema' => $this->buildCapturePlanLikeInputSchema(false),
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
                'description' => 'Queue AI research for an idea (runs in the background). Provide idea_id (UUID of existing idea) or content (new idea text). Returns idea_id and research_run_id; research_id is null until the run completes.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'idea_id' => ['type' => 'string', 'description' => 'UUID of an existing idea thought to queue research for'],
                        'content' => ['type' => 'string', 'description' => 'New idea text; creates idea then queues research (use when no idea_id)'],
                    ],
                    'required' => [],
                ],
            ],
            [
                'name' => 'process_meeting',
                'description' => 'Queue AI meeting processing (summary + categorization). Provide thought_id for an existing meeting thought, or content for a new meeting transcript. Supports optional meeting_skill_id and force_rerun.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'thought_id' => ['type' => 'string', 'description' => 'UUID of an existing meeting thought to process'],
                        'content' => ['type' => 'string', 'description' => 'Meeting transcript/plain text to save as a meeting and process'],
                        'plan_slug' => ['type' => 'string', 'description' => 'Optional slug used when content creates a new meeting thought'],
                        'meeting_skill_id' => ['type' => 'integer', 'description' => 'Optional meeting skill id for manual runs'],
                        'force_rerun' => ['type' => 'boolean', 'description' => 'When true, create a new run even if another active run exists for the same meeting+skill'],
                    ],
                    'required' => [],
                ],
            ],
            [
                'name' => 'capture_video',
                'description' => 'Save a YouTube video as a video thought. Same normalization as web capture. Optional pasted transcript skips automatic fetch. Duplicate URL returns the existing root thought id.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'url' => ['type' => 'string', 'description' => 'YouTube watch or youtu.be URL'],
                        'transcript' => ['type' => 'string', 'description' => 'Optional full transcript text; when set, automatic YouTube fetch is not queued'],
                        'research_now' => ['type' => 'boolean', 'description' => 'When true, queues video research using the built-in video workflow. If a transcript is missing, transcript fetch runs first and video research is queued after the transcript reaches a terminal state; if a transcript is already present, video research is queued immediately.'],
                        'source_metadata' => ['type' => 'object', 'description' => 'Optional metadata merged onto the root video thought (e.g. project, client)'],
                    ],
                    'required' => ['url'],
                ],
            ],
            [
                'name' => 'get_working_memory',
                'description' => 'Return global, project, or insights working memory snapshot with freshness and confidence.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'scope_type' => ['type' => 'string', 'enum' => ['global', 'project', 'insights']],
                        'scope_key' => ['type' => 'string'],
                    ],
                    'required' => ['scope_type', 'scope_key'],
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
        if (config('mcp.debug_auth', false)) {
            Log::info('MCP auth debug', $this->mcpAuthDebugContext($request));
        }

        $token = BearerTokenExtractor::fromRequest($request);
        if ($token !== null && config('oauth-mcp.enabled', true)) {
            try {
                $payload = $this->oauthJwt->verifyAccessToken($token);
                $user = User::find($payload['user_id']);

                return $user;
            } catch (\Throwable $e) {
                if (config('mcp.log_oauth_failures', false)) {
                    Log::warning('MCP OAuth JWT rejected', [
                        'exception' => $e::class,
                        'message' => $e->getMessage(),
                    ]);
                }

                return null;
            }
        }

        $key = $request->header('x-ideatub-key');
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

    /**
     * Safe diagnostics for Laravel Cloud / log aggregation (never log tokens or API keys).
     *
     * @return array<string, mixed>
     */
    private function mcpAuthDebugContext(Request $request): array
    {
        $authHeader = $request->header('Authorization');
        $rawAuth = is_string($authHeader) && $authHeader !== '';
        $serverAuth = is_string($request->server('HTTP_AUTHORIZATION')) && $request->server('HTTP_AUTHORIZATION') !== '';
        $bearerToken = BearerTokenExtractor::fromRequest($request);

        $keyHeader = $request->header('x-ideatub-key');
        $hasKeyHeader = is_string($keyHeader) && trim($keyHeader) !== '';

        $origin = $request->headers->get('Origin');
        $originHost = is_string($origin) && $origin !== '' ? parse_url($origin, PHP_URL_HOST) : null;

        $body = $request->all();
        $method = $body['method'] ?? null;

        $serverKeys = array_keys($request->server->all());
        $authishServerKeys = array_values(array_filter($serverKeys, static function (string $k): bool {
            return stripos($k, 'AUTH') !== false || stripos($k, 'TOKEN') !== false;
        }));

        return [
            'streamable_accept' => $this->wantsStreamableHttpPost($request),
            'jsonrpc_method' => is_string($method) ? $method : null,
            'header_authorization_set' => $rawAuth,
            'server_http_authorization_set' => $serverAuth,
            'bearer_extracted' => $bearerToken !== null,
            'bearer_length' => $bearerToken !== null ? strlen($bearerToken) : 0,
            'x_ideatub_key_header_set' => $hasKeyHeader,
            'x_access_token_header_set' => is_string($request->header('X-Access-Token')) && trim($request->header('X-Access-Token')) !== '',
            'mcp_session_id_set' => is_string($request->header('Mcp-Session-Id')) && $request->header('Mcp-Session-Id') !== '',
            'oauth_mcp_enabled' => (bool) config('oauth-mcp.enabled', true),
            'origin_host' => is_string($originHost) ? $originHost : null,
            'user_agent' => substr((string) $request->userAgent(), 0, 120),
            'server_keys_matching_auth_or_token' => array_slice($authishServerKeys, 0, 25),
        ];
    }

    private function unauthorizedResponse(): JsonResponse
    {
        $resourceMetadata = config('oauth-mcp.enabled', true)
            ? rtrim((string) config('oauth-mcp.issuer', config('app.url')), '/').'/.well-known/oauth-protected-resource'
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
            'capture_meeting' => $this->captureMeeting($params),
            'add_meeting' => $this->captureMeeting($params),
            'add_meeting_notes' => $this->captureMeeting($params),
            'capture_idea' => $this->captureIdea($params),
            'get_ideas' => $this->getIdeas($params),
            'research_idea' => $this->researchIdea($params),
            'process_meeting' => $this->processMeeting($params),
            'capture_video' => $this->captureVideo($params),
            'get_working_memory' => $this->getWorkingMemory($params),
            'sync_jira' => $this->syncJira($params),
            default => throw new \InvalidArgumentException("Unknown method: {$method}"),
        };
    }

    /**
     * get_working_memory: Return scoped working memory via {@see WorkingMemoryAssembler::forScope}.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function getWorkingMemory(array $params): array
    {
        $input = $params;
        foreach (['scope_type', 'scope_key'] as $key) {
            if (isset($input[$key]) && is_string($input[$key])) {
                $input[$key] = trim($input[$key]);
            }
        }

        $v = Validator::make($input, [
            'scope_type' => 'required|string|in:global,project,insights',
            'scope_key' => 'required|string|max:191',
        ]);
        if ($v->fails()) {
            throw new \InvalidArgumentException($v->errors()->first());
        }

        /** @var array{scope_type: string, scope_key: string} $validated */
        $validated = $v->validated();

        return $this->workingMemoryAssembler->forScope(
            (int) auth()->id(),
            $validated['scope_type'],
            $validated['scope_key']
        );
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
            ->visibleInStream()
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
        $count = Thought::query()
            ->where('user_id', auth()->id())
            ->visibleInStream()
            ->count();

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
     * capture_video: Save or reuse a YouTube video thought via {@see VideoCaptureService}.
     *
     * @param  array<string, mixed>  $params
     * @return array{id: string, video_id: string, transcript_status: mixed, research_pending: bool, warning?: string}
     */
    private function captureVideo(array $params): array
    {
        $v = Validator::make($params, [
            'url' => 'required|string|max:2048',
            'transcript' => 'sometimes|nullable|string|max:524288',
            'research_now' => 'sometimes|boolean',
            'source_metadata' => 'sometimes|nullable|array',
        ]);
        if ($v->fails()) {
            throw new \InvalidArgumentException($v->errors()->first());
        }

        $url = trim((string) $params['url']);
        $transcript = array_key_exists('transcript', $params) && $params['transcript'] !== null
            ? (string) $params['transcript']
            : null;
        $transcriptProvided = $transcript !== null && trim($transcript) !== '';
        $researchNow = ! empty($params['research_now']);
        $sourceMetadata = isset($params['source_metadata']) && is_array($params['source_metadata'])
            ? $params['source_metadata']
            : null;

        $user = Auth::user();
        if ($user === null) {
            throw new \InvalidArgumentException('Not authenticated.');
        }

        $root = $this->videoCaptureService->capture($user, $url, $transcriptProvided ? $transcript : null, $sourceMetadata);

        $root->refresh();
        $intentMerged = ! empty($root->metadata[VideoCaptureService::META_VIDEO_RESEARCH_INTENT_PENDING]);

        if ($researchNow) {
            $meta = is_array($root->metadata) ? $root->metadata : [];
            $meta[VideoCaptureService::META_VIDEO_RESEARCH_INTENT_PENDING] = true;
            $meta['research_pending'] = true;
            $root->update([
                'metadata' => Thought::normalizeMetadataTags($meta),
            ]);
            $root->refresh();
        } elseif ($intentMerged) {
            $meta = is_array($root->metadata) ? $root->metadata : [];
            $meta['research_pending'] = true;
            $root->update([
                'metadata' => Thought::normalizeMetadataTags($meta),
            ]);
            $root->refresh();
        }

        $researchRequested = $researchNow || $intentMerged;

        $warning = null;
        if (! $transcriptProvided) {
            $queued = $this->videoCaptureService->queueTranscriptFetchIfPending($root, $researchRequested);
            $root->refresh();
            $status = data_get($root->metadata, 'transcript_status');
            if (! $queued && $status === VideoCaptureService::TRANSCRIPT_STATUS_PENDING) {
                if ($researchRequested) {
                    $this->videoCaptureService->clearStalledResearchRequestMarkers($root);
                    $root->refresh();
                }
                $warning = 'Transcript fetch could not be queued; the video was saved. Retry transcript fetch later if needed.';
            }
        }

        $root->refresh();
        if ($researchRequested && $this->videoCaptureService->transcriptFetchShouldNoop($root)) {
            RunVideoResearch::dispatch($root->id);
        }

        $root->refresh();
        $metadata = is_array($root->metadata) ? $root->metadata : [];
        $researchPending = ! empty($metadata[VideoCaptureService::META_VIDEO_RESEARCH_INTENT_PENDING])
            || ! empty($metadata['research_pending']);

        $out = [
            'id' => $root->id,
            'video_id' => (string) ($metadata['video_id'] ?? ''),
            'transcript_status' => $metadata['transcript_status'] ?? null,
            'research_pending' => $researchPending,
        ];
        if ($warning !== null) {
            $out['warning'] = $warning;
        }

        return $out;
    }

    /**
     * research_idea: Queue AI research for an idea. Accepts exactly one of idea_id (existing idea)
     * or content (new idea); content creates the idea first, then queues a run (same service path as web).
     *
     * @param  array<string, mixed>  $params
     * @return array{idea_id: string, research_run_id: int, research_id: null}
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

        if ($ideaId !== null && $content !== null) {
            throw new \InvalidArgumentException('Provide only one of idea_id or content.');
        }

        if ($ideaId !== null) {
            $uuidValidator = Validator::make(['idea_id' => $ideaId], ['idea_id' => 'uuid']);
            if ($uuidValidator->fails()) {
                throw new \InvalidArgumentException('idea_id must be a valid UUID.');
            }
        }

        // If content provided, create idea and queue research (idea_id is ignored when content is present).
        if ($content !== null) {
            $result = $this->researchService->createIdeaAndQueueResearchRun($content, 'mcp');

            return [
                'idea_id' => $result['idea']->id,
                'research_run_id' => $result['run']->id,
                'research_id' => null,
            ];
        }

        // idea_id only: queue research for existing idea.
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

        $run = $this->researchService->queueResearchRunForIdea($thought, 'mcp');

        return [
            'idea_id' => $thought->id,
            'research_run_id' => $run->id,
            'research_id' => null,
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
        if (isset($params['content']) && mb_strlen((string) $params['content']) > 65535) {
            throw new \InvalidArgumentException('Content must be 65535 characters or fewer.');
        }
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
        if (isset($params['content']) && mb_strlen((string) $params['content']) > 65535) {
            throw new \InvalidArgumentException('Content must be 65535 characters or fewer.');
        }
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
     * capture_meeting / add_meeting / add_meeting_notes: same as capture_plan with doc_type forced to meeting.
     *
     * @param  array<string, mixed>  $params
     * @return array{id: string, plan_slug?: string, doc_type?: string}
     */
    private function captureMeeting(array $params): array
    {
        if (isset($params['content']) && mb_strlen((string) $params['content']) > 65535) {
            throw new \InvalidArgumentException('Content must be 65535 characters or fewer.');
        }
        $params['doc_type'] = 'meeting';

        return $this->capturePlan($params);
    }

    /**
     * process_meeting: Queue AI meeting processing for an existing meeting thought or from raw content.
     *
     * @param  array<string, mixed>  $params
     * @return array{meeting_id: string, meeting_run_id: int, analysis_id: null}
     */
    private function processMeeting(array $params): array
    {
        if (isset($params['content']) && mb_strlen((string) $params['content']) > 65535) {
            throw new \InvalidArgumentException('Content must be 65535 characters or fewer.');
        }

        $thoughtId = isset($params['thought_id']) && trim((string) $params['thought_id']) !== ''
            ? trim((string) $params['thought_id'])
            : null;
        $content = isset($params['content']) && trim((string) $params['content']) !== ''
            ? trim((string) $params['content'])
            : null;

        if ($thoughtId === null && $content === null) {
            throw new \InvalidArgumentException('At least one of thought_id or content is required.');
        }

        if ($thoughtId !== null && $content !== null) {
            throw new \InvalidArgumentException('Provide only one of thought_id or content.');
        }

        $meetingSkillId = isset($params['meeting_skill_id'])
            ? (int) $params['meeting_skill_id']
            : null;
        $forceRerun = ! empty($params['force_rerun']);
        $planSlug = isset($params['plan_slug']) && trim((string) $params['plan_slug']) !== ''
            ? trim((string) $params['plan_slug'])
            : null;

        if ($thoughtId !== null) {
            $uuidValidator = Validator::make(['thought_id' => $thoughtId], ['thought_id' => 'uuid']);
            if ($uuidValidator->fails()) {
                throw new \InvalidArgumentException('thought_id must be a valid UUID.');
            }

            $meeting = Thought::query()->find($thoughtId);
            if ($meeting === null || $meeting->user_id !== auth()->id()) {
                throw new \InvalidArgumentException('Meeting thought not found.');
            }

            if (($meeting->metadata['type'] ?? null) !== 'meeting') {
                throw new \InvalidArgumentException('Thought is not a meeting.');
            }

            $run = $this->meetingService->queueMeetingRunForThought($meeting, 'mcp', $meetingSkillId, $forceRerun);

            return [
                'meeting_id' => $meeting->id,
                'meeting_run_id' => $run->id,
                'analysis_id' => null,
            ];
        }

        $user = Auth::user();
        if (! $user instanceof User) {
            throw new \InvalidArgumentException('Not authenticated.');
        }

        $run = $this->meetingService->createMeetingAndQueueRun(
            user: $user,
            content: (string) $content,
            source: 'mcp',
            meetingSkillId: $meetingSkillId,
            forceRerun: $forceRerun,
            planSlug: $planSlug,
        );

        return [
            'meeting_id' => $run->meeting_thought_id,
            'meeting_run_id' => $run->id,
            'analysis_id' => null,
        ];
    }

    /**
     * capture_plan: Save a document (plan, decision, dev, support, spec, research, meeting) or section as a thought.
     * doc_type sets source and tag prefix (e.g. decision:slug, spec:slug, meeting:slug). When plan_slug is provided,
     * adds tag <doc_type>:<slug> so all sections can be viewed via Stream ?tag=... (slug form e.g. decision-project-spec).
     * Optional parent_id links this thought to a root for hierarchy.
     *
     * @param  array<string, mixed>  $params
     * @return array{id: string, plan_slug?: string, doc_type?: string}
     */
    private function capturePlan(array $params): array
    {
        if (isset($params['content']) && mb_strlen((string) $params['content']) > 65535) {
            throw new \InvalidArgumentException('Content must be 65535 characters or fewer.');
        }
        $allowedDocTypes = ['plan', 'decision', 'dev', 'support', 'spec', 'research', 'meeting'];
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

        if ($docType === 'meeting') {
            $meetingThoughtId = $out['id'] ?? null;
            if (is_string($meetingThoughtId) && $meetingThoughtId !== '') {
                $meetingThought = Thought::query()->find($meetingThoughtId);
                if ($meetingThought instanceof Thought && $meetingThought->user_id === auth()->id()) {
                    $this->meetingService->queueAutoRunForMeetingThought($meetingThought, 'mcp');
                }
            }
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
