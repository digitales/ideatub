<?php

namespace App\Http\Controllers;

use App\Events\IdeaResearchRequested;
use App\Jobs\ProcessExtraEmailResearch;
use App\Models\CapturedInboundEmail;
use App\Models\ImportedEmail;
use App\Models\Thought;
use App\Models\ThoughtLinkSummary;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

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
    public function newsletterResearch(Thought $thought): RedirectResponse
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

        DB::transaction(function () use ($stored, $thought): void {
            $previousResearchThoughtId = $stored->research_thought_id;

            // Reset so the job's research_thought_id guard does not bail early.
            $stored->processing_status = 'research_queued';
            $stored->research_thought_id = null;
            $stored->save();

            if ($previousResearchThoughtId !== null) {
                ThoughtLinkSummary::query()
                    ->where('source_thought_id', $thought->id)
                    ->where('parent_research_thought_id', $previousResearchThoughtId)
                    ->delete();
            }

            // Clear stale status from the thought so the badge resets.
            $meta = $thought->source_metadata ?? [];
            unset($meta['newsletter_research']);
            $thought->source_metadata = $meta;
            $thought->save();
        });

        // Dispatch after the transaction commits so the job sees committed state.
        if ($isImported) {
            ProcessExtraEmailResearch::dispatch(importedEmailId: $storedId);
        } else {
            ProcessExtraEmailResearch::dispatch(capturedInboundEmailId: $storedId);
        }

        return redirect()->back()->with('success', 'Newsletter research queued. Refresh in a moment to see results.');
    }
}
