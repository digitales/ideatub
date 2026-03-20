<?php

namespace Database\Factories;

use App\Models\MailAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MailAccount>
 */
class MailAccountFactory extends Factory
{
    protected $model = MailAccount::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'provider' => 'fastmail',
            'display_name' => 'Primary Fastmail',
            'account_email' => fake()->safeEmail(),
            'status' => 'active',
            'credentials_json' => [
                'credential' => 'secret',
                'account_id' => 'u123',
                'api_url' => 'https://api.fastmail.com/jmap/api/',
            ],
            'settings_json' => [
                'sync_enabled' => true,
                'include_sent' => true,
                'include_received_personal' => true,
                'exclude_bulk' => true,
                'initial_backfill_window_days' => 90,
            ],
            'provider_checkpoint_json' => null,
            'last_synced_at' => null,
            'last_successful_sync_at' => null,
        ];
    }
}
