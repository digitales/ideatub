<?php

namespace Tests\Unit\Services;

use App\Models\Achievement;
use App\Models\Application;
use App\Models\Thought;
use App\Models\User;
use App\Services\Documents\DocumentAssemblyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DocumentAssemblyServiceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function test_assemble_includes_tagged_achievements_and_marks_them_used(): void
    {
        $user = User::factory()->create();
        $research = Thought::create(['user_id' => $user->id, 'content' => 'Company builds fintech tooling.']);
        $application = Application::factory()->for($user)->create(['research_thought_id' => $research->id]);
        $laravelBullet = Achievement::factory()->for($user)->create(['tag' => 'laravel', 'bullet_text' => 'Shipped a Laravel MCP server.', 'times_used' => 0]);
        Achievement::factory()->for($user)->create(['tag' => 'design', 'bullet_text' => 'Ran design workshops.']);

        $result = app(DocumentAssemblyService::class)->assemble($application, ['laravel']);

        $this->assertStringContainsString('Shipped a Laravel MCP server.', $result['cv_markdown']);
        $this->assertStringNotContainsString('Ran design workshops.', $result['cv_markdown']);
        $this->assertStringContainsString('Company builds fintech tooling.', $result['cover_letter_markdown']);
        $this->assertSame(1, $laravelBullet->fresh()->times_used);
    }
}
