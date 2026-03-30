<?php

namespace App\Http\Controllers;

use App\Events\IdeaResearchRequested;
use App\Jobs\ProcessExtraEmailResearch;
use App\Models\CapturedInboundEmail;
use App\Models\ImportedEmail;
use App\Models\Thought;
use App\Services\Email\ResetNewsletterResearchState;
use Illuminate\Http\RedirectResponse;

class EmailResearchController extends Controller
{
    /**
     * Trigger general idea research on an email thought.
     */
    public function ideaResearch(Thought $thought): RedirectResponse
    {
        $this->authorize('update', $thought);

        if ($thought->source !== 'email') {
            abort(422);
        }

        $thought->update([
            'metadata' => array_merge($thought->metadata ?? [], ['research_pending' => true]),
        ]);

        IdeaResearchRequested::dispatch($thought, 'email');

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
}
