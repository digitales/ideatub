# Blade View Presenters Refactor Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Refactor the bloated Blade views called out in the approved presenter spec so display derivation moves out of templates, render-time queries are removed, and list/detail pages keep using preloaded data without lazy-loading regressions.

**Architecture:** Add a small presenter layer under `app/View/Presenters` and keep controllers responsible for querying, eager loading, and building lookup maps. Introduce reusable fragment presenters for email status and email metadata, page/item presenters for the list-heavy idea and stream surfaces, and a lightweight presenter guard that throws when required relations or lookup payloads are missing instead of silently lazy loading. Use the existing feature tests as the primary HTML regression net, then add focused presenter unit tests and query-budget checks for the pages most likely to regress.

**Tech Stack:** Laravel 12, PHP 8.2+, Blade, Eloquent, Laravel feature/unit tests, unit tests under `tests/Unit`, manual browser verification for list/detail pages.

**Spec:** `docs/superpowers/specs/2026-03-31-blade-view-presenters-design.md`

---

## File Structure

| File | Responsibility |
|------|----------------|
| `app/View/Presenters/MissingPresenterData.php` | Single exception type for missing preloaded relations or lookup payloads |
| `app/View/Presenters/Concerns/EnsuresPresenterDataIsLoaded.php` | Shared guard helpers for `relationLoaded()` and required lookup keys |
| `app/View/Presenters/Settings/MailAccountCardPresenter.php` | Render-ready fields for each connected mail account row |
| `app/View/Presenters/Email/NewsletterResearchStatusPresenter.php` | Label, link, and skip-reason presentation for newsletter research status |
| `app/View/Presenters/Email/EmailMetadataPresenter.php` | Read-only fallback and formatting logic for the thought detail email sidebar |
| `app/View/Presenters/Thoughts/ThoughtDetailPresenter.php` | Wrapper for email-thought detail page inputs so Blade stops assembling preview/sidebar state |
| `app/View/Presenters/Thoughts/IdeaIndexCardPresenter.php` | Precomputed card state for `idea.index` |
| `app/View/Presenters/Thoughts/StreamThoughtCardPresenter.php` | Precomputed card state for `idea.stream` and typed stream variants |
| `app/View/Presenters/Ideas/IdeaListItemPresenter.php` | Render-ready state for the incomplete ideas list |
| `app/View/Presenters/Ideas/CompletedIdeaPresenter.php` | Logged/completed labels and fallback markers for completed ideas |
| `app/Models/MailAccount.php` | Add a `latestSyncRun()` relation that controllers can eager load instead of querying in Blade |
| `app/Http/Controllers/EmailAccountSettingsController.php` | Preload `latestSyncRun` and pass presenter-backed rows to the settings view |
| `app/Http/Controllers/IdeaController.php` | Build presenter-backed data for thought detail, index, stream, ideas, and completed pages |
| `resources/views/idea/index.blade.php` | Forward presenter-backed home feed cards into the index card partial for full-page and AJAX refresh paths |
| `resources/views/idea/stream.blade.php` | Forward presenter-backed stream cards into the stream partial for full-page and typed-stream rendering |
| `resources/views/settings/email-accounts.blade.php` | Render presenter-backed mail account rows only |
| `resources/views/idea/partials/email_newsletter_research_status.blade.php` | Render a presenter-backed status object instead of deriving labels/flags inline |
| `resources/views/idea/partials/thought_detail_email_sidebar.blade.php` | Render presenter-backed email metadata fields |
| `resources/views/idea/show.blade.php` | Consume a detail presenter instead of shaping email preview sections inline |
| `resources/views/idea/index_thought_cards.blade.php` | Render presenter-backed home feed cards |
| `resources/views/idea/stream_thoughts.blade.php` | Render presenter-backed stream cards |
| `resources/views/idea/ideas.blade.php` | Forward presenter-backed incomplete idea rows into the ideas list partial |
| `resources/views/idea/completed.blade.php` | Forward presenter-backed completed idea rows into the completed list partial |
| `resources/views/idea/partials/ideas_list.blade.php` | Render presenter-backed incomplete idea rows |
| `resources/views/idea/partials/completed_ideas_list.blade.php` | Render presenter-backed completed idea rows |
| `tests/Unit/View/Presenters/PresenterGuardTest.php` | Prove missing relations and lookup keys fail fast |
| `tests/Unit/View/Presenters/Settings/MailAccountCardPresenterTest.php` | Presenter coverage for latest sync label/time formatting |
| `tests/Unit/View/Presenters/Email/NewsletterResearchStatusPresenterTest.php` | Presenter coverage for status labels, skip reasons, and links |
| `tests/Unit/View/Presenters/Email/EmailMetadataPresenterTest.php` | Presenter coverage for participant formatting and fallback values |
| `tests/Unit/View/Presenters/Ideas/IdeaListItemPresenterTest.php` | Presenter coverage for incomplete idea research-state branching |
| `tests/Unit/View/Presenters/Ideas/CompletedIdeaPresenterTest.php` | Presenter coverage for logged/completed labels and fallback markers |
| `tests/Feature/EmailAccountSettingsTest.php` | Feature coverage for the settings page after removing the view query |
| `tests/Feature/EmailThoughtStatusDisplayTest.php` | Regression coverage for index/stream/detail research status markup |
| `tests/Feature/ThoughtShowPageTest.php` | Feature coverage for detail-page email sidebar and research preview |
| `tests/Feature/IdeaPageTest.php` | Feature coverage for presenter-backed recent/index cards |
| `tests/Feature/StreamPageTest.php` | Feature coverage for presenter-backed stream cards |
| `tests/Feature/IdeaIdeasTest.php` | Feature coverage for presenter-backed incomplete ideas |
| `tests/Feature/CompletedIdeasPageTest.php` | Feature coverage for presenter-backed completed ideas |
| `tests/Feature/ViewPresenterQueryBudgetTest.php` | Query-budget checks for list/detail pages with lazy-loading prevention enabled |

---

## Task 1: Add presenter guard infrastructure

**Files:**
- Create: `app/View/Presenters/MissingPresenterData.php`
- Create: `app/View/Presenters/Concerns/EnsuresPresenterDataIsLoaded.php`
- Create: `tests/Unit/View/Presenters/PresenterGuardTest.php`

