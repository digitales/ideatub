<?php

namespace Tests\Feature;

use App\Jobs\SyncMailAccountIncremental;
use App\Models\MailAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SyncAllMailAccountsCommandTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function command_dispatches_incremental_sync_for_active_mail_accounts(): void
    {
        Bus::fake();

        $user = User::factory()->create();
        MailAccount::create([
            'user_id' => $user->id,
            'provider' => 'fastmail',
            'display_name' => 'Primary',
            'account_email' => 'owner@fastmail.fm',
            'status' => 'active',
            'credentials_json' => ['credential' => 'secret'],
            'settings_json' => ['sync_enabled' => true],
        ]);

        $this->artisan('mail:sync-all')->assertExitCode(0);

        Bus::assertDispatched(SyncMailAccountIncremental::class);
    }
}
