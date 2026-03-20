<?php

namespace App\Services\Email;

use Carbon\CarbonImmutable;

readonly class NormalizedEmailMessage
{
    /**
     * @param  array<int, array<string, mixed>>  $from
     * @param  array<int, array<string, mixed>>  $to
     * @param  array<int, array<string, mixed>>  $cc
     * @param  array<int, string>  $providerMailboxIds
     */
    public function __construct(
        public string $providerMessageId,
        public ?string $providerThreadId,
        public array $providerMailboxIds,
        public string $direction,
        public ?string $subject,
        public array $from,
        public array $to,
        public array $cc,
        public ?CarbonImmutable $sentAt,
        public ?CarbonImmutable $receivedAt,
        public string $bodyText,
    ) {}
}