- [ ] **Step 1: Write the failing presenter guard tests**

Create `tests/Unit/View/Presenters/PresenterGuardTest.php` with a small anonymous presenter that uses the trait and proves:

```php
public function test_require_relation_loaded_throws_when_relation_missing(): void
{
    $thought = Thought::factory()->make();

    $this->expectException(MissingPresenterData::class);
    $this->expectExceptionMessage('comments');

    $presenter = new class($thought) {
        use EnsuresPresenterDataIsLoaded;

        public function __construct(private Thought $thought) {}

        public function touchGuard(): void
        {
            $this->requireRelationLoaded($this->thought, 'comments');
        }
    };

    $presenter->touchGuard();
}
```

Add a second test for a required lookup key:

```php
public function test_require_lookup_value_throws_when_key_missing(): void
{
    $this->expectException(MissingPresenterData::class);

    $presenter = new class {
        use EnsuresPresenterDataIsLoaded;

        public function touchGuard(): void
        {
            $this->requireLookupKey([], 'newsletterStatusByThoughtId');
        }
    };

    $presenter->touchGuard();
}
```

- [ ] **Step 2: Run the new presenter guard test and verify it fails**

Run:

```bash
php artisan test tests/Unit/View/Presenters/PresenterGuardTest.php -v
```

Expected: FAIL because the presenter exception and trait do not exist yet.

- [ ] **Step 3: Implement the shared exception and guard trait**

Create `app/View/Presenters/MissingPresenterData.php`:

```php
<?php

namespace App\View\Presenters;

use RuntimeException;

final class MissingPresenterData extends RuntimeException
{
    public static function relation(string $presenter, string $relation): self
    {
        return new self(sprintf('%s requires relation [%s] to be preloaded.', $presenter, $relation));
    }

    public static function lookup(string $presenter, string $key): self
    {
        return new self(sprintf('%s requires lookup key [%s].', $presenter, $key));
    }
}
```

Create `app/View/Presenters/Concerns/EnsuresPresenterDataIsLoaded.php`:

```php
<?php

namespace App\View\Presenters\Concerns;

use App\View\Presenters\MissingPresenterData;
use Illuminate\Database\Eloquent\Model;

trait EnsuresPresenterDataIsLoaded
{
    protected function requireRelationLoaded(Model $model, string $relation): void
    {
        if (! $model->relationLoaded($relation)) {
            throw MissingPresenterData::relation(static::class, $relation);
        }
    }

    /**
     * @param  array<string, mixed>  $lookup
     */
    protected function requireLookupKey(array $lookup, string $key): void
    {
        if (! array_key_exists($key, $lookup)) {
            throw MissingPresenterData::lookup(static::class, $key);
        }
    }
}
```

- [ ] **Step 4: Re-run the presenter guard test and verify it passes**

Run:

```bash
php artisan test tests/Unit/View/Presenters/PresenterGuardTest.php -v
```

Expected: PASS.

- [ ] **Step 5: Commit the guard infrastructure**

```bash
git add app/View/Presenters/MissingPresenterData.php app/View/Presenters/Concerns/EnsuresPresenterDataIsLoaded.php tests/Unit/View/Presenters/PresenterGuardTest.php
git commit -m "feat: add presenter preload guards"
```

---

## Task 2: Remove the mail-account query from Blade

**Files:**
- Modify: `app/Models/MailAccount.php`
- Modify: `app/Http/Controllers/EmailAccountSettingsController.php`
- Create: `app/View/Presenters/Settings/MailAccountCardPresenter.php`
- Create: `tests/Unit/View/Presenters/Settings/MailAccountCardPresenterTest.php`
- Modify: `resources/views/settings/email-accounts.blade.php`
- Modify: `tests/Feature/EmailAccountSettingsTest.php`

- [ ] **Step 1: Add failing unit coverage for the mail-account presenter**

Create `tests/Unit/View/Presenters/Settings/MailAccountCardPresenterTest.php` with cases like:

```php
public function test_presenter_exposes_latest_sync_fields_from_preloaded_relation(): void
{
    $account = MailAccount::factory()->create([
        'last_synced_at' => now()->subMinutes(5),
    ]);

    MailSyncRun::create([
        'mail_account_id' => $account->id,
        'run_type' => 'backfill',
        'status' => 'completed',
        'started_at' => now()->subMinute(),
        'finished_at' => now(),
        'stats_json' => ['imported' => 3],
        'error_summary' => null,
    ]);

    $loaded = MailAccount::query()->with('latestSyncRun')->findOrFail($account->id);
    $presenter = new MailAccountCardPresenter($loaded);

    $this->assertTrue($presenter->hasLatestSync());
    $this->assertSame('completed', $presenter->latestSyncStatus());
    $this->assertSame('owner@fastmail.fm', $presenter->accountEmail());
}
```

Add a second test that proves `MailAccountCardPresenter` throws when `latestSyncRun` was not preloaded.

- [ ] **Step 2: Add a failing feature assertion that the settings page still renders latest sync content**

Extend `tests/Feature/EmailAccountSettingsTest.php` to keep the existing latest-sync assertion and add one structural assertion that still proves the rendered row contains only the expected presenter-backed text:

```php
$response->assertSee('Latest sync');
$response->assertSee('completed');
$response->assertSee('owner@fastmail.fm');
```

Keep the current feature test name `test_connected_account_page_shows_latest_sync_status`.

- [ ] **Step 3: Run the focused settings tests and verify they fail**

Run:

```bash
php artisan test tests/Unit/View/Presenters/Settings/MailAccountCardPresenterTest.php tests/Feature/EmailAccountSettingsTest.php --filter=latest_sync -v
```

Expected: FAIL because `MailAccountCardPresenter` and `latestSyncRun` do not exist yet.

- [ ] **Step 4: Add a dedicated `latestSyncRun()` relation to `MailAccount`**

Modify `app/Models/MailAccount.php`:

```php
use Illuminate\Database\Eloquent\Relations\HasOne;

public function latestSyncRun(): HasOne
{
    return $this->hasOne(MailSyncRun::class)->latestOfMany('started_at');
}
```

This is the key change that lets the controller eager load one row per account instead of querying from Blade.

- [ ] **Step 5: Implement the settings presenter**

