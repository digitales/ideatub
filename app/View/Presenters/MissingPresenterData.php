<?php

namespace App\View\Presenters;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

class MissingPresenterData extends RuntimeException
{
    public static function forRelation(Model $model, string $relation): self
    {
        return new self(sprintf(
            'Presenter requires relation [%s] to be loaded on %s.',
            $relation,
            $model::class
        ));
    }

    public static function forLookupKey(string $key): self
    {
        return new self(sprintf(
            'Presenter requires lookup key [%s] to be present in the preloaded payload.',
            $key
        ));
    }
}
