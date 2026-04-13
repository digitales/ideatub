<?php

namespace App\Enums;

enum ThoughtLinkType: string
{
    case RelatesTo = 'relates_to';
    case SpawnedFrom = 'spawned_from';
    case Supports = 'supports';
    case Contradicts = 'contradicts';
    case Supersedes = 'supersedes';

    public function label(): string
    {
        return match ($this) {
            self::RelatesTo => 'Relates to',
            self::SpawnedFrom => 'Spawned from',
            self::Supports => 'Supports',
            self::Contradicts => 'Contradicts',
            self::Supersedes => 'Supersedes',
        };
    }
}
