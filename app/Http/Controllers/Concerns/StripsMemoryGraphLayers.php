<?php

namespace App\Http\Controllers\Concerns;

use App\Services\Graph\ThoughtGraphQuery;

trait StripsMemoryGraphLayers
{
    private function stripDisabledGraphLayers(ThoughtGraphQuery $query): ThoughtGraphQuery
    {
        if (! config('features.memory_graph_semantic')) {
            $query->includeSemantic = false;
        }

        return $query;
    }
}
