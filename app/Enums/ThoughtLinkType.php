<?php

namespace App\Enums;

enum ThoughtLinkType: string
{
    case RelatesTo = 'relates_to';
    case SpawnedFrom = 'spawned_from';
    case Supports = 'supports';
    case Contradicts = 'contradicts';
    case Supersedes = 'supersedes';
}
