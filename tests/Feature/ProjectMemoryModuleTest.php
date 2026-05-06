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
        $formStart = '<form method="POST" action="'.$refreshAction.'"';
        $formStartPosition = strpos($html, $formStart);

        $this->assertNotFalse($formStartPosition, 'Refresh form action should point to project refresh route.');

        $formEndPosition = strpos($html, '</form>', $formStartPosition);
        $this->assertNotFalse($formEndPosition, 'Refresh form should have a closing </form> tag.');

        $formHtml = substr($html, $formStartPosition, ($formEndPosition - $formStartPosition) + strlen('</form>'));
        $this->assertStringContainsString('name="_token"', $formHtml, 'Refresh form should include a CSRF token field.');
        $this->assertStringContainsString('type="submit"', $formHtml, 'Refresh form should include a submit button.');
        $this->assertStringContainsString('Refresh working memory', $formHtml, 'Refresh form submit button label should be present.');
    }
}
