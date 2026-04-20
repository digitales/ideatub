<?php

namespace App\Http\Controllers;

use App\Models\Comment;
use App\Models\ResearchShare;
use App\Models\Thought;
use App\Support\Comments\ShareContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;

class SharedResearchCommentController extends Controller
{
    public function store(Request $request, string $token): RedirectResponse
    {
        $share = ResearchShare::where('token', $token)->firstOrFail();

        abort_unless((bool) $share->allow_comments, 403, 'Comments are disabled on this share.');

        if ($request->filled('website_url')) {
            return Redirect::to('/r/'.$token.'#comments');
        }

        $validated = $request->validate([
            'commentable_id' => ['required', 'string', 'max:36'],
            'author_name' => [
                'required', 'string', 'max:100',
                function ($attr, $value, $fail) {
                    if (preg_match('/https?:\/\//i', (string) $value)) {
                        $fail('Name cannot contain URLs.');
                    }
                    if (preg_match('/[\p{Cc}]/u', (string) $value)) {
                        $fail('Name contains invalid characters.');
                    }
                },
            ],
            'content' => ['required', 'string', 'max:2000'],
        ]);

        $target = Thought::find($validated['commentable_id']);

        abort_unless($target !== null, 422);
        abort_unless(
            $target->id === $share->thought_id || $target->parent_id === $share->thought_id,
            422
        );

        $context = new ShareContext(
            researchThoughtId: $share->thought_id,
            shareId: $share->id,
            allowComments: (bool) $share->allow_comments,
        );

        abort_unless($target->authorizeCommentCreation(null, $context), 403);

        Comment::create([
            'commentable_type' => 'thought',
            'commentable_id' => $target->id,
            'author_user_id' => null,
            'author_name' => trim($validated['author_name']),
            'content' => $validated['content'],
            'format' => 'plain',
            'ip_hash' => hash('sha256', $request->ip().'|'.config('app.key')),
        ]);

        return Redirect::to('/r/'.$token.'#comments');
    }
}
