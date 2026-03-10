<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Thought;
use App\Models\User;
use Illuminate\Support\Facades\Validator;

class McpController extends Controller
{
    /**
     * Capture a thought (optionally as a comment on a parent).
     * Call from MCP JSON-RPC handler with resolved user and params.
     *
     * @param  array{content: string, parent_id?: int, in_reply_to?: int}  $params
     * @return array{id: int} thought id
     *
     * @throws \InvalidArgumentException if parent not found or not owned by user
     */
    public static function captureThought(User $user, array $params): array
    {
        $v = Validator::make($params, [
            'content' => 'required|string|max:65535',
            'parent_id' => 'nullable|integer|exists:thoughts,id',
            'in_reply_to' => 'nullable|integer|exists:thoughts,id',
        ]);

        if ($v->fails()) {
            throw new \InvalidArgumentException('Validation failed: '.$v->errors()->first());
        }

        $validated = $v->validated();
        $parentId = $validated['parent_id'] ?? $validated['in_reply_to'] ?? null;

        $parent = null;
        if ($parentId !== null) {
            $parent = Thought::find($parentId);
            if (! $parent) {
                throw new \InvalidArgumentException('Parent thought not found.');
            }
            if ((int) $parent->user_id !== (int) $user->id) {
                throw new \InvalidArgumentException('Parent thought does not belong to you.');
            }
        }

        $thought = Thought::create([
            'user_id' => $user->id,
            'content' => $validated['content'],
            'metadata' => [],
            'parent_id' => $parent?->id,
        ]);

        return ['id' => $thought->id];
    }
}
