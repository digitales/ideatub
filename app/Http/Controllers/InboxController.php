<?php

namespace App\Http\Controllers;

use App\Models\InboxItem;
use App\Models\User;
use App\Services\Email\EmailReviewActionService;
use App\Services\Inbox\InboxActionService;
use App\Services\Inbox\InboxBulkActionService;
use App\Services\Inbox\InboxIndexPresenter;
use App\Support\Inbox\InboxGroupDescriptor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use InvalidArgumentException;

class InboxController extends Controller
{
    public function index(InboxIndexPresenter $presenter): View
    {
        $viewModel = $presenter->present(auth()->user());

        return view('inbox.index', [
            'groups' => $viewModel['groups'],
            'singles' => $viewModel['singles'],
            'inboxInitialCount' => $viewModel['inboxInitialCount'],
        ]);
    }

    public function bulkGroupAction(
        Request $request,
        string $generatorType,
        InboxBulkActionService $bulkActionService,
    ): RedirectResponse|JsonResponse {
        $allowedActions = InboxGroupDescriptor::bulkActionsFor($generatorType);

        $validated = $request->validate([
            'action' => ['required', 'string', Rule::in($allowedActions)],
        ]);

        try {
            $clearedCount = $bulkActionService->apply(
                $request->user(),
                $generatorType,
                $validated['action'],
            );
        } catch (InvalidArgumentException $e) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => $e->getMessage(),
                ], 422);
            }

            return redirect()->route('inbox.index')->with('error', $e->getMessage());
        }

        $message = InboxGroupDescriptor::bulkActionSuccessMessage($validated['action'], $clearedCount);

        if ($request->expectsJson()) {
            return response()->json([
                'ok' => true,
                'message' => $message,
                'cleared_count' => $clearedCount,
                'remaining_count' => $this->actionableInboxCountFor($request->user()),
            ]);
        }

        return redirect()->route('inbox.index')->with('success', $message);
    }

    public function markDone(Request $request, InboxItem $inboxItem, InboxActionService $actionService): RedirectResponse|JsonResponse
    {
        $this->authorize('update', $inboxItem);

        $actionService->markDone($inboxItem);

        if ($request->expectsJson()) {
            return response()->json([
                'ok' => true,
                'message' => 'Inbox item marked done.',
                'item_id' => $inboxItem->id,
                'remaining_count' => $this->actionableInboxCountFor($request->user()),
            ]);
        }

        return redirect()->route('inbox.index')->with('success', 'Inbox item marked done.');
    }

    public function snooze(Request $request, InboxItem $inboxItem, InboxActionService $actionService): RedirectResponse|JsonResponse
    {
        $this->authorize('update', $inboxItem);

        $validated = $request->validate([
            'preset' => 'required|in:tomorrow,next_week',
        ]);

        $actionService->snooze($inboxItem, $validated['preset']);

        if ($request->expectsJson()) {
            return response()->json([
                'ok' => true,
                'message' => 'Inbox item snoozed.',
                'item_id' => $inboxItem->id,
                'remaining_count' => $this->actionableInboxCountFor($request->user()),
            ]);
        }

        return redirect()->route('inbox.index')->with('success', 'Inbox item snoozed.');
    }

    public function saveAsThought(
        Request $request,
        InboxItem $inboxItem,
        InboxActionService $actionService,
        EmailReviewActionService $reviewActionService,
    ): RedirectResponse|JsonResponse {
        $this->authorize('update', $inboxItem);

        $isStandardSave = ($inboxItem->generator_type ?? '') !== 'email_sender_review';
        $expectsJson = $request->expectsJson();

        try {
            if (! $isStandardSave) {
                $reviewActionService->saveReviewedEmailAsThought($inboxItem, $request->user());
            } else {
                $actionService->saveAsThought($inboxItem);
            }
        } catch (\Throwable $e) {
            report($e);

            if ($expectsJson && $isStandardSave) {
                return response()->json([
                    'message' => 'Unable to save inbox item as a thought.',
                ], 503);
            }

            return redirect()->route('inbox.index')->with('error', 'Unable to save inbox item as a thought.');
        }

        if ($expectsJson && $isStandardSave) {
            return response()->json([
                'ok' => true,
                'message' => 'Saved as thought.',
                'item_id' => $inboxItem->id,
                'remaining_count' => $this->actionableInboxCountFor($request->user()),
            ]);
        }

        return redirect()->route('inbox.index')->with('success', 'Saved as thought.');
    }

    public function applyEmailReviewAction(Request $request, InboxItem $inboxItem, EmailReviewActionService $reviewActionService): RedirectResponse|JsonResponse
    {
        $this->authorize('update', $inboxItem);

        $validated = $request->validate([
            'action' => 'required|in:allow,ignore,extra_process,save_thought',
        ]);

        $expectsJson = $request->expectsJson();

        if ($validated['action'] === 'save_thought') {
            try {
                $reviewActionService->saveReviewedEmailAsThought($inboxItem, $request->user());
            } catch (\Throwable $e) {
                report($e);

                if ($expectsJson) {
                    return response()->json([
                        'message' => 'Unable to save inbox item as a thought.',
                    ], 503);
                }

                return redirect()->route('inbox.index')->with('error', 'Unable to save inbox item as a thought.');
            }

            if ($expectsJson) {
                return response()->json([
                    'ok' => true,
                    'message' => 'Saved as thought.',
                    'item_id' => $inboxItem->id,
                    'remaining_count' => $this->actionableInboxCountFor($request->user()),
                ]);
            }

            return redirect()->route('inbox.index')->with('success', 'Saved as thought.');
        }

        try {
            $applied = $reviewActionService->applySenderClassification($inboxItem, $request->user(), $validated['action']);
        } catch (InvalidArgumentException $e) {
            report($e);

            if ($expectsJson) {
                return response()->json([
                    'message' => 'Unable to apply sender classification.',
                ], 422);
            }

            return redirect()->route('inbox.index')->with('error', 'Unable to apply sender classification.');
        }

        if (! $applied) {
            if ($expectsJson) {
                return response()->json([
                    'ok' => true,
                    'message' => 'Sender classification was already handled.',
                    'item_id' => $inboxItem->id,
                    'remaining_count' => $this->actionableInboxCountFor($request->user()),
                ]);
            }

            return redirect()->route('inbox.index')->with('success', 'Sender classification was already handled.');
        }

        if ($validated['action'] === 'allow') {
            try {
                $reviewActionService->saveReviewedEmailAsThought($inboxItem, $request->user());
            } catch (\Throwable $e) {
                report($e);

                if ($expectsJson) {
                    return response()->json([
                        'ok' => true,
                        'message' => 'Sender rule saved. Could not import email as a thought.',
                        'item_id' => $inboxItem->id,
                        'remaining_count' => $this->actionableInboxCountFor($request->user()),
                    ]);
                }

                return redirect()->route('inbox.index')->with('success', 'Sender rule saved. Could not import email as a thought.');
            }
        }

        if ($expectsJson) {
            return response()->json([
                'ok' => true,
                'message' => 'Sender classification saved.',
                'item_id' => $inboxItem->id,
                'remaining_count' => $this->actionableInboxCountFor($request->user()),
            ]);
        }

        return redirect()->route('inbox.index')->with('success', 'Sender classification saved.');
    }

    private function actionableInboxCountFor(User $user): int
    {
        return (int) InboxItem::query()
            ->forUser($user)
            ->actionable()
            ->count();
    }
}
