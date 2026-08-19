<?php

namespace Tests\Unit\Models;

use App\Models\Application;
use App\Models\Interaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class InteractionTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function test_belongs_to_an_application(): void
    {
        $user = User::factory()->create();
        $application = Application::factory()->for($user)->create();
        $interaction = Interaction::factory()->for($user)->create(['application_id' => $application->id]);

        $this->assertTrue($interaction->application->is($application));
    }

    #[Test]
    public function test_types_constant_lists_five_types(): void
    {
        $this->assertSame(['interview', 'follow_up', 'rejection', 'offer', 'note'], Interaction::TYPES);
    }
}
