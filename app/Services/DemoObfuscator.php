<?php

namespace App\Services;

final class DemoObfuscator
{
    private const PLACEHOLDER = 'Demo content hidden';

    public function __construct(
        private readonly DemoMode $demoMode,
        private readonly DemoObfuscationGenerator $generator,
    ) {}

    public function obfuscate(?string $text, string $fieldContext): ?string
    {
        try {
            if ($text === null) {
                return null;
            }

            if ($text === '') {
                return '';
            }

            $seed = $this->demoMode->seed();
            if ($seed === null || $seed === '') {
                return self::PLACEHOLDER;
            }

            $normalized = $this->normalizeInput($text);

            if ($normalized === '') {
                return '';
            }

            return $this->generator->generate($normalized, $fieldContext, $seed);
        } catch (\Throwable) {
            return self::PLACEHOLDER;
        }
    }

    private function normalizeInput(string $text): string
    {
        $trimmed = trim($text);

        if ($trimmed === '') {
            return '';
        }

        if (class_exists(\Normalizer::class)) {
            $normalized = \Normalizer::normalize($trimmed, \Normalizer::FORM_C);
            if ($normalized === false) {
                throw new \InvalidArgumentException('Unicode normalization failed');
            }

            return $normalized;
        }

        return $trimmed;
    }
}

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
