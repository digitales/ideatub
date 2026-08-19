<?php

namespace Tests\Unit\Models;

use App\Models\JobProspect;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class JobProspectTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function test_belongs_to_user_and_optionally_a_promoted_application(): void
    {
        $user = User::factory()->create();
        $prospect = JobProspect::factory()->for($user)->create();

        $this->assertTrue($prospect->user->is($user));
        $this->assertNull($prospect->promotedApplication);

        $application = \App\Models\Application::factory()->for($user)->create();
        $prospect->update(['promoted_application_id' => $application->id, 'status' => 'promoted']);

        $this->assertTrue($prospect->fresh()->promotedApplication->is($application));
    }

    #[Test]
    public function test_status_constant_lists_five_states(): void
    {
        $this->assertSame(['new', 'scored', 'shortlisted', 'dismissed', 'promoted'], JobProspect::STATUSES);
    }
}
