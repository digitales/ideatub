<?php

namespace Tests\Feature;

use App\Models\Thought;
use App\Models\User;
use App\Support\Research\MicrositeInAppPathHelper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MicrositePublishedIdeatubLinksHtmlTest extends TestCase
{
    use RefreshDatabase;

    public function test_rewrites_gfm_autolink_reports_href_to_in_app_research_route(): void
    {
        $user = User::factory()->create();
        $root = Thought::factory()->create([
            'user_id' => $user->id,
            'parent_id' => null,
            'content' => '# Root',
            'metadata' => ['type' => 'research'],
            'source_metadata' => [
                'document_layout' => 'microsite',
                'file_path' => 'x/00-intro.md',
                'page_path_segment' => '00-intro',
                'import_order' => 0,
            ],
        ]);
        Thought::factory()->create([
            'user_id' => $user->id,
            'parent_id' => $root->id,
            'content' => '# Other',
            'metadata' => ['type' => 'research'],
            'source_metadata' => [
                'document_layout' => 'microsite',
                'microsite_root_id' => (string) $root->id,
                'file_path' => 'x/01-spdd-and-reasons.md',
                'page_path_segment' => '01-spdd-and-reasons',
                'import_order' => 1,
            ],
        ]);

        $html = '<p><a href="https://ideatub.com/reports/structured-prompt-driven/01-spdd-and-reasons">Section</a></p>';
        $out = MicrositeInAppPathHelper::rewritePublishedIdeatubLinksInHtml($root->fresh(), $html);

        $expected = route('idea.research.page', [
            'thought' => $root,
            'page' => '01-spdd-and-reasons',
        ], true);
        $this->assertStringContainsString('href="'.e($expected).'"', $out);
        $this->assertStringNotContainsString('reports/', $out);
    }

    public function test_rewrites_root_relative_reports_href_in_rendered_html(): void
    {
        $user = User::factory()->create();
        $root = Thought::factory()->create([
            'user_id' => $user->id,
            'parent_id' => null,
            'content' => '# Root',
            'metadata' => ['type' => 'research'],
            'source_metadata' => [
                'document_layout' => 'microsite',
                'file_path' => 'x/00-intro.md',
                'page_path_segment' => '00-intro',
                'import_order' => 0,
            ],
        ]);
        Thought::factory()->create([
            'user_id' => $user->id,
            'parent_id' => $root->id,
            'content' => '# Child',
            'metadata' => ['type' => 'research'],
            'source_metadata' => [
                'document_layout' => 'microsite',
                'microsite_root_id' => (string) $root->id,
                'file_path' => 'x/01-spdd-and-reasons.md',
                'page_path_segment' => '01-spdd-and-reasons',
                'import_order' => 1,
            ],
        ]);

        $html = '<p><a href="/reports/structured-prompt-driven/01-spdd-and-reasons">Section</a></p>';
        $out = MicrositeInAppPathHelper::rewritePublishedIdeatubLinksInHtml($root->fresh(), $html);

        $expected = route('idea.research.page', [
            'thought' => $root,
            'page' => '01-spdd-and-reasons',
        ], true);
        $this->assertStringContainsString('href="'.e($expected).'"', $out);
        $this->assertStringNotContainsString('/reports/', $out);
    }
}
