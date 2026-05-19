<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Projects\ProjectListingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ProjectsApiController extends Controller
{
    public function __construct(
        private ProjectListingService $projectListingService,
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
}
