<?php

namespace App\Services\Inbox\Contracts;

use App\Models\User;

interface InboxGenerator
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function generate(User $user): array;
}