Create `app/View/Presenters/Settings/MailAccountCardPresenter.php`:

```php
<?php

namespace App\View\Presenters\Settings;

use App\Models\MailAccount;
use App\View\Presenters\Concerns\EnsuresPresenterDataIsLoaded;

final class MailAccountCardPresenter
{
    use EnsuresPresenterDataIsLoaded;

    public function __construct(private MailAccount $account)
    {
        $this->requireRelationLoaded($account, 'latestSyncRun');
    }

    public function displayName(): string
    {
        return $this->account->display_name;
    }

    public function accountEmail(): string
    {
        return $this->account->account_email;
    }

    public function hasLatestSync(): bool
    {
        return $this->account->latestSyncRun !== null;
    }

    public function latestSyncStatus(): ?string
    {
        return $this->account->latestSyncRun?->status;
    }

    public function lastSyncedHuman(): ?string
    {
        return $this->account->last_synced_at?->diffForHumans();
    }

    public function model(): MailAccount
    {
        return $this->account;
    }
}
```

- [ ] **Step 6: Eager load the relation and pass presenters from the controller**

Modify `app/Http/Controllers/EmailAccountSettingsController.php`:

```php
$mailAccounts = $request->user()
    ->mailAccounts()
    ->with('latestSyncRun')
    ->latest()
    ->get()
    ->map(fn (MailAccount $account) => new MailAccountCardPresenter($account));

return view('settings.email-accounts', [
    'mailAccounts' => $mailAccounts,
]);
```

- [ ] **Step 7: Simplify the Blade row to render presenter fields only**

Update `resources/views/settings/email-accounts.blade.php` so each row uses a presenter variable:

```blade
@foreach ($mailAccounts as $accountCard)
    @php($mailAccount = $accountCard->model())
    <h3>{{ $accountCard->displayName() }}</h3>
    <p>{{ $accountCard->accountEmail() }}</p>
    @if ($accountCard->hasLatestSync())
        <p>
            Latest sync: {{ $accountCard->latestSyncStatus() }}
            @if ($accountCard->lastSyncedHuman())
                &mdash; {{ $accountCard->lastSyncedHuman() }}
            @endif
        </p>
    @endif
@endforeach
```

Delete the inline `@php($latestRun = $mailAccount->syncRuns()->latest()->first())`.

- [ ] **Step 8: Re-run the settings presenter and feature tests**

Run:

```bash
php artisan test tests/Unit/View/Presenters/Settings/MailAccountCardPresenterTest.php tests/Feature/EmailAccountSettingsTest.php -v
```

Expected: PASS.

- [ ] **Step 9: Commit the mail-account refactor**

```bash
git add app/Models/MailAccount.php app/Http/Controllers/EmailAccountSettingsController.php app/View/Presenters/Settings/MailAccountCardPresenter.php resources/views/settings/email-accounts.blade.php tests/Unit/View/Presenters/Settings/MailAccountCardPresenterTest.php tests/Feature/EmailAccountSettingsTest.php
git commit -m "refactor: move email account display logic into presenters"
```

---

## Task 3: Extract newsletter research status presentation

**Files:**
- Create: `app/View/Presenters/Email/NewsletterResearchStatusPresenter.php`
- Create: `tests/Unit/View/Presenters/Email/NewsletterResearchStatusPresenterTest.php`
- Modify: `app/Http/Controllers/IdeaController.php`
- Modify: `resources/views/idea/partials/email_newsletter_research_status.blade.php`
- Modify: `tests/Feature/EmailThoughtStatusDisplayTest.php`

- [ ] **Step 1: Add failing unit tests for status-label and skip-reason mapping**

Create `tests/Unit/View/Presenters/Email/NewsletterResearchStatusPresenterTest.php` with coverage like:

```php
public function test_presenter_maps_completed_status_to_ready_label_and_link_visibility(): void
{
    $presenter = NewsletterResearchStatusPresenter::fromArray([
        'status' => 'research_completed',
        'research_thought_id' => (string) Str::uuid(),
        'skip_reason' => '',
        'show_research_link' => true,
        'show_skip_info' => false,
    ]);

    $this->assertSame('research_completed', $presenter->status());
    $this->assertSame('Research ready', $presenter->label());
    $this->assertTrue($presenter->showsResearchLink());
}
```

Also add skipped-status coverage:

```php
$this->assertTrue($presenter->showsSkipInfo());
$this->assertSame('Not enough meaningful content to research.', $presenter->skipReason());
```

- [ ] **Step 2: Add a failing feature assertion that the existing markup still renders**

Keep the current `tests/Feature/EmailThoughtStatusDisplayTest.php` assertions and add one more detail-page assertion if needed so the shared partial remains covered in all three contexts:

```php
$detail->assertSee('data-email-research-status="research_completed"', false);
```

- [ ] **Step 3: Run the focused email status tests and verify they fail**

Run:

```bash
php artisan test tests/Unit/View/Presenters/Email/NewsletterResearchStatusPresenterTest.php tests/Feature/EmailThoughtStatusDisplayTest.php -v
```

Expected: FAIL because the presenter does not exist yet.

- [ ] **Step 4: Implement the status presenter**

Create `app/View/Presenters/Email/NewsletterResearchStatusPresenter.php`:

```php
<?php

namespace App\View\Presenters\Email;

final class NewsletterResearchStatusPresenter
{
    private const LABELS = [
        'research_queued' => 'Research queued',
        'research_completed' => 'Research ready',
        'research_partial' => 'Partial research',
        'research_skipped' => 'Research skipped',
        'research_failed' => 'Research failed',
    ];

    /**
     * @param  array{status: string, research_thought_id: string|null, skip_reason: string, show_research_link: bool, show_skip_info: bool}  $payload
     */
    private function __construct(
        private array $payload,
        private string $popoverSuffix,
    ) {}

    public static function fromArray(?array $payload, string $popoverSuffix = 'status'): ?self
    {
        return is_array($payload) && ($payload['status'] ?? '') !== ''
            ? new self($payload, $popoverSuffix)
            : null;
    }

    public function status(): string
    {
        return $this->payload['status'];
    }

    public function label(): string
    {
        return self::LABELS[$this->status()] ?? ucfirst(str_replace('_', ' ', $this->status()));
    }

    public function researchThoughtId(): ?string
    {
        return $this->payload['research_thought_id'];
    }

    public function skipReason(): string
    {
        return $this->payload['skip_reason'];
    }

    public function showsResearchLink(): bool
    {
        return (bool) $this->payload['show_research_link'];
    }

    public function showsSkipInfo(): bool
    {
        return (bool) $this->payload['show_skip_info'];
    }

    public function skipReasonPopoverId(): string
    {
        return 'email-research-skip-reason-'.$this->popoverSuffix;
    }
}
```

