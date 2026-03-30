<?php

namespace Tests\Unit\Services;

use App\Models\ImportedEmail;
use App\Models\MailAccount;
use App\Models\Thought;
use App\Models\User;
use App\Services\Email\ResetNewsletterResearchState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ResetNewsletterResearchStateTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function reset_rejects_a_stored_email_that_belongs_to_a_different_thought(): void
    {
        $user = User::factory()->create();
        $account = MailAccount::factory()->create(['user_id' => $user->id]);
        $thought = Thought::factory()->create([
            'user_id' => $user->id,
            'source' => 'email',
        ]);
        $otherThought = Thought::factory()->create([
            'user_id' => $user->id,
            'source' => 'email',
        ]);
        $stored = ImportedEmail::query()->create([
            'user_id' => $user->id,
            'mail_account_id' => $account->id,
            'provider' => 'fastmail',
            'provider_message_id' => 'guard-msg-1',
            'direction' => 'received',
            'subject' => 'Guarded newsletter',
            'body_text' => 'Body text.',
            'from_json' => [['email' => 'news@example.com', 'name' => 'News']],
            'processing_status' => 'research_completed',
            'thought_id' => $otherThought->id,
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Stored email row does not belong to the provided email thought.');

        app(ResetNewsletterResearchState::class)->reset($thought, $stored);
    }
}
