<?php

namespace App\Services\WorkingMemory;

use Illuminate\Support\Str;
use InvalidArgumentException;

class WorkingMemoryScopeNormalizer
{
    /**
     * Shared semantic normalization for working-memory scope (API, assembler, builder).
     *
     * @return array{0: string, 1: string}
     */
    public function normalize(string $scopeType, string $scopeKey): array
    {
        $trimmedScopeType = Str::of($scopeType)->trim()->toString();
        if (! in_array($trimmedScopeType, ['global', 'project', 'insights'], true)) {
            throw new InvalidArgumentException('Invalid scope_type. Allowed values: global, project, insights.');
        }

        $trimmedScopeKey = Str::of($scopeKey)->trim()->toString();
        if ($trimmedScopeKey === '') {
            throw new InvalidArgumentException('Invalid scope_key. scope_key must not be empty.');
        }

        if ($trimmedScopeType === 'global') {
            if ($trimmedScopeKey !== 'global') {
                throw new InvalidArgumentException("Invalid scope_key for global scope. scope_key must be exactly 'global'.");
            }

            return ['global', 'global'];
        }

        if ($trimmedScopeType === 'insights') {
            if ($trimmedScopeKey !== 'global') {
                throw new InvalidArgumentException("Invalid scope_key for insights scope. scope_key must be exactly 'global'.");
            }

            return ['insights', 'global'];
        }

        return ['project', Str::of($trimmedScopeKey)->lower()->toString()];
    }
}
