<?php

namespace App\Services\Email;

use App\Models\MailAccount;

class EmailFilterService
{
    /**
     * @return array{include: bool, reason: ?string}
     */
    public function evaluate(MailAccount $account, NormalizedEmailMessage $message): array
    {
        if ($message->direction === 'sent') {
            return [
                'include' => true,
                'reason' => null,
            ];
        }

        $fromAddresses = array_filter(array_map(
            static fn (array $entry) => mb_strtolower((string) ($entry['email'] ?? '')),
            $message->from
        ));

        foreach ($fromAddresses as $fromAddress) {
            if (str_contains($fromAddress, 'no-reply') || str_contains($fromAddress, 'noreply')) {
                return [
                    'include' => false,
                    'reason' => 'bulk_sender',
                ];
            }
        }

        $accountAddresses = array_filter(array_map('mb_strtolower', array_merge(
            [$account->account_email],
            $account->settings_json['aliases'] ?? []
        )));

        $targetAddresses = array_filter(array_map(
            static fn (array $entry) => mb_strtolower((string) ($entry['email'] ?? '')),
            array_merge($message->to, $message->cc)
        ));

        foreach ($targetAddresses as $targetAddress) {
            if (in_array($targetAddress, $accountAddresses, true)) {
                return [
                    'include' => true,
                    'reason' => null,
                ];
            }
        }

        return [
            'include' => false,
            'reason' => 'not_directly_addressed',
        ];
    }
}
