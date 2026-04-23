<?php

namespace Tests\Unit\Support\Research;

use App\Models\Thought;
use App\Support\Research\MicrositeInAppPathHelper;
use Illuminate\Support\Str;
use Tests\TestCase;

class MicrositeInAppPathHelperTest extends TestCase
{
    public function test_rewrites_page_query_href_to_canonical_in_app_routes(): void
    {
        $root = new Thought;
        $id = (string) Str::uuid();
        $root->forceFill(['id' => $id]);
        $html = '<a href="?page=01-child">c</a>';
        $out = MicrositeInAppPathHelper::rewriteQueryPageLinksInHtml($root, $html);
        $this->assertStringContainsString(
            (string) route('idea.research.page', [
                'thought' => $root,
                'page' => '01-child',
            ], true),
            $out
        );
        $this->assertStringNotContainsString('?page=', $out);
    }

    public function test_rewrites_to_show_when_page_empty(): void
    {
        $root = new Thought;
        $root->forceFill(['id' => (string) Str::uuid()]);
        $html = '<a href="?page=">x</a>';
        $out = MicrositeInAppPathHelper::rewriteQueryPageLinksInHtml($root, $html);
        $this->assertStringContainsString(
            (string) route('idea.research.show', $root, true),
            $out
        );
    }
}
