<?php

namespace App\Jobs;

use App\Models\EmailSenderRule;
use App\Models\Thought;
use App\Models\User;
use App\Services\Email\ThoughtEmailSenderResolver;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ReconcileIgnoredSenderThoughtVisibility implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly int $userId,
        public readonly string $senderEmail,
    ) {}

    public function handle(ThoughtEmailSenderResolver $resolver): void
    {
        if (! config('services.email_sender_policy.enabled')) {
            return;
        }

        $user = User::query()->find($this->userId);
        if ($user === null) {
            return;
        }

        $rule = EmailSenderRule::query()
            ->where('user_id', $user->id)
            ->where('sender_email', $this->senderEmail)
            ->first();

        $isIgnored = $rule !== null && $rule->action === EmailSenderRule::ACTION_IGNORE;

        Thought::query()
            ->where('user_id', $user->id)
            ->whereNull('parent_id')
            ->matchingCanonicalSourceType('email')
            ->orderBy('id')
            ->chunkById(100, function ($thoughts) use ($resolver, $isIgnored): void {
                foreach ($thoughts as $thought) {
                    $resolved = $resolver->resolve($thought);
                    if ($resolved === null || $resolved !== $this->senderEmail) {
                        continue;
                    }

                    if ($isIgnored) {
                        $this->hideIfEligible($thought);
                    } else {
                        $this->restoreIfIgnoredSenderOnly($thought);
                    }
                }
            });
    }

    private function hideIfEligible(Thought $thought): void
    {
        $visible = (bool) $thought->is_visible_in_stream;
        $reason = $thought->visibility_reason;

        if (! ($visible || $reason === Thought::VISIBILITY_REASON_IGNORED_SENDER)) {
            return;
        }

        if ($reason !== null && $reason !== Thought::VISIBILITY_REASON_IGNORED_SENDER) {
            return;
        }

        Thought::withoutEvents(function () use ($thought): void {
            Thought::query()->whereKey($thought->id)->update([
                'is_visible_in_stream' => false,
                'visibility_reason' => Thought::VISIBILITY_REASON_IGNORED_SENDER,
            ]);
        });
    }

    private function restoreIfIgnoredSenderOnly(Thought $thought): void
    {
        if ($thought->visibility_reason !== Thought::VISIBILITY_REASON_IGNORED_SENDER) {
            return;
        }

        Thought::withoutEvents(function () use ($thought): void {
            Thought::query()->whereKey($thought->id)->update([
                'is_visible_in_stream' => true,
                'visibility_reason' => null,
            ]);
        });
    }
}
