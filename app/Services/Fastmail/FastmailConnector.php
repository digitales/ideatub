<?php

namespace App\Services\Fastmail;

use App\Exceptions\InvalidMailAccountCredentialsException;
use App\Models\MailAccount;
use App\Services\Email\NormalizedEmailMessage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Throwable;

class FastmailConnector
{
    public function __construct(
        private readonly FastmailHttpClient $httpClient
    ) {}

    /**
     * @param  array{account_email: string, credential: string}  $input
     * @return array{account_email: string, account_id: string, aliases: array<int, string>, api_url: string}
     */
    public function validateCredentials(array $input): array
    {
        $credential = trim((string) ($input['credential'] ?? ''));
        if ($credential === '') {
            throw new InvalidMailAccountCredentialsException('Fastmail API token is required.');
        }

        try {
            $payload = $this->httpClient->discoverSession([
                'credential' => $credential,
            ]);
        } catch (Throwable) {
            throw new InvalidMailAccountCredentialsException('Unable to validate Fastmail credentials.');
        }
        
        $accountEmail = (string) ($payload['username'] ?? $input['account_email'] ?? '');
        $accountId = (string) data_get($payload, 'primaryAccounts.urn:ietf:params:jmap:mail', '');
        $apiUrl = (string) ($payload['apiUrl'] ?? '');

        if ($accountEmail === '' || $accountId === '' || $apiUrl === '') {
            throw new InvalidMailAccountCredentialsException('Fastmail session response is missing required fields.');
        }

        $requestedEmail = mb_strtolower(trim((string) ($input['account_email'] ?? '')));
        $sessionEmail = mb_strtolower(trim($accountEmail));
        if ($requestedEmail !== '' && $requestedEmail !== $sessionEmail) {
            throw new InvalidMailAccountCredentialsException('Use the primary Fastmail account email for this API token.');
        }

        return [
            'account_email' => $sessionEmail,
            'account_id' => $accountId,
            'aliases' => [],
            'api_url' => $apiUrl,
        ];
    }

    /**
     * @return array<int, array{id: string, name: string}>
     */
    public function listMailboxes(MailAccount $account): array
    {
        $response = $this->httpClient->request($this->credentialsFor($account), [
            'using' => [
                'urn:ietf:params:jmap:core',
                'urn:ietf:params:jmap:mail',
            ],
            'methodCalls' => [
                ['Mailbox/get', [
                    'accountId' => $this->accountIdFor($account),
                ], 'm1'],
            ],
        ]);

        $mailboxes = $this->responseData($response, 'Mailbox/get')['list'] ?? [];

        return array_map(static fn (array $mailbox) => [
            'id' => (string) $mailbox['id'],
            'name' => (string) $mailbox['name'],
        ], $mailboxes);
    }

    /**
     * @param  array{mailbox_id?: string, limit?: int}  $options
     * @return array{messages: array<int, NormalizedEmailMessage>, next_checkpoint: array<string, mixed>}
     */
    public function fetchBackfillBatch(MailAccount $account, array $options): array
    {
        $limit = (int) ($options['limit'] ?? config('services.mail_sync.backfill_batch_size', 50));
        $mailboxId = $options['mailbox_id'] ?? null;

        $response = $this->httpClient->request($this->credentialsFor($account), [
            'using' => [
                'urn:ietf:params:jmap:core',
                'urn:ietf:params:jmap:mail',
            ],
            'methodCalls' => [
                ['Email/query', array_filter([
                    'accountId' => $this->accountIdFor($account),
                    'filter' => $mailboxId ? ['inMailbox' => $mailboxId] : null,
                    'sort' => [['property' => 'receivedAt', 'isAscending' => false]],
                    'limit' => $limit,
                ], static fn ($value) => $value !== null), 'q1'],
                ['Email/get', [
                    'accountId' => $this->accountIdFor($account),
                    '#ids' => [
                        'resultOf' => 'q1',
                        'name' => 'Email/query',
                        'path' => '/ids/*',
                    ],
                ], 'g1'],
            ],
        ]);

        $messages = $this->normalizeMessages($account, $this->responseData($response, 'Email/get')['list'] ?? []);
        $queryState = (string) ($this->responseData($response, 'Email/query')['queryState'] ?? '');

        return [
            'messages' => $messages,
            'next_checkpoint' => [
                'query_state' => $queryState,
                'mailbox_id' => $mailboxId,
            ],
        ];
    }

