<?php

namespace App\Support\Comments;

final class ShareContext
{
    public function __construct(
        public readonly string $researchThoughtId,
        public readonly int $shareId,
        public readonly bool $allowComments,
    ) {}
}
