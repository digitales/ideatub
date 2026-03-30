<?php

namespace Tests\Unit\Services\LinkSummary;

use App\Services\LinkSummary\NewsletterEditorialLinkCandidateBuilder;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class NewsletterEditorialLinkCandidateBuilderTest extends TestCase
{
    private NewsletterEditorialLinkCandidateBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->builder = new NewsletterEditorialLinkCandidateBuilder;
    }

    #[Test]
    public function keeps_editorial_links_but_filters_social_footer_and_account_noise(): void
    {
        $body = <<<'TXT'
HEADLINES & LAUNCHES

Mythos shipped a major composer refresh this week — worth skimming if you follow creative tooling.
Read the Mythos write-up: https://mythos.example.com/launch-notes

Follow us https://linkedin.com/company/mythos or https://twitter.com/mythos for updates.
Unsubscribe https://newsletter.example.com/unsubscribe and manage prefs at https://newsletter.example.com/manage
We are hiring: https://careers.example.com/jobs/designer
TXT;

        $links = [
            ['url' => 'https://mythos.example.com/launch-notes', 'type' => 'generic'],
            ['url' => 'https://linkedin.com/company/mythos', 'type' => 'generic'],
            ['url' => 'https://twitter.com/mythos', 'type' => 'generic'],
            ['url' => 'https://newsletter.example.com/unsubscribe', 'type' => 'generic'],
            ['url' => 'https://newsletter.example.com/manage', 'type' => 'generic'],
            ['url' => 'https://careers.example.com/jobs/designer', 'type' => 'generic'],
        ];

        $out = $this->builder->build($body, $links);

        $this->assertCount(1, $out);
        $this->assertSame('https://mythos.example.com/launch-notes', $out[0]['original_url']);
        $this->assertSame('editorial', $out[0]['classification']);
        $this->assertSame('HEADLINES & LAUNCHES', $out[0]['newsletter_section_label']);
        $this->assertStringContainsString('Mythos', $out[0]['source_excerpt']);
        $this->assertStringContainsString('https://mythos.example.com/launch-notes', $out[0]['source_excerpt']);
    }

    #[Test]
    public function assigns_section_order_and_source_excerpt_from_nearby_newsletter_copy(): void
    {
        $body = <<<'TXT'
HEADLINES & LAUNCHES

Mythos shipped a major composer refresh this week.
The launch notes explain how the new workflow reduces friction for repeat drafting.
More detail: https://mythos.example.com/launch-notes

DEEP DIVES & ANALYSIS

AutoBe published a long teardown of the tooling market — dense but useful if you follow the space.
It walks through the economics, product constraints, and why bundling keeps showing up.
Deep dive here: https://autobe.example.com/tooling-teardown
TXT;

        $links = [
            ['url' => 'https://mythos.example.com/launch-notes', 'type' => 'generic'],
            ['url' => 'https://autobe.example.com/tooling-teardown', 'type' => 'generic'],
        ];

        $out = $this->builder->build($body, $links);

        $this->assertCount(2, $out);

        $mythos = $out[0];
        $this->assertSame(1, $mythos['newsletter_section_order']);
        $this->assertSame('HEADLINES & LAUNCHES', $mythos['newsletter_section_label']);
        $this->assertSame(
            'Mythos shipped a major composer refresh this week. The launch notes explain how the new workflow reduces friction for repeat drafting. More detail: https://mythos.example.com/launch-notes',
            $mythos['source_excerpt']
        );
        $this->assertStringContainsString('composer', $mythos['source_excerpt']);
        $this->assertStringContainsString('reduces friction', $mythos['source_excerpt']);

        $autobe = $out[1];
        $this->assertSame(2, $autobe['newsletter_section_order']);
        $this->assertSame('DEEP DIVES & ANALYSIS', $autobe['newsletter_section_label']);
        $this->assertStringContainsString('AutoBe', $autobe['source_excerpt']);
        $this->assertStringContainsString('economics, product constraints', $autobe['source_excerpt']);
        $this->assertStringContainsString('https://autobe.example.com/tooling-teardown', $autobe['source_excerpt']);
        $this->assertStringNotContainsString('HEADLINES & LAUNCHES', $autobe['source_excerpt']);
    }

    #[Test]
    public function classifies_sponsor_links_separately_from_editorial_links_when_together_with_and_sponsor_marker_present(): void
    {
        $body = <<<'TXT'
HEADLINES & LAUNCHES

Editorial pick: https://editorial.example.com/story-one

TOGETHER WITH DATAVIZ CO

This placement is labeled (SPONSOR). Try their dashboard: https://sponsor.example.com/start

DEEP DIVES & ANALYSIS

Another editorial link https://editorial.example.com/story-two
TXT;

        $links = [
            ['url' => 'https://editorial.example.com/story-one', 'type' => 'generic'],
            ['url' => 'https://sponsor.example.com/start', 'type' => 'generic'],
            ['url' => 'https://editorial.example.com/story-two', 'type' => 'generic'],
        ];

        $out = $this->builder->build($body, $links);

        $this->assertCount(3, $out);

        $byUrl = [];
        foreach ($out as $row) {
            $byUrl[$row['normalized_url']] = $row;
        }

        $this->assertSame('editorial', $byUrl['https://editorial.example.com/story-one']['classification']);
        $this->assertSame('sponsor', $byUrl['https://sponsor.example.com/start']['classification']);
        $this->assertSame('editorial', $byUrl['https://editorial.example.com/story-two']['classification']);

        $this->assertStringContainsString('(SPONSOR)', $byUrl['https://sponsor.example.com/start']['source_excerpt']);
    }

    #[Test]
    public function sponsor_interval_stops_at_a_new_blank_line_delimited_editorial_block_even_without_an_all_caps_heading(): void
    {
        $body = <<<'TXT'
HEADLINES & LAUNCHES

TOGETHER WITH DATAVIZ CO
This placement is labeled (SPONSOR). Try their dashboard: https://sponsor.example.com/start

Deep dives and analysis
AutoBe published a long teardown of the tooling market.
Read more: https://editorial.example.com/story-two
TXT;

        $links = [
            ['url' => 'https://sponsor.example.com/start', 'type' => 'generic'],
            ['url' => 'https://editorial.example.com/story-two', 'type' => 'generic'],
        ];

        $out = $this->builder->build($body, $links);

        $this->assertCount(2, $out);

        $byUrl = [];
        foreach ($out as $row) {
            $byUrl[$row['normalized_url']] = $row;
        }

        $this->assertSame('sponsor', $byUrl['https://sponsor.example.com/start']['classification']);
        $this->assertSame('editorial', $byUrl['https://editorial.example.com/story-two']['classification']);
        $this->assertStringContainsString('AutoBe published', $byUrl['https://editorial.example.com/story-two']['source_excerpt']);
    }

    #[Test]
    public function falls_back_to_uncategorized_editorial_links_when_no_heading_is_available(): void
    {
        $body = <<<'TXT'
No section heading here — just a standalone blurb before the link.
Check this out: https://orphan.example.com/article
TXT;

        $links = [
            ['url' => 'https://orphan.example.com/article', 'type' => 'generic'],
        ];

        $out = $this->builder->build($body, $links);

        $this->assertCount(1, $out);
        $this->assertSame('Uncategorized editorial links', $out[0]['newsletter_section_label']);
        $this->assertSame(0, $out[0]['newsletter_section_order']);
        $this->assertSame('editorial', $out[0]['classification']);
    }

    #[Test]
    public function dedupes_by_normalized_url_keeping_the_first_useful_source_excerpt(): void
    {
        $body = <<<'TXT'
HEADLINES & LAUNCHES

First mention with unique context alpha-bravo-charlie.
https://dup.example.com/page

Later mention different context delta-echo-foxtrot.
https://dup.example.com/page
TXT;

        $links = [
            ['url' => 'https://dup.example.com/page', 'type' => 'generic'],
            ['url' => 'https://dup.example.com/page', 'type' => 'generic'],
        ];

        $out = $this->builder->build($body, $links);

        $this->assertCount(1, $out);
        $this->assertStringContainsString('alpha-bravo-charlie', $out[0]['source_excerpt']);
        $this->assertStringNotContainsString('delta-echo-foxtrot', $out[0]['source_excerpt']);
    }

    #[Test]
    public function filters_x_com_and_referral_style_account_paths_as_noise(): void
    {
        $body = <<<'TXT'
HEADLINES & LAUNCHES

Good read https://good.example.com/post
Also https://x.com/someone/status/1 and https://refer.example.com/refer/abc
Account https://accounts.example.com/billing/plan
Admin https://app.example.com/account-management/users
Sell sponsorships via https://advertise.tldr.tech/placements
TXT;

        $links = [
            ['url' => 'https://good.example.com/post', 'type' => 'generic'],
            ['url' => 'https://x.com/someone/status/1', 'type' => 'generic'],
            ['url' => 'https://refer.example.com/refer/abc', 'type' => 'generic'],
            ['url' => 'https://accounts.example.com/billing/plan', 'type' => 'generic'],
            ['url' => 'https://app.example.com/account-management/users', 'type' => 'generic'],
            ['url' => 'https://advertise.tldr.tech/placements', 'type' => 'generic'],
        ];

        $out = $this->builder->build($body, $links);

        $this->assertCount(1, $out);
        $this->assertSame('https://good.example.com/post', $out[0]['normalized_url']);
    }

    #[Test]
    public function output_rows_include_original_normalized_hash_classification_section_and_excerpt_keys(): void
    {
        $body = "HEADLINES\n\nhttps://a.example.com/x";
        $links = [['url' => 'https://a.example.com/x', 'type' => 'generic']];

        $out = $this->builder->build($body, $links);

        $this->assertCount(1, $out);
        $row = $out[0];
        $this->assertArrayHasKey('original_url', $row);
        $this->assertArrayHasKey('normalized_url', $row);
        $this->assertArrayHasKey('normalized_url_hash', $row);
        $this->assertArrayHasKey('classification', $row);
        $this->assertArrayHasKey('newsletter_section_label', $row);
        $this->assertArrayHasKey('newsletter_section_order', $row);
        $this->assertArrayHasKey('source_excerpt', $row);
        $this->assertSame(sha1($row['normalized_url']), $row['normalized_url_hash']);
    }
}
