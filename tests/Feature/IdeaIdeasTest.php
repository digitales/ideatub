<?php

namespace Tests\Feature;

use App\Jobs\RunResearchRun;
use App\Models\ResearchRun;
use App\Models\ResearchSkill;
use App\Models\ResearchSkillVersion;
use App\Models\Thought;
use App\Models\User;
use App\Models\UserPreference;
use App\Services\OpenRouterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\AssertsIdeasSectionNav;
use Tests\TestCase;

class IdeaIdeasTest extends TestCase
{
    use AssertsIdeasSectionNav;
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

    public function test_ideas_ajax_refetch_returns_list_html_with_presenter_logged_date_ymd(): void
    {
        $user = User::factory()->create();
        $thought = Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Ajax list idea',
            'metadata' => ['type' => 'idea', 'completed' => false, 'logged_date' => '2025-08-20'],
            'embedding' => null,
        ]);

        $response = $this->actingAs($user)->withHeaders([
            'X-Requested-With' => 'XMLHttpRequest',
            'Accept' => 'application/json',
        ])->get(route('idea.ideas'));

        $response->assertOk();
        $response->assertJsonStructure(['html', 'latest_created_at']);
        $html = $response->json('html');
        $this->assertStringContainsString('Ajax list idea', $html);
        $this->assertStringContainsString('data-thought-id="'.$thought->id.'"', $html);
        $this->assertStringContainsString('2025-08-20', $html);
    }

    public function test_ideas_page_shows_shared_secondary_nav_with_ideas_active(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('idea.ideas'));

        $response->assertStatus(200);
        $this->assertIdeasSectionNav($response, 'ideas');
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

        $response1 = $this->actingAs($user)
            ->from(route('idea.revisit'))
            ->patch(route('ideas.toggle-completed', $thought), [
                '_token' => csrf_token(),
            ]);
        $response1->assertRedirect(route('idea.revisit'));
        $response1->assertSessionHas('success', 'Marked as complete.');

        $thought->refresh();
        $this->assertTrue($thought->isIdeaCompleted());
        $this->assertTrue($thought->metadata['completed'] ?? false);
        $this->assertNotEmpty($thought->metadata['completed_at'] ?? null);

        $response2 = $this->actingAs($user)
            ->from(route('idea.ideas'))
            ->patch(route('ideas.toggle-completed', $thought), [
                '_token' => csrf_token(),
            ]);
        $response2->assertRedirect(route('idea.ideas'));
        $response2->assertSessionHas('success', 'Marked as incomplete.');

        $thought->refresh();
        $this->assertFalse($thought->isIdeaCompleted());
        $this->assertFalse($thought->metadata['completed'] ?? true);
        $this->assertArrayNotHasKey('completed_at', $thought->metadata);
    }

    public function test_completing_idea_writes_metadata_completed_at_iso8601(): void
    {
        $user = User::factory()->create();
        $thought = Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Complete me',
            'metadata' => ['type' => 'idea', 'completed' => false, 'logged_date' => '2025-03-01', 'tags' => ['a']],
            'embedding' => null,
        ]);

        $this->travelTo(Carbon::parse('2026-03-24 12:00:00', 'UTC'));
        try {
            $this->actingAs($user)->patch(route('ideas.toggle-completed', $thought), [
                '_token' => csrf_token(),
            ]);

            $thought->refresh();
            $this->assertTrue($thought->isIdeaCompleted());
            $this->assertSame('2026-03-24T12:00:00+00:00', $thought->metadata['completed_at']);
            $this->assertSame(['a'], $thought->metadata['tags']);
        } finally {
            $this->travelBack();
        }
    }

    public function test_patch_toggle_completed_json_includes_completed_at(): void
    {
        $user = User::factory()->create();
        $thought = Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'JSON toggle',
            'metadata' => ['type' => 'idea', 'completed' => false, 'logged_date' => '2025-03-01'],
            'embedding' => null,
        ]);

        $this->travelTo(Carbon::parse('2026-03-24 15:30:00', 'UTC'));
        try {
            $completeResponse = $this->actingAs($user)->patchJson(route('ideas.toggle-completed', $thought));
            $completeResponse->assertOk();
            $completeResponse->assertJson([
                'completed' => true,
                'completed_at' => '2026-03-24T15:30:00+00:00',
            ]);

            $thought->refresh();
            $incompleteResponse = $this->actingAs($user)->patchJson(route('ideas.toggle-completed', $thought));
            $incompleteResponse->assertOk();
            $incompleteResponse->assertJson([
                'completed' => false,
                'completed_at' => null,
            ]);
        } finally {
            $this->travelBack();
        }
    }

    public function test_ideas_page_lists_incomplete_only_hides_completed(): void
    {
        $user = User::factory()->create();
        $incomplete = Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Incomplete idea',
            'metadata' => ['type' => 'idea', 'completed' => false, 'logged_date' => '2025-03-01'],
            'embedding' => null,
        ]);
        Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Done idea',
            'metadata' => ['type' => 'idea', 'completed' => true, 'logged_date' => '2025-03-02'],
            'embedding' => null,
        ]);

        $response = $this->actingAs($user)->get(route('idea.ideas'));

        $response->assertStatus(200);
        $response->assertSee('Incomplete idea');
        $response->assertDontSee('Done idea');
        $response->assertSee('2025-03-01');
        $response->assertDontSee('2025-03-02');
        $response->assertSee('1 incomplete');
        $response->assertSee('Mark as complete');
        $html = $response->getContent();
        $this->assertMatchesRegularExpression(
            '/<li\b[^>]*>.*?<input[^>]*type="checkbox"(?![^>]*checked)[^>]*>.*?Incomplete idea.*?<\/li>/s',
            $html,
        );
        $this->assertMatchesRegularExpression(
            '/<li\b[^>]*data-thought-id="'.preg_quote($incomplete->id, '/').'"[^>]*>/',
            $html,
        );
    }

    public function test_ideas_page_hides_rows_with_completed_at_even_when_completed_flag_is_false(): void
    {
        $user = User::factory()->create();
        Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Timestamped idea',
            'metadata' => [
                'type' => 'idea',
                'completed' => false,
                'completed_at' => now()->toIso8601String(),
                'logged_date' => '2025-03-03',
            ],
            'embedding' => null,
        ]);

        $response = $this->actingAs($user)->get(route('idea.ideas'));

        $response->assertStatus(200);
        $response->assertDontSee('Timestamped idea');
        $response->assertDontSee('2025-03-03');
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

    public function test_ideas_list_outer_card_element_carries_thought_id_for_dom_removal(): void
    {
        $user = User::factory()->create();
        $thought = Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Delete me cleanly',
            'metadata' => ['type' => 'idea', 'completed' => false, 'logged_date' => '2025-03-01'],
            'embedding' => null,
        ]);

        $response = $this->actingAs($user)->get(route('idea.ideas'));

        $response->assertStatus(200);
        $this->assertMatchesRegularExpression(
            '/<li\b[^>]*data-thought-id="'.preg_quote($thought->id, '/').'"[^>]*>/',
            $response->getContent(),
        );
    }

    public function test_ideas_list_adds_safe_wrap_classes_for_long_card_content(): void
    {
        $user = User::factory()->create();
        Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'https://advertise.tldr.tech/?utm_source=tldrai&utm_medium=newsletter&utm_campaign=advertisetopnav',
            'metadata' => [
                'type' => 'idea',
                'completed' => false,
                'logged_date' => '2025-03-01',
                'tags' => ['https://links.tldrnewsletter.com/pRgBqs'],
            ],
            'embedding' => null,
        ]);

        $response = $this->actingAs($user)->get(route('idea.index'));

        $response->assertOk();
        $html = $response->getContent();
        $this->assertStringContainsString('break-words [overflow-wrap:anywhere]', $html);
        $this->assertStringContainsString('max-w-full break-words [overflow-wrap:anywhere]', $html);
    }

    public function test_save_idea_does_not_queue_research_when_auto_run_is_off(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        UserPreference::set($user, UserPreference::KEY_RESEARCH_AUTO_RUN_ENABLED, false);
        $skill = ResearchSkill::factory()->create([
            'user_id' => $user->id,
            'is_default' => true,
            'allow_auto_run' => true,
            'is_manual_enabled' => true,
            'is_active' => true,
        ]);
        ResearchSkillVersion::factory()->create(['research_skill_id' => $skill->id]);
        $fakeEmbedding = array_fill(0, 1536, 0.01);
        $this->mock(OpenRouterService::class, function ($mock) use ($fakeEmbedding): void {
            $mock->shouldReceive('embed')->once()->andReturn($fakeEmbedding);
            $mock->shouldReceive('extractMetadata')->once()->andReturn(['tags' => []]);
        });

        $this->actingAs($user)->post(route('ideas.store'), [
            'content' => 'No auto queue',
            '_token' => csrf_token(),
        ]);

        $idea = Thought::where('user_id', $user->id)->where('metadata->type', 'idea')->first();
        $this->assertNotNull($idea);
        $this->assertSame(0, ResearchRun::where('idea_thought_id', $idea->id)->count());
        Queue::assertNotPushed(RunResearchRun::class);
    }

    public function test_save_idea_queues_default_research_when_auto_run_on_and_skill_eligible(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        UserPreference::set($user, UserPreference::KEY_RESEARCH_AUTO_RUN_ENABLED, true);
        $skill = ResearchSkill::factory()->create([
            'user_id' => $user->id,
            'name' => 'Auto default skill',
            'is_default' => true,
            'allow_auto_run' => true,
            'is_manual_enabled' => true,
            'is_active' => true,
        ]);
        ResearchSkillVersion::factory()->create(['research_skill_id' => $skill->id]);
        $fakeEmbedding = array_fill(0, 1536, 0.01);
        $this->mock(OpenRouterService::class, function ($mock) use ($fakeEmbedding): void {
            $mock->shouldReceive('embed')->once()->andReturn($fakeEmbedding);
            $mock->shouldReceive('extractMetadata')->once()->andReturn(['tags' => []]);
        });

        $response = $this->actingAs($user)->post(route('ideas.store'), [
            'content' => 'Queue me',
            '_token' => csrf_token(),
        ]);

        $response->assertRedirect(route('idea.ideas'));
        $idea = Thought::where('user_id', $user->id)->where('metadata->type', 'idea')->first();
        $this->assertNotNull($idea);
        $run = ResearchRun::where('idea_thought_id', $idea->id)->first();
        $this->assertNotNull($run);
        $this->assertSame('queued', $run->status);
        Queue::assertPushed(RunResearchRun::class);
    }

    public function test_save_idea_does_not_queue_when_auto_run_on_but_default_not_auto_eligible(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        UserPreference::set($user, UserPreference::KEY_RESEARCH_AUTO_RUN_ENABLED, true);
        $skill = ResearchSkill::factory()->create([
            'user_id' => $user->id,
            'is_default' => true,
            'allow_auto_run' => false,
            'is_manual_enabled' => true,
            'is_active' => true,
        ]);
        ResearchSkillVersion::factory()->create(['research_skill_id' => $skill->id]);
        $fakeEmbedding = array_fill(0, 1536, 0.01);
        $this->mock(OpenRouterService::class, function ($mock) use ($fakeEmbedding): void {
            $mock->shouldReceive('embed')->once()->andReturn($fakeEmbedding);
            $mock->shouldReceive('extractMetadata')->once()->andReturn(['tags' => []]);
        });

        $this->actingAs($user)->post(route('ideas.store'), [
            'content' => 'No queue without allow_auto_run',
            '_token' => csrf_token(),
        ]);

        $idea = Thought::where('user_id', $user->id)->where('metadata->type', 'idea')->first();
        $this->assertNotNull($idea);
        $this->assertSame(0, ResearchRun::where('idea_thought_id', $idea->id)->count());
        Queue::assertNotPushed(RunResearchRun::class);
    }

    public function test_ideas_page_shows_skill_name_for_queued_research_run(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $idea = Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Visible queued idea',
            'metadata' => ['type' => 'idea', 'completed' => false, 'logged_date' => '2025-04-01'],
            'embedding' => null,
        ]);
        $skill = ResearchSkill::factory()->create([
            'user_id' => $user->id,
            'name' => 'Stream skill label',
        ]);
        ResearchSkillVersion::factory()->create(['research_skill_id' => $skill->id]);
        ResearchRun::factory()->create([
            'user_id' => $user->id,
            'idea_thought_id' => $idea->id,
            'research_skill_id' => $skill->id,
            'research_skill_version_id' => $skill->fresh()->latestVersion->id,
            'status' => 'queued',
        ]);

        $response = $this->actingAs($user)->get(route('idea.ideas'));

        $response->assertOk();
        $response->assertSee('Queued (Stream skill label)…', false);
    }

    public function test_ideas_page_save_plus_research_button_label(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('idea.ideas'));

        $response->assertOk();
        $response->assertSee('Save + research', false);
    }

    public function test_ideas_page_lists_only_manual_enabled_active_skills_for_save_plus_research(): void
    {
        $user = User::factory()->create();
        $manualEnabled = ResearchSkill::factory()->create([
            'user_id' => $user->id,
            'name' => 'Manual enabled',
            'is_manual_enabled' => true,
            'is_active' => true,
        ]);
        ResearchSkillVersion::factory()->create(['research_skill_id' => $manualEnabled->id]);

        $inactive = ResearchSkill::factory()->create([
            'user_id' => $user->id,
            'name' => 'Inactive skill',
            'is_manual_enabled' => true,
            'is_active' => false,
        ]);
        ResearchSkillVersion::factory()->create(['research_skill_id' => $inactive->id]);

        $manualDisabled = ResearchSkill::factory()->create([
            'user_id' => $user->id,
            'name' => 'Manual disabled',
            'is_manual_enabled' => false,
            'is_active' => true,
        ]);
        ResearchSkillVersion::factory()->create(['research_skill_id' => $manualDisabled->id]);

        $otherUserSkill = ResearchSkill::factory()->create([
            'user_id' => User::factory()->create()->id,
            'name' => 'Other user skill',
            'is_manual_enabled' => true,
            'is_active' => true,
        ]);
        ResearchSkillVersion::factory()->create(['research_skill_id' => $otherUserSkill->id]);

        $response = $this->actingAs($user)->get(route('idea.ideas'));

        $response->assertOk();
        $response->assertSee('name="research_skill_id"', false);
        $response->assertSee('Manual enabled', false);
        $response->assertDontSee('Inactive skill', false);
        $response->assertDontSee('Manual disabled', false);
        $response->assertDontSee('Other user skill', false);
    }

    public function test_save_plus_research_queues_the_selected_manual_skill(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $selectedSkill = ResearchSkill::factory()->create([
            'user_id' => $user->id,
            'name' => 'Selected skill',
            'is_manual_enabled' => true,
            'is_active' => true,
        ]);
        ResearchSkillVersion::factory()->create(['research_skill_id' => $selectedSkill->id]);

        $fakeEmbedding = array_fill(0, 1536, 0.01);
        $this->mock(OpenRouterService::class, function ($mock) use ($fakeEmbedding): void {
            $mock->shouldReceive('embed')->once()->andReturn($fakeEmbedding);
            $mock->shouldReceive('extractMetadata')->once()->andReturn(['tags' => []]);
        });

        $response = $this->actingAs($user)->post(route('ideas.research-new'), [
            'content' => 'Research with selected skill',
            'research_skill_id' => $selectedSkill->id,
            '_token' => csrf_token(),
        ]);

        $response->assertRedirect(route('idea.ideas'));
        $idea = Thought::where('user_id', $user->id)->where('metadata->type', 'idea')->first();
        $this->assertNotNull($idea);
        $this->assertDatabaseHas('research_runs', [
            'idea_thought_id' => $idea->id,
            'research_skill_id' => $selectedSkill->id,
            'research_skill_version_id' => $selectedSkill->fresh()->latestVersion->id,
        ]);
    }

    public function test_save_plus_research_rejects_skill_selection_from_another_user(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $otherSkill = ResearchSkill::factory()->create([
            'user_id' => User::factory()->create()->id,
            'is_manual_enabled' => true,
            'is_active' => true,
        ]);
        ResearchSkillVersion::factory()->create(['research_skill_id' => $otherSkill->id]);

        $response = $this->actingAs($user)
            ->from(route('idea.ideas'))
            ->post(route('ideas.research-new'), [
                'content' => 'Invalid skill selection',
                'research_skill_id' => $otherSkill->id,
                '_token' => csrf_token(),
            ]);

        $response->assertRedirect(route('idea.ideas'));
        $response->assertSessionHasErrors('research_skill_id');
        $this->assertSame(0, Thought::where('user_id', $user->id)->where('metadata->type', 'idea')->count());
        $this->assertSame(0, ResearchRun::query()->count());
        Queue::assertNothingPushed();
    }
}
