<?php

namespace App\Services\Inbox;

use App\Models\InboxItem;
use App\Models\User;
use App\Services\Inbox\Contracts\InboxGenerator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;
use Throwable;

class InboxGenerationService
{
    public function generateForUser(User $user): int
    {
        $max = max(0, (int) config('inbox.max_new_items_per_user_per_run', 5));
        /** @var array<int, mixed> $generatorClasses */
        $generatorClasses = config('inbox.generators', []);
        if (! is_array($generatorClasses)) {
            $generatorClasses = [];
        }

        $pendingKeys = InboxItem::query()
            ->where('user_id', $user->id)
            ->where('status', 'pending')
            ->pluck('dedupe_key')
            ->all();

        $pendingSet = array_flip($pendingKeys);

        $created = 0;

        foreach ($generatorClasses as $class) {
            if ($created >= $max) {
                break;
            }

            if (! is_string($class) || $class === '') {
                continue;
            }

            try {
                $generator = app($class);
                if (! $generator instanceof InboxGenerator) {
                    Log::error('Inbox generator is not an instance of InboxGenerator.', [
                        'generator' => $class,
                        'user_id' => $user->id,
                    ]);

                    continue;
                }

                $items = $generator->generate($user);
            } catch (Throwable $e) {
                report($e);
                Log::error('Inbox generator failed: '.$e->getMessage(), [
                    'exception' => $e,
                    'generator' => $class,
                    'user_id' => $user->id,
                ]);

                continue;
            }

            if (! is_array($items)) {
                continue;
            }

            foreach ($items as $payload) {
                if ($created >= $max) {
                    break 2;
                }

                if (! is_array($payload)) {
                    continue;
                }

                $dedupeKey = $payload['dedupe_key'] ?? null;
                if (! is_string($dedupeKey) || $dedupeKey === '') {
                    continue;
                }

                if (isset($pendingSet[$dedupeKey])) {
                    continue;
                }

                try {
                    InboxItem::create([
                        'user_id' => $user->id,
                        'generator_type' => (string) ($payload['generator_type'] ?? ''),
                        'title' => (string) ($payload['title'] ?? ''),
                        'body' => (string) ($payload['body'] ?? ''),
                        'status' => 'pending',
                        'snoozed_until' => null,
                        'generated_at' => $payload['generated_at'] ?? now(),
                        'actioned_at' => null,
                        'dedupe_key' => $dedupeKey,
                        'source_data' => $payload['source_data'] ?? null,
                    ]);
                } catch (UniqueConstraintViolationException) {
                    $pendingSet[$dedupeKey] = true;

                    continue;
                }

                $pendingSet[$dedupeKey] = true;
                $created++;
            }
        }

        return $created;
    }
}
