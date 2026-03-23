<?php

namespace App\Http\Controllers;

use App\Events\IdeaResearchRequested;
use App\Jobs\ProcessExtraEmailResearch;
use App\Models\CapturedInboundEmail;
use App\Models\ImportedEmail;
use App\Models\Thought;
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
            abort(403);
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
    public function newsletterResearch(Thought $thought): RedirectResponse
    {
        $this->authorize('update', $thought);

        if ($thought->source !== 'email') {
            abort(403);
        }

        $stored = ImportedEmail::where('thought_id', $thought->id)->first()
            ?? CapturedInboundEmail::where('thought_id', $thought->id)->first();

        if ($stored === null) {
            abort(404);
        }

        // Reset so the job's research_thought_id guard does not bail early.
        $stored->processing_status = 'research_queued';
        $stored->research_thought_id = null;
        $stored->save();

        // Clear stale status from the thought so the badge resets.
        $meta = $thought->source_metadata ?? [];
        unset($meta['newsletter_research']);
        $thought->source_metadata = $meta;
        $thought->save();

        if ($stored instanceof ImportedEmail) {
            ProcessExtraEmailResearch::dispatch(importedEmailId: $stored->id);
        } else {
            ProcessExtraEmailResearch::dispatch(capturedInboundEmailId: $stored->id);
        }

        return redirect()->back()->with('success', 'Newsletter research queued. Refresh in a moment to see results.');
    }
}
