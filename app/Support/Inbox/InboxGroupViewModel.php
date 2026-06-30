<?php

namespace App\Support\Inbox;

use App\Models\InboxItem;
use Illuminate\Support\Collection;

final class InboxGroupViewModel
{
    /**
     * @param  Collection<int, InboxItem>  $items
     * @param  list<string>  $bulkActions
     */
    public function __construct(
        public readonly string $generatorType,
        public readonly string $title,
        public readonly string $subtitle,
        public readonly Collection $items,
        public readonly array $bulkActions,
    ) {}
}
