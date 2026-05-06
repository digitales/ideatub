<?php

namespace App\Services\Fastmail;

use App\Exceptions\InvalidMailAccountCredentialsException;
use App\Models\MailAccount;
use App\Services\Email\NormalizedEmailMessage;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\RequestException;
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
        $response = $this->requestWithCredentialHandling($this->credentialsFor($account), [
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

        $response = $this->requestWithCredentialHandling($this->credentialsFor($account), [
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
                ['Email/get', $this->emailGetArguments($account, [
                    'resultOf' => 'q1',
                    'name' => 'Email/query',
                    'path' => '/ids/*',
                ]), 'g1'],
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
        $batchSize = max(1, (int) config('services.mail_sync.incremental_batch_size', 25));
        $credentials = $this->credentialsFor($account);

        $pending = $checkpoint['pending_incremental_fetch'] ?? null;
        if (is_array($pending) && ($pending['remaining_provider_message_ids'] ?? []) !== []) {
            return $this->fetchIncrementalContinuePending(
                $account,
                $credentials,
                $mailboxId,
                (string) ($checkpoint['query_state'] ?? ''),
                $pending,
                $batchSize
            );
        }

        $response = $this->requestWithCredentialHandling($credentials, [
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
            ],
        ]);

        $queryChangesData = $this->responseData($response, 'Email/queryChanges');
        $newQueryState = (string) ($queryChangesData['newQueryState'] ?? '');
        $addedIds = $this->emailIdsFromQueryChangesAdded($queryChangesData);

        if ($addedIds === []) {
            return [
                'messages' => [],
                'next_checkpoint' => [
                    'query_state' => $newQueryState,
                    'mailbox_id' => $mailboxId,
                ],
            ];
        }

        $idsThisRound = array_slice($addedIds, 0, $batchSize);
        $remainingAfterRound = array_slice($addedIds, $batchSize);

        $getResponse = $this->requestWithCredentialHandling($credentials, [
            'using' => [
                'urn:ietf:params:jmap:core',
                'urn:ietf:params:jmap:mail',
            ],
            'methodCalls' => [
                ['Email/get', array_merge($this->emailGetBaseArguments($account), [
                    'ids' => $idsThisRound,
                ]), 'g1'],
            ],
        ]);

        $messages = $this->normalizeMessages($account, $this->responseData($getResponse, 'Email/get')['list'] ?? []);

        if ($remainingAfterRound !== []) {
            return [
                'messages' => $messages,
                'next_checkpoint' => [
                    'query_state' => (string) ($checkpoint['query_state'] ?? ''),
                    'mailbox_id' => $mailboxId,
                    'pending_incremental_fetch' => [
                        'target_query_state' => $newQueryState,
                        'remaining_provider_message_ids' => array_values($remainingAfterRound),
                    ],
                ],
            ];
        }

        return [
            'messages' => $messages,
            'next_checkpoint' => [
                'query_state' => $newQueryState,
                'mailbox_id' => $mailboxId,
            ],
        ];
    }

    /**
     * @param  array{credential: string, api_url?: string}  $credentials
     * @param  array<string, mixed>  $pending
     * @return array{messages: array<int, NormalizedEmailMessage>, next_checkpoint: array<string, mixed>}
     */
    private function fetchIncrementalContinuePending(
        MailAccount $account,
        array $credentials,
        ?string $mailboxId,
        string $queryStateHeld,
        array $pending,
        int $batchSize
    ): array {
        $remaining = $pending['remaining_provider_message_ids'] ?? [];
        $remaining = is_array($remaining) ? $remaining : [];
        $targetQueryState = (string) ($pending['target_query_state'] ?? '');

        $idsThisRound = array_slice($remaining, 0, $batchSize);
        $newRemaining = array_slice($remaining, $batchSize);

        $getResponse = $this->requestWithCredentialHandling($credentials, [
            'using' => [
                'urn:ietf:params:jmap:core',
                'urn:ietf:params:jmap:mail',
            ],
            'methodCalls' => [
                ['Email/get', array_merge($this->emailGetBaseArguments($account), [
                    'ids' => $idsThisRound,
                ]), 'g1'],
            ],
        ]);

        $messages = $this->normalizeMessages($account, $this->responseData($getResponse, 'Email/get')['list'] ?? []);

        if ($newRemaining !== []) {
            return [
                'messages' => $messages,
                'next_checkpoint' => [
                    'query_state' => $queryStateHeld,
                    'mailbox_id' => $mailboxId,
                    'pending_incremental_fetch' => [
                        'target_query_state' => $targetQueryState,
                        'remaining_provider_message_ids' => array_values($newRemaining),
                    ],
                ],
            ];
        }

        return [
            'messages' => $messages,
            'next_checkpoint' => [
                'query_state' => $targetQueryState,
                'mailbox_id' => $mailboxId,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $queryChangesData
     * @return array<int, string>
     */
    private function emailIdsFromQueryChangesAdded(array $queryChangesData): array
    {
        $ids = [];
        foreach ($queryChangesData['added'] ?? [] as $row) {
            if (is_array($row) && isset($row['id'])) {
                $ids[] = (string) $row['id'];
            } elseif (is_string($row)) {
                $ids[] = $row;
            }
        }

        return $ids;
    }

    public function fetchMessageById(MailAccount $account, string $providerMessageId): ?NormalizedEmailMessage
    {
        $response = $this->requestWithCredentialHandling($this->credentialsFor($account), [
            'using' => [
                'urn:ietf:params:jmap:core',
                'urn:ietf:params:jmap:mail',
            ],
            'methodCalls' => [
                ['Email/get', array_merge($this->emailGetBaseArguments($account), [
                    'ids' => [$providerMessageId],
                ]), 'g1'],
            ],
        ]);

        $list = $this->responseData($response, 'Email/get')['list'] ?? [];
        if ($list === []) {
            return null;
        }

        $messages = $this->normalizeMessages($account, $list);

        return $messages[0] ?? null;
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
     * @return array<string, mixed>
     */
    private function emailGetBaseArguments(MailAccount $account): array
    {
        return [
            'accountId' => $this->accountIdFor($account),
            'properties' => [
                'id',
                'threadId',
                'mailboxIds',
                'subject',
                'from',
                'to',
                'cc',
                'sentAt',
                'receivedAt',
                'textBody',
                'htmlBody',
                'bodyValues',
            ],
            'bodyProperties' => [
                'partId',
                'type',
            ],
            'fetchTextBodyValues' => true,
            'fetchHTMLBodyValues' => true,
        ];
    }

    /**
     * @param  array{resultOf: string, name: string, path: string}  $idsReference
     * @return array<string, mixed>
     */
    private function emailGetArguments(MailAccount $account, array $idsReference): array
    {
        return array_merge($this->emailGetBaseArguments($account), [
            '#ids' => $idsReference,
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $messages
     * @return array<int, NormalizedEmailMessage>
     */
    private function normalizeMessages(MailAccount $account, array $messages): array
    {
        return array_map(function (array $message) use ($account) {
            $mailboxIds = array_keys($message['mailboxIds'] ?? []);
            $bodyText = $this->normalizedBodyTextFromJmapEmail($message);

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
    private function normalizedBodyTextFromJmapEmail(array $message): string
    {
        $bodyValues = $message['bodyValues'] ?? [];
        $bodyValues = is_array($bodyValues) ? $bodyValues : [];

        $textBody = $message['textBody'] ?? [];
        $textBody = is_array($textBody) ? $textBody : [];
        $textChunks = $this->collectBodyValuesForBodyParts($textBody, $bodyValues);
        $plain = implode("\n\n", $textChunks);
        if (trim($plain) !== '') {
            return $plain;
        }

        $htmlBody = $message['htmlBody'] ?? [];
        $htmlBody = is_array($htmlBody) ? $htmlBody : [];
        $htmlChunks = $this->collectBodyValuesForBodyParts($htmlBody, $bodyValues);
        $html = implode("\n\n", $htmlChunks);
        if (trim($html) === '') {
            return '';
        }

        return $this->htmlToPlainTextConservative($html);
    }

    /**
     * @param  array<int, mixed>  $bodyParts
     * @param  array<string, mixed>  $bodyValues
     * @return array<int, string>
     */
    private function collectBodyValuesForBodyParts(array $bodyParts, array $bodyValues): array
    {
        $chunks = [];
        foreach ($bodyParts as $part) {
            if (! is_array($part)) {
                continue;
            }
            $partId = (string) ($part['partId'] ?? '');
            $value = '';
            if ($partId !== '' && isset($bodyValues[$partId]) && is_array($bodyValues[$partId])) {
                $value = (string) ($bodyValues[$partId]['value'] ?? '');
            }
            if ($value === '') {
                $value = (string) ($part['value'] ?? '');
            }
            if ($value !== '') {
                $chunks[] = $value;
            }
        }

        return $chunks;
    }

    private function htmlToPlainTextConservative(string $html): string
    {
        $decoded = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $stripped = strip_tags($decoded);
        $withNewlines = preg_replace('/\R+/u', "\n", $stripped) ?? $stripped;
        $collapsed = preg_replace('/[ \t]+/u', ' ', $withNewlines) ?? $withNewlines;

        return trim($collapsed);
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

    /**
     * @param  array{credential: string, api_url?: string}  $credentials
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function requestWithCredentialHandling(array $credentials, array $payload): array
    {
        try {
            return $this->httpClient->request($credentials, $payload);
        } catch (RequestException $exception) {
            $status = $exception->response?->status();

            if ($status === 401 || $status === 403) {
                throw new InvalidMailAccountCredentialsException('Fastmail credentials are invalid or expired.', 0, $exception);
            }

            throw $exception;
        }
    }
}
