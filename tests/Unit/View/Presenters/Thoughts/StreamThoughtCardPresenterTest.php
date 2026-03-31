<?php

namespace Tests\Unit\View\Presenters\Thoughts;

use App\Models\Thought;
use App\Models\User;
use App\Services\DemoMode;
use App\View\Presenters\MissingPresenterData;
use App\View\Presenters\Thoughts\StreamThoughtCardPresenter;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class StreamThoughtCardPresenterTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_throws_when_comments_relation_is_not_loaded(): void
    {
        $thought = Thought::factory()->create();

        $this->expectException(MissingPresenterData::class);
        $this->expectExceptionMessage('comments');

        StreamThoughtCardPresenter::fromThought($thought, null, false);
    }

    #[Test]
    public function it_uses_jira_updated_at_for_activity_when_present(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-31 12:00:00', 'UTC'));
        try {
            $user = User::factory()->create();
            $this->actingAs($user);

            $thought = Thought::factory()->create([
                'user_id' => $user->id,
                'source' => 'jira',
                'source_metadata' => [
                    'jira_updated_at' => '2026-03-20T12:00:00.000+0000',
                ],
            ]);
            $thought->setRelation('comments', collect());

            $card = StreamThoughtCardPresenter::fromThought($thought, null, false);

            $expected = Carbon::parse('2026-03-20T12:00:00.000+0000')->diffForHumans();
            $this->assertSame($expected, $card->activityAtHuman());
        } finally {
            Carbon::setTestNow();
        }
    }

    #[Test]
    public function it_falls_back_to_created_at_when_jira_timestamp_is_invalid(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $thought = Thought::factory()->create([
            'user_id' => $user->id,
            'source' => 'jira',
            'source_metadata' => [
                'jira_updated_at' => 'not-a-date',
            ],
        ]);
        $thought->setRelation('comments', collect());

        $card = StreamThoughtCardPresenter::fromThought($thought, null, false);

        $this->assertSame($thought->created_at->diffForHumans(), $card->activityAtHuman());
    }

    #[Test]
    public function it_obfuscates_display_fields_in_demo_mode_and_disables_editing(): void
    {
        config(['services.demo_mode.enabled' => true]);
        session([
            DemoMode::ENABLED_SESSION_KEY => true,
            DemoMode::SEED_SESSION_KEY => 'unit-seed-stream-card',
        ]);

        $user = User::factory()->create();
        $this->actingAs($user);

        $root = Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'IDEATUB_DEMO_STREAM_BODY_MARKER',
        ]);
        $reply = Thought::factory()->create([
            'user_id' => $user->id,
            'parent_id' => $root->id,
            'content' => 'IDEATUB_DEMO_STREAM_COMMENT_MARKER_LONG_'.str_repeat('y', 300),
        ]);
        $root->setRelation('comments', collect([$reply]));

        $card = StreamThoughtCardPresenter::fromThought($root, null, false);

        $this->assertFalse($card->editable());
        $this->assertStringNotContainsString('IDEATUB_DEMO_STREAM_BODY_MARKER', $card->displayContent());
        $rows = $card->commentPreviewRows();
        $this->assertCount(1, $rows);
        $this->assertStringNotContainsString('IDEATUB_DEMO_STREAM_COMMENT_MARKER', $rows[0]['content']);

        session()->forget([DemoMode::ENABLED_SESSION_KEY, DemoMode::SEED_SESSION_KEY]);

        $rootFresh = $root->fresh();
        $rootFresh->setRelation('comments', collect([$reply->fresh()]));
        $cardNormal = StreamThoughtCardPresenter::fromThought($rootFresh, null, false);
        $this->assertTrue($cardNormal->editable());
        $this->assertSame('IDEATUB_DEMO_STREAM_BODY_MARKER', $cardNormal->displayContent());
        $this->assertStringContainsString('IDEATUB_DEMO_STREAM_COMMENT_MARKER', $cardNormal->commentPreviewRows()[0]['content']);
    }
}
