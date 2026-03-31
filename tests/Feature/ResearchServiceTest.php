<?php

namespace Tests\Feature;

use App\Jobs\RunResearchRun;
use App\Models\ImportedEmail;
use App\Models\MailAccount;
use App\Models\ResearchRun;
use App\Models\Thought;
use App\Models\User;
use App\Services\OpenRouterService;
use App\Services\Research\ResearchSkillManager;
use App\Services\ResearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class ResearchServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_run_research_for_existing_idea_creates_linked_research_thought(): void
    {
        $user = User::factory()->create();
        $idea = Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Build a small SaaS for vehicle analytics',
            'metadata' => ['type' => 'idea', 'completed' => false, 'logged_date' => now()->toDateString()],
            'embedding' => null,
        ]);

        $researchText = 'Key considerations: market size, MVP scope. Next steps: validate with users, pick stack.';
        $this->mock(OpenRouterService::class, function ($mock) use ($researchText): void {
            $mock->shouldReceive('researchNote')
                ->once()
                ->with('Build a small SaaS for vehicle analytics')
                ->andReturn($researchText);
        });

        $service = app(ResearchService::class);
        $research = $service->runResearchForIdea($idea, 'web');

        $this->assertInstanceOf(Thought::class, $research);
        $this->assertSame($researchText, $research->content);
        $this->assertSame('research', $research->metadata['type']);
        $this->assertSame($idea->id, $research->metadata['idea_id']);
        $this->assertSame(['research'], $research->metadata['tags'] ?? []);
        $this->assertSame((string) $idea->user_id, (string) $research->user_id);
        $this->assertSame('web', $research->source);
        $this->assertNull($research->embedding);

        $found = Thought::researchForIdea($idea->id)->first();
        $this->assertNotNull($found);
        $this->assertSame($research->id, $found->id);
    }

    public function test_create_idea_and_research_creates_idea_then_linked_research(): void
    {
        $user = User::factory()->create();
        $fakeEmbedding = array_fill(0, 1536, 0.01);
        $researchText = 'Research note: validate demand, then build MVP.';
        $this->mock(OpenRouterService::class, function ($mock) use ($fakeEmbedding, $researchText): void {
            $mock->shouldReceive('embed')->once()->andReturn($fakeEmbedding);
            $mock->shouldReceive('extractMetadata')->once()->andReturn(['tags' => []]);
            $mock->shouldReceive('researchNote')
                ->once()
                ->with('Ship a side project this quarter')
                ->andReturn($researchText);
        });

        $this->actingAs($user);
        $service = app(ResearchService::class);
        $result = $service->createIdeaAndResearch('Ship a side project this quarter', 'web');

        $this->assertArrayHasKey('idea', $result);
        $this->assertArrayHasKey('research', $result);
        $idea = $result['idea'];
        $research = $result['research'];

        $this->assertInstanceOf(Thought::class, $idea);
        $this->assertSame('idea', $idea->metadata['type']);
        $this->assertSame('Ship a side project this quarter', $idea->getDecodedContent());

        $this->assertInstanceOf(Thought::class, $research);
        $this->assertSame('research', $research->metadata['type']);
        $this->assertSame($idea->id, $research->metadata['idea_id']);
        $this->assertSame(['research'], $research->metadata['tags'] ?? []);
        $this->assertSame($researchText, $research->content);
    }

    public function test_create_idea_only_creates_idea_without_research(): void
    {
        $user = User::factory()->create();
        $fakeEmbedding = array_fill(0, 1536, 0.01);
        $this->mock(OpenRouterService::class, function ($mock) use ($fakeEmbedding): void {
            $mock->shouldReceive('embed')->once()->andReturn($fakeEmbedding);
            $mock->shouldReceive('extractMetadata')->once()->andReturn(['tags' => []]);
            $mock->shouldNotReceive('researchNote');
        });

        $this->actingAs($user);
        $service = app(ResearchService::class);
        $idea = $service->createIdeaOnly('An idea without research', 'web');

        $this->assertInstanceOf(Thought::class, $idea);
        $this->assertSame('idea', $idea->metadata['type']);
        $this->assertSame('An idea without research', $idea->getDecodedContent());

        $this->assertSame(0, Thought::where('metadata->type', 'research')->count());
    }

    public function test_run_research_for_email_thought_creates_research_source_and_persists_email_linkage(): void
    {
        $user = User::factory()->create();
        $emailThought = Thought::factory()->create([
            'user_id' => $user->id,
            'source' => 'email',
            'content' => 'Ross Tweedie: Your GoDaddy Renewal Notice',
            'metadata' => ['type' => 'note', 'tags' => ['godaddy']],
            'source_metadata' => [
                'subject' => 'Ross Tweedie: Your GoDaddy Renewal Notice',
                'from' => 'GoDaddy Renewals <renewals@godaddy.com>',
            ],
            'embedding' => null,
        ]);
        $account = MailAccount::factory()->create(['user_id' => $user->id]);
        $storedEmail = ImportedEmail::query()->create([
            'user_id' => $user->id,
            'mail_account_id' => $account->id,
            'provider' => 'fastmail',
            'provider_message_id' => 'email-research-linkage-msg',
            'direction' => 'received',
            'subject' => 'Ross Tweedie: Your GoDaddy Renewal Notice',
            'from_json' => [['email' => 'renewals@godaddy.com', 'name' => 'GoDaddy Renewals']],
            'processing_status' => 'imported',
            'thought_id' => $emailThought->id,
            'research_thought_id' => null,
        ]);

        $researchText = '### Research Brief: Ross Tweedie: Your GoDaddy Renewal Notice';
        $this->mock(OpenRouterService::class, function ($mock) use ($researchText): void {
            $mock->shouldReceive('researchNote')
                ->once()
                ->with('Ross Tweedie: Your GoDaddy Renewal Notice')
                ->andReturn($researchText);
        });

        $service = app(ResearchService::class);
        $research = $service->runResearchForIdea($emailThought, 'email');

        $this->assertSame('research', $research->source);
        $this->assertSame($emailThought->id, $research->metadata['idea_id']);
        $this->assertSame($emailThought->id, $research->metadata['email_thought_id'] ?? null);
        $this->assertSame('Ross Tweedie: Your GoDaddy Renewal Notice', $research->metadata['email_subject'] ?? null);
        $this->assertSame('GoDaddy Renewals <renewals@godaddy.com>', $research->metadata['email_sender'] ?? null);
        $this->assertSame($emailThought->id, $research->source_metadata['email_thought_id'] ?? null);

        $emailThought->refresh();
        $storedEmail->refresh();
        $this->assertSame($research->id, data_get($emailThought->source_metadata, 'research_thought_id'));
        $this->assertSame($research->id, $storedEmail->research_thought_id);
    }

    public function test_create_idea_and_research_when_research_fails_keeps_idea_returns_null_research(): void
    {
        $user = User::factory()->create();
        $fakeEmbedding = array_fill(0, 1536, 0.01);
        $this->mock(OpenRouterService::class, function ($mock) use ($fakeEmbedding): void {
            $mock->shouldReceive('embed')->once()->andReturn($fakeEmbedding);
            $mock->shouldReceive('extractMetadata')->once()->andReturn(['tags' => []]);
            $mock->shouldReceive('researchNote')
                ->once()
                ->andThrow(new \RuntimeException('API error'));
        });

        $this->actingAs($user);
        $service = app(ResearchService::class);
        $result = $service->createIdeaAndResearch('An idea that research will fail for', 'mcp');

        $this->assertArrayHasKey('idea', $result);
        $this->assertArrayHasKey('research', $result);
        $this->assertInstanceOf(Thought::class, $result['idea']);
        $this->assertSame('idea', $result['idea']->metadata['type']);
        $this->assertNull($result['research']);

        $researchCount = Thought::where('metadata->type', 'research')->count();
        $this->assertSame(0, $researchCount);
    }

    public function test_create_idea_and_queue_research_run_creates_queued_run_without_calling_research_note(): void
    {
        Bus::fake();
        $user = User::factory()->create();
        $fakeEmbedding = array_fill(0, 1536, 0.01);
        $this->mock(OpenRouterService::class, function ($mock) use ($fakeEmbedding): void {
            $mock->shouldReceive('embed')->once()->andReturn($fakeEmbedding);
            $mock->shouldReceive('extractMetadata')->once()->andReturn(['tags' => []]);
            $mock->shouldNotReceive('researchNote');
        });

        $this->actingAs($user);
        $service = app(ResearchService::class);
        $result = $service->createIdeaAndQueueResearchRun('Queued from service test', 'mcp');

        $this->assertArrayHasKey('idea', $result);
        $this->assertArrayHasKey('run', $result);
        $idea = $result['idea'];
        $run = $result['run'];

        $this->assertInstanceOf(Thought::class, $idea);
        $this->assertSame('idea', $idea->metadata['type']);
        $this->assertInstanceOf(ResearchRun::class, $run);
        $this->assertSame($idea->id, $run->idea_thought_id);
        $this->assertSame('mcp', $run->source);
        $this->assertSame('queued', $run->status);

        $this->assertSame(0, Thought::where('metadata->type', 'research')->count());

        Bus::assertDispatched(RunResearchRun::class, fn (RunResearchRun $job) => $job->researchRunId === $run->id);
    }

    public function test_queue_research_run_for_idea_second_call_reuses_active_run(): void
    {
        Bus::fake();
        $user = User::factory()->create();
        $idea = Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Idea for duplicate run guard',
            'metadata' => ['type' => 'idea', 'completed' => false, 'logged_date' => now()->toDateString()],
            'embedding' => null,
        ]);

        $this->actingAs($user);
        $service = app(ResearchService::class);
        $first = $service->queueResearchRunForIdea($idea, 'web');
        $second = $service->queueResearchRunForIdea($idea, 'web');

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, ResearchRun::query()->where('idea_thought_id', $idea->id)->count());
        Bus::assertDispatchedTimes(RunResearchRun::class, 1);
    }

    public function test_queue_research_run_for_idea_respects_active_run_limit(): void
    {
        Bus::fake();
        config(['research.max_active_runs_per_user' => 2]);

        $user = User::factory()->create();
        $skill = app(ResearchSkillManager::class)->create($user, [
            'name' => 'Default',
            'is_default' => true,
        ]);

        Thought::factory()->count(2)->create([
            'user_id' => $user->id,
            'metadata' => ['type' => 'idea', 'completed' => false, 'logged_date' => now()->toDateString()],
            'embedding' => null,
        ])->each(function (Thought $idea) use ($user, $skill): void {
            ResearchRun::factory()->create([
                'user_id' => $user->id,
                'idea_thought_id' => $idea->id,
                'research_skill_id' => $skill->id,
                'research_skill_version_id' => $skill->fresh()->latestVersion->id,
                'status' => 'queued',
            ]);
        });

        $newIdea = Thought::factory()->create([
            'user_id' => $user->id,
            'metadata' => ['type' => 'idea', 'completed' => false, 'logged_date' => now()->toDateString()],
            'embedding' => null,
        ]);

        $this->actingAs($user);
        $service = app(ResearchService::class);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Active research run limit reached');

        try {
            $service->queueResearchRunForIdea($newIdea, 'web');
        } finally {
            $this->assertSame(2, ResearchRun::query()->where('user_id', $user->id)->count());
            Bus::assertNothingDispatched();
        }
    }

    public function test_create_idea_only_requires_authenticated_user(): void
    {
        $this->mock(OpenRouterService::class, function ($mock): void {
            $mock->shouldNotReceive('embed');
            $mock->shouldNotReceive('extractMetadata');
            $mock->shouldNotReceive('researchNote');
        });

        $service = app(ResearchService::class);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Authenticated user is required to create an idea.');

        $service->createIdeaOnly('This should fail without a user', 'mcp');
    }
}
