<?php

namespace Tests\Unit\Services;

use App\Exceptions\InvalidMailAccountCredentialsException;
use App\Models\MailAccount;
use App\Services\Fastmail\FastmailConnector;
use App\Services\Fastmail\FastmailHttpClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FastmailConnectorTest extends TestCase
{
    use RefreshDatabase;

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
            return $request->url() === 'https://api.fastmail.com/jmap/api/'
                && $request['methodCalls'][1][0] === 'Email/get'
                && ($request['methodCalls'][1][1]['#ids']['resultOf'] ?? null) === 'q1'
                && ($request['methodCalls'][1][1]['#ids']['name'] ?? null) === 'Email/query'
                && ($request['methodCalls'][1][1]['#ids']['path'] ?? null) === '/ids/*';
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
            return $request->url() === 'https://api.fastmail.com/jmap/api/'
                && $request['methodCalls'][1][0] === 'Email/get'
                && ($request['methodCalls'][1][1]['#ids']['resultOf'] ?? null) === 'c1'
                && ($request['methodCalls'][1][1]['#ids']['name'] ?? null) === 'Email/queryChanges'
                && ($request['methodCalls'][1][1]['#ids']['path'] ?? null) === '/added/*/id';
        });
    }
}
