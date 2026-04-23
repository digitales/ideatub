<?php

namespace Tests\Feature\Thoughts;

use App\Models\Thought;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ThoughtContentSha256Test extends TestCase
{
    use RefreshDatabase;

    public function test_thoughts_table_has_content_sha256_column(): void
    {
        $this->assertTrue(Schema::hasColumn('thoughts', 'content_sha256'));
    }

    public function test_creating_a_thought_populates_content_sha256(): void
    {
        $user = User::factory()->create();

        $t = Thought::create([
            'user_id' => $user->id,
            'content' => 'Hello world',
            'source' => 'test',
        ]);

        $expected = hash('sha256', 'Hello world');
        $this->assertSame($expected, $t->fresh()->content_sha256);
    }

    public function test_updating_content_updates_content_sha256(): void
    {
        $user = User::factory()->create();
        $t = Thought::create([
            'user_id' => $user->id,
            'content' => 'one',
            'source' => 'test',
        ]);

        $t->content = 'two';
        $t->save();

        $this->assertSame(hash('sha256', 'two'), $t->fresh()->content_sha256);
    }

    public function test_content_sha256_uses_decoded_content(): void
    {
        $user = User::factory()->create();
        $encoded = 'don&#039;t stop';
        $decoded = "don't stop";

        $t = Thought::create([
            'user_id' => $user->id,
            'content' => $encoded,
            'source' => 'test',
        ]);

        $this->assertSame(hash('sha256', $decoded), $t->fresh()->content_sha256);
    }
}
