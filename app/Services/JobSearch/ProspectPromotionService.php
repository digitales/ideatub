<?php

namespace App\Services\JobSearch;

use App\Models\Application;
use App\Models\Company;
use App\Models\JobProspect;
use App\Models\Thought;
use Illuminate\Support\Facades\DB;

class ProspectPromotionService
{
    /**
     * Promote a shortlisted prospect into an Application. Defaults to the
     * `researching` stage; pass `applied` for the "already applied elsewhere"
     * fast path (sets applied_at, spec §4/§5).
     */
    public function promote(JobProspect $prospect, ?string $stage = null): Application
    {
        $stage = $stage ?? 'researching';
        if (! in_array($stage, Application::STAGES, true)) {
            throw new \InvalidArgumentException("Invalid stage: {$stage}");
        }

        return DB::transaction(function () use ($prospect, $stage) {
            $company = Company::query()->firstOrCreate(
                ['user_id' => $prospect->user_id, 'name' => $prospect->company],
                []
            );

            $application = Application::query()->create([
                'user_id' => $prospect->user_id,
                'company_id' => $company->id,
                'job_prospect_id' => $prospect->id,
                'role_title' => $prospect->role_title,
                'stage' => $stage,
                'source' => $prospect->source,
                'applied_at' => $stage === 'applied' ? now() : null,
                'last_activity_at' => now(),
            ]);

            if (trim((string) $prospect->notes) !== '') {
                $thought = Thought::create([
                    'user_id' => $prospect->user_id,
                    'content' => $prospect->notes,
                    'source' => 'job_search',
                ]);
                $application->research_thought_id = $thought->id;
                $application->save();
            }

            $prospect->update([
                'status' => 'promoted',
                'promoted_application_id' => $application->id,
            ]);

            return $application->fresh(['company', 'researchThought']);
        });
    }
}
