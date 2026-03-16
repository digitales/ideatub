<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Thought;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RealtimeCheckController extends Controller
{
    /**
     * GET /api/thoughts/realtime-check — Polling endpoint for realtime stream.
     * Query param: since (required, ISO 8601 date). Returns has_new when user has thoughts created after since.
     */
    public function realtimeCheck(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'since' => 'required|date',
        ]);

        $since = $validated['since'];
        $hasNew = Thought::query()
            ->where('user_id', $request->user()->id)
            ->where('created_at', '>', $since)
            ->exists();

        return response()->json(['has_new' => $hasNew]);
    }
}
