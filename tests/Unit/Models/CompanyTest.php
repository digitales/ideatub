<?php

namespace Tests\Unit\Models;

use App\Models\Application;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CompanyTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function test_belongs_to_user_and_has_many_applications(): void
    {
        $user = User::factory()->create();
        $company = Company::factory()->for($user)->create();
        $application = Application::factory()->for($user)->create(['company_id' => $company->id]);

        $this->assertTrue($company->user->is($user));
        $this->assertCount(1, $company->applications);
        $this->assertTrue($company->applications->first()->is($application));
    }
}
