<?php

namespace App\Http\Controllers;

use App\Models\InboxItem;
use App\Services\Inbox\InboxActionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class InboxController extends Controller
{
    public function index(): View
    {
        $items = InboxItem::query()
            ->forUser(auth()->user())
            ->actionable()
            ->orderByDesc('generated_at')
            ->paginate(20);

        return view('inbox.index', ['items' => $items]);
    }

    public function markDone(InboxItem $inboxItem, InboxActionService $actionService): RedirectResponse
    {
        $this->authorize('update', $inboxItem);

        $actionService->markDone($inboxItem);

        return redirect()->route('inbox.index')->with('success', 'Inbox item marked done.');
    }

    public function snooze(Request $request, InboxItem $inboxItem, InboxActionService $actionService): RedirectResponse
    {
        $this->authorize('update', $inboxItem);

        $validated = $request->validate([
            'preset' => 'required|in:tomorrow,next_week',
        ]);

        $actionService->snooze($inboxItem, $validated['preset']);

        return redirect()->route('inbox.index')->with('success', 'Inbox item snoozed.');
    }

    public function saveAsThought(InboxItem $inboxItem, InboxActionService $actionService): RedirectResponse
    {
        $this->authorize('update', $inboxItem);

        try {
            $actionService->saveAsThought($inboxItem);
        } catch (\Throwable $e) {
            report($e);

            return redirect()->route('inbox.index')->with('error', 'Unable to save inbox item as a thought.');
        }

        return redirect()->route('inbox.index')->with('success', 'Saved as thought.');
    }
}
