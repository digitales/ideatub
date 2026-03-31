<?php

namespace Tests\Unit\View\Presenters\Email;

use App\View\Presenters\Email\NewsletterResearchStatusPresenter;
use PHPUnit\Framework\TestCase;

class NewsletterResearchStatusPresenterTest extends TestCase
{
    public function test_from_array_returns_null_for_null_payload(): void
    {
        $this->assertNull(NewsletterResearchStatusPresenter::fromArray(null, 'abc'));
    }

    public function test_label_maps_known_statuses(): void
    {
        $cases = [
            'research_queued' => 'Research queued',
            'research_completed' => 'Research ready',
            'research_partial' => 'Partial research',
            'research_skipped' => 'Research skipped',
            'research_failed' => 'Research failed',
        ];

        foreach ($cases as $status => $expected) {
            $p = NewsletterResearchStatusPresenter::fromArray([
                'status' => $status,
                'research_thought_id' => null,
                'skip_reason' => '',
                'show_research_link' => false,
                'show_skip_info' => false,
            ], 'suffix');

            $this->assertSame($expected, $p->label(), "status {$status}");
        }
    }

    public function test_label_falls_back_for_unknown_status(): void
    {
        $p = NewsletterResearchStatusPresenter::fromArray([
            'status' => 'custom_thing_here',
            'research_thought_id' => null,
            'skip_reason' => '',
            'show_research_link' => false,
            'show_skip_info' => false,
        ], 'x');

        $this->assertSame('Custom thing here', $p->label());
    }

    public function test_shows_research_link_and_skip_visibility_from_payload(): void
    {
        $withLink = NewsletterResearchStatusPresenter::fromArray([
            'status' => 'research_completed',
            'research_thought_id' => '00000000-0000-0000-0000-000000000099',
            'skip_reason' => '',
            'show_research_link' => true,
            'show_skip_info' => false,
        ], 't1');

        $this->assertTrue($withLink->showsResearchLink());
        $this->assertFalse($withLink->showsSkipInfo());
        $this->assertSame('00000000-0000-0000-0000-000000000099', $withLink->researchThoughtId());

        $withSkip = NewsletterResearchStatusPresenter::fromArray([
            'status' => 'research_skipped',
            'research_thought_id' => null,
            'skip_reason' => 'Too short.',
            'show_research_link' => false,
            'show_skip_info' => true,
        ], 't2');

        $this->assertFalse($withSkip->showsResearchLink());
        $this->assertTrue($withSkip->showsSkipInfo());
        $this->assertSame('Too short.', $withSkip->skipReason());
    }

    public function test_skip_reason_popover_id_uses_suffix(): void
    {
        $p = NewsletterResearchStatusPresenter::fromArray([
            'status' => 'research_skipped',
            'research_thought_id' => null,
            'skip_reason' => 'x',
            'show_research_link' => false,
            'show_skip_info' => true,
        ], 'thought-uuid-here');

        $this->assertSame('email-research-skip-reason-thought-uuid-here', $p->skipReasonPopoverId());
    }

    public function test_status_returns_payload_status(): void
    {
        $p = NewsletterResearchStatusPresenter::fromArray([
            'status' => 'research_partial',
            'research_thought_id' => null,
            'skip_reason' => '',
            'show_research_link' => false,
            'show_skip_info' => false,
        ], 's');

        $this->assertSame('research_partial', $p->status());
    }
}