    /**
     * @return array{messages: array<int, NormalizedEmailMessage>, next_checkpoint: array<string, mixed>}
     */
    public function fetchIncrementalBatch(MailAccount $account): array
    {
        $checkpoint = $account->provider_checkpoint_json ?? [];
        $mailboxId = $checkpoint['mailbox_id'] ?? null;

        $response = $this->httpClient->request($this->credentialsFor($account), [
            'using' => [
                'urn:ietf:params:jmap:core',
                'urn:ietf:params:jmap:mail',
            ],
            'methodCalls' => [
                ['Email/queryChanges', array_filter([
                    'accountId' => $this->accountIdFor($account),
                    'sinceQueryState' => $checkpoint['query_state'] ?? '',
                    'filter' => $mailboxId ? ['inMailbox' => $mailboxId] : null,
                ], static fn ($value) => $value !== null), 'c1'],
                ['Email/get', [
                    'accountId' => $this->accountIdFor($account),
                    '#ids' => [
                        'resultOf' => 'c1',
                        'name' => 'Email/queryChanges',
                        'path' => '/added/*/id',
                    ],
                ], 'g1'],
            ],
        ]);

        $messages = $this->normalizeMessages($account, $this->responseData($response, 'Email/get')['list'] ?? []);
        $newQueryState = (string) ($this->responseData($response, 'Email/queryChanges')['newQueryState'] ?? '');

        return [
            'messages' => $messages,
            'next_checkpoint' => [
                'query_state' => $newQueryState,
                'mailbox_id' => $mailboxId,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     */
    private function responseData(array $response, string $methodName): array
    {
        foreach (($response['methodResponses'] ?? []) as $methodResponse) {
            if (($methodResponse[0] ?? null) === $methodName) {
                return $methodResponse[1] ?? [];
            }
        }

        return [];
    }

    /**
     * @param  array<int, array<string, mixed>>  $messages
     * @return array<int, NormalizedEmailMessage>
     */
    private function normalizeMessages(MailAccount $account, array $messages): array
    {
        return array_map(function (array $message) use ($account) {
            $mailboxIds = array_keys($message['mailboxIds'] ?? []);
            $bodyParts = $message['textBody'] ?? [];
            $bodyText = (string) Arr::get($bodyParts, '0.value', '');

            return new NormalizedEmailMessage(
                providerMessageId: (string) $message['id'],
                providerThreadId: $message['threadId'] ?? null,
                providerMailboxIds: $mailboxIds,
                direction: $this->detectDirection($account, $message),
                subject: $message['subject'] ?? null,
                from: $message['from'] ?? [],
                to: $message['to'] ?? [],
                cc: $message['cc'] ?? [],
                sentAt: isset($message['sentAt']) ? CarbonImmutable::parse($message['sentAt']) : null,
                receivedAt: isset($message['receivedAt']) ? CarbonImmutable::parse($message['receivedAt']) : null,
                bodyText: $bodyText,
            );
        }, $messages);
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function detectDirection(MailAccount $account, array $message): string
    {
        $accountAddresses = array_filter(array_map('mb_strtolower', array_merge(
            [$account->account_email],
            $account->settings_json['aliases'] ?? []
        )));

        foreach (($message['from'] ?? []) as $from) {
            $fromEmail = mb_strtolower((string) ($from['email'] ?? ''));
            if ($fromEmail !== '' && in_array($fromEmail, $accountAddresses, true)) {
                return 'sent';
            }
        }

        return 'received';
    }

    /**
     * @return array{credential: string, api_url: string}
     */
    private function credentialsFor(MailAccount $account): array
    {
        return [
            'credential' => (string) ($account->credentials_json['credential'] ?? ''),
            'api_url' => (string) ($account->credentials_json['api_url'] ?? 'https://api.fastmail.com/jmap/api/'),
        ];
    }

    private function accountIdFor(MailAccount $account): string
    {
        return (string) ($account->credentials_json['account_id'] ?? '');
    }
}
