<?php

namespace App\Services;

use Illuminate\Support\Str;

final class DemoMode
{
    public const ENABLED_SESSION_KEY = 'demo_mode.enabled';

    public const SEED_SESSION_KEY = 'demo_mode.seed';

    /**
     * True when the demo feature is enabled in config and this session has opted in.
     * All obfuscation and demo-safe UI gating should use this (not the session flag alone).
     */
    public function enabled(): bool
    {
        if (! (bool) config('services.demo_mode.enabled', false)) {
            return false;
        }

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
