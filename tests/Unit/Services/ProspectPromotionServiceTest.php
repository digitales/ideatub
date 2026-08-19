<?php

namespace Tests\Unit\Services;

use App\Models\JobProspect;
use App\Models\User;
use App\Services\JobSearch\ProspectPromotionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProspectPromotionServiceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function test_promote_creates_application_at_researching_by_default_and_links_back(): void
    {
        $user = User::factory()->create();
        $prospect = JobProspect::factory()->for($user)->create([
            'company' => 'Acme Ltd',
            'role_title' => 'Staff Engineer',
            'notes' => 'Recruiter mentioned £120k base.',
        ]);

        $application = app(ProspectPromotionService::class)->promote($prospect);

        $this->assertSame('researching', $application->stage);
        $this->assertSame('Staff Engineer', $application->role_title);
        $this->assertSame('Acme Ltd', $application->company->name);
        $this->assertTrue($prospect->fresh()->promotedApplication->is($application));
        $this->assertSame('promoted', $prospect->fresh()->status);
        $this->assertNotNull($application->research_thought_id);
        $this->assertStringContainsString('Recruiter mentioned £120k base.', $application->researchThought->content);
    }

    #[Test]
    public function test_promote_with_applied_stage_override_sets_applied_at(): void
    {
        $user = User::factory()->create();
        $prospect = JobProspect::factory()->for($user)->create();

        $application = app(ProspectPromotionService::class)->promote($prospect, 'applied');

        $this->assertSame('applied', $application->stage);
        $this->assertNotNull($application->applied_at);
    }

    #[Test]
    public function test_promote_without_notes_does_not_create_research_thought(): void
    {
        $user = User::factory()->create();
        $prospect = JobProspect::factory()->for($user)->create(['notes' => null]);

        $application = app(ProspectPromotionService::class)->promote($prospect);

        $this->assertNull($application->research_thought_id);
    }
}
