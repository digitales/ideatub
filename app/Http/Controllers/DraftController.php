<?php

namespace App\Http\Controllers;

use App\Models\Draft;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class DraftController extends Controller
{
    private const CAP = 10;

    /**
     * List current user's drafts, ordered by updated_at desc. JSON only.
     */
    public function index(Request $request): JsonResponse
    {
        $drafts = Draft::where('user_id', $request->user()->id)
            ->orderByDesc('updated_at')
            ->limit(self::CAP)
            ->get()
            ->map(fn (Draft $d) => [
                'id' => $d->id,
                'content_preview' => $d->content_preview,
                'updated_at' => $d->updated_at->toIso8601String(),
                'updated_at_human' => $d->updated_at->diffForHumans(),
            ]);

        return response()->json($drafts);
    }

    /**
     * Create a draft. Enforce cap by deleting oldest first. JSON only.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'content' => 'required|string|max:65535',
            'no_chunking' => 'sometimes|boolean',
        ]);

        $userId = $request->user()->id;
        $count = Draft::where('user_id', $userId)->count();
        if ($count >= self::CAP) {
            Draft::where('user_id', $userId)
                ->orderBy('updated_at')
                ->limit($count - self::CAP + 1)
                ->delete();
        }

        $draft = Draft::create([
            'user_id' => $userId,
            'content' => $validated['content'],
            'no_chunking' => (bool) ($validated['no_chunking'] ?? false),
        ]);

        return response()->json($this->draftResponse($draft), Response::HTTP_CREATED);
    }

    /**
     * Get one draft for resume. 404 if not found or not owner. JSON only.
     */
    public function show(Request $request, Draft $draft): JsonResponse
    {
        if ($draft->user_id !== $request->user()->id) {
            abort(404);
        }

        return response()->json($this->draftResponse($draft));
    }

    /**
     * Update draft (auto-save). 404 if not owner. JSON only.
     */
    public function update(Request $request, Draft $draft): JsonResponse
    {
        if ($draft->user_id !== $request->user()->id) {
            abort(404);
        }

        $validated = $request->validate([
            'content' => 'required|string|max:65535',
            'no_chunking' => 'sometimes|boolean',
        ]);

        $draft->update([
            'content' => $validated['content'],
            'no_chunking' => (bool) ($validated['no_chunking'] ?? $draft->no_chunking),
        ]);

        return response()->json($this->draftResponse($draft));
    }

    /**
     * Delete draft. 404 if not owner. 204. JSON only.
     */
    public function destroy(Request $request, Draft $draft): JsonResponse
    {
        if ($draft->user_id !== $request->user()->id) {
            abort(404);
        }

        $draft->delete();

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }

    private function draftResponse(Draft $draft): array
    {
        return [
            'id' => $draft->id,
            'content' => $draft->content,
            'no_chunking' => $draft->no_chunking,
            'updated_at' => $draft->updated_at->toIso8601String(),
        ];
    }
}
