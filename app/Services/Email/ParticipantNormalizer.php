<?php

namespace App\Services\Email;

class ParticipantNormalizer
{
    /**
     * @param  array<int, array<string, mixed>>  $from
     * @param  array<int, array<string, mixed>>  $to
     * @param  array<int, array<string, mixed>>  $cc
     * @return array<int, array{role: string, email: string, name: ?string}>
     */
    public function normalize(array $from, array $to, array $cc): array
    {
        $participants = [];

        foreach (['from' => $from, 'to' => $to, 'cc' => $cc] as $role => $entries) {
            foreach ($entries as $entry) {
                $email = mb_strtolower(trim((string) ($entry['email'] ?? '')));
                if ($email === '') {
                    continue;
                }

                $participants[] = [
                    'role' => $role,
                    'email' => $email,
                    'name' => isset($entry['name']) ? trim((string) $entry['name']) : null,
                ];
            }
        }

        return $participants;
    }
}
