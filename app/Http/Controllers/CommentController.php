<?php

namespace App\Http\Controllers;

use App\Contracts\Commentable;
use App\Models\Comment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CommentController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $morphMap = Relation::morphMap();

        $validated = $request->validate([
            'commentable_type' => ['required', 'string', Rule::in(array_keys($morphMap))],
            'commentable_id' => ['required', 'string', 'max:36'],
            'content' => ['required', 'string', 'max:10000'],
        ]);

        /** @var class-string<Model> $modelClass */
        $modelClass = $morphMap[$validated['commentable_type']];
        $commentable = $modelClass::find($validated['commentable_id']);

        abort_unless($commentable !== null, 404);
        abort_unless($commentable instanceof Commentable, 422);
        abort_unless(
            $commentable->authorizeCommentCreation($request->user(), null),
            403
        );

        Comment::create([
            'commentable_type' => $validated['commentable_type'],
            'commentable_id' => $validated['commentable_id'],
            'author_user_id' => $request->user()->id,
            'author_name' => null,
            'content' => $validated['content'],
            'format' => 'markdown',
        ]);

        return redirect()->back()->with('success', 'Comment posted.');
    }

    public function update(Request $request, Comment $comment): RedirectResponse
    {
        $this->authorize('update', $comment);

        $validated = $request->validate([
            'content' => ['required', 'string', 'max:10000'],
        ]);

        $comment->update(['content' => $validated['content']]);

        return redirect()->back()->with('success', 'Comment updated.');
    }

    public function destroy(Request $request, Comment $comment): RedirectResponse
    {
        $this->authorize('delete', $comment);

        $comment->delete();

        return redirect()->back()->with('success', 'Comment deleted.');
    }
}