- [ ] **Step 5: Wrap the existing controller payloads with presenters**

In `app/Http/Controllers/IdeaController.php`, keep the existing `buildEmailNewsletterResearchStatus*` helpers for now, but wrap their results before rendering. If array maps are simplest on this controller, use:

```php
$newsletterResearchStatuses = collect($this->buildEmailNewsletterResearchStatuses($thoughts))
    ->mapWithKeys(fn (?array $payload, string $thoughtId) => [
        $thoughtId => NewsletterResearchStatusPresenter::fromArray($payload, $thoughtId),
    ])
    ->all();
```

Do the same for the single-thought detail payload:

```php
$newsletterStatus = NewsletterResearchStatusPresenter::fromArray(
    $thought->source === 'email' ? $this->buildEmailNewsletterResearchStatus($thought) : null,
    $thought->id,
);
```

- [ ] **Step 6: Simplify the shared Blade partial**

Update `resources/views/idea/partials/email_newsletter_research_status.blade.php` so it expects a presenter and preserves the current popover behavior:

```blade
@if ($newsletterResearchStatus)
    <span data-email-research-status="{{ $newsletterResearchStatus->status() }}">
        {{ $newsletterResearchStatus->label() }}
    </span>

    @if ($newsletterResearchStatus->showsResearchLink())
        <a href="{{ route('idea.research.show', $newsletterResearchStatus->researchThoughtId()) }}">View research</a>
    @endif

    @if ($newsletterResearchStatus->showsSkipInfo())
        <span class="text-[10px] text-slate-brand/60">Skipped: {{ $newsletterResearchStatus->skipReason() }}</span>
        <span
            class="relative inline-flex max-w-full align-middle"
            x-data="{ fromHover: false, fromFocus: false, fromClick: false, get reveal() { return this.fromHover || this.fromFocus || this.fromClick }, close() { this.fromHover = false; this.fromFocus = false; this.fromClick = false } }"
            @mouseenter="fromHover = true"
            @mouseleave="fromHover = false"
            @keydown.escape.window="close()"
            @click.outside="close()"
        >
            <button
                type="button"
                aria-controls="{{ $newsletterResearchStatus->skipReasonPopoverId() }}"
                x-bind:aria-expanded="reveal ? 'true' : 'false'"
            >Why research was skipped</button>
            <span aria-hidden="true" data-email-research-skip-hover-bridge class="absolute left-0 top-full h-1 w-full"></span>
            <div
                id="{{ $newsletterResearchStatus->skipReasonPopoverId() }}"
                data-email-research-skip-reason
                x-show="reveal"
                x-cloak
            >{{ $newsletterResearchStatus->skipReason() }}</div>
        </span>
    @endif
@endif
```

Keep the existing classes, `data-email-research-skip-*` hooks, hover bridge, and `aria-controls` behavior unchanged when you swap the data source from arrays to presenter methods.

- [ ] **Step 7: Re-run the email status unit and feature tests**

Run:

```bash
php artisan test tests/Unit/View/Presenters/Email/NewsletterResearchStatusPresenterTest.php tests/Feature/EmailThoughtStatusDisplayTest.php -v
```

Expected: PASS.

- [ ] **Step 8: Commit the status presenter work**

```bash
git add app/View/Presenters/Email/NewsletterResearchStatusPresenter.php app/Http/Controllers/IdeaController.php resources/views/idea/partials/email_newsletter_research_status.blade.php tests/Unit/View/Presenters/Email/NewsletterResearchStatusPresenterTest.php tests/Feature/EmailThoughtStatusDisplayTest.php
git commit -m "refactor: present email research status outside Blade"
```

---

## Task 4: Move email thought detail shaping into presenters

**Files:**
- Create: `app/View/Presenters/Email/EmailMetadataPresenter.php`
- Create: `app/View/Presenters/Thoughts/ThoughtDetailPresenter.php`
- Create: `tests/Unit/View/Presenters/Email/EmailMetadataPresenterTest.php`
- Modify: `app/Http/Controllers/IdeaController.php`
- Modify: `resources/views/idea/show.blade.php`
- Modify: `resources/views/idea/partials/thought_detail_email_sidebar.blade.php`
- Modify: `tests/Feature/ThoughtShowPageTest.php`

- [ ] **Step 1: Add failing unit coverage for email metadata formatting**

Create `tests/Unit/View/Presenters/Email/EmailMetadataPresenterTest.php` with cases for:

```php
public function test_presenter_formats_participants_and_prefers_imported_email_values(): void
{
    $presenter = new EmailMetadataPresenter(
        sourceMetadata: [
            'subject' => 'Metadata subject',
            'from' => [['email' => 'meta@example.com', 'name' => 'Meta Sender']],
        ],
        importedEmail: ImportedEmail::factory()->make([
            'subject' => 'Imported subject',
            'from_json' => [['email' => 'real@example.com', 'name' => 'Real Sender']],
        ]),
    );

    $this->assertSame('Imported subject', $presenter->subject());
    $this->assertSame('Real Sender <real@example.com>', $presenter->fromLine());
}
```

Add a fallback-only case where no imported email exists and metadata strings are used.

- [ ] **Step 2: Add failing detail-page assertions for sidebar content that must survive the refactor**

In `tests/Feature/ThoughtShowPageTest.php`, keep the current email-page assertions and add explicit checks for values now shaped by the presenter:

```php
$response->assertSee('Email metadata');
$response->assertSee('Subject: Imported subject');
$response->assertSee('From: Sender <sender@example.com>');
$response->assertSee('Run newsletter research');
```

- [ ] **Step 3: Run the focused detail presenter tests and verify they fail**

Run:

```bash
php artisan test tests/Unit/View/Presenters/Email/EmailMetadataPresenterTest.php tests/Feature/ThoughtShowPageTest.php --filter=email -v
```

