<?php

namespace App\Http\Controllers;

use App\Models\Thought;
use App\Models\User;
use App\Services\ArticleCaptureService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ArticleController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();

        $articles = Thought::query()
            ->where('user_id', $user->id)
            ->where('source', 'article')
            ->whereNull('parent_id')
            ->orderByDesc('created_at')
            ->paginate(25);

        return view('article.index', compact('articles'));
    }

    public function store(Request $request, ArticleCaptureService $captureService): RedirectResponse
    {
        $validated = $request->validate([
            'url' => 'required|url|max:2048',
        ]);

        $user = $request->user();
        if (! $user instanceof User) {
            abort(403);
        }

        try {
            $captureService->capture($validated['url'], [
                'user_id' => $user->id,
            ]);
        } catch (\InvalidArgumentException $e) {
            return redirect()
                ->route('articles.index')
                ->withInput()
                ->withErrors(['url' => $e->getMessage()]);
        } catch (\Throwable $e) {
            report($e);

            return redirect()
                ->route('articles.index')
                ->withInput()
                ->with('error', 'Unable to capture article. Please try again.');
        }

        return redirect()
            ->route('articles.index')
            ->with('success', 'Article capture started.');
    }
}
