<?php

namespace App\Policies;

use App\Models\ImportBatch;
use App\Models\User;

class ImportPolicy
{
    public function view(User $user, ImportBatch $batch): bool
    {
        return $user->id === $batch->user_id;
    }

    public function cancel(User $user, ImportBatch $batch): bool
    {
        return $user->id === $batch->user_id;
    }

    public function retryFailed(User $user, ImportBatch $batch): bool
    {
        return $user->id === $batch->user_id;
    }

    public function deleteThoughts(User $user, ImportBatch $batch): bool
    {
        return $user->id === $batch->user_id;
    }
}