Expected: FAIL because the presenter classes do not exist yet.

- [ ] **Step 4: Implement `EmailMetadataPresenter`**

Create `app/View/Presenters/Email/EmailMetadataPresenter.php`:

```php
<?php

namespace App\View\Presenters\Email;

use App\Models\ImportedEmail;
use Illuminate\Support\Carbon;

final class EmailMetadataPresenter
{
    public function __construct(
        private array $sourceMetadata,
        private ?ImportedEmail $importedEmail,
    ) {}

    public function subject(): ?string { /* prefer imported email, then metadata */ }
    public function direction(): ?string { /* same fallback rule */ }
    public function fromLine(): ?string { /* format participants */ }
    public function toLine(): ?string { /* format participants */ }
    public function ccLine(): ?string { /* format participants */ }
    public function sentLine(): ?string { /* Carbon -> toDayDateTimeString() */ }
    public function receivedLine(): ?string { /* Carbon -> toDayDateTimeString() */ }
    public function provider(): ?string { /* imported email, then metadata */ }
    public function mailboxName(): ?string { /* imported email, then metadata */ }
    public function mailboxId(): ?string { /* imported email, then metadata */ }
    public function threadId(): ?string { /* imported email, then metadata */ }
    public function accountEmail(): ?string { /* imported email->mailAccount->account_email if already loaded, else metadata */ }
}
```

Keep the participant formatter private to the presenter so it disappears from Blade.

If `accountEmail()` needs `mailAccount`, either:

```php
$importedEmail?->loadMissing('mailAccount');
```

before building the presenter, or pass the resolved account email as a scalar from the controller. Do not let `accountEmail()` trigger a lazy-loaded relation on its own. If the presenter reads `mailAccount`, guard it with `relationLoaded('mailAccount')`.

- [ ] **Step 5: Implement `ThoughtDetailPresenter`**

Create `app/View/Presenters/Thoughts/ThoughtDetailPresenter.php` to wrap the detail-page state:

```php
<?php

namespace App\View\Presenters\Thoughts;

use App\Models\Thought;
use App\View\Presenters\Email\EmailMetadataPresenter;
use App\View\Presenters\Email\NewsletterResearchStatusPresenter;

final class ThoughtDetailPresenter
{
    public function __construct(
        private Thought $thought,
        private ?EmailMetadataPresenter $emailMetadata,
        private ?NewsletterResearchStatusPresenter $newsletterStatus,
        private ?array $emailResearchPreview,
        private ?string $contentHtml,
        private ?array $senderRuleContext,
    ) {}

    public function thought(): Thought { return $this->thought; }
    public function isEmailThought(): bool { return $this->thought->source === 'email'; }
    public function emailBodyText(): string { /* imported body text fallback handled in controller */ }
    public function emailMetadata(): ?EmailMetadataPresenter { return $this->emailMetadata; }
    public function newsletterStatus(): ?NewsletterResearchStatusPresenter { return $this->newsletterStatus; }
    public function emailResearchPreview(): ?array { return $this->emailResearchPreview; }
    public function contentHtml(): ?string { return $this->contentHtml; }
    public function senderRuleContext(): ?array { return $this->senderRuleContext; }
}
```

- [ ] **Step 6: Pass the detail presenter from the controller**

In `app/Http/Controllers/IdeaController.php`, keep the existing query helpers, but replace the loose `show()` payload with a presenter:

```php
$importedEmail?->loadMissing('mailAccount');

$newsletterStatus = NewsletterResearchStatusPresenter::fromArray(
    $thought->source === 'email' ? $this->buildEmailNewsletterResearchStatus($thought) : null,
    $thought->id,
);

$detail = new ThoughtDetailPresenter(
    thought: $thought,
    emailMetadata: $thought->source === 'email'
        ? new EmailMetadataPresenter($thought->source_metadata ?? [], $importedEmail)
        : null,
    newsletterStatus: $newsletterStatus,
    emailResearchPreview: $emailResearchPreview,
    contentHtml: $contentHtml,
    senderRuleContext: $senderRuleContext,
);

return view('idea.show', [
    'detail' => $detail,
]);
```

- [ ] **Step 7: Simplify `idea.show` and the email sidebar partial**

In `resources/views/idea/show.blade.php`, replace the top-level `@php` block and `emailResearchPreviewSections` shaping with presenter-backed values:

```blade
@php($thought = $detail->thought())

@if ($detail->isEmailThought())
    {{ $detail->emailBodyText() }}
@else
    {!! $detail->contentHtml() !!}
@endif
```

In `resources/views/idea/partials/thought_detail_email_sidebar.blade.php`, remove the participant/date formatting `@php` block and render:

```blade
@php($email = $detail->emailMetadata())

@if ($email?->subject())
    <p><span class="font-medium text-deep-indigo">Subject: {{ $email->subject() }}</span></p>
@endif
```

Continue rendering the sender-rule form and shared newsletter status partial unchanged except for the new presenter input.

- [ ] **Step 8: Re-run the thought detail tests**

Run:

```bash
php artisan test tests/Unit/View/Presenters/Email/EmailMetadataPresenterTest.php tests/Feature/ThoughtShowPageTest.php --filter=email -v
```

Expected: PASS.

- [ ] **Step 9: Commit the email detail presenter refactor**

```bash
git add app/View/Presenters/Email/EmailMetadataPresenter.php app/View/Presenters/Thoughts/ThoughtDetailPresenter.php app/Http/Controllers/IdeaController.php resources/views/idea/show.blade.php resources/views/idea/partials/thought_detail_email_sidebar.blade.php tests/Unit/View/Presenters/Email/EmailMetadataPresenterTest.php tests/Feature/ThoughtShowPageTest.php
git commit -m "refactor: move email detail display logic into presenters"
```

---

## Task 5: Add presenter-backed index and stream cards

**Files:**
- Create: `app/View/Presenters/Thoughts/IdeaIndexCardPresenter.php`
- Create: `app/View/Presenters/Thoughts/StreamThoughtCardPresenter.php`
- Modify: `app/Http/Controllers/IdeaController.php`
- Modify: `resources/views/idea/index.blade.php`
- Modify: `resources/views/idea/stream.blade.php`
- Modify: `resources/views/idea/index_thought_cards.blade.php`
- Modify: `resources/views/idea/stream_thoughts.blade.php`
- Modify: `tests/Feature/IdeaPageTest.php`
- Modify: `tests/Feature/StreamPageTest.php`

