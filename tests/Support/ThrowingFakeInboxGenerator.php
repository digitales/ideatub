<?php

namespace Tests\Support;

use App\Models\User;
use App\Services\Inbox\Contracts\InboxGenerator;

final class ThrowingFakeInboxGenerator implements InboxGenerator
{
    public function __construct(
        private \Throwable $throwable
    ) {}

    public function generate(User $user): array
    {
        throw $this->throwable;
    }
}
