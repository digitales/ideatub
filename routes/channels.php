<?php

use App\Models\ImportBatch;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('import.{batchId}', function ($user, string $batchId) {
    $batch = ImportBatch::query()->find($batchId);

    return $batch !== null && (int) $batch->user_id === (int) $user->id;
});
