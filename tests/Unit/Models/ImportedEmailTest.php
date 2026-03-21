<?php

namespace Tests\Unit\Models;

use App\Models\ImportedEmail;
use App\Models\InboxItem;
use App\Models\MailAccount;
use App\Models\Thought;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ImportedEmailTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_resolves_review_and_research_relationships(): void
    {
        $account = MailAccount::factory()->create();
        $reviewInboxItem = InboxItem::factory()->create([
            'user_id' => $account->user_id,
        ]);
        $researchThought = Thought::factory()->create([
            'user_id' => $account->user_id,
            'embedding' => null,
        ]);

        $importedEmail = ImportedEmail::create([
            'user_id' => $account->user_id,
            'mail_account_id' => $account->id,
            'provider' => $account->provider,
            'provider_message_id' => 'msg-model-relations',
            'direction' => 'received',
            'processing_status' => 'review_queued',
            'review_inbox_item_id' => $reviewInboxItem->id,
            'research_thought_id' => $researchThought->id,
        ]);

        $this->assertTrue($importedEmail->reviewInboxItem->is($reviewInboxItem));
        $this->assertTrue($importedEmail->researchThought->is($researchThought));
    }
}
