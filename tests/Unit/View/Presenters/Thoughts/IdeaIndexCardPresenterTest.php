<?php

namespace Tests\Unit\View\Presenters\Thoughts;

use App\Models\Thought;
use App\Models\User;
use App\Services\DemoMode;
use App\View\Presenters\MissingPresenterData;
use App\View\Presenters\Thoughts\IdeaIndexCardPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class IdeaIndexCardPresenterTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_throws_when_comments_relation_is_not_loaded(): void
    {
        $thought = Thought::factory()->create();

        $this->expectException(MissingPresenterData::class);
        $this->expectExceptionMessage('childThoughts');

        IdeaIndexCardPresenter::fromThought($thought, 0);
    }

    #[Test]
    public function it_throws_when_parent_id_is_set_but_parent_relation_is_not_loaded(): void
    {
        $parent = Thought::factory()->create();
        $child = Thought::factory()->create([
            'user_id' => $parent->user_id,
            'parent_id' => $parent->id,
        ]);
        $child->setRelation('childThoughts', collect());

        $this->expectException(MissingPresenterData::class);
        $this->expectExceptionMessage('parent');

        IdeaIndexCardPresenter::fromThought($child, -1);
    }

    #[Test]
    public function it_builds_reply_state_for_top_level_thoughts(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $thought = Thought::factory()->create([
            'user_id' => $user->id,
            'parent_id' => null,
            'content' => 'Root',
        ]);
        $thought->setRelation('childThoughts', collect());

        $card = IdeaIndexCardPresenter::fromThought($thought, 2);

        $this->assertSame(2, $card->currentReplyableIndex());
        $this->assertSame(route('idea.index', ['parent_id' => $thought->id]), $card->replyHref());
        $this->assertTrue($card->showReplyLink());
        $this->assertTrue($card->previewMode());
    }

    #[Test]
    public function it_builds_reply_state_for_child_thoughts(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $parent = Thought::factory()->create(['user_id' => $user->id, 'content' => 'Parent body text']);
        $child = Thought::factory()->create([
            'user_id' => $user->id,
            'parent_id' => $parent->id,
            'content' => 'Child',
        ]);
        $child->load('parent');
        $child->setRelation('childThoughts', collect());

        $card = IdeaIndexCardPresenter::fromThought($child, -1);

        $this->assertSame(-1, $card->currentReplyableIndex());
        $this->assertSame('', $card->replyHref());
        $this->assertFalse($card->showReplyLink());
        $this->assertFalse($card->previewMode());
        $this->assertTrue($card->showParentPreview());
        $this->assertStringContainsString('Parent', $card->displayParentPreviewExcerpt() ?? '');
    }

    #[Test]
    public function it_exposes_video_latest_research_url_when_presenter_is_given_one(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $thought = Thought::factory()->create([
            'user_id' => $user->id,
            'parent_id' => null,
            'metadata' => [
                'type' => 'video',
                'video_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
                'transcript_status' => 'available',
                'transcript_source' => 'youtube',
            ],
        ]);
        $thought->setRelation('childThoughts', collect());

        $card = IdeaIndexCardPresenter::fromThought($thought, 0, null, 'https://example.org/research-page');

        $this->assertTrue($card->isVideoThought());
        $this->assertSame('https://example.org/research-page', $card->videoLatestResearchUrl());
        $this->assertSame('Transcript available', $card->transcriptStatusLabel());
    }

    #[Test]
    public function it_obfuscates_display_fields_in_demo_mode_and_disables_editing(): void
    {
        config(['services.demo_mode.enabled' => true]);
        session([
            DemoMode::ENABLED_SESSION_KEY => true,
            DemoMode::SEED_SESSION_KEY => 'unit-seed-idea-index-card',
        ]);

        $user = User::factory()->create();
        $this->actingAs($user);

        $parent = Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'IDEATUB_DEMO_PARENT_MARKER_IDX',
        ]);
        $child = Thought::factory()->create([
            'user_id' => $user->id,
            'parent_id' => $parent->id,
            'content' => 'IDEATUB_DEMO_BODY_MARKER_IDX',
        ]);
        $child->load('parent');
        $comment = Thought::factory()->create([
            'user_id' => $user->id,
            'parent_id' => $child->id,
            'content' => 'IDEATUB_DEMO_COMMENT_MARKER_IDX_LONG_TEXT_'.str_repeat('x', 300),
        ]);
        $child->setRelation('childThoughts', collect([$comment]));

        $card = IdeaIndexCardPresenter::fromThought($child, -1);

        $this->assertFalse($card->editable());
        $this->assertStringNotContainsString('IDEATUB_DEMO_BODY_MARKER_IDX', $card->displayContent());
        $this->assertStringNotContainsString('IDEATUB_DEMO_PARENT_MARKER_IDX', $card->displayParentPreviewExcerpt() ?? '');
        $rows = $card->commentPreviewRows();
        $this->assertCount(1, $rows);
        $this->assertStringNotContainsString('IDEATUB_DEMO_COMMENT_MARKER_IDX', $rows[0]['content']);

        session()->forget([DemoMode::ENABLED_SESSION_KEY, DemoMode::SEED_SESSION_KEY]);

        $cardNormal = IdeaIndexCardPresenter::fromThought($child->fresh(['parent', 'childThoughts']), -1);
        $this->assertTrue($cardNormal->editable());
        $this->assertSame('IDEATUB_DEMO_BODY_MARKER_IDX', $cardNormal->displayContent());
        $this->assertStringContainsString('IDEATUB_DEMO_PARENT_MARKER_IDX', $cardNormal->displayParentPreviewExcerpt() ?? '');
        $rowsNormal = $cardNormal->commentPreviewRows();
        $this->assertStringContainsString('IDEATUB_DEMO_COMMENT_MARKER_IDX', $rowsNormal[0]['content']);
    }
}
