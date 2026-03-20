<?php

namespace App\Http\Controllers;

use App\Jobs\BackfillMailAccount;
use App\Jobs\SyncMailAccountIncremental;
use App\Exceptions\InvalidMailAccountCredentialsException;
use App\Models\MailAccount;
use App\Services\Fastmail\FastmailConnector;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class EmailAccountSettingsController extends Controller
{
    public function __construct(
        private readonly FastmailConnector $connector
    ) {}

    public function index(Request $request): View
    {
        abort_unless(config('services.mail_sync.enabled', true), 404);

        return view('settings.email-accounts', [
            'mailAccounts' => $request->user()->mailAccounts()->latest()->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless(config('services.mail_sync.enabled', true), 404);

        $validated = $request->validate([
            'provider' => 'required|string|in:fastmail',
            'display_name' => 'required|string|max:255',
            'account_email' => 'required|email|max:255',
            'credential' => 'required|string|max:1000',
            'sync_enabled' => 'sometimes|nullable|boolean',
            'include_sent' => 'sometimes|nullable|boolean',
            'include_received_personal' => 'sometimes|nullable|boolean',
            'exclude_bulk' => 'sometimes|nullable|boolean',
            'initial_backfill_window_days' => 'required|integer|in:30,90,365',
        ]);

        try {
            $connection = $this->connector->validateCredentials([
                'account_email' => $validated['account_email'],
                'credential' => $validated['credential'],
            ]);
        } catch (InvalidMailAccountCredentialsException $e) {
            return redirect()
                ->route('settings.email-accounts.index')
                ->withInput($request->except('credential'))
                ->with('error', $e->getMessage());
        }

        $mailAccount = MailAccount::query()->updateOrCreate(
            [
                'user_id' => $request->user()->id,
                'provider' => $validated['provider'],
                'account_email' => $connection['account_email'],
            ],
            [
                'display_name' => trim($validated['display_name']),
                'status' => 'active',
                'credentials_json' => [
                    'credential' => $validated['credential'],
                    'account_id' => $connection['account_id'],
                    'api_url' => $connection['api_url'],
                ],
                'settings_json' => [
                    'sync_enabled' => $request->boolean('sync_enabled'),
                    'include_sent' => $request->boolean('include_sent'),
                    'include_received_personal' => $request->boolean('include_received_personal'),
                    'exclude_bulk' => $request->boolean('exclude_bulk'),
                    'initial_backfill_window_days' => (int) $validated['initial_backfill_window_days'],
                    'aliases' => $connection['aliases'],
                ],
            ]
        );

        BackfillMailAccount::dispatch($mailAccount->id);

        return redirect()
            ->route('settings.email-accounts.index')
            ->with('success', 'Fastmail account saved.');
    }

    public function destroy(Request $request, MailAccount $mailAccount): RedirectResponse
    {
        abort_unless(config('services.mail_sync.enabled', true), 404);

        abort_unless($mailAccount->user_id === $request->user()->id, 403);

        $mailAccount->delete();

        return redirect()
            ->route('settings.email-accounts.index')
            ->with('success', 'Mail account disconnected.');
    }

    public function backfill(Request $request, MailAccount $mailAccount): RedirectResponse
    {
        abort_unless(config('services.mail_sync.enabled', true), 404);

        abort_unless($mailAccount->user_id === $request->user()->id, 403);

        BackfillMailAccount::dispatch($mailAccount->id);

        return redirect()
            ->route('settings.email-accounts.index')
            ->with('success', 'Backfill queued.');
    }

    public function syncNow(Request $request, MailAccount $mailAccount): RedirectResponse
    {
        abort_unless(config('services.mail_sync.enabled', true), 404);

        abort_unless($mailAccount->user_id === $request->user()->id, 403);

        SyncMailAccountIncremental::dispatch($mailAccount->id);

        return redirect()
            ->route('settings.email-accounts.index')
            ->with('success', 'Incremental sync queued.');
    }
}
