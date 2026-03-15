<?php

namespace Tests\Feature;

use App\Models\Thought;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class NormalizeThoughtContentEntitiesCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_lists_thoughts_that_would_be_updated(): void
    {
        $user = User::factory()->create();
        $thought = Thought::factory()->create(['user_id' => $user->id, 'content' => 'Plain text']);
        DB::table('thoughts')->where('id', $thought->id)->update(['content' => "Daphne&#039;s breathing"]);

        $this->artisan('thoughts:normalize-content-entities', ['--dry-run' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('would be updated');

        $thought->refresh();
        $this->assertSame("Daphne&#039;s breathing", $thought->content);
    }

    public function test_normalizes_encoded_content(): void
    {
        $user = User::factory()->create();
        $thought = Thought::factory()->create(['user_id' => $user->id, 'content' => 'Plain']);
        DB::table('thoughts')->where('id', $thought->id)->update(['content' => "Daphne&#039;s breathing was 30."]);

        $this->artisan('thoughts:normalize-content-entities')
            ->assertSuccessful()
            ->expectsOutputToContain('Updated 1 thought(s)');

        $thought->refresh();
        $this->assertSame("Daphne's breathing was 30.", $thought->content);
    }

    public function test_skips_plain_content(): void
    {
        $user = User::factory()->create();
        Thought::factory()->create(['user_id' => $user->id, 'content' => "Daphne's plain text"]);

        $this->artisan('thoughts:normalize-content-entities')
            ->assertSuccessful()
            ->expectsOutputToContain('Updated 0 thought(s)');
    }
}
