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
}
