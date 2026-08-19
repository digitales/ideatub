<?php

namespace Tests\Unit\Models;

use App\Models\Application;
use App\Models\Company;
use App\Models\Interaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ApplicationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function test_belongs_to_company_and_has_many_interactions(): void
    {
        $user = User::factory()->create();
        $company = Company::factory()->for($user)->create();
        $application = Application::factory()->for($user)->create(['company_id' => $company->id]);
        Interaction::factory()->for($user)->create(['application_id' => $application->id]);

        $this->assertTrue($application->company->is($company));
        $this->assertCount(1, $application->interactions);
    }

    #[Test]
    public function test_stages_constant_lists_seven_stages(): void
    {
        $this->assertSame(
            ['researching', 'applied', 'screening', 'interviewing', 'offer', 'rejected', 'withdrawn'],
            Application::STAGES
        );
    }
}
