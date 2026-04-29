<?php

namespace Tests\Unit\Services\Import;

use App\Services\Import\MicrositeMarkdownLinkRewriter;
use Tests\TestCase;

class MicrositeMarkdownLinkRewriterTest extends TestCase
{
    public function test_rewrites_md_link_to_page_query(): void
    {
        $r = new MicrositeMarkdownLinkRewriter;
        $k1 = $r->pathKeyForRelativePath('docs/00-s.md');
        $k2 = $r->pathKeyForRelativePath('docs/01-t.md');
        $map = [$k1 => '00-s', $k2 => '01-t'];
        $out = $r->rewrite(
            'See [other](01-t.md).',
            'docs/00-s.md',
            $map
        );
        $this->assertStringContainsString('?page=01-t', (string) $out['markdown']);
    }

    public function test_counts_local_image_refs(): void
    {
        $r = new MicrositeMarkdownLinkRewriter;
        $k = $r->pathKeyForRelativePath('x/00-a.md');
        $out = $r->rewrite(
            "![x](https://a.com/1.png) ![y](./local.png)\n[md](a.md).",
            'x/00-a.md',
            [$k => '00-a']
        );
        $this->assertSame(1, $out['localAssetRefCount']);
    }

    public function test_rewrites_ideatub_reports_absolute_url_when_segment_matches_batch(): void
    {
        $r = new MicrositeMarkdownLinkRewriter;
        $map = [
            $r->pathKeyForRelativePath('01-spdd-and-reasons.md') => '01-spdd-and-reasons',
        ];
        $out = $r->rewrite(
            'See [canvas](https://ideatub.com/reports/structured-prompt-driven/01-spdd-and-reasons).',
            '00-intro.md',
            $map
        );
        $this->assertStringContainsString('[canvas](?page=01-spdd-and-reasons)', (string) $out['markdown']);
        $this->assertStringNotContainsString('reports/', (string) $out['markdown']);
    }

    public function test_preserves_fragment_on_ideatub_reports_rewrite(): void
    {
        $r = new MicrositeMarkdownLinkRewriter;
        $map = [$r->pathKeyForRelativePath('01-spdd-and-reasons.md') => '01-spdd-and-reasons'];
        $out = $r->rewrite(
            '[x](https://www.ideatub.com/reports/foo/01-spdd-and-reasons#heading)',
            '00-intro.md',
            $map
        );
        $this->assertStringContainsString('?page=01-spdd-and-reasons#heading', (string) $out['markdown']);
    }

    public function test_leaves_ideatub_reports_url_unchanged_when_segment_not_in_batch(): void
    {
        $r = new MicrositeMarkdownLinkRewriter;
        $map = [$r->pathKeyForRelativePath('00-intro.md') => '00-intro'];
        $md = '[other report](https://ideatub.com/reports/other-slug/99-other-page)';
        $out = $r->rewrite($md, '00-intro.md', $map);
        $this->assertSame($md, (string) $out['markdown']);
    }

    public function test_leaves_two_segment_reports_path_unchanged(): void
    {
        $r = new MicrositeMarkdownLinkRewriter;
        $map = [$r->pathKeyForRelativePath('01-spdd-and-reasons.md') => '01-spdd-and-reasons'];
        $md = '[home](https://ideatub.com/reports/structured-prompt-driven)';
        $out = $r->rewrite($md, '00-intro.md', $map);
        $this->assertSame($md, (string) $out['markdown']);
    }

    public function test_rewrites_in_app_research_url_when_root_id_matches_and_fourth_arg_set(): void
    {
        $r = new MicrositeMarkdownLinkRewriter;
        $rootId = '019dd8c5-3bb5-71d4-a2de-e35863b7559d';
        $map = [$r->pathKeyForRelativePath('01-other.md') => '01-other'];
        $out = $r->rewrite(
            '[link](https://ideatub.com/research/'.$rootId.'/p/01-other)',
            '00-intro.md',
            $map,
            $rootId
        );
        $this->assertStringContainsString('[link](?page=01-other)', (string) $out['markdown']);
        $this->assertStringNotContainsString('/research/', (string) $out['markdown']);
    }

    public function test_does_not_rewrite_in_app_research_url_when_root_uuid_differs(): void
    {
        $r = new MicrositeMarkdownLinkRewriter;
        $map = [$r->pathKeyForRelativePath('01-other.md') => '01-other'];
        $md = '[link](https://ideatub.com/research/019dd8c5-3bb5-71d4-a2de-e35863b7559d/p/01-other)';
        $out = $r->rewrite(
            $md,
            '00-intro.md',
            $map,
            'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee'
        );
        $this->assertSame($md, (string) $out['markdown']);
    }

    public function test_import_without_fourth_arg_leaves_in_app_research_urls_unchanged(): void
    {
        $r = new MicrositeMarkdownLinkRewriter;
        $map = [$r->pathKeyForRelativePath('01-other.md') => '01-other'];
        $md = '[link](https://ideatub.com/research/019dd8c5-3bb5-71d4-a2de-e35863b7559d/p/01-other)';
        $out = $r->rewrite($md, '00-intro.md', $map);
        $this->assertSame($md, (string) $out['markdown']);
    }

    public function test_rewrites_bare_reports_url_not_in_brackets(): void
    {
        $r = new MicrositeMarkdownLinkRewriter;
        $map = [$r->pathKeyForRelativePath('01-spdd-and-reasons.md') => '01-spdd-and-reasons'];
        $md = "Microsite sections\n\nSee https://ideatub.com/reports/structured-prompt-driven/01-spdd-and-reasons for intro.";
        $out = $r->rewrite($md, '00-intro.md', $map);
        $this->assertStringContainsString('See ?page=01-spdd-and-reasons for intro', (string) $out['markdown']);
        $this->assertStringNotContainsString('reports/', (string) $out['markdown']);
    }

    public function test_rewrites_bare_in_app_research_url_when_root_id_passed(): void
    {
        $r = new MicrositeMarkdownLinkRewriter;
        $id = '019dd8c5-3bb5-71d4-a2de-e35863b7559d';
        $map = [$r->pathKeyForRelativePath('01-other.md') => '01-other'];
        $md = 'Link: https://ideatub.com/research/'.$id.'/p/01-other.';
        $out = $r->rewrite($md, '00-intro.md', $map, $id);
        $this->assertStringContainsString('Link: ?page=01-other.', (string) $out['markdown']);
        $this->assertStringNotContainsString('/research/', (string) $out['markdown']);
    }

    public function test_rewrites_bracket_link_with_root_relative_reports_path(): void
    {
        $r = new MicrositeMarkdownLinkRewriter;
        $map = [$r->pathKeyForRelativePath('01-spdd-and-reasons.md') => '01-spdd-and-reasons'];
        $out = $r->rewrite(
            '[Section](/reports/structured-prompt-driven/01-spdd-and-reasons)',
            '00-intro.md',
            $map
        );
        $this->assertStringContainsString('[Section](?page=01-spdd-and-reasons)', (string) $out['markdown']);
        $this->assertStringNotContainsString('/reports/', (string) $out['markdown']);
    }

    public function test_rewrites_bare_root_relative_reports_path(): void
    {
        $r = new MicrositeMarkdownLinkRewriter;
        $map = [$r->pathKeyForRelativePath('01-x.md') => '01-x'];
        $out = $r->rewrite(
            "Summary\n\n/reports/z/01-x\n",
            '00-intro.md',
            $map
        );
        $this->assertStringContainsString('?page=01-x', (string) $out['markdown']);
        $this->assertStringNotContainsString('/reports/', (string) $out['markdown']);
    }
}
