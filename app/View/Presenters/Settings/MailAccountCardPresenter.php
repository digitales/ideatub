<?php

namespace App\View\Presenters\Settings;

use App\Models\MailAccount;
use App\View\Presenters\Concerns\EnsuresPresenterDataIsLoaded;

class MailAccountCardPresenter
{
    use EnsuresPresenterDataIsLoaded;

    public function __construct(
        private readonly MailAccount $mailAccount
    ) {
        $this->requireRelationLoaded($mailAccount, 'latestSyncRun');
    }

    public function mailAccount(): MailAccount
    {
        return $this->mailAccount;
    }

    public function displayName(): string
    {
        return (string) $this->mailAccount->display_name;
    }

    public function accountEmail(): string
    {
        return (string) $this->mailAccount->account_email;
    }

    public function hasLatestSyncRun(): bool
    {
        return $this->mailAccount->latestSyncRun !== null;
    }

    public function latestSyncStatus(): ?string
    {
        return $this->mailAccount->latestSyncRun?->status;
    }

    public function lastSyncedHumanText(): ?string
    {
        $at = $this->mailAccount->last_synced_at;

        return $at ? $at->diffForHumans() : null;
    }
}
