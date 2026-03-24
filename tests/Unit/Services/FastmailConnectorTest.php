<?php

namespace Tests\Unit\Services;

use App\Exceptions\InvalidMailAccountCredentialsException;
use App\Models\MailAccount;
use App\Services\Email\NormalizedEmailMessage;
use App\Services\Fastmail\FastmailConnector;
use App\Services\Fastmail\FastmailHttpClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FastmailConnectorTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, mixed>|null
     */
    private function emailGetArgumentsFromJmapRequest(Request $request): ?array
    {
        foreach ($request['methodCalls'] ?? [] as $call) {
            if (($call[0] ?? null) === 'Email/get') {
                $args = $call[1] ?? null;

                return is_array($args) ? $args : null;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function assertEmailGetRequestsExplicitJmapBodyValues(array $arguments): void
    {
        foreach (['textBody', 'htmlBody', 'bodyValues'] as $property) {
            $this->assertContains(
                $property,
                $arguments['properties'] ?? [],
                'Email/get should request '.$property.' in properties.'
            );
        }

        foreach (['partId', 'type'] as $bodyProperty) {
            $this->assertContains(
                $bodyProperty,
                $arguments['bodyProperties'] ?? [],
                'Email/get should request '.$bodyProperty.' in bodyProperties.'
            );
        }

        $this->assertTrue(
            (bool) ($arguments['fetchTextBodyValues'] ?? false),
            'Email/get should set fetchTextBodyValues true.'
        );
        $this->assertTrue(
            (bool) ($arguments['fetchHTMLBodyValues'] ?? false),
            'Email/get should set fetchHTMLBodyValues true.'
        );
    }

    #[Test]
    public function fastmail_http_client_sends_validated_session_request(): void
    {
        Http::fake([
            'https://api.fastmail.com/jmap/session' => Http::response([
                'apiUrl' => 'https://api.fastmail.com/jmap/api/',
            ], 200),
        ]);

        $client = app(FastmailHttpClient::class);
        $result = $client->discoverSession([
            'credential' => 'secret',
        ]);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.fastmail.com/jmap/session'
                && $request->method() === 'GET'
                && $request->hasHeader('Authorization', 'Bearer secret')
                && $request->hasHeader('Accept', 'application/json');
        });

        $this->assertSame('https://api.fastmail.com/jmap/api/', $result['apiUrl']);
    }

    #[Test]
    public function validate_credentials_returns_normalized_account_details(): void
    {
        Http::fake([
            'https://api.fastmail.com/jmap/session' => Http::response([
                'username' => 'owner@fastmail.fm',
                'apiUrl' => 'https://api.fastmail.com/jmap/api/',
                'accounts' => [
                    'u123' => [
                        'name' => 'owner@fastmail.fm',
                    ],
                ],
                'primaryAccounts' => [
                    'urn:ietf:params:jmap:mail' => 'u123',
                ],
            ], 200),
        ]);

        $connector = app(FastmailConnector::class);

        $result = $connector->validateCredentials([
            'account_email' => 'owner@fastmail.fm',
            'credential' => 'secret',
        ]);

        $this->assertSame('owner@fastmail.fm', $result['account_email']);
        $this->assertSame('u123', $result['account_id']);
        $this->assertSame([], $result['aliases']);
    }

    #[Test]
    public function validate_credentials_throws_when_session_username_does_not_match_requested_account(): void
    {
        Http::fake([
            'https://api.fastmail.com/jmap/session' => Http::response([
                'username' => 'different@fastmail.fm',
                'apiUrl' => 'https://api.fastmail.com/jmap/api/',
                'primaryAccounts' => [
                    'urn:ietf:params:jmap:mail' => 'u123',
                ],
            ], 200),
        ]);

        $connector = app(FastmailConnector::class);

        $this->expectException(InvalidMailAccountCredentialsException::class);
        $this->expectExceptionMessage('Use the primary Fastmail account email for this API token.');

        $connector->validateCredentials([
            'account_email' => 'owner@fastmail.fm',
            'credential' => 'secret',
        ]);
    }

    #[Test]
    public function list_mailboxes_returns_mailbox_ids_and_names(): void
    {
        Http::fake([
            'https://api.fastmail.com/jmap/api/' => Http::response([
                'methodResponses' => [
                    ['Mailbox/get', [
                        'list' => [
                            ['id' => 'mb-inbox', 'name' => 'Inbox'],
                            ['id' => 'mb-sent', 'name' => 'Sent'],
                        ],
                    ], 'm1'],
                ],
            ], 200),
        ]);

        $account = MailAccount::factory()->create();
        $connector = app(FastmailConnector::class);

        $mailboxes = $connector->listMailboxes($account);

        $this->assertSame([
            ['id' => 'mb-inbox', 'name' => 'Inbox'],
            ['id' => 'mb-sent', 'name' => 'Sent'],
        ], $mailboxes);
    }

    #[Test]
    public function fetch_backfill_batch_returns_normalized_messages_and_next_checkpoint(): void
    {
        Http::fake([
            'https://api.fastmail.com/jmap/api/' => Http::response([
                'methodResponses' => [
                    ['Email/query', [
                        'ids' => ['msg-1'],
                        'queryState' => 'state-1',
                    ], 'q1'],
                    ['Email/get', [
                        'list' => [
                            [
                                'id' => 'msg-1',
                                'threadId' => 'thread-1',
                                'mailboxIds' => ['mb-inbox' => true],
                                'keywords' => ['$seen' => true],
                                'subject' => 'Hello world',
                                'from' => [['email' => 'sender@example.com', 'name' => 'Sender']],
                                'to' => [['email' => 'owner@fastmail.fm', 'name' => 'Owner']],
                                'cc' => [],
                                'sentAt' => '2026-03-20T10:00:00Z',
                                'receivedAt' => '2026-03-20T10:00:05Z',
                                'textBody' => [['partId' => '1', 'type' => 'text/plain', 'value' => 'Body text']],
                            ],
                        ],
                    ], 'g1'],
                ],
            ], 200),
        ]);

        $account = MailAccount::factory()->create();
        $connector = app(FastmailConnector::class);

        $result = $connector->fetchBackfillBatch($account, [
            'mailbox_id' => 'mb-inbox',
            'limit' => 25,
        ]);

        $this->assertCount(1, $result['messages']);
        $this->assertSame('msg-1', $result['messages'][0]->providerMessageId);
        $this->assertSame('thread-1', $result['messages'][0]->providerThreadId);
        $this->assertSame('Hello world', $result['messages'][0]->subject);
        $this->assertSame('state-1', $result['next_checkpoint']['query_state']);
        $this->assertSame('mb-inbox', $result['next_checkpoint']['mailbox_id']);
        Http::assertSent(function ($request) {
            if ($request->url() !== 'https://api.fastmail.com/jmap/api/') {
                return false;
            }

            $emailGet = $this->emailGetArgumentsFromJmapRequest($request);
            if ($emailGet === null) {
                return false;
            }

            $this->assertEmailGetRequestsExplicitJmapBodyValues($emailGet);

            return ($emailGet['#ids']['resultOf'] ?? null) === 'q1'
                && ($emailGet['#ids']['name'] ?? null) === 'Email/query'
                && ($emailGet['#ids']['path'] ?? null) === '/ids/*';
        });
    }

    #[Test]
    public function fetch_incremental_batch_returns_normalized_messages_and_next_checkpoint(): void
    {
        Http::fake([
            'https://api.fastmail.com/jmap/api/' => Http::response([
                'methodResponses' => [
                    ['Email/queryChanges', [
                        'removed' => [],
                        'added' => [
                            ['id' => 'msg-2'],
                        ],
                        'newQueryState' => 'state-2',
                    ], 'c1'],
                    ['Email/get', [
                        'list' => [
                            [
                                'id' => 'msg-2',
                                'threadId' => 'thread-2',
                                'mailboxIds' => ['mb-sent' => true],
                                'keywords' => ['$draft' => false],
                                'subject' => 'Sent hello',
                                'from' => [['email' => 'owner@fastmail.fm', 'name' => 'Owner']],
                                'to' => [['email' => 'friend@example.com', 'name' => 'Friend']],
                                'cc' => [],
                                'sentAt' => '2026-03-20T11:00:00Z',
                                'receivedAt' => '2026-03-20T11:00:01Z',
                                'textBody' => [['partId' => '1', 'type' => 'text/plain', 'value' => 'Sent body']],
                            ],
                        ],
                    ], 'g1'],
                ],
            ], 200),
        ]);

        $account = MailAccount::factory()->create([
            'provider_checkpoint_json' => [
                'query_state' => 'state-1',
                'mailbox_id' => 'mb-sent',
            ],
        ]);
        $connector = app(FastmailConnector::class);

        $result = $connector->fetchIncrementalBatch($account);

        $this->assertCount(1, $result['messages']);
        $this->assertSame('msg-2', $result['messages'][0]->providerMessageId);
        $this->assertSame('Sent body', $result['messages'][0]->bodyText);
        $this->assertSame('state-2', $result['next_checkpoint']['query_state']);
        $this->assertSame('mb-sent', $result['next_checkpoint']['mailbox_id']);
        Http::assertSent(function ($request) {
            if ($request->url() !== 'https://api.fastmail.com/jmap/api/') {
                return false;
            }

            $emailGet = $this->emailGetArgumentsFromJmapRequest($request);
            if ($emailGet === null) {
                return false;
            }

            $this->assertEmailGetRequestsExplicitJmapBodyValues($emailGet);

            return ($emailGet['#ids']['resultOf'] ?? null) === 'c1'
                && ($emailGet['#ids']['name'] ?? null) === 'Email/queryChanges'
                && ($emailGet['#ids']['path'] ?? null) === '/added/*/id';
        });
    }

    #[Test]
    public function normalization_assembles_text_body_from_body_values_using_text_body_part_ids(): void
    {
        Http::fake([
            'https://api.fastmail.com/jmap/api/' => Http::response([
                'methodResponses' => [
                    ['Email/query', [
                        'ids' => ['msg-bodyvalues'],
                        'queryState' => 'state-bv',
                    ], 'q1'],
                    ['Email/get', [
                        'list' => [
                            [
                                'id' => 'msg-bodyvalues',
                                'threadId' => 'thread-bv',
                                'mailboxIds' => ['mb-inbox' => true],
                                'subject' => 'BodyValues subject',
                                'from' => [['email' => 'a@example.com', 'name' => 'A']],
                                'to' => [['email' => 'owner@fastmail.fm', 'name' => 'Owner']],
                                'cc' => [],
                                'sentAt' => '2026-03-20T12:00:00Z',
                                'receivedAt' => '2026-03-20T12:00:01Z',
                                'textBody' => [
                                    ['partId' => '2', 'type' => 'text/plain'],
                                ],
                                'bodyValues' => [
                                    '2' => ['value' => 'Plain from bodyValues'],
                                ],
                            ],
                        ],
                    ], 'g1'],
                ],
            ], 200),
        ]);

        $account = MailAccount::factory()->create();
        $connector = app(FastmailConnector::class);

        $result = $connector->fetchBackfillBatch($account, ['mailbox_id' => 'mb-inbox']);

        $this->assertSame('Plain from bodyValues', $result['messages'][0]->bodyText);
    }

    #[Test]
    public function normalization_concatenates_multiple_text_body_parts_in_order(): void
    {
        Http::fake([
            'https://api.fastmail.com/jmap/api/' => Http::response([
                'methodResponses' => [
                    ['Email/query', ['ids' => ['msg-multi'], 'queryState' => 's'], 'q1'],
                    ['Email/get', [
                        'list' => [
                            [
                                'id' => 'msg-multi',
                                'threadId' => 't',
                                'mailboxIds' => ['mb-inbox' => true],
                                'subject' => 'Multi',
                                'from' => [['email' => 'x@example.com', 'name' => 'X']],
                                'to' => [['email' => 'owner@fastmail.fm', 'name' => 'O']],
                                'cc' => [],
                                'sentAt' => '2026-03-20T12:00:00Z',
                                'receivedAt' => '2026-03-20T12:00:01Z',
                                'textBody' => [
                                    ['partId' => 'a', 'type' => 'text/plain'],
                                    ['partId' => 'b', 'type' => 'text/plain'],
                                ],
                                'bodyValues' => [
                                    'a' => ['value' => 'First part'],
                                    'b' => ['value' => 'Second part'],
                                ],
                            ],
                        ],
                    ], 'g1'],
                ],
            ], 200),
        ]);

        $account = MailAccount::factory()->create();
        $connector = app(FastmailConnector::class);

        $result = $connector->fetchBackfillBatch($account, []);

        $this->assertSame("First part\n\nSecond part", $result['messages'][0]->bodyText);
    }

    #[Test]
    public function normalization_falls_back_to_html_body_from_body_values_when_no_text_body(): void
    {
        Http::fake([
            'https://api.fastmail.com/jmap/api/' => Http::response([
                'methodResponses' => [
                    ['Email/query', ['ids' => ['msg-html'], 'queryState' => 's'], 'q1'],
                    ['Email/get', [
                        'list' => [
                            [
                                'id' => 'msg-html',
                                'threadId' => 't',
                                'mailboxIds' => ['mb-inbox' => true],
                                'subject' => 'HTML only',
                                'from' => [['email' => 'h@example.com', 'name' => 'H']],
                                'to' => [['email' => 'owner@fastmail.fm', 'name' => 'O']],
                                'cc' => [],
                                'sentAt' => '2026-03-20T12:00:00Z',
                                'receivedAt' => '2026-03-20T12:00:01Z',
                                'textBody' => [],
                                'htmlBody' => [
                                    ['partId' => 'h1', 'type' => 'text/html'],
                                ],
                                'bodyValues' => [
                                    'h1' => ['value' => '<p>Hello &amp; <b>welcome</b></p>'],
                                ],
                            ],
                        ],
                    ], 'g1'],
                ],
            ], 200),
        ]);

        $account = MailAccount::factory()->create();
        $connector = app(FastmailConnector::class);

        $result = $connector->fetchBackfillBatch($account, []);

        $this->assertSame('Hello & welcome', $result['messages'][0]->bodyText);
    }

    #[Test]
    public function normalization_yields_empty_body_when_body_values_missing_without_error(): void
    {
        Http::fake([
            'https://api.fastmail.com/jmap/api/' => Http::response([
                'methodResponses' => [
                    ['Email/query', ['ids' => ['msg-empty'], 'queryState' => 's'], 'q1'],
                    ['Email/get', [
                        'list' => [
                            [
                                'id' => 'msg-empty',
                                'threadId' => 't',
                                'mailboxIds' => ['mb-inbox' => true],
                                'subject' => 'No body',
                                'from' => [['email' => 'n@example.com', 'name' => 'N']],
                                'to' => [['email' => 'owner@fastmail.fm', 'name' => 'O']],
                                'cc' => [],
                                'sentAt' => '2026-03-20T12:00:00Z',
                                'receivedAt' => '2026-03-20T12:00:01Z',
                                'textBody' => [
                                    ['partId' => 'x', 'type' => 'text/plain'],
                                ],
                            ],
                        ],
                    ], 'g1'],
                ],
            ], 200),
        ]);

        $account = MailAccount::factory()->create();
        $connector = app(FastmailConnector::class);

        $result = $connector->fetchBackfillBatch($account, []);

        $this->assertSame('', $result['messages'][0]->bodyText);
    }

    #[Test]
    public function fetch_message_by_id_returns_normalized_message_when_found(): void
    {
        Http::fake([
            'https://api.fastmail.com/jmap/api/' => Http::response([
                'methodResponses' => [
                    ['Email/get', [
                        'list' => [
                            [
                                'id' => 'msg-by-id',
                                'threadId' => 'thread-by-id',
                                'mailboxIds' => ['mb-inbox' => true],
                                'subject' => 'Single fetch',
                                'from' => [['email' => 'sender@example.com', 'name' => 'Sender']],
                                'to' => [['email' => 'owner@fastmail.fm', 'name' => 'Owner']],
                                'cc' => [],
                                'sentAt' => '2026-03-20T10:00:00Z',
                                'receivedAt' => '2026-03-20T10:00:05Z',
                                'textBody' => [['partId' => '1', 'type' => 'text/plain', 'value' => 'Single body']],
                            ],
                        ],
                    ], 'g1'],
                ],
            ], 200),
        ]);

        $account = MailAccount::factory()->create();
        $connector = app(FastmailConnector::class);

        $message = $connector->fetchMessageById($account, 'msg-by-id');

        $this->assertInstanceOf(NormalizedEmailMessage::class, $message);
        $this->assertSame('msg-by-id', $message->providerMessageId);
        $this->assertSame('Single fetch', $message->subject);
        $this->assertSame('Single body', $message->bodyText);

        Http::assertSent(function ($request) use ($account) {
            if ($request->url() !== 'https://api.fastmail.com/jmap/api/') {
                return false;
            }

            $calls = $request['methodCalls'] ?? [];
            if (count($calls) !== 1 || ($calls[0][0] ?? null) !== 'Email/get') {
                return false;
            }

            $args = $calls[0][1] ?? [];
            if (! is_array($args)) {
                return false;
            }

            $this->assertSame(
                (string) ($account->credentials_json['account_id'] ?? ''),
                (string) ($args['accountId'] ?? '')
            );
            $this->assertSame(['msg-by-id'], $args['ids'] ?? null);
            $this->assertArrayNotHasKey('#ids', $args);
            $this->assertEmailGetRequestsExplicitJmapBodyValues($args);

            return true;
        });
    }

    #[Test]
    public function fetch_message_by_id_returns_null_when_not_found(): void
    {
        Http::fake([
            'https://api.fastmail.com/jmap/api/' => Http::response([
                'methodResponses' => [
                    ['Email/get', [
                        'list' => [],
                        'notFound' => ['msg-missing'],
                    ], 'g1'],
                ],
            ], 200),
        ]);

        $account = MailAccount::factory()->create();
        $connector = app(FastmailConnector::class);

        $this->assertNull($connector->fetchMessageById($account, 'msg-missing'));
    }
}
