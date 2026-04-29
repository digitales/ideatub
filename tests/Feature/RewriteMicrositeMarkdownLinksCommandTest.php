<?php

namespace Tests\Feature;

use App\Models\Thought;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class RewriteMicrositeMarkdownLinksCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_rewrites_in_app_research_and_reports_urls(): void
    {
        $user = User::factory()->create();
        $root = Thought::factory()->create([
            'user_id' => $user->id,
            'parent_id' => null,
            'content' => '', // set after create so URL includes root id
            'metadata' => ['type' => 'research'],
            'source_metadata' => [
                'document_layout' => 'microsite',
                'file_path' => 'spdd/00-intro.md',
                'page_path_segment' => '00-intro',
                'import_order' => 0,
            ],
        ]);
        $root->update([
            'content' => 'See [other](https://ideatub.com/research/'.(string) $root->id.'/p/01-other) and [rep](https://ideatub.com/reports/z/01-other).',
        ]);

        Thought::factory()->create([
            'user_id' => $user->id,
            'parent_id' => $root->id,
            'content' => '# Page two',
            'metadata' => ['type' => 'research'],
            'source_metadata' => [
                'document_layout' => 'microsite',
                'microsite_root_id' => (string) $root->id,
                'file_path' => 'spdd/01-other.md',
                'page_path_segment' => '01-other',
                'import_order' => 1,
            ],
        ]);

        $exit = Artisan::call('research:microsite-rewrite-links', [
            '--root' => (string) $root->id,
        ]);

        $this->assertSame(0, $exit);
        $root->refresh();
        $this->assertStringContainsString('[other](?page=01-other)', (string) $root->content);
        $this->assertStringContainsString('[rep](?page=01-other)', (string) $root->content);
        $this->assertStringNotContainsString('/research/', (string) $root->content);
        $this->assertStringNotContainsString('reports/', (string) $root->content);
    }

    public function test_dry_run_does_not_persist(): void
    {
        $user = User::factory()->create();
        $before = 'See [other](https://ideatub.com/reports/x/01-other).';
        $root = Thought::factory()->create([
            'user_id' => $user->id,
            'parent_id' => null,
            'content' => $before,
            'metadata' => ['type' => 'research'],
            'source_metadata' => [
                'document_layout' => 'microsite',
                'file_path' => 'a/00-intro.md',
                'page_path_segment' => '00-intro',
                'import_order' => 0,
            ],
        ]);
        Thought::factory()->create([
            'user_id' => $user->id,
            'parent_id' => $root->id,
            'content' => '# c',
            'metadata' => ['type' => 'research'],
            'source_metadata' => [
                'document_layout' => 'microsite',
                'microsite_root_id' => (string) $root->id,
                'file_path' => 'a/01-other.md',
                'page_path_segment' => '01-other',
                'import_order' => 1,
            ],
        ]);

        Artisan::call('research:microsite-rewrite-links', [
            '--root' => (string) $root->id,
            '--dry-run' => true,
        ]);

        $root->refresh();
        $this->assertSame($before, (string) $root->getRawContent());
    }
}