- [ ] **Step 1: Add failing feature assertions for presenter-backed cards**

Re-use the existing feature tests in `tests/Feature/IdeaPageTest.php` and `tests/Feature/StreamPageTest.php` by adding assertions that specifically rely on the new presenter outputs:

```php
$response->assertSee('Reply');
$response->assertSee('data-email-research-status="research_completed"', false);
$response->assertSee(route('thoughts.show', $thought), false);
```

Add one stream-side assertion for the Jira activity timestamp fallback markup that currently comes from the inline `@php` block.

- [ ] **Step 2: Use the focused feed tests as the safety loop while the contract changes**

Run:

```bash
php artisan test tests/Feature/IdeaPageTest.php tests/Feature/StreamPageTest.php tests/Feature/EmailThoughtStatusDisplayTest.php -v
```

Expected: These may still PASS before the contract changes. Once you start swapping `thoughts` and `newsletterResearchStatuses` for presenter-backed `cards`, re-run this command after each controller/view edit and do not move on until it is green again.

- [ ] **Step 3: Implement `IdeaIndexCardPresenter`**

Create `app/View/Presenters/Thoughts/IdeaIndexCardPresenter.php` with methods like:

```php
public function thought(): Thought
public function isEditable(): bool
public function replyHref(): ?string
public function currentReplyableIndex(): int
public function createdAtHuman(): string
public function parentPreview(): ?string
public function newsletterStatus(): ?NewsletterResearchStatusPresenter
```

Constructor inputs should be:

```php
public function __construct(
    private Thought $thought,
    private ?NewsletterResearchStatusPresenter $newsletterStatus,
    private int $currentReplyableIndex,
) {
    if ($thought->parent_id !== null) {
        $this->requireRelationLoaded($thought, 'parent');
    }

    $this->requireRelationLoaded($thought, 'comments');
}
```

- [ ] **Step 4: Implement `StreamThoughtCardPresenter`**

Create `app/View/Presenters/Thoughts/StreamThoughtCardPresenter.php` with methods like:

```php
public function thought(): Thought
public function isEditable(): bool
public function share(): mixed
public function activityAtHuman(): string
public function newsletterStatus(): ?NewsletterResearchStatusPresenter
```

Move the Jira `jira_updated_at` parsing from Blade into `activityAtHuman()`.

- [ ] **Step 5: Build presenter collections in every `IdeaController` index/stream code path**

For paginator-backed `idea.index` paths, build cards from the paginator collection:

```php
$replyableIndex = 0;
$cards = $thoughts->getCollection()->map(function (Thought $thought) use (&$replyableIndex, $newsletterResearchStatuses) {
    $current = $thought->parent_id ? -1 : $replyableIndex++;

    return new IdeaIndexCardPresenter(
        thought: $thought,
        newsletterStatus: $newsletterResearchStatuses[$thought->id] ?? null,
        currentReplyableIndex: $current,
    );
});
```

Update:

- the full-page `index()` return used by `resources/views/idea/index.blade.php`
- the index AJAX branch that currently renders `view('idea.index_thought_cards', ...)`

For collection-backed `idea.index` paths you can use plain `->map(...)`.

For paginator-backed `idea.stream`, typed stream variants, and stream AJAX branches, use the paginator collection or `through(...)` rather than assuming a plain collection:

```php
$cards = $thoughts->getCollection()->map(fn (Thought $thought) => new StreamThoughtCardPresenter(
    thought: $thought,
    share: $shareByThoughtId[$thought->id] ?? null,
    newsletterStatus: $newsletterResearchStatuses[$thought->id] ?? null,
));
```

Update every path that currently renders `view('idea.stream_thoughts', ...)` or includes `@include('idea.stream_thoughts', ...)`, including the main stream action, helper methods such as `streamCollectionResponse()`, and any JSON/AJAX branches, so they all pass the same `$cards` contract.

- [ ] **Step 6: Update the parent views and partials to consume presenter cards**

In `resources/views/idea/index.blade.php`, change the include to pass `$cards`:

```blade
@include('idea.index_thought_cards', ['cards' => $cards])
```

In `resources/views/idea/stream.blade.php`, change the include to pass `$cards`:

```blade
@include('idea.stream_thoughts', ['cards' => $cards, 'showFullSections' => (bool) $tag])
```

In `resources/views/idea/index_thought_cards.blade.php`:

```blade
@foreach ($cards as $card)
    @php($thought = $card->thought())
    <div data-thought-id="{{ $thought->id }}" data-reply-href="{{ $card->replyHref() }}">
        <span>{{ $card->createdAtHuman() }}</span>
        @include('idea.partials.email_newsletter_research_status', ['newsletterResearchStatus' => $card->newsletterStatus()])
    </div>
@endforeach
```

In `resources/views/idea/stream_thoughts.blade.php`, do the same and replace the inline Jira timestamp logic with `{{ $card->activityAtHuman() }}`.

Keep the existing actions, editable-content include, comments, type badge, and tag row markup. The only goal of this task is to move the current `@php` card-state derivation into presenters, not to redesign the rest of the card HTML.

- [ ] **Step 7: Re-run the feed feature tests**

Run:

```bash
php artisan test tests/Feature/IdeaPageTest.php tests/Feature/StreamPageTest.php tests/Feature/EmailThoughtStatusDisplayTest.php -v
```

Expected: PASS.

- [ ] **Step 8: Commit the card presenter work**

```bash
git add app/View/Presenters/Thoughts/IdeaIndexCardPresenter.php app/View/Presenters/Thoughts/StreamThoughtCardPresenter.php app/Http/Controllers/IdeaController.php resources/views/idea/index_thought_cards.blade.php resources/views/idea/stream_thoughts.blade.php tests/Feature/IdeaPageTest.php tests/Feature/StreamPageTest.php tests/Feature/EmailThoughtStatusDisplayTest.php
git commit -m "refactor: move feed card display state into presenters"
```

Include `resources/views/idea/index.blade.php` and `resources/views/idea/stream.blade.php` in the commit if you changed their includes as planned.

