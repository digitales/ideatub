<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectShare;
use App\Models\Thought;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SharedProjectViewTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_unknown_token_returns_404(): void
    {
        $this->get(route('shared-projects.hub', ['token' => str_repeat('b', 32)]))
            ->assertNotFound();
    }

    public function test_hub_without_password_shows_project_and_members(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id, 'title' => 'Team space']);
        $thought = Thought::factory()->create(['user_id' => $user->id, 'content' => 'First member line']);
        $project->thoughts()->attach($thought->id, ['sort_order' => 0]);

        $share = ProjectShare::create([
            'user_id' => $user->id,
            'project_id' => $project->id,
            'token' => ProjectShare::generateToken(),
            'password_hash' => null,
            'expires_at' => null,
        ]);

        $response = $this->get(route('shared-projects.hub', $share->token));

        $response->assertOk();
        $response->assertSee('Team space', false);
        $response->assertSee('First member line', false);
        $response->assertSee('Read all', false);
    }

    public function test_read_all_renders_member_content(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);
        $thought = Thought::factory()->create(['user_id' => $user->id, 'content' => '## Hello'."\n\nBody text."]);
        $project->thoughts()->attach($thought->id, ['sort_order' => 0]);

        $share = ProjectShare::create([
            'user_id' => $user->id,
            'project_id' => $project->id,
            'token' => ProjectShare::generateToken(),
        ]);

        $response = $this->get(route('shared-projects.read', $share->token));

        $response->assertOk();
        $response->assertSee('Body text.', false);
    }

    public function test_thought_not_in_project_returns_404(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);
        $inside = Thought::factory()->create(['user_id' => $user->id]);
        $outside = Thought::factory()->create(['user_id' => $user->id]);
        $project->thoughts()->attach($inside->id, ['sort_order' => 0]);

        $share = ProjectShare::create([
            'user_id' => $user->id,
            'project_id' => $project->id,
            'token' => ProjectShare::generateToken(),
        ]);

        $this->get(route('shared-projects.thought', ['token' => $share->token, 'thoughtId' => $outside->id]))
            ->assertNotFound();
    }

    public function test_password_protected_hub_post_wrong_password_returns_401(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);
        $share = ProjectShare::create([
            'user_id' => $user->id,
            'project_id' => $project->id,
            'token' => ProjectShare::generateToken(),
            'password_hash' => Hash::make('good'),
        ]);

        $this->post(route('shared-projects.hub', $share->token), [
            'password' => 'bad',
            '_token' => csrf_token(),
        ])->assertStatus(401);
    }

    public function test_soft_deleted_project_share_returns_404(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);
        $share = ProjectShare::create([
            'user_id' => $user->id,
            'project_id' => $project->id,
            'token' => ProjectShare::generateToken(),
        ]);
        $project->delete();

        $this->get(route('shared-projects.hub', $share->token))->assertNotFound();
    }
}
