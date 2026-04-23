<?php

namespace Tests\Unit\View\Research;

use App\Models\Thought;
use App\Models\User;
use App\View\Presenters\Comments\ResearchCommentsPresenter;
use App\View\Research\ResearchContentCommentsViewData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

class ResearchContentCommentsViewDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_none_has_no_comments(): void
    {
        $data = ResearchContentCommentsViewData::none();

        $this->assertFalse($data->hasComments);
        $this->assertSame('owner', $data->commentsMode);
        $this->assertNull($data->commentsFormAction);
        $this->assertTrue($data->commentsShowControls);
        $this->assertSame([], $data->sectionItems);
    }

    public function test_for_owner_builds_section_thread_include(): void
    {
        $user = User::factory()->create();
        $root = Thought::factory()->create(['user_id' => $user->id]);
        $sectionThought = Thought::factory()->create([
            'user_id' => $user->id,
            'parent_id' => $root->id,
        ]);

        $presenter = new ResearchCommentsPresenter($root, $user, null);

        $sections = new Collection([(object) [
            'id' => $sectionThought->id,
            'thought' => $sectionThought,
            'content_html' => '<p>Section</p>',
        ]]);

        $data = ResearchContentCommentsViewData::forOwner($presenter, $sections);

        $this->assertTrue($data->hasComments);
        $this->assertSame(route('comments.store'), $data->commentsFormAction);

        $this->assertCount(1, $data->sectionItems);
        $item = $data->sectionItems[0];
        $this->assertSame('<p>Section</p>', $item->contentHtml);
        $this->assertNotNull($item->mobileThreadInclude);
        $this->assertSame(route('comments.store'), $item->mobileThreadInclude['formAction']);
        $this->assertSame('Section comments', $item->mobileThreadInclude['title']);
        $this->assertSame('Comments', $item->sidebarThreadInclude['title']);
    }
}