---

## Task 6: Add presenter-backed ideas and completed lists

**Files:**
- Create: `app/View/Presenters/Ideas/IdeaListItemPresenter.php`
- Create: `app/View/Presenters/Ideas/CompletedIdeaPresenter.php`
- Create: `tests/Unit/View/Presenters/Ideas/IdeaListItemPresenterTest.php`
- Create: `tests/Unit/View/Presenters/Ideas/CompletedIdeaPresenterTest.php`
- Modify: `app/Http/Controllers/IdeaController.php`
- Modify: `resources/views/idea/ideas.blade.php`
- Modify: `resources/views/idea/completed.blade.php`
- Modify: `resources/views/idea/partials/ideas_list.blade.php`
- Modify: `resources/views/idea/partials/completed_ideas_list.blade.php`
- Modify: `tests/Feature/IdeaIdeasTest.php`
- Modify: `tests/Feature/CompletedIdeasPageTest.php`

- [ ] **Step 1: Add failing unit coverage for incomplete and completed idea presenters**

Create `tests/Unit/View/Presenters/Ideas/IdeaListItemPresenterTest.php` with coverage like:

```php
public function test_presenter_exposes_logged_date_and_research_state(): void
{
    $thought = Thought::factory()->make([
        'metadata' => ['type' => 'idea', 'logged_date' => '2025-03-01', 'research_pending' => true],
    ]);

    $presenter = new IdeaListItemPresenter($thought, collect());

    $this->assertSame('2025-03-01', $presenter->loggedDate());
    $this->assertTrue($presenter->isResearchPending());
    $this->assertFalse($presenter->hasResearchResults());
}
```

Create `tests/Unit/View/Presenters/Ideas/CompletedIdeaPresenterTest.php` with cases like:

```php
public function test_presenter_formats_logged_and_completed_labels(): void
{
    $thought = Thought::factory()->make([
        'metadata' => [
            'type' => 'idea',
            'completed' => true,
            'logged_date' => '2025-04-01',
            'completed_at' => '2026-03-24T15:30:00+00:00',
        ],
    ]);

    $presenter = new CompletedIdeaPresenter($thought);

    $this->assertSame('April 1, 2025', $presenter->loggedLabel());
    $this->assertSame('March 24, 2026', $presenter->completedLabel());
}
```

Also add a malformed-completed-at case that returns `—`.

- [ ] **Step 2: Extend feature tests for the list pages**

In `tests/Feature/IdeaIdeasTest.php`, keep the current list behavior assertions and add checks for presenter-backed outputs such as:

```php
$response->assertSee('Research this idea');
$response->assertSee('2025-03-01');
```

In `tests/Feature/CompletedIdeasPageTest.php`, keep the current date-string assertions and add a fallback marker assertion:

```php
$response->assertSee('Completed —', false);
```

- [ ] **Step 3: Run the ideas/completed tests and verify they fail when the new presenters are missing**

Run:

```bash
php artisan test tests/Unit/View/Presenters/Ideas/IdeaListItemPresenterTest.php tests/Unit/View/Presenters/Ideas/CompletedIdeaPresenterTest.php tests/Feature/IdeaIdeasTest.php tests/Feature/CompletedIdeasPageTest.php -v
```

Expected: FAIL because the presenter classes do not exist yet.

- [ ] **Step 4: Implement the incomplete-ideas and completed-ideas presenters**

Create `app/View/Presenters/Ideas/IdeaListItemPresenter.php` with methods like:

```php
public function thought(): Thought
public function loggedDate(): string
public function isResearchPending(): bool
public function hasResearchResults(): bool
public function researchItems(): Collection
```

Use the existing `Thought::getLoggedDate()` and `Thought::isResearchPending()` methods since they are query-free metadata helpers.

Create `app/View/Presenters/Ideas/CompletedIdeaPresenter.php` with methods like:

```php
public function thought(): Thought
public function loggedLabel(): string
public function completedLabel(): string
```

Use the same Carbon formatting currently in Blade:

```php
Carbon::parse($thought->getLoggedDate(), config('app.timezone'))->format('F j, Y');
```

- [ ] **Step 5: Build presenter rows in `IdeaController@ideas` and `IdeaController@completed`**

In `ideas()`:

```php
$ideaRows = $ideas->getCollection()->map(
    fn (Thought $thought) => new IdeaListItemPresenter(
        thought: $thought,
        researchList: $researchByIdea->get($thought->id, collect()),
    )
);
```

Update both:

- the full-page `return view('idea.ideas', ...)`
- the AJAX/refetch branch that currently renders `view('idea.partials.ideas_list', ...)`

so they each pass `ideas` plus `ideaRows`.

In `completed()`:

```php
$completedRows = $ideas->getCollection()->map(
    fn (Thought $thought) => new CompletedIdeaPresenter($thought)
);
```

Pass both the paginator and the presenter rows to the views so pagination markup stays intact.

- [ ] **Step 6: Update the parent views and simplify the list partials**

In `resources/views/idea/ideas.blade.php`, change the include to pass both the paginator and presenter rows:

```blade
@include('idea.partials.ideas_list', ['ideas' => $ideas, 'ideaRows' => $ideaRows])
```

In `resources/views/idea/completed.blade.php`, do the same:

```blade
@include('idea.partials.completed_ideas_list', ['ideas' => $ideas, 'completedRows' => $completedRows])
```

Update the `ideas()` AJAX branch in `IdeaController` to render:

```php
view('idea.partials.ideas_list', [
    'ideas' => $ideas,
    'ideaRows' => $ideaRows,
])
```

so realtime refetches use the same presenter contract as the full-page render.

In `resources/views/idea/partials/ideas_list.blade.php`:

```blade
@foreach ($ideaRows as $row)
    @php($thought = $row->thought())
    <p class="text-[11px] text-slate-brand/50 mt-1">{{ $row->loggedDate() }}</p>
    @if ($row->isResearchPending())
        ...
    @elseif (! $row->hasResearchResults())
        ...
    @else
        @foreach ($row->researchItems() as $research)
            ...
        @endforeach
    @endif
@endforeach
```

In `resources/views/idea/partials/completed_ideas_list.blade.php`:

