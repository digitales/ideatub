<?php

namespace Tests\Feature;

use App\Models\JobProspect;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class JobProspectControllerTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function test_shortlist_action_sets_status(): void
    {
        config(['features.job_search' => true]);
        $user = User::factory()->create();
        $prospect = JobProspect::factory()->for($user)->create(['status' => 'scored']);

        $this->actingAs($user)->post(route('job_pipeline.prospects.shortlist', $prospect))->assertRedirect();

        $this->assertSame('shortlisted', $prospect->fresh()->status);
    }

    #[Test]
    public function test_mark_applied_action_promotes_directly_to_applied(): void
    {
        config(['features.job_search' => true]);
        $user = User::factory()->create();
        $prospect = JobProspect::factory()->for($user)->create();

        $this->actingAs($user)->post(route('job_pipeline.prospects.mark-applied', $prospect))->assertRedirect();

        $this->assertSame('applied', $prospect->fresh()->promotedApplication->stage);
    }

    #[Test]
    public function test_dismiss_action_sets_status(): void
    {
        config(['features.job_search' => true]);
        $user = User::factory()->create();
        $prospect = JobProspect::factory()->for($user)->create();

        $this->actingAs($user)->post(route('job_pipeline.prospects.dismiss', $prospect))->assertRedirect();

        $this->assertSame('dismissed', $prospect->fresh()->status);
    }
}
