<?php

namespace Tests\Unit\View\Presenters\Thoughts;

use App\Models\Thought;
use App\Models\User;
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
        $this->expectExceptionMessage('comments');

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
        $child->setRelation('comments', collect());

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
        $thought->setRelation('comments', collect());

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
        $child->setRelation('comments', collect());

        $card = IdeaIndexCardPresenter::fromThought($child, -1);

        $this->assertSame(-1, $card->currentReplyableIndex());
        $this->assertSame('', $card->replyHref());
        $this->assertFalse($card->showReplyLink());
        $this->assertFalse($card->previewMode());
        $this->assertTrue($card->showParentPreview());
        $this->assertStringContainsString('Parent', $card->parentPreviewExcerpt() ?? '');
    }
}
