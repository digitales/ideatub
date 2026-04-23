<?php

namespace Tests\Feature\Commands;

use App\Models\Thought;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BackfillThoughtContentSha256CommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_populates_missing_hashes_in_chunks(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < 5; $i++) {
            Thought::create([
                'user_id' => $user->id,
                'content' => "thought $i",
                'source' => 'test',
            ]);
        }

        DB::table('thoughts')->update(['content_sha256' => null]);

        $this->artisan('thoughts:backfill-content-sha256', ['--chunk' => 2])
            ->expectsOutputToContain('Backfilled 5 thoughts.')
            ->assertExitCode(0);

        $rows = DB::table('thoughts')->get();
        $this->assertCount(5, $rows);
        foreach ($rows as $row) {
            $this->assertSame(hash('sha256', $row->content), $row->content_sha256);
        }
    }

    public function test_it_is_idempotent(): void
    {
        $user = User::factory()->create();
        Thought::create([
            'user_id' => $user->id,
            'content' => 'only one',
            'source' => 'test',
        ]);

        $this->artisan('thoughts:backfill-content-sha256')->assertExitCode(0);

        $this->artisan('thoughts:backfill-content-sha256')
            ->expectsOutputToContain('Backfilled 0 thoughts.')
            ->assertExitCode(0);
    }

    public function test_it_hashes_the_decoded_form_of_stored_content(): void
    {
        $user = User::factory()->create();

        $thoughtId = (string) \Illuminate\Support\Str::uuid();
        DB::table('thoughts')->insert([
            'id' => $thoughtId,
            'user_id' => $user->id,
            'content' => "it&#039;s fine",
            'source' => 'test',
            'content_sha256' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('thoughts:backfill-content-sha256')->assertExitCode(0);

        $row = DB::table('thoughts')->where('id', $thoughtId)->first();
        $this->assertSame(hash('sha256', "it's fine"), $row->content_sha256);
    }
}
