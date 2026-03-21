<?php

namespace App\Services\Email;

use App\Models\EmailSenderRule;
use App\Models\User;

class EmailSenderRuleService
{
    private const MAILBOX_PATTERN = '[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9-]+(?:\.[a-zA-Z0-9-]+)+';

    public function normalizeSender(string $rawSender): string
    {
        return $this->extractFirstMailbox($rawSender) ?? '';
    }

    /**
     * Resolve the sender policy for an inbound address string.
     *
     * @return array{action: string, normalized_sender: string, rule_id: int|null, raw_sender: string}
     */
    public function resolveForUser(User $user, string $rawSender): array
    {
        $normalized = $this->extractFirstMailbox($rawSender);

        if ($normalized === null) {
            return [
                'action' => EmailSenderRule::ACTION_REVIEW,
                'normalized_sender' => '',
                'rule_id' => null,
                'raw_sender' => $rawSender,
            ];
        }

        $rule = $user->emailSenderRules()
            ->where('sender_email', $normalized)
            ->first();

        if ($rule === null) {
            return [
                'action' => EmailSenderRule::ACTION_REVIEW,
                'normalized_sender' => $normalized,
                'rule_id' => null,
                'raw_sender' => $rawSender,
            ];
        }

        return [
            'action' => $rule->action,
            'normalized_sender' => $normalized,
            'rule_id' => $rule->id,
            'raw_sender' => $rawSender,
        ];
    }

    private function extractFirstMailbox(string $raw): ?string
    {
        $trimmed = trim($raw);
        if ($trimmed === '') {
            return null;
        }

        if (preg_match_all('/<\s*('.self::MAILBOX_PATTERN.')\s*>/', $trimmed, $angleMatches)) {
            foreach ($angleMatches[1] as $addr) {
                $normalized = $this->normalizeMailbox($addr);
                if ($normalized !== '') {
                    return $normalized;
                }
            }
        }

        if (preg_match('/\b('.self::MAILBOX_PATTERN.')\b/', $trimmed, $plainMatch)) {
            return $this->normalizeMailbox($plainMatch[1]);
        }

        return null;
    }

    private function normalizeMailbox(string $addr): string
    {
        return mb_strtolower(trim($addr));
    }
}
