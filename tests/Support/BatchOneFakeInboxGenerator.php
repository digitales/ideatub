<?php

namespace Tests\Support;

use App\Models\User;
use App\Services\Inbox\Contracts\InboxGenerator;

final class BatchOneFakeInboxGenerator implements InboxGenerator
{
    /**
     * @var array<int, array<string, mixed>>
     */
    public static array $payloads = [];

    public function generate(User $user): array
    {
        return self::$payloads;
    }
}
