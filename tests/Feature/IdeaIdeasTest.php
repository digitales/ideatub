<?php

namespace Tests\Feature;

use App\Models\Thought;
use App\Models\User;
use App\Services\OpenRouterService;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IdeaIdeasTest extends TestCase
{
    use RefreshDatabase;

    public function test_ideas_page_loads_empty_for_authenticated_user(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('idea.ideas'));

        $response->assertStatus(200);
        $response->assertSee('Ideas');
        $response->assertSee('Add idea');
        $response->assertSee('No ideas yet');
    }

    public function test_ideas_page_shows_shared_secondary_nav_with_ideas_active(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('idea.ideas'));

        $response->assertStatus(200);
        $response->assertSee('data-testid="ideas-section-nav"', false);

        $html = $response->getContent();
        libxml_use_internal_errors(true);
        $dom = new DOMDocument;
        $dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        $xpath = new DOMXPath($dom);
        $navNodes = $xpath->query('//*[@data-testid="ideas-section-nav"]');
        $this->assertSame(1, $navNodes->length);
        $nav = $navNodes->item(0);
        $ideasUrl = route('idea.ideas');
        $revisitUrl = route('idea.revisit');
        $ideasLink = $xpath->query(".//a[@href='{$ideasUrl}']", $nav)->item(0);
        $revisitLink = $xpath->query(".//a[@href='{$revisitUrl}']", $nav)->item(0);
        $this->assertNotNull($ideasLink);
        $this->assertNotNull($revisitLink);
        $this->assertSame('page', $ideasLink->getAttribute('aria-current'));
        $this->assertSame('', $revisitLink->getAttribute('aria-current'));
    }

    public function test_ideas_page_redirects_guests(): void
    {
        $response = $this->get(route('idea.ideas'));

        $response->assertRedirect(route('login'));
    }

    public function test_post_idea_then_list_shows_it_with_logged_date(): void
    {
        $user = User::factory()->create();
        $fakeEmbedding = array_fill(0, 1536, 0.01);
        $this->mock(OpenRouterService::class, function ($mock) use ($fakeEmbedding): void {
            $mock->shouldReceive('embed')->once()->andReturn($fakeEmbedding);
            $mock->shouldReceive('extractMetadata')->once()->andReturn(['tags' => []]);
        });

        $response = $this->actingAs($user)->post(route('ideas.store'), [
            'content' => 'Ship the feature by Friday',
            '_token' => csrf_token(),
        ]);

        $response->assertRedirect(route('idea.ideas'));
        $response->assertSessionHas('success', 'Idea saved.');

        $idea = Thought::where('user_id', $user->id)->where('metadata->type', 'idea')->first();
        $this->assertNotNull($idea);
        $this->assertSame('Ship the feature by Friday', $idea->getDecodedContent());
        $this->assertFalse($idea->isIdeaCompleted());
        $this->assertSame(now()->toDateString(), $idea->getLoggedDate());

        $listResponse = $this->actingAs($user)->get(route('idea.ideas'));
        $listResponse->assertStatus(200);
        $listResponse->assertSee('Ship the feature by Friday');
        $listResponse->assertSee(now()->toDateString());
    }

    public function test_post_idea_with_logged_date_stores_it(): void
    {
        $user = User::factory()->create();
        $loggedDate = '2025-03-10';
        $fakeEmbedding = array_fill(0, 1536, 0.01);
        $this->mock(OpenRouterService::class, function ($mock) use ($fakeEmbedding): void {
            $mock->shouldReceive('embed')->once()->andReturn($fakeEmbedding);
            $mock->shouldReceive('extractMetadata')->once()->andReturn(['tags' => []]);
        });

        $this->actingAs($user)->post(route('ideas.store'), [
            'content' => 'Backdated idea',
            'logged_date' => $loggedDate,
            '_token' => csrf_token(),
        ]);

        $idea = Thought::where('user_id', $user->id)->ideas()->first();
        $this->assertNotNull($idea);
        $this->assertSame($loggedDate, $idea->getLoggedDate());

        $listResponse = $this->actingAs($user)->get(route('idea.ideas'));
        $listResponse->assertSee($loggedDate);
    }

    public function test_patch_toggle_completed_sets_completed_true_then_false(): void
    {
        $user = User::factory()->create();
        $thought = Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'An idea to complete',
            'metadata' => ['type' => 'idea', 'completed' => false, 'logged_date' => now()->toDateString()],
            'embedding' => null,
        ]);

        $response1 = $this->actingAs($user)->patch(route('ideas.toggle-completed', $thought), [
            '_token' => csrf_token(),
        ]);
        $response1->assertRedirect(route('idea.ideas'));
        $response1->assertSessionHas('success', 'Marked as complete.');

        $thought->refresh();
        $this->assertTrue($thought->isIdeaCompleted());
        $this->assertTrue($thought->metadata['completed'] ?? false);

        $response2 = $this->actingAs($user)->patch(route('ideas.toggle-completed', $thought), [
            '_token' => csrf_token(),
        ]);
        $response2->assertSessionHas('success', 'Marked as incomplete.');

        $thought->refresh();
        $this->assertFalse($thought->isIdeaCompleted());
        $this->assertFalse($thought->metadata['completed'] ?? true);
    }

    public function test_ideas_list_shows_completed_state_per_idea(): void
    {
        $user = User::factory()->create();
        $incomplete = Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Incomplete idea',
            'metadata' => ['type' => 'idea', 'completed' => false, 'logged_date' => '2025-03-01'],
            'embedding' => null,
        ]);
        $complete = Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Done idea',
            'metadata' => ['type' => 'idea', 'completed' => true, 'logged_date' => '2025-03-02'],
            'embedding' => null,
        ]);

        $response = $this->actingAs($user)->get(route('idea.ideas'));

        $response->assertStatus(200);
        $response->assertSee('Incomplete idea');
        $response->assertSee('Done idea');
        $response->assertSee('2025-03-01');
        $response->assertSee('2025-03-02');
        $html = $response->getContent();
        $this->assertMatchesRegularExpression(
            '/<li\b[^>]*>.*?<input[^>]*type="checkbox"[^>]*checked[^>]*>.*?Done idea.*?<\/li>/s',
            $html,
        );
        $this->assertMatchesRegularExpression(
            '/<li\b[^>]*>.*?<input[^>]*type="checkbox"(?![^>]*checked)[^>]*>.*?Incomplete idea.*?<\/li>/s',
            $html,
        );
    }

    public function test_toggle_completed_returns_422_for_non_idea_thought(): void
    {
        $user = User::factory()->create();
        $thought = Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Just a thought',
            'metadata' => ['tags' => []],
            'embedding' => null,
        ]);

        $response = $this->actingAs($user)->patch(route('ideas.toggle-completed', $thought), [
            '_token' => csrf_token(),
        ]);

        $response->assertStatus(422);
        $thought->refresh();
        $this->assertArrayNotHasKey('completed', $thought->metadata ?? []);
    }

    public function test_ideas_list_row_exposes_edit_and_full_content_for_truncated_preview(): void
    {
        $user = User::factory()->create();
        $fullTail = 'IDEATUB_FULL_EDIT_MARKER';
        $longContent = str_repeat('A', 220).$fullTail;
        $thought = Thought::factory()->create([
            'user_id' => $user->id,
            'content' => $longContent,
            'metadata' => ['type' => 'idea', 'completed' => false, 'logged_date' => '2025-03-01'],
            'embedding' => null,
        ]);

        $response = $this->actingAs($user)->get(route('idea.ideas'));

        $response->assertStatus(200);
        $response->assertSee('data-thought-id="'.$thought->id.'"', false);
        $response->assertSee('requestEdit()', false);
        $response->assertSee('Edit');
        $response->assertSee('ideatub-thought-content-update:'.route('ideas.update-content', $thought), false);
        $response->assertSee($fullTail, false);
        $this->assertMatchesRegularExpression('/previewMaxLength\s*:\s*200/', $response->getContent());
    }
}
