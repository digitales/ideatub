<?php

namespace App\Services;

use Illuminate\Support\Str;

final class DemoMode
{
    public const ENABLED_SESSION_KEY = 'demo_mode.enabled';

    public const SEED_SESSION_KEY = 'demo_mode.seed';

    public function enabled(): bool
    {
        return (bool) session(self::ENABLED_SESSION_KEY, false);
    }

    public function enable(): void
    {
        session([self::ENABLED_SESSION_KEY => true]);

        $existing = session(self::SEED_SESSION_KEY);
        if ($existing === null || $existing === '') {
            session([self::SEED_SESSION_KEY => Str::random(40)]);
        }
    }

    public function disable(): void
    {
        session()->forget([self::ENABLED_SESSION_KEY, self::SEED_SESSION_KEY]);
    }

    public function seed(): ?string
    {
        $seed = session(self::SEED_SESSION_KEY);

        if ($seed === null || $seed === '') {
            return null;
        }

        return (string) $seed;
    }
}
