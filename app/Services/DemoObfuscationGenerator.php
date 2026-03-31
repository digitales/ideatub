<?php

namespace App\Services;

class DemoObfuscationGenerator
{
    /** @var list<string> */
    private const WORD_BANK = [
        'amber', 'basin', 'coral', 'delta', 'ember', 'fjord', 'grove', 'harbor',
        'ivory', 'juniper', 'kelp', 'lumen', 'meadow', 'nova', 'oasis', 'prairie',
        'quartz', 'ridge', 'sable', 'terra', 'umbra', 'vista', 'willow', 'zenith',
        'aurora', 'breeze', 'cedar', 'dune', 'elm', 'falcon', 'glacier', 'haven',
    ];

    public function generate(string $normalized, string $fieldContext, string $seed): string
    {
        $payload = $normalized."\0".$fieldContext;
        $digest = hash_hmac('sha256', $payload, $seed, true);
        $wordCount = min(40, max(5, ord($digest[0]) % 24 + 5));

        $words = [];
        $bankCount = count(self::WORD_BANK);
        for ($i = 0; $i < $wordCount; $i++) {
            $byte = ord($digest[($i % 32) + 1]);
            $words[] = self::WORD_BANK[$byte % $bankCount];
        }

        return implode(' ', $words);
    }
}
