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
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
            'action' => ['nullable', 'string'],
        ]);

        $filterAction = $this->resolvedFilterAction($request);
        $filterQ = trim((string) ($validated['q'] ?? ''));

        $query = $request->user()->emailSenderRules()->orderBy('sender_email');

        if ($filterAction !== null) {
            $query->where('action', $filterAction);
        }

        if ($filterQ !== '') {
            $escaped = addcslashes($filterQ, '%_\\');
            $query->whereRaw('LOWER(sender_email) LIKE ?', ['%'.mb_strtolower($escaped).'%']);
        }

        $rules = $query->paginate(25)->withQueryString();

        return view('settings.email-sender-rules', [
            'rules' => $rules,
            'filterAction' => $filterAction,
            'filterQ' => $filterQ,
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
                ->route('settings.email-sender-rules.index', $this->filterRedirectQuery($request))
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
            ->route('settings.email-sender-rules.index', $this->filterRedirectQuery($request))
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
            ->route('settings.email-sender-rules.index', $this->filterRedirectQuery($request))
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
            ->route('settings.email-sender-rules.index', $this->filterRedirectQuery($request))
            ->with('success', 'Sender rule removed.');
    }

    /**
     * @return array{action?: string, q?: string}
     */
    private function filterRedirectQuery(Request $request): array
    {
        $query = [];
        $action = $this->resolvedFilterAction($request);
        if ($action !== null) {
            $query['action'] = $action;
        }

        $q = $this->resolvedFilterQ($request);
        if ($q !== '') {
            $query['q'] = $q;
        }

        return $query;
    }

    private function resolvedFilterAction(Request $request): ?string
    {
        $raw = $request->input('filter_action', $request->query('action'));
        if (! is_string($raw) || $raw === '' || $raw === 'all') {
            return null;
        }

        return EmailSenderRule::isValidAction($raw) ? $raw : null;
    }

    private function resolvedFilterQ(Request $request): string
    {
        $raw = $request->input('filter_q', $request->query('q', ''));
        if (! is_string($raw)) {
            return '';
        }

        return trim(mb_substr($raw, 0, 255));
    }
}
