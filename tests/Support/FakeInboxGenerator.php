<?php

namespace Tests\Support;

use App\Models\User;
use App\Services\Inbox\Contracts\InboxGenerator;

final class FakeInboxGenerator implements InboxGenerator
{
    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    public function __construct(
        private array $items = [],
        private ?\Throwable $throwOnGenerate = null
    ) {}

    public function generate(User $user): array
    {
        if ($this->throwOnGenerate !== null) {
            throw $this->throwOnGenerate;
        }

        return $this->items;
    }
}
