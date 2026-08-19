<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class JobApplicationControllerTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function test_index_404s_when_feature_flag_disabled(): void
    {
        config(['features.job_search' => false]);
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('job_pipeline.applications.index'))->assertNotFound();
    }

    #[Test]
    public function test_index_shows_board_grouped_by_stage_when_enabled(): void
    {
        config(['features.job_search' => true]);
        $user = User::factory()->create();
        $company = Company::factory()->for($user)->create();
        Application::factory()->for($user)->create(['company_id' => $company->id, 'stage' => 'applied']);

        $response = $this->actingAs($user)->get(route('job_pipeline.applications.index'));

        $response->assertOk();
        $response->assertViewIs('job_pipeline.applications.board');
    }

    #[Test]
    public function test_show_404s_for_another_users_application(): void
    {
        config(['features.job_search' => true]);
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $company = Company::factory()->for($owner)->create();
        $application = Application::factory()->for($owner)->create(['company_id' => $company->id]);

        $this->actingAs($stranger)->get(route('job_pipeline.applications.show', $application))->assertForbidden();
    }
}
