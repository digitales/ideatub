<?php

namespace Tests\Feature;

use App\Models\Thought;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExtractUntaggedThoughtsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_without_tags_scope_excludes_thoughts_that_have_tags(): void
    {
        $user = User::factory()->create();

        Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Has tags',
            'metadata' => ['tags' => ['work'], 'type' => 'note'],
        ]);

        Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'No tags',
            'metadata' => ['tags' => [], 'type' => 'meeting'],
        ]);

        Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Null metadata',
            'metadata' => null,
        ]);

        $withoutTags = Thought::withoutTags()->get();

        $this->assertCount(2, $withoutTags);
        $this->assertTrue($withoutTags->pluck('content')->contains('No tags'));
        $this->assertTrue($withoutTags->pluck('content')->contains('Null metadata'));
        $this->assertFalse($withoutTags->pluck('content')->contains('Has tags'));
    }

    public function test_dry_run_lists_thoughts_without_tags_and_makes_no_changes(): void
    {
        $user = User::factory()->create();
        Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Untagged thought',
            'metadata' => ['tags' => []],
        ]);

        $this->artisan('thoughts:extract-untagged', ['--dry-run' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('Found 1 thought(s) without tags');

        $thought = Thought::first();
        $this->assertSame([], $thought->metadata['tags'] ?? []);
    }
}
