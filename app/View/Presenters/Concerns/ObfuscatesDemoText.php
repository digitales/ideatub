<?php

namespace App\View\Presenters\Concerns;

use App\Services\DemoMode;
use App\Services\DemoObfuscator;

trait ObfuscatesDemoText
{
    protected function demoText(?string $value, string $context): ?string
    {
        if (! app(DemoMode::class)->enabled()) {
            return $value;
        }

        return app(DemoObfuscator::class)->obfuscate($value, $context);
    }
}
