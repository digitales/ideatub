<?php

namespace App\Services\Email;

use App\Models\EmailSenderRule;
use App\Models\Thought;
use App\Models\User;

class EmailThoughtStreamVisibilityService
{
    public function __construct(
        private readonly EmailSenderRuleService $senderRuleService,
    ) {}

    /**
     * @return array{is_visible_in_stream: bool, visibility_reason: string|null}
     */
    public function decideVisibilityForUserAndRawSender(User $user, string $rawSender): array
    {
        $decision = $this->senderRuleService->resolveForUser($user, $rawSender);

        if ($decision['action'] === EmailSenderRule::ACTION_IGNORE) {
            return [
                'is_visible_in_stream' => false,
                'visibility_reason' => Thought::VISIBILITY_REASON_IGNORED_SENDER,
            ];
        }

        return [
            'is_visible_in_stream' => true,
            'visibility_reason' => null,
        ];
    }

    public function applyToThought(Thought $thought, User $user, string $rawSender): void
    {
        if (! config('services.email_sender_policy.enabled')) {
            return;
        }

        $thought->refresh();

        $state = $this->decideVisibilityForUserAndRawSender($user, $rawSender);

        if ($state['is_visible_in_stream']) {
            $this->applyVisibleState($thought, $state);

            return;
        }

        $this->hideIfEligible($thought, $state);
    }

    /**
     * @param  array{is_visible_in_stream: bool, visibility_reason: string|null}  $state
     */
    private function applyVisibleState(Thought $thought, array $state): void
    {
        if ($thought->visibility_reason !== null
            && $thought->visibility_reason !== Thought::VISIBILITY_REASON_IGNORED_SENDER) {
            return;
        }

        if ($thought->is_visible_in_stream === $state['is_visible_in_stream']
            && $thought->visibility_reason === $state['visibility_reason']) {
            return;
        }

        Thought::withoutEvents(function () use ($thought, $state): void {
            Thought::query()->whereKey($thought->id)->update([
                'is_visible_in_stream' => $state['is_visible_in_stream'],
                'visibility_reason' => $state['visibility_reason'],
            ]);
        });

        $thought->refresh();
    }

    /**
     * @param  array{is_visible_in_stream: bool, visibility_reason: string|null}  $state
     */
    private function hideIfEligible(Thought $thought, array $state): void
    {
        $visible = (bool) $thought->is_visible_in_stream;
        $reason = $thought->visibility_reason;

        if (! ($visible || $reason === Thought::VISIBILITY_REASON_IGNORED_SENDER)) {
            return;
        }

        if ($reason !== null && $reason !== Thought::VISIBILITY_REASON_IGNORED_SENDER) {
            return;
        }

        Thought::withoutEvents(function () use ($thought, $state): void {
            Thought::query()->whereKey($thought->id)->update([
                'is_visible_in_stream' => $state['is_visible_in_stream'],
                'visibility_reason' => $state['visibility_reason'],
            ]);
        });

        $thought->refresh();
    }
}
