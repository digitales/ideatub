<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
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

    #[Test]
    public function test_owner_can_download_exported_cv_pdf(): void
    {
        Storage::fake('local');
        config(['features.job_search' => true]);
        $owner = User::factory()->create();
        $company = Company::factory()->for($owner)->create();
        $application = Application::factory()->for($owner)->create([
            'company_id' => $company->id,
            'cv_pdf_path' => "job_pipeline/{$owner->id}-cv.pdf",
        ]);
        Storage::disk('local')->put($application->cv_pdf_path, '%PDF-1.4 fake contents');

        $response = $this->actingAs($owner)->get(route('job_pipeline.applications.download', [$application, 'cv']));

        $response->assertOk();
    }

    #[Test]
    public function test_another_user_cannot_download_someone_elses_pdf(): void
    {
        Storage::fake('local');
        config(['features.job_search' => true]);
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $company = Company::factory()->for($owner)->create();
        $application = Application::factory()->for($owner)->create([
            'company_id' => $company->id,
            'cv_pdf_path' => "job_pipeline/{$owner->id}-cv.pdf",
        ]);
        Storage::disk('local')->put($application->cv_pdf_path, '%PDF-1.4 fake contents');

        $this->actingAs($stranger)
            ->get(route('job_pipeline.applications.download', [$application, 'cv']))
            ->assertForbidden();
    }

    #[Test]
    public function test_download_404s_when_document_not_exported(): void
    {
        config(['features.job_search' => true]);
        $owner = User::factory()->create();
        $company = Company::factory()->for($owner)->create();
        $application = Application::factory()->for($owner)->create(['company_id' => $company->id]);

        $this->actingAs($owner)
            ->get(route('job_pipeline.applications.download', [$application, 'cv']))
            ->assertNotFound();
    }

    #[Test]
    public function test_download_404s_when_feature_flag_disabled(): void
    {
        config(['features.job_search' => true]);
        $owner = User::factory()->create();
        $company = Company::factory()->for($owner)->create();
        $application = Application::factory()->for($owner)->create([
            'company_id' => $company->id,
            'cv_pdf_path' => "job_pipeline/{$owner->id}-cv.pdf",
        ]);

        config(['features.job_search' => false]);

        $this->actingAs($owner)
            ->get(route('job_pipeline.applications.download', [$application, 'cv']))
            ->assertNotFound();
    }
}
