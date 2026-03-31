<?php

namespace Tests\Unit\View\Presenters;

use App\Models\Thought;
use App\View\Presenters\Concerns\EnsuresPresenterDataIsLoaded;
use App\View\Presenters\MissingPresenterData;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PresenterGuardTest extends TestCase
{
    private function presenterGuardDouble(): object
    {
        return new class
        {
            use EnsuresPresenterDataIsLoaded;

            public function guardRelation(Model $model, string $relation): void
            {
                $this->requireRelationLoaded($model, $relation);
            }

            public function guardLookup(array $lookup, string $key): void
            {
                $this->requireLookupKey($lookup, $key);
            }
        };
    }

    #[Test]
    public function require_relation_loaded_throws_when_relation_is_not_loaded(): void
    {
        $presenter = $this->presenterGuardDouble();

        $thought = Thought::factory()->make();

        $this->expectException(MissingPresenterData::class);
        $this->expectExceptionMessage(
            'Presenter requires relation [comments] to be loaded on '.Thought::class.'.'
        );

        $presenter->guardRelation($thought, 'comments');
    }

    #[Test]
    public function require_lookup_key_throws_when_key_is_missing(): void
    {
        $presenter = $this->presenterGuardDouble();

        $this->expectException(MissingPresenterData::class);
        $this->expectExceptionMessage(
            'Presenter requires lookup key [newsletterStatusByThoughtId] to be present in the preloaded payload.'
        );

        $presenter->guardLookup([], 'newsletterStatusByThoughtId');
    }

    #[Test]
    public function require_relation_loaded_does_not_throw_when_relation_is_loaded(): void
    {
        $this->expectNotToPerformAssertions();

        $presenter = $this->presenterGuardDouble();

        $thought = Thought::factory()->make();
        $thought->setRelation('comments', collect());

        $presenter->guardRelation($thought, 'comments');
    }

    #[Test]
    public function require_lookup_key_does_not_throw_when_key_exists_even_if_value_is_null(): void
    {
        $this->expectNotToPerformAssertions();

        $presenter = $this->presenterGuardDouble();

        $presenter->guardLookup(['newsletterStatusByThoughtId' => null], 'newsletterStatusByThoughtId');
    }
}
