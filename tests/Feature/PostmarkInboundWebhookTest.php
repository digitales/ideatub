<?php

namespace Tests\Feature;

use App\Models\Thought;
use App\Models\UnmatchedInboundEmail;
use App\Models\User;
use App\Models\UserInboundAddress;
use App\Services\OpenRouterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PostmarkInboundWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const WEBHOOK_SECRET = 'test-secret-123';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.postmark_inbound.webhook_secret' => self::WEBHOOK_SECRET]);
    }

    private function webhookUrl(string $token = self::WEBHOOK_SECRET): string
    {
        return '/webhooks/postmark/inbound/'.$token;
    }

    private function minimalPayload(array $overrides = []): array
    {
        return array_merge([
            'From' => 'sender@example.com',
            'MessageID' => '73e6d360-66eb-11e1-8e72-a8904824019b',
            'TextBody' => '',
            'HtmlBody' => '',
        ], $overrides);
    }

    public function test_wrong_token_returns_404(): void
    {
        $response = $this->postJson($this->webhookUrl('wrong-token'), $this->minimalPayload(['TextBody' => 'Hi']));

        $response->assertStatus(404);
        $this->assertDatabaseCount('thoughts', 0);
        $this->assertDatabaseCount('unmatched_inbound_emails', 0);
    }

    public function test_empty_body_returns_200_and_no_thought(): void
    {
        User::factory()->create(['email' => 'sender@example.com']);

        $response = $this->postJson($this->webhookUrl(), $this->minimalPayload());

        $response->assertStatus(200);
        $this->assertDatabaseCount('thoughts', 0);
        $this->assertDatabaseCount('unmatched_inbound_emails', 0);
    }

    public function test_matched_user_creates_thought(): void
    {
        User::factory()->create(['email' => 'sender@example.com']);
        $fakeEmbedding = array_fill(0, 1536, 0.01);
        $this->mock(OpenRouterService::class, function ($mock) use ($fakeEmbedding): void {
            $mock->shouldReceive('embed')->once()->with('Hello')->andReturn($fakeEmbedding);
            $mock->shouldReceive('extractMetadata')->once()->with('Hello')->andReturn(['tags' => []]);
        });

        $response = $this->postJson($this->webhookUrl(), $this->minimalPayload([
            'TextBody' => 'Hello',
            'MessageID' => 'msg-123',
        ]));

        $response->assertStatus(200);
        $this->assertDatabaseCount('thoughts', 1);
        $thought = Thought::first();
        $this->assertSame('Hello', $thought->content);
        $this->assertSame('email', $thought->source);
        $this->assertSame('msg-123', $thought->source_metadata['message_id'] ?? null);
        $this->assertSame('sender@example.com', $thought->source_metadata['from'] ?? null);
    }

    public function test_unmatched_sender_stores_in_unmatched(): void
    {
        $response = $this->postJson($this->webhookUrl(), $this->minimalPayload([
            'From' => 'unknown@example.com',
            'TextBody' => 'Hi',
            'MessageID' => 'msg-456',
        ]));

        $response->assertStatus(200);
        $this->assertDatabaseCount('thoughts', 0);
        $this->assertDatabaseCount('unmatched_inbound_emails', 1);
        $unmatched = UnmatchedInboundEmail::first();
        $this->assertSame('unknown@example.com', $unmatched->from_email);
        $this->assertSame('msg-456', $unmatched->message_id);
        $this->assertSame('Hi', $unmatched->body_text);
    }

    public function test_idempotency_same_message_id(): void
    {
        User::factory()->create(['email' => 'sender@example.com']);
        $fakeEmbedding = array_fill(0, 1536, 0.01);
        $this->mock(OpenRouterService::class, function ($mock) use ($fakeEmbedding): void {
            $mock->shouldReceive('embed')->once()->with('Hello')->andReturn($fakeEmbedding);
            $mock->shouldReceive('extractMetadata')->once()->with('Hello')->andReturn(['tags' => []]);
        });

        $payload = $this->minimalPayload(['TextBody' => 'Hello', 'MessageID' => 'msg-idem']);

        $this->postJson($this->webhookUrl(), $payload)->assertStatus(200);
        $this->postJson($this->webhookUrl(), $payload)->assertStatus(200);

        $this->assertDatabaseCount('thoughts', 1);
    }

    public function test_inbound_address_matches_user(): void
    {
        $user = User::factory()->create(['email' => 'primary@example.com']);
        UserInboundAddress::create(['user_id' => $user->id, 'email' => 'alias@example.com']);
        $fakeEmbedding = array_fill(0, 1536, 0.01);
        $this->mock(OpenRouterService::class, function ($mock) use ($fakeEmbedding): void {
            $mock->shouldReceive('embed')->once()->with('Via alias')->andReturn($fakeEmbedding);
            $mock->shouldReceive('extractMetadata')->once()->with('Via alias')->andReturn(['tags' => []]);
        });

        $response = $this->postJson($this->webhookUrl(), $this->minimalPayload([
            'From' => 'alias@example.com',
            'TextBody' => 'Via alias',
            'MessageID' => 'msg-alias',
        ]));

        $response->assertStatus(200);
        $this->assertDatabaseCount('thoughts', 1);
        $thought = Thought::first();
        $this->assertSame($user->id, $thought->user_id);
        $this->assertSame('Via alias', $thought->content);
    }

    public function test_attachment_names_in_source_metadata(): void
    {
        User::factory()->create(['email' => 'sender@example.com']);
        $fakeEmbedding = array_fill(0, 1536, 0.01);
        $this->mock(OpenRouterService::class, function ($mock) use ($fakeEmbedding): void {
            $mock->shouldReceive('embed')->once()->andReturn($fakeEmbedding);
            $mock->shouldReceive('extractMetadata')->once()->andReturn(['tags' => []]);
        });

        $response = $this->postJson($this->webhookUrl(), $this->minimalPayload([
            'TextBody' => 'See attachment',
            'MessageID' => 'msg-att',
            'Attachments' => [
                ['Name' => 'file.pdf', 'Content' => 'base64...', 'ContentType' => 'application/pdf'],
                ['Name' => 'screenshot.png', 'Content' => '...', 'ContentType' => 'image/png'],
            ],
        ]));

        $response->assertStatus(200);
        $thought = Thought::first();
        $this->assertArrayHasKey('attachment_names', $thought->source_metadata);
        $this->assertSame(['file.pdf', 'screenshot.png'], $thought->source_metadata['attachment_names']);
    }
}
