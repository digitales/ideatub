<?php

namespace Tests\Unit\Services\WorkingMemory;

use App\Models\User;
use App\Models\UserPreference;
use App\Services\WorkingMemory\ForcedTagResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ForcedTagResolverTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_normalizes_and_deduplicates_tags_from_csv_and_newlines(): void
    {
        $resolver = app(ForcedTagResolver::class);

        $tags = $resolver->normalizeTags(" AI, ml\nAi\r\ndata, ,ML ");

        $this->assertSame(['ai', 'ml', 'data'], $tags);
    }

    #[Test]
    public function it_normalizes_array_input(): void
    {
        $resolver = app(ForcedTagResolver::class);

        $tags = $resolver->normalizeTags([' AI ', 'ml', '', 'ML', 'Data ']);

        $this->assertSame(['ai', 'ml', 'data'], $tags);
    }

    #[Test]
    public function it_returns_empty_array_for_empty_or_null_input(): void
    {
        $resolver = app(ForcedTagResolver::class);

        $this->assertSame([], $resolver->normalizeTags(null));
        $this->assertSame([], $resolver->normalizeTags(''));
        $this->assertSame([], $resolver->normalizeTags(" \n , \r\n "));
    }

    #[Test]
    public function it_reads_user_preference_and_returns_normalized_tags(): void
    {
        $user = User::factory()->create();
        UserPreference::set($user, UserPreference::KEY_WORKING_MEMORY_FORCED_TAGS, " AI,\nml,AI ");

        $tags = app(ForcedTagResolver::class)->forUserId((int) $user->id);

        $this->assertSame(['ai', 'ml'], $tags);
    }
}
