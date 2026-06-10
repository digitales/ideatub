<?php

namespace Tests\Unit\Support;

use App\Models\Thought;
use App\Support\Research\MicrositeInAppPathHelper;
use Tests\TestCase;

class MicrositeInAppPathHelperTest extends TestCase
{
    public function test_rewrite_query_page_links_preserves_url_fragment(): void
    {
        $root = new Thought;
        $root->id = '019dd8c5-3bb5-71d4-a2de-e35863b7559d';

        $html = '<a href="?page=01-other#section">Go</a>';
        $out = MicrositeInAppPathHelper::rewriteQueryPageLinksInHtml($root, $html);

        $expected = route('idea.research.page', [
            'thought' => $root,
            'page' => '01-other',
        ], true).'#section';
        $this->assertStringContainsString('href="'.e($expected).'"', $out);
    }
}
