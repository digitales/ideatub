<?php

namespace Tests\Unit\Policies;

use App\Models\Application;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ApplicationPolicyTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function test_view_and_update_require_ownership(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $application = Application::factory()->for($owner)->create();

        $this->assertTrue($owner->can('view', $application));
        $this->assertFalse($stranger->can('view', $application));
        $this->assertTrue($owner->can('update', $application));
        $this->assertFalse($stranger->can('update', $application));
    }
}
