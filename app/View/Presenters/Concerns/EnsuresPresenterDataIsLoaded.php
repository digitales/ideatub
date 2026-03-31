<?php

namespace App\View\Presenters\Concerns;

use App\View\Presenters\MissingPresenterData;
use Illuminate\Database\Eloquent\Model;

trait EnsuresPresenterDataIsLoaded
{
    protected function requireRelationLoaded(Model $model, string $relation): void
    {
        if (! $model->relationLoaded($relation)) {
            throw MissingPresenterData::forRelation($model, $relation);
        }
    }

    protected function requireLookupKey(array $lookup, string $key): void
    {
        if (! array_key_exists($key, $lookup)) {
            throw MissingPresenterData::forLookupKey($key);
        }
    }
}
