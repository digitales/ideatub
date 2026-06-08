<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Services\Projects\ProjectListingService;
use App\Services\Projects\ProjectSettingsService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ProjectsApiController extends Controller
{
    public function __construct(
        private ProjectListingService $projectListingService,
        private ProjectSettingsService $projectSettingsService,
    ) {}

    /**
     * GET /api/projects — List projects for Elixirr scope discovery.
     * Query params: elixirr_client_slug (optional), parent_project_id (optional, uuid).
     */
    public function index(Request $request): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'elixirr_client_slug' => 'sometimes|string|max:64',
            'parent_project_id' => 'sometimes|uuid',
        ]);
        if ($v->fails()) {
            return response()->json(['error' => 'validation_error', 'message' => $v->errors()->first()], 422);
        }

        $payload = $this->projectListingService->forUser(
            (int) auth()->id(),
            $request->has('elixirr_client_slug') ? (string) $request->input('elixirr_client_slug') : null,
            $request->has('parent_project_id') ? (string) $request->input('parent_project_id') : null,
        );

        return response()->json($payload);
    }

    /**
     * PATCH /api/projects/{project} — Update project settings.
     */
    public function update(Request $request, Project $project): JsonResponse
    {
        if ((int) auth()->id() !== (int) $project->user_id) {
            return response()->json(['error' => 'forbidden', 'message' => 'You do not own this project.'], 403);
        }

        $v = Validator::make($request->all(), [
            'working_memory_auto_update' => 'sometimes|boolean',
        ]);
        if ($v->fails()) {
            return response()->json(['error' => 'validation_error', 'message' => $v->errors()->first()], 422);
        }

        /** @var array{working_memory_auto_update?: bool} $validated */
        $validated = $v->validated();

        try {
            $updated = $this->projectSettingsService->updateForUser((int) auth()->id(), $project, $validated);
        } catch (AuthorizationException $exception) {
            return response()->json(['error' => 'forbidden', 'message' => $exception->getMessage()], 403);
        }

        return response()->json([
            'data' => [
                'id' => (string) $updated->id,
                'title' => $updated->title,
                'working_memory_auto_update' => (bool) $updated->working_memory_auto_update,
            ],
        ]);
    }
}
