<?php

namespace Tests\Feature;

use App\Models\Thought;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BackfillResearchTitlesTest extends TestCase
{
    use RefreshDatabase;

    public function test_backfill_extracts_title_from_markdown_heading(): void
    {
        $user = User::factory()->create();
        $thought = Thought::factory()->create([
            'user_id' => $user->id,
            'parent_id' => null,
            'content' => "# My Research Title\n\nBody content here.",
            'metadata' => ['type' => 'research', 'tags' => []],
        ]);

        $this->artisan('research:backfill-titles')
            ->assertExitCode(0);

        $this->assertSame('My Research Title', $thought->fresh()->metadata['title']);
    }

    public function test_backfill_extracts_title_from_plain_text_when_no_heading(): void
    {
        $user = User::factory()->create();
        $thought = Thought::factory()->create([
            'user_id' => $user->id,
            'parent_id' => null,
            'content' => 'This is a research note without any markdown headings but with plenty of text content that goes on.',
            'metadata' => ['type' => 'research', 'tags' => []],
        ]);

        $this->artisan('research:backfill-titles')
            ->assertExitCode(0);

        $title = $thought->fresh()->metadata['title'];
        $this->assertNotNull($title);
        $this->assertLessThanOrEqual(80, mb_strlen($title));
    }

    public function test_backfill_skips_thoughts_with_existing_title(): void
    {
        $user = User::factory()->create();
        $thought = Thought::factory()->create([
            'user_id' => $user->id,
            'parent_id' => null,
            'content' => "# Different Heading\n\nBody.",
            'metadata' => ['type' => 'research', 'tags' => [], 'title' => 'Keep This'],
        ]);

        $this->artisan('research:backfill-titles')
            ->assertExitCode(0);

        $this->assertSame('Keep This', $thought->fresh()->metadata['title']);
    }

    public function test_backfill_dry_run_does_not_modify(): void
    {
        $user = User::factory()->create();
        $thought = Thought::factory()->create([
            'user_id' => $user->id,
            'parent_id' => null,
            'content' => "# Would Be Extracted\n\nBody.",
            'metadata' => ['type' => 'research', 'tags' => []],
        ]);

        $this->artisan('research:backfill-titles', ['--dry-run' => true])
            ->assertExitCode(0);

        $this->assertNull($thought->fresh()->metadata['title'] ?? null);
    }
}
