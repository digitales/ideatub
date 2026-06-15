<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use App\Models\WorkingMemory;
use App\Models\WorkingMemoryVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PulseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_guest_redirects_to_login(): void
    {
        config(['features.attention_pulse' => true]);

        $this->get(route('pulse.show'))->assertRedirect(route('login'));
    }

    public function test_authenticated_flag_off_returns_404(): void
    {
        config(['features.attention_pulse' => false]);
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('pulse.show'))->assertNotFound();
    }

    public function test_authenticated_flag_on_shows_sections_when_signals_exist(): void
    {
        config([
            'features.attention_pulse' => true,
            'features.working_memory_ui' => true,
        ]);

        $user = User::factory()->create();
        $memory = WorkingMemory::factory()->for($user)->create([
            'scope_type' => 'global',
            'scope_key' => 'global',
            'freshness_state' => 'fresh',
            'last_refreshed_at' => now(),
        ]);

        $version = WorkingMemoryVersion::factory()->for($memory)->create([
            'authoring_status' => 'fallback',
            'build_type' => 'consolidated',
            'summary_markdown' => '# Fallback',
        ]);
        $memory->update(['latest_version_id' => $version->id]);

        $response = $this->actingAs($user)->get(route('pulse.show'));

        $response->assertOk();
        $response->assertSee('Pulse', false);
        $response->assertSee('Memory health', false);
        $response->assertSee('Fallback authoring', false);
    }

    public function test_empty_state_when_no_signals(): void
    {
        config(['features.attention_pulse' => true]);
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('pulse.show'));

        $response->assertOk();
        $response->assertSee('Nothing needs attention right now', false);
    }
}
