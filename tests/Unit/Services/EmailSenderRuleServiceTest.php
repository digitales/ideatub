<?php

namespace Tests\Unit\Services;

use App\Models\EmailSenderRule;
use App\Models\User;
use App\Services\Email\EmailSenderRuleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EmailSenderRuleServiceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function exact_sender_match_returns_explicit_action(): void
    {
        $user = User::factory()->create();
        $rule = EmailSenderRule::create([
            'user_id' => $user->id,
            'sender_email' => 'natesnewsletter@substack.com',
            'action' => EmailSenderRule::ACTION_EXTRA_PROCESS,
        ]);

        $decision = app(EmailSenderRuleService::class)->resolveForUser(
            $user,
            'Nate <natesnewsletter@substack.com>'
        );

        $this->assertSame(EmailSenderRule::ACTION_EXTRA_PROCESS, $decision['action']);
        $this->assertSame('natesnewsletter@substack.com', $decision['normalized_sender']);
        $this->assertSame($rule->id, $decision['rule_id']);
        $this->assertSame('Nate <natesnewsletter@substack.com>', $decision['raw_sender']);
    }

    #[Test]
    public function unknown_sender_defaults_to_review(): void
    {
        $user = User::factory()->create();

        $decision = app(EmailSenderRuleService::class)->resolveForUser($user, 'Unknown@Example.com');

        $this->assertSame(EmailSenderRule::ACTION_REVIEW, $decision['action']);
        $this->assertSame('unknown@example.com', $decision['normalized_sender']);
        $this->assertNull($decision['rule_id']);
        $this->assertSame('Unknown@Example.com', $decision['raw_sender']);
    }

    #[Test]
    public function mixed_case_input_is_normalized_for_lookup(): void
    {
        $user = User::factory()->create();
        EmailSenderRule::create([
            'user_id' => $user->id,
            'sender_email' => 'allowed@example.com',
            'action' => EmailSenderRule::ACTION_ALLOW,
        ]);

        $decision = app(EmailSenderRuleService::class)->resolveForUser(
            $user,
            'Someone <ALLOWED@EXAMPLE.COM>'
        );

        $this->assertSame(EmailSenderRule::ACTION_ALLOW, $decision['action']);
        $this->assertSame('allowed@example.com', $decision['normalized_sender']);
        $this->assertNotNull($decision['rule_id']);
    }

    #[Test]
    public function plus_addressing_is_not_stripped(): void
    {
        $user = User::factory()->create();
        EmailSenderRule::create([
            'user_id' => $user->id,
            'sender_email' => 'user+news@example.com',
            'action' => EmailSenderRule::ACTION_IGNORE,
        ]);

        $decision = app(EmailSenderRuleService::class)->resolveForUser(
            $user,
            'Newsletter <User+News@Example.com>'
        );

        $this->assertSame(EmailSenderRule::ACTION_IGNORE, $decision['action']);
        $this->assertSame('user+news@example.com', $decision['normalized_sender']);
        $this->assertNotNull($decision['rule_id']);
    }

    #[Test]
    public function when_multiple_mailboxes_are_present_first_parsed_sender_wins_and_raw_sender_is_preserved(): void
    {
        $user = User::factory()->create();
        EmailSenderRule::create([
            'user_id' => $user->id,
            'sender_email' => 'second@example.com',
            'action' => EmailSenderRule::ACTION_ALLOW,
        ]);

        $raw = 'First <first@example.com>, Second <second@example.com>';
        $decision = app(EmailSenderRuleService::class)->resolveForUser($user, $raw);

        $this->assertSame(EmailSenderRule::ACTION_REVIEW, $decision['action']);
        $this->assertSame('first@example.com', $decision['normalized_sender']);
        $this->assertNull($decision['rule_id']);
        $this->assertSame($raw, $decision['raw_sender']);
    }

    #[Test]
    public function unparseable_sender_input_falls_back_to_review_and_preserves_raw_sender(): void
    {
        $user = User::factory()->create();
        $raw = 'Build failed <ops@deploy status>';

        $decision = app(EmailSenderRuleService::class)->resolveForUser($user, $raw);

        $this->assertSame(EmailSenderRule::ACTION_REVIEW, $decision['action']);
        $this->assertSame('', $decision['normalized_sender']);
        $this->assertNull($decision['rule_id']);
        $this->assertSame($raw, $decision['raw_sender']);
    }
}
