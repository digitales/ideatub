<?php

namespace App\Support;

use Illuminate\Contracts\Session\Session;
use Illuminate\Validation\ValidationException;

class RegistrationGate
{
    public const SESSION_KEY = 'beta_access_verified';

    public static function isOpen(): bool
    {
        return (bool) config('registration.enabled', true);
    }

    public static function requiresBetaCode(): bool
    {
        $code = config('registration.beta_access_code');

        return is_string($code) && $code !== '';
    }

    public static function validateBetaCode(?string $code): bool
    {
        if (! self::requiresBetaCode()) {
            return true;
        }

        $expected = config('registration.beta_access_code');

        return is_string($code)
            && $code !== ''
            && hash_equals($expected, $code);
    }

    public static function canCreateNewUser(Session $session): bool
    {
        if (! self::isOpen()) {
            return false;
        }

        if (self::requiresBetaCode() && ! $session->get(self::SESSION_KEY, false)) {
            return false;
        }

        return true;
    }

    public static function markBetaAccessVerified(Session $session): void
    {
        $session->put(self::SESSION_KEY, true);
    }

    public static function forgetBetaAccessVerified(Session $session): void
    {
        $session->forget(self::SESSION_KEY);
    }

    /**
     * @throws ValidationException
     */
    public static function assertBetaCode(?string $code): void
    {
        if (! self::requiresBetaCode()) {
            return;
        }

        if (! self::validateBetaCode($code)) {
            throw ValidationException::withMessages([
                'beta_code' => 'A valid beta access code is required.',
            ]);
        }
    }
}
