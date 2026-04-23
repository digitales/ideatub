<?php

namespace Tests\Unit;

use App\Models\Comment;
use App\Models\Thought;
use App\Models\ThoughtCommentRead;
use App\Models\User;
use App\Support\Comments\ShareContext;
use App\View\Presenters\Comments\ResearchCommentsPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResearchCommentsPresenterTest extends TestCase
{
    use RefreshDatabase;

    public function test_splits_page_level_and_section_level_rows(): void
    {
        $user = User::factory()->create();
        $root = Thought::factory()->create(['user_id' => $user->id]);
        $section = Thought::factory()->create([
            'user_id' => $user->id,
            'parent_id' => $root->id,
            'source_metadata' => ['section_index' => 1],
        ]);
        Comment::create([
            'commentable_type' => 'thought',
            'commentable_id' => $root->id,
            'author_user_id' => $user->id,
            'content' => 'page-level',
            'format' => 'markdown',
        ]);
        Comment::create([
            'commentable_type' => 'thought',
            'commentable_id' => $section->id,
            'author_user_id' => $user->id,
            'content' => 'section-level',
            'format' => 'markdown',
        ]);

        $presenter = new ResearchCommentsPresenter($root, $user, null);

        $this->assertCount(1, $presenter->pageLevelRows());
        $this->assertCount(1, $presenter->sectionRowsFor($section));
        $this->assertStringContainsString('page-level', $presenter->pageLevelRows()[0]['content_html']);
    }

    public function test_unread_count_excludes_current_user_comments(): void
    {
        $owner = User::factory()->create();
        $root = Thought::factory()->create(['user_id' => $owner->id]);

        Comment::create([
            'commentable_type' => 'thought',
            'commentable_id' => $root->id,
            'author_user_id' => $owner->id,
            'content' => 'self',
            'format' => 'markdown',
        ]);
        Comment::create([
            'commentable_type' => 'thought',
            'commentable_id' => $root->id,
            'author_user_id' => null,
            'author_name' => 'Guest',
            'content' => 'guest',
            'format' => 'plain',
        ]);

        $presenter = new ResearchCommentsPresenter($root, $owner, null);

        $this->assertSame(1, $presenter->unreadCount());

        ThoughtCommentRead::markRead($owner->id, $root->id);
        $presenter = new ResearchCommentsPresenter($root->fresh(), $owner->fresh(), null);
        $this->assertSame(0, $presenter->unreadCount());
    }

    public function test_allow_guest_comments_reflects_share_context(): void
    {
        $owner = User::factory()->create();
        $root = Thought::factory()->create(['user_id' => $owner->id]);

        $ctxOn = new ShareContext($root->id, 1, true);
        $ctxOff = new ShareContext($root->id, 1, false);

        $this->assertTrue((new ResearchCommentsPresenter($root, null, $ctxOn))->allowGuestComments());
        $this->assertFalse((new ResearchCommentsPresenter($root, null, $ctxOff))->allowGuestComments());
    }

    public function test_thread_include_for_section_matches_manual_include_keys(): void
    {
        $user = User::factory()->create();
        $root = Thought::factory()->create(['user_id' => $user->id]);
        $section = Thought::factory()->create([
            'user_id' => $user->id,
            'parent_id' => $root->id,
        ]);
        $presenter = new ResearchCommentsPresenter($root, $user, null);

        $props = $presenter->threadIncludeForSection(
            $section,
            formAction: 'https://example.test/comments',
            mode: 'owner',
            showControls: true,
            title: 'Section comments',
        );

        $this->assertSame('https://example.test/comments', $props['formAction']);
        $this->assertSame('thought', $props['commentableType']);
        $this->assertSame((string) $section->id, $props['commentableId']);
        $this->assertSame('owner', $props['mode']);
        $this->assertSame('Section comments', $props['title']);
        $this->assertTrue($props['showControls']);
        $this->assertNull($props['disabledMessage']);
        $this->assertIsArray($props['rows']);
    }
}
