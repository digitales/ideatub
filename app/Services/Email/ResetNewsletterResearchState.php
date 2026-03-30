<?php

namespace App\Services\Email;

use App\Models\CapturedInboundEmail;
use App\Models\ImportedEmail;
use App\Models\Thought;
use App\Models\ThoughtLinkSummary;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ResetNewsletterResearchState
{
    /**
     * Reset stored email and thought state so newsletter research can run again cleanly.
     */
    public function reset(Thought $thought, ImportedEmail|CapturedInboundEmail $stored): void
    {
        if ($stored->thought_id !== $thought->id) {
            throw new InvalidArgumentException('Stored email row does not belong to the provided email thought.');
        }

        DB::transaction(function () use ($thought, $stored): void {
            $previousResearchThoughtId = $stored->research_thought_id;

            $stored->processing_status = 'research_queued';
            $stored->research_thought_id = null;
            $stored->save();

            if ($previousResearchThoughtId !== null) {
                ThoughtLinkSummary::query()
                    ->where('source_thought_id', $thought->id)
                    ->where('parent_research_thought_id', $previousResearchThoughtId)
                    ->delete();
            }

            $meta = $thought->source_metadata ?? [];
            unset($meta['newsletter_research']);
            $thought->source_metadata = $meta;
            $thought->save();
        });
    }
}
