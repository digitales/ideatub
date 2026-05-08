<?php

namespace Tests\Feature;

use App\Jobs\BuildTopicDigestJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class BuildTopicDigestCommandTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_dispatches_a_topic_digest_job_for_the_resolved_user(): void
    {
        Queue::fake();

        $user = User::factory()->create();

        $exit = $this->artisan('compactions:topic-digest', [
            'scope_type' => 'project',
            'scope_key' => 'dezeen',
            'topic' => 'pricing',
            '--user' => (string) $user->id,
        ])->run();

        $this->assertSame(0, $exit);

        Queue::assertPushed(BuildTopicDigestJob::class, fn (BuildTopicDigestJob $job) => $job->userId === $user->id
            && $job->scopeType === 'project'
            && $job->scopeKey === 'dezeen'
            && $job->topic === 'pricing');
    }

    #[Test]
    public function it_fails_with_unknown_scope_type(): void
    {
        Queue::fake();

        $user = User::factory()->create();

        $exit = $this->artisan('compactions:topic-digest', [
            'scope_type' => 'bogus',
            'scope_key' => 'whatever',
            'topic' => 'pricing',
            '--user' => (string) $user->id,
        ])->run();

        $this->assertSame(1, $exit);
        Queue::assertNothingPushed();
    }

    #[Test]
    public function it_fails_with_non_numeric_user_option(): void
    {
        Queue::fake();

        $exit = $this->artisan('compactions:topic-digest', [
            'scope_type' => 'project',
            'scope_key' => 'dezeen',
            'topic' => 'pricing',
            '--user' => 'not-a-number',
        ])->run();

        $this->assertSame(1, $exit);
        Queue::assertNothingPushed();
    }

    #[Test]
    public function it_falls_back_to_first_user_when_user_option_omitted(): void
    {
        Queue::fake();

        $earliest = User::factory()->create();
        User::factory()->count(2)->create();

        $exit = $this->artisan('compactions:topic-digest', [
            'scope_type' => 'project',
            'scope_key' => 'dezeen',
            'topic' => 'pricing',
        ])->run();

        $this->assertSame(0, $exit);

        Queue::assertPushed(BuildTopicDigestJob::class, fn (BuildTopicDigestJob $job) => $job->userId === $earliest->id);
    }

    #[Test]
    public function it_fails_when_no_users_exist_and_user_option_omitted(): void
    {
        Queue::fake();

        $exit = $this->artisan('compactions:topic-digest', [
            'scope_type' => 'project',
            'scope_key' => 'dezeen',
            'topic' => 'pricing',
        ])->run();

        $this->assertSame(1, $exit);
        Queue::assertNothingPushed();
    }

    #[Test]
    public function it_fails_when_user_id_does_not_exist(): void
    {
        Queue::fake();

        $exit = $this->artisan('compactions:topic-digest', [
            'scope_type' => 'project',
            'scope_key' => 'dezeen',
            'topic' => 'pricing',
            '--user' => '999999',
        ])->run();

        $this->assertSame(1, $exit);
        Queue::assertNothingPushed();
    }

    #[Test]
    public function it_fails_with_empty_scope_key(): void
    {
        Queue::fake();

        $user = User::factory()->create();

        $exit = $this->artisan('compactions:topic-digest', [
            'scope_type' => 'project',
            'scope_key' => '',
            'topic' => 'pricing',
            '--user' => (string) $user->id,
        ])->run();

        $this->assertSame(1, $exit);
        Queue::assertNothingPushed();
    }

    #[Test]
    public function it_trims_scope_key_before_dispatch(): void
    {
        Queue::fake();

        $user = User::factory()->create();

        $exit = $this->artisan('compactions:topic-digest', [
            'scope_type' => 'project',
            'scope_key' => '  dezeen  ',
            'topic' => 'pricing',
            '--user' => (string) $user->id,
        ])->run();

        $this->assertSame(0, $exit);

        Queue::assertPushed(BuildTopicDigestJob::class, fn (BuildTopicDigestJob $job) => $job->scopeKey === 'dezeen');
    }

    #[Test]
    public function it_dispatches_under_tag_scope(): void
    {
        Queue::fake();

        $user = User::factory()->create();

        $exit = $this->artisan('compactions:topic-digest', [
            'scope_type' => 'tag',
            'scope_key' => 'design-sync',
            'topic' => 'pricing',
            '--user' => (string) $user->id,
        ])->run();

        $this->assertSame(0, $exit);

        Queue::assertPushed(BuildTopicDigestJob::class, fn (BuildTopicDigestJob $job) => $job->userId === $user->id
            && $job->scopeType === 'tag'
            && $job->scopeKey === 'design-sync'
            && $job->topic === 'pricing');
    }

    #[Test]
    public function it_fails_with_invalid_scope_key_for_global_scope(): void
    {
        Queue::fake();

        $user = User::factory()->create();

        $exit = $this->artisan('compactions:topic-digest', [
            'scope_type' => 'global',
            'scope_key' => 'not-global',
            'topic' => 'pricing',
            '--user' => (string) $user->id,
        ])->run();

        $this->assertSame(1, $exit);
        Queue::assertNothingPushed();
    }
}
