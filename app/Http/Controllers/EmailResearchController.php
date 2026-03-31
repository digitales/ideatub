<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessExtraEmailResearch;
use App\Models\CapturedInboundEmail;
use App\Models\ImportedEmail;
use App\Models\Thought;
use App\Services\Email\ResetNewsletterResearchState;
use App\Services\ResearchService;
use Illuminate\Http\RedirectResponse;

class EmailResearchController extends Controller
{
    public function __construct(
        private ResearchService $researchService
    ) {}

    /**
     * Trigger general idea research on an email thought.
     */
    public function ideaResearch(Thought $thought): RedirectResponse
    {
        $this->authorize('update', $thought);

        if ($thought->source !== 'email') {
            abort(422);
        }

        $this->markResearchPending($thought);

        try {
            $this->researchService->queueResearchRunForIdea($thought, 'email');
        } catch (\Throwable $e) {
            report($e);
            $this->clearResearchPending($thought);

            return redirect()->back()->with('error', 'Unable to start idea research. Please try again.');
        }

        return redirect()->back()->with('success', 'Idea research started. Refresh in a moment to see results.');
    }

    /**
     * Re-trigger newsletter research on an email thought.
     * Resets processing state so ProcessExtraEmailResearch can run cleanly.
     */
    public function newsletterResearch(Thought $thought, ResetNewsletterResearchState $resetNewsletterResearchState): RedirectResponse
    {
        $this->authorize('update', $thought);

        if ($thought->source !== 'email') {
            abort(422);
        }

        $stored = ImportedEmail::where('thought_id', $thought->id)->first()
            ?? CapturedInboundEmail::where('thought_id', $thought->id)->first();

        if ($stored === null) {
            abort(404);
        }

        $isImported = $stored instanceof ImportedEmail;
        $storedId = $stored->id;

        $resetNewsletterResearchState->reset($thought, $stored);

        // Dispatch after the transaction commits so the job sees committed state.
        if ($isImported) {
            ProcessExtraEmailResearch::dispatch(importedEmailId: $storedId);
        } else {
            ProcessExtraEmailResearch::dispatch(capturedInboundEmailId: $storedId);
        }

        return redirect()->back()->with('success', 'Newsletter research queued. Refresh in a moment to see results.');
    }

    private function markResearchPending(Thought $thought): void
    {
        $thought->update([
            'metadata' => array_merge($thought->metadata ?? [], ['research_pending' => true]),
        ]);
    }

    private function clearResearchPending(Thought $thought): void
    {
        $thought->refresh();

        $metadata = $thought->metadata ?? [];
        unset($metadata['research_pending']);
        $thought->update(['metadata' => $metadata]);
    }
}