```blade
@foreach ($completedRows as $row)
    @php($thought = $row->thought())
    <p class="text-[11px] text-slate-brand/50 mt-1">Logged {{ $row->loggedLabel() }}</p>
    <p class="text-[11px] text-slate-brand/50 mt-0.5">Completed {{ $row->completedLabel() }}</p>
@endforeach
```

- [ ] **Step 7: Re-run the ideas/completed tests**

Run:

```bash
php artisan test tests/Unit/View/Presenters/Ideas/IdeaListItemPresenterTest.php tests/Unit/View/Presenters/Ideas/CompletedIdeaPresenterTest.php tests/Feature/IdeaIdeasTest.php tests/Feature/CompletedIdeasPageTest.php -v
```

Expected: PASS.

- [ ] **Step 8: Commit the ideas/completed presenter refactor**

```bash
git add app/View/Presenters/Ideas/IdeaListItemPresenter.php app/View/Presenters/Ideas/CompletedIdeaPresenter.php app/Http/Controllers/IdeaController.php resources/views/idea/ideas.blade.php resources/views/idea/completed.blade.php resources/views/idea/partials/ideas_list.blade.php resources/views/idea/partials/completed_ideas_list.blade.php tests/Unit/View/Presenters/Ideas/IdeaListItemPresenterTest.php tests/Unit/View/Presenters/Ideas/CompletedIdeaPresenterTest.php tests/Feature/IdeaIdeasTest.php tests/Feature/CompletedIdeasPageTest.php
git commit -m "refactor: move idea list formatting into presenters"
```

---

## Task 7: Add query-budget coverage and final verification

**Files:**
- Create: `tests/Feature/ViewPresenterQueryBudgetTest.php`
- Modify: `tests/TestCase.php` only if a small shared helper is genuinely needed; otherwise keep query-count helpers local to the new test file

- [ ] **Step 1: Add failing query-budget tests for the riskiest pages**

Create `tests/Feature/ViewPresenterQueryBudgetTest.php` with focused route-level checks. Use one helper that records executed queries with `DB::listen()` and enables lazy-loading prevention for the duration of the test:

```php
private function assertQueryBudget(callable $callback, int $maxQueries): void
{
    $queries = [];
    DB::listen(function ($event) use (&$queries): void {
        $queries[] = $event->sql;
    });

    Model::preventLazyLoading();

    try {
        $callback();
    } finally {
        Model::preventLazyLoading(false);
    }

    $this->assertLessThanOrEqual($maxQueries, count($queries), implode("\n", $queries));
}
```

Add cases for:

1. `settings.email-accounts.index`
2. `idea.index`
3. `idea.stream`
4. `idea.ideas`
5. `idea.completed`
6. `thoughts.show` for an email thought

Seed 15-25 rows per list page so an O(n) regression is visible.

- [ ] **Step 2: Run the new query-budget test and verify it fails**

Run:

```bash
php artisan test tests/Feature/ViewPresenterQueryBudgetTest.php -v
```

Expected: FAIL until the routes are fully presenter-backed and the settings page no longer queries per row.

- [ ] **Step 3: Tune the budgets after measuring the green implementation**

Once Tasks 2-6 are green, set conservative per-route thresholds based on the measured steady-state query counts. Keep the thresholds tight enough to catch row-by-row regressions, not just catastrophic spikes.

Example structure:

```php
$this->assertQueryBudget(
    fn () => $this->actingAs($user)->get(route('settings.email-accounts.index'))->assertOk(),
    maxQueries: 4,
);
```

Use the real measured number plus a small buffer. Do not guess high.

- [ ] **Step 4: Run the full focused suite**

Run:

```bash
php artisan test \
  tests/Unit/View/Presenters \
  tests/Feature/EmailAccountSettingsTest.php \
  tests/Feature/EmailThoughtStatusDisplayTest.php \
  tests/Feature/ThoughtShowPageTest.php \
  tests/Feature/IdeaPageTest.php \
  tests/Feature/StreamPageTest.php \
  tests/Feature/IdeaIdeasTest.php \
  tests/Feature/CompletedIdeasPageTest.php \
  tests/Feature/ViewPresenterQueryBudgetTest.php -v
```

Expected: PASS.

- [ ] **Step 5: Do a manual browser verification sweep**

Check these routes manually while signed in:

1. `settings/email-accounts`
2. `idea.index`
3. `idea.stream`
4. `idea.ideas`
5. `idea.completed`
6. one email `thoughts.show` page

Verify:

- latest sync text still renders correctly
- newsletter research badge/link/skip-info UI still works
- email metadata sidebar content still matches before/after behavior
- feed cards still show links, reply affordances, tags, and comments
- ideas/completed lists still show the same dates and research actions
- no lazy-loading exceptions are thrown in development

- [ ] **Step 6: Commit the query-budget coverage**

```bash
git add tests/Feature/ViewPresenterQueryBudgetTest.php tests/TestCase.php
git commit -m "test: guard presenter pages against query regressions"
```

Only include `tests/TestCase.php` in this commit if it actually changed.

---

## Notes for the Implementer

- Keep the controller query boundary intact. Presenters should never call `syncRuns()`, `comments()`, `importedEmail()`, or similar query-entry methods.
- It is acceptable for presenters to call query-free metadata helpers already on `Thought`, such as `getLoggedDate()`, `getIdeaCompletedAt()`, and `isResearchPending()`, but do not call model methods unless you have verified they are query-free.
- Do not broaden this refactor into unrelated layout cleanup. The `layouts.idea` composer that counts inbox items should only be touched if it blocks the query-budget tests for the presenter-backed pages.
- When passing nested presenter data into shared partials, prefer presenter instances or small scalar/array payloads over raw models.
- If a page already has a good controller-side helper that produces arrays, keep it temporarily and wrap that output with a presenter rather than rewriting the data source in the same task.
- Standardizing repeated presenter-mapping code inside `IdeaController` is a useful follow-up cleanup, but it is optional in this sweep unless the repetition is actively getting in the way of the refactor.

## Recommended Execution Order

1. Task 1
2. Task 2
3. Task 3
4. Task 4
5. Task 5
6. Task 6
7. Task 7

This order removes the only confirmed view query first, then extracts the reusable email presenters, then applies the pattern to the larger list surfaces, and finishes with explicit query-regression guardrails.
