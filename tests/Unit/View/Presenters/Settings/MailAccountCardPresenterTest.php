<?php

namespace Tests\Unit\View\Presenters\Settings;

use App\Models\MailAccount;
use App\Models\MailSyncRun;
use App\View\Presenters\MissingPresenterData;
use App\View\Presenters\Settings\MailAccountCardPresenter;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MailAccountCardPresenterTest extends TestCase
{
    #[Test]
    public function it_throws_when_latest_sync_run_is_not_preloaded(): void
    {
        $account = MailAccount::factory()->make();

        $this->expectException(MissingPresenterData::class);
        $this->expectExceptionMessage(
            'Presenter requires relation [latestSyncRun] to be loaded on '.MailAccount::class.'.'
        );

        new MailAccountCardPresenter($account);
    }

    #[Test]
    public function it_exposes_latest_sync_fields_from_preloaded_latest_sync_run(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-31 12:00:00'));

        $account = MailAccount::factory()->make([
            'display_name' => 'Primary Fastmail',
            'account_email' => 'owner@fastmail.fm',
            'last_synced_at' => Carbon::parse('2026-03-31 11:00:00'),
        ]);

        $run = new MailSyncRun([
            'status' => 'completed',
            'started_at' => Carbon::parse('2026-03-31 10:00:00'),
        ]);

        $account->setRelation('latestSyncRun', $run);

        $presenter = new MailAccountCardPresenter($account);

        $this->assertSame('Primary Fastmail', $presenter->displayName());
        $this->assertSame('owner@fastmail.fm', $presenter->accountEmail());
        $this->assertTrue($presenter->hasLatestSyncRun());
        $this->assertSame('completed', $presenter->latestSyncStatus());
        $this->assertSame('1 hour ago', $presenter->lastSyncedHumanText());

        Carbon::setTestNow();
    }

    #[Test]
    public function it_reports_no_latest_sync_when_relation_is_null_but_loaded(): void
    {
        $account = MailAccount::factory()->make();
        $account->setRelation('latestSyncRun', null);

        $presenter = new MailAccountCardPresenter($account);

        $this->assertFalse($presenter->hasLatestSyncRun());
        $this->assertNull($presenter->latestSyncStatus());
        $this->assertNull($presenter->lastSyncedHumanText());
    }
}
