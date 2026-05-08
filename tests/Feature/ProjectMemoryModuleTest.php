<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProjectMemoryModuleTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function project_owner_sees_refresh_working_memory_button_on_project_show_page(): void
    {
        $this->withoutVite();
        config(['features.working_memory_ui' => true]);

        $user = User::factory()->create();
        assert($user instanceof User);
        $project = Project::factory()->create([
            'user_id' => $user->id,
        ]);

        $response = $this->actingAs($user)
            ->get(route('projects.show', $project))
            ->assertOk();

        $html = (string) $response->getContent();
        $refreshAction = route('working-memory.refresh.project', $project);
        $response->assertSee('action="'.$refreshAction.'"', false);

        $matched = preg_match(
            '/<form[\s\S]*?data-working-memory-refresh[\s\S]*?>[\s\S]*?<\/form>/',
            $html,
            $matches
        );
        $this->assertSame(1, $matched, 'Refresh form with data-working-memory-refresh should be present.');
        $formHtml = $matches[0];

        $this->assertStringContainsString('name="_token"', $formHtml, 'Refresh form should include a CSRF token field.');
        $this->assertStringContainsString('type="submit"', $formHtml, 'Refresh form should include a submit button.');
        $this->assertStringContainsString('Refresh working memory', $formHtml, 'Refresh form submit button label should be present.');
        $this->assertStringContainsString('data-working-memory-refresh', $formHtml, 'Refresh form should be marked for shared pending-state script.');
    }
}
