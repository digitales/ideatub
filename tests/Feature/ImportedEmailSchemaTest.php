<?php

namespace Tests\Feature;

use App\Models\ImportedEmail;
use App\Models\MailAccount;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImportedEmailSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_provider_message_id_is_unique_per_mail_account(): void
    {
        $account = MailAccount::factory()->create();

        ImportedEmail::create([
            'user_id' => $account->user_id,
            'mail_account_id' => $account->id,
            'provider' => 'fastmail',
            'provider_message_id' => 'msg-123',
            'direction' => 'sent',
            'processing_status' => 'pending',
        ]);

        $this->expectException(QueryException::class);

        ImportedEmail::create([
            'user_id' => $account->user_id,
            'mail_account_id' => $account->id,
            'provider' => 'fastmail',
            'provider_message_id' => 'msg-123',
            'direction' => 'sent',
            'processing_status' => 'pending',
        ]);
    }
}
