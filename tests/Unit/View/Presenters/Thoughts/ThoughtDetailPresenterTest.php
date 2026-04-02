<?php

namespace Tests\Unit\View\Presenters\Thoughts;

use App\Models\Thought;
use App\Models\User;
use App\View\Presenters\Thoughts\ThoughtDetailPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ThoughtDetailPresenterTest extends TestCase
{
    use RefreshDatabase;

    public function test_video_latest_research_url_is_memoized_within_the_presenter(): void
    {
        $user = User::factory()->create();
        $research = Thought::factory()->create([
            'user_id' => $user->id,
            'parent_id' => null,
            'source' => 'research',
            'metadata' => ['type' => 'research', 'tags' => ['video']],
        ]);
        $video = Thought::factory()->create([
            'user_id' => $user->id,
            'parent_id' => null,
            'source' => 'video',
            'metadata' => [
                'type' => 'video',
                'video_id' => 'detail-memoized-1',
                'video_url' => 'https://www.youtube.com/watch?v=detail-memoized-1',
                'research_thought_id' => $research->id,
            ],
        ]);
        $video->setRelation('comments', collect());

        $presenter = ThoughtDetailPresenter::forShow(
            thought: $video,
            contentHtml: null,
            linkedResearchUrl: null,
            emailResearchPreview: null,
            newsletterResearchStatus: null,
            senderRuleContext: null,
            emailMetadata: null,
            importedEmailForBody: null,
        );

        DB::flushQueryLog();
        DB::enableQueryLog();

        $first = $presenter->videoLatestResearchUrl();
        $second = $presenter->videoLatestResearchUrl();

        $queries = array_filter(DB::getQueryLog(), function (array $entry): bool {
            $sql = $entry['query'] ?? '';

            return is_string($sql)
                && preg_match('/select .* from ["`]?thoughts["`]?/i', $sql) === 1;
        });

        $this->assertSame(route('idea.research.show', $research), $first);
        $this->assertSame($first, $second);
        $this->assertCount(1, $queries);
    }
}
