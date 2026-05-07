<?php

namespace Tests\Feature;

use App\Jobs\BuildScopeDigestJob;
use App\Models\Thought;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class BuildWeeklyDigestsCommandTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_dispatches_a_digest_job_per_active_scope(): void
    {
        Queue::fake();
        Carbon::setTestNow(Carbon::parse('2026-05-07T10:00:00Z'));

        $user = User::factory()->create();
        Thought::factory()->count(3)->create([
            'user_id' => $user->id,
            'metadata' => ['type' => 'idea', 'tags' => ['project:dezeen']],
            'source_metadata' => ['project' => 'dezeen'],
            'created_at' => Carbon::parse('2026-05-04T10:00:00Z'),
        ]);

        $exit = $this->artisan('compactions:digest')->run();

        $this->assertSame(0, $exit);

        // The fixture's three thoughts each resolve to the same three scopes
        // (project/dezeen, tag/project:dezeen, global/global). After dedup
        // the command should push exactly one job per unique tuple.
        Queue::assertPushed(BuildScopeDigestJob::class, 3);

        Queue::assertPushed(BuildScopeDigestJob::class, function (BuildScopeDigestJob $job) use ($user): bool {
            return $job->userId === $user->id
                && $job->scopeType === 'project'
                && $job->scopeKey === 'dezeen';
        });
    }

    #[Test]
    public function it_skips_when_no_active_scope(): void
    {
        Queue::fake();
        User::factory()->create();

        $exit = $this->artisan('compactions:digest')->run();

        $this->assertSame(0, $exit);
        Queue::assertNotPushed(BuildScopeDigestJob::class);
    }

    #[Test]
    public function it_dispatches_distinct_jobs_per_user_in_the_same_scope(): void
    {
        Queue::fake();
        Carbon::setTestNow(Carbon::parse('2026-05-07T10:00:00Z'));

        $userA = User::factory()->create();
        $userB = User::factory()->create();

        foreach ([$userA, $userB] as $user) {
            Thought::factory()->create([
                'user_id' => $user->id,
                'metadata' => ['type' => 'idea', 'tags' => ['project:dezeen']],
                'source_metadata' => ['project' => 'dezeen'],
                'created_at' => Carbon::parse('2026-05-04T10:00:00Z'),
            ]);
        }

        $exit = $this->artisan('compactions:digest')->run();

        $this->assertSame(0, $exit);

        Queue::assertPushed(BuildScopeDigestJob::class, function (BuildScopeDigestJob $job) use ($userA): bool {
            return $job->userId === $userA->id
                && $job->scopeType === 'project'
                && $job->scopeKey === 'dezeen';
        });

        Queue::assertPushed(BuildScopeDigestJob::class, function (BuildScopeDigestJob $job) use ($userB): bool {
            return $job->userId === $userB->id
                && $job->scopeType === 'project'
                && $job->scopeKey === 'dezeen';
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }
}
