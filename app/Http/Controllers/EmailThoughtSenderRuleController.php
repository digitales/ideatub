<?php

namespace App\Http\Controllers;

use App\Models\EmailSenderRule;
use App\Models\Thought;
use App\Services\Email\ThoughtEmailSenderContextResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class EmailThoughtSenderRuleController extends Controller
{
    public function __construct(
        private readonly ThoughtEmailSenderContextResolver $senderContextResolver,
    ) {
        $this->middleware(function ($request, $next) {
            abort_unless(config('services.email_sender_policy.enabled'), 404);

            return $next($request);
        });
    }

    public function store(Request $request, Thought $thought): RedirectResponse
    {
        $this->authorize('update', $thought);
        abort_unless($thought->source === 'email', 404);

        $validated = $request->validate([
            'action' => ['required', 'string', Rule::in(EmailSenderRule::actions())],
        ]);

        $sender = $this->resolvedSender($thought);
        if ($sender === null) {
            return redirect()
                ->route('thoughts.show', $thought)
                ->with('error', 'Sender rule unavailable for this email.');
        }

        $request->user()->emailSenderRules()->updateOrCreate(
            ['sender_email' => $sender],
            ['action' => $validated['action']]
        );

        return redirect()
            ->route('thoughts.show', $thought)
            ->with('success', 'Sender rule saved.');
    }

    public function destroy(Request $request, Thought $thought): RedirectResponse
    {
        $this->authorize('update', $thought);
        abort_unless($thought->source === 'email', 404);

        $sender = $this->resolvedSender($thought);
        if ($sender === null) {
            return redirect()
                ->route('thoughts.show', $thought)
                ->with('error', 'Sender rule unavailable for this email.');
        }

        $request->user()->emailSenderRules()
            ->where('sender_email', $sender)
            ->delete();

        return redirect()
            ->route('thoughts.show', $thought)
            ->with('success', 'Sender rule removed.');
    }

    private function resolvedSender(Thought $thought): ?string
    {
        $context = $this->senderContextResolver->resolve($thought);

        if (! ($context['sender_available'] ?? false)) {
            return null;
        }

        $sender = $context['normalized_sender'] ?? null;

        return is_string($sender) && $sender !== '' ? $sender : null;
    }
}
