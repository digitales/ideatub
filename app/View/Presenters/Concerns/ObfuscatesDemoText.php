<?php

namespace App\View\Presenters\Concerns;

use App\Services\DemoMode;
use App\Services\DemoObfuscator;

trait ObfuscatesDemoText
{
    protected function demoAwareText(?string $text, string $fieldContext): ?string
    {
        $demoMode = app(DemoMode::class);

        if (! $demoMode->enabled()) {
            return $text;
        }

        $obfuscator = new DemoObfuscator($demoMode->seed());

        return $obfuscator->obfuscate($text, $fieldContext);
    }
}
