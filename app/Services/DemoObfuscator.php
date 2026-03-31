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
