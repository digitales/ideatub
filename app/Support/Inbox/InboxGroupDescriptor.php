<?php

namespace App\Support\Inbox;

use App\Models\InboxItem;
use Illuminate\Support\Collection;

final class InboxGroupDescriptor
{
    /**
     * @return list<string>
     */
    public static function bulkActionsFor(string $generatorType): array
    {
        /** @var array<string, list<string>> $map */
        $map = config('inbox.group_bulk_actions', []);

        return $map[$generatorType] ?? $map['default'] ?? ['done_all'];
    }

    public static function actionRequiresConfirmation(string $action): bool
    {
        /** @var list<string> $actions */
        $actions = config('inbox.group_confirm_actions', []);

        return in_array($action, $actions, true);
    }

    /**
     * @param  Collection<int, InboxItem>  $items
     * @return array{title: string, subtitle: string}
     */
    public static function summary(string $generatorType, Collection $items): array
    {
        $count = $items->count();
        $titles = $items->pluck('title')->unique()->values();

        $title = $titles->count() === 1
            ? (string) $titles->first()
            : self::humanizeGeneratorType($generatorType);

        /** @var array<string, string> $subtitles */
        $subtitles = config('inbox.group_subtitles', []);
        $template = $subtitles[$generatorType] ?? $subtitles['default'] ?? ':count items';

        return [
            'title' => $title,
            'subtitle' => str_replace(':count', (string) $count, $template),
        ];
    }

    public static function humanizeGeneratorType(string $generatorType): string
    {
        return ucwords(str_replace('_', ' ', $generatorType));
    }

    public static function bulkActionLabel(string $action): string
    {
        return match ($action) {
            'done_all' => 'Done all',
            'ok_all' => 'OK all',
            'allow_all' => 'Allow all senders',
            'ignore_all' => 'Ignore all senders',
            default => ucwords(str_replace('_', ' ', $action)),
        };
    }

    public static function bulkActionPendingLabel(string $action): string
    {
        return match ($action) {
            'done_all' => 'Marking done...',
            'ok_all' => 'Dismissing...',
            'allow_all' => 'Allowing senders...',
            'ignore_all' => 'Ignoring senders...',
            default => 'Working...',
        };
    }

    public static function bulkActionSuccessMessage(string $action, int $count): string
    {
        return match ($action) {
            'done_all', 'ok_all' => $count === 1
                ? 'Inbox item marked done.'
                : "Marked {$count} inbox items done.",
            'allow_all' => $count === 1
                ? 'Sender allowed.'
                : "Allowed {$count} senders.",
            'ignore_all' => $count === 1
                ? 'Sender ignored.'
                : "Ignored {$count} senders.",
            default => 'Done.',
        };
    }
}
