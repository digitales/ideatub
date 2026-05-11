<?php

namespace Tests\Feature;

use App\Jobs\ClassifyArticleLinks;
use App\Jobs\ProcessThoughtLinkSummary;
use App\Jobs\RunResearchRun;
use App\Models\ResearchSkill;
use App\Models\ResearchSkillVersion;
use App\Models\Thought;
use App\Models\ThoughtLinkSummary;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ClassifyArticleLinksJobTest extends TestCase
{
    use RefreshDatabase;

    private function createUserWithResearchSkill(): User
    {
        $user = User::factory()->create();

        $skill = ResearchSkill::query()->create([
            'user_id' => $user->id,
            'name' => 'Default',
            'is_active' => true,
            'is_default' => true,
            'is_manual_enabled' => true,
        ]);

        ResearchSkillVersion::query()->create([
            'research_skill_id' => $skill->id,
            'version' => 1,
            'workflow_type' => 'quick_brief',
            'instructions' => 'Research this.',
            'context_options' => [],
            'output_shape' => [],
            'intensity' => 'medium',
        ]);

        return $user;
    }

    private function createRootArticleThought(User $user): Thought
    {
        return Thought::factory()->create([
            'user_id' => $user->id,
            'source' => 'article',
            'content' => 'Test Article',
            'source_metadata' => [
                'url' => 'https://example.com/article',
                'domain' => 'example.com',
                'status' => 'scraped',
            ],
        ]);
    }

    public function test_filters_out_social_and_nav_links(): void
    {
        Queue::fake();
        $user = $this->createUserWithResearchSkill();
        $root = $this->createRootArticleThought($user);

        $links = [
            ['url' => 'https://other-site.com/research', 'anchor_text' => 'Research'],
            ['url' => 'https://twitter.com/intent/tweet?text=hi', 'anchor_text' => 'Tweet'],
            ['url' => 'https://facebook.com/sharer/sharer.php', 'anchor_text' => 'Share'],
            ['url' => 'https://example.com/about', 'anchor_text' => 'About'],
            ['url' => 'https://example.com/article', 'anchor_text' => 'Self'],
            ['url' => 'https://example.com/image.jpg', 'anchor_text' => 'Image'],
        ];

        $job = new ClassifyArticleLinks($root->id, $links);
        app()->call([$job, 'handle']);

        $summaries = ThoughtLinkSummary::query()
            ->where('source_thought_id', $root->id)
            ->get();

        $this->assertCount(1, $summaries);
        $this->assertSame('https://other-site.com/research', $summaries->first()->original_url);

        Queue::assertPushed(ProcessThoughtLinkSummary::class, 1);
    }

    public function test_dispatches_research_run(): void
    {
        Queue::fake();
        $user = $this->createUserWithResearchSkill();
        $root = $this->createRootArticleThought($user);

        $job = new ClassifyArticleLinks($root->id, [
            ['url' => 'https://other-site.com/article', 'anchor_text' => 'Link'],
        ]);
        app()->call([$job, 'handle']);

        Queue::assertPushed(RunResearchRun::class);
    }

    public function test_updates_root_status_to_complete(): void
    {
        Queue::fake();
        $user = $this->createUserWithResearchSkill();
        $root = $this->createRootArticleThought($user);

        $job = new ClassifyArticleLinks($root->id, []);
        app()->call([$job, 'handle']);

        $root->refresh();
        $this->assertSame('complete', $root->source_metadata['status']);
    }

    public function test_handles_empty_links_gracefully(): void
    {
        Queue::fake();
        $user = $this->createUserWithResearchSkill();
        $root = $this->createRootArticleThought($user);

        $job = new ClassifyArticleLinks($root->id, []);
        app()->call([$job, 'handle']);

        $this->assertSame(0, ThoughtLinkSummary::query()->count());
        $root->refresh();
        $this->assertSame('complete', $root->source_metadata['status']);
    }
}
