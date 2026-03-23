<?php

namespace App\Http\Controllers;

use App\Jobs\ReconcileIgnoredSenderThoughtVisibility;
use App\Models\EmailSenderRule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class EmailSenderRuleSettingsController extends Controller
{
    public function __construct()
    {
        $this->middleware(function ($request, $next) {
            abort_unless(config('services.email_sender_policy.enabled'), 404);

            return $next($request);
        });
    }

    public function index(Request $request): View
    {
        $rules = $request->user()->emailSenderRules()->orderBy('sender_email')->get();

        return view('settings.email-sender-rules', [
            'rules' => $rules,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'sender_email' => ['required', 'email', 'max:255'],
            'action' => ['required', 'string', Rule::in(EmailSenderRule::actions())],
        ]);

        $senderEmail = mb_strtolower(trim($validated['sender_email']));

        if ($request->user()->emailSenderRules()->where('sender_email', $senderEmail)->exists()) {
            return redirect()
                ->route('settings.email-sender-rules.index')
                ->withInput()
                ->with('error', 'A rule for that sender email already exists.');
        }

        $request->user()->emailSenderRules()->create([
            'sender_email' => $senderEmail,
            'action' => $validated['action'],
        ]);

        if (config('services.email_sender_policy.enabled')) {
            ReconcileIgnoredSenderThoughtVisibility::dispatch((int) $request->user()->id, $senderEmail);
        }

        return redirect()
            ->route('settings.email-sender-rules.index')
            ->with('success', 'Sender rule added.');
    }

    public function update(Request $request, EmailSenderRule $emailSenderRule): RedirectResponse
    {
        abort_unless($emailSenderRule->user_id === $request->user()->id, 403);

        $validated = $request->validate([
            'action' => ['required', 'string', Rule::in(EmailSenderRule::actions())],
        ]);

        $emailSenderRule->update(['action' => $validated['action']]);

        if (config('services.email_sender_policy.enabled')) {
            ReconcileIgnoredSenderThoughtVisibility::dispatch((int) $request->user()->id, $emailSenderRule->sender_email);
        }

        return redirect()
            ->route('settings.email-sender-rules.index')
            ->with('success', 'Sender rule updated.');
    }

    public function destroy(Request $request, EmailSenderRule $emailSenderRule): RedirectResponse
    {
        abort_unless($emailSenderRule->user_id === $request->user()->id, 403);

        $senderEmail = $emailSenderRule->sender_email;
        $emailSenderRule->delete();

        if (config('services.email_sender_policy.enabled')) {
            ReconcileIgnoredSenderThoughtVisibility::dispatch((int) $request->user()->id, $senderEmail);
        }

        return redirect()
            ->route('settings.email-sender-rules.index')
            ->with('success', 'Sender rule removed.');
    }
}
