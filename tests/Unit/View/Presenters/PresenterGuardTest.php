<?php

namespace Tests\Unit\View\Presenters;

use App\Models\Thought;
use App\View\Presenters\Concerns\EnsuresPresenterDataIsLoaded;
use App\View\Presenters\MissingPresenterData;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PresenterGuardTest extends TestCase
{
    #[Test]
    public function require_relation_loaded_throws_when_relation_is_not_loaded(): void
    {
        $presenter = new class
        {
            use EnsuresPresenterDataIsLoaded;
        };

        $thought = Thought::factory()->make();

        $this->expectException(MissingPresenterData::class);

        $presenter->requireRelationLoaded($thought, 'comments');
    }

    #[Test]
    public function require_lookup_key_throws_when_key_is_missing(): void
    {
        $presenter = new class
        {
            use EnsuresPresenterDataIsLoaded;
        };

        $this->expectException(MissingPresenterData::class);

        $presenter->requireLookupKey([], 'newsletterStatusByThoughtId');
    }
}
