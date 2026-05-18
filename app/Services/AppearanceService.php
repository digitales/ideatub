<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserPreference;
use Illuminate\Contracts\Session\Session;
use Illuminate\Validation\ValidationException;

class AppearanceService
{
    public const SESSION_KEY = 'appearance';

    /**
     * @return list<string>
     */
    public function allowed(): array
    {
        return ['light', 'dark', 'system'];
    }

    public function default(): string
    {
        return 'system';
    }

    public function getStored(User $user): string
    {
        $stored = UserPreference::get($user, UserPreference::KEY_APPEARANCE, $this->default());

        return is_string($stored) && in_array($stored, $this->allowed(), true)
            ? $stored
            : $this->default();
    }

    public function hydrateSession(User $user, Session $session): void
    {
        $session->put(self::SESSION_KEY, $this->getStored($user));
    }

    public function current(Session $session): string
    {
        $value = $session->get(self::SESSION_KEY, $this->default());

        return is_string($value) && in_array($value, $this->allowed(), true)
            ? $value
            : $this->default();
    }

    public function set(User $user, Session $session, string $appearance): void
    {
        if (! in_array($appearance, $this->allowed(), true)) {
            throw ValidationException::withMessages([
                'appearance' => ['The appearance value is invalid.'],
            ]);
        }

        $session->put(self::SESSION_KEY, $appearance);
        UserPreference::set($user, UserPreference::KEY_APPEARANCE, $appearance);
    }

    public function isEffectivelyDark(string $appearance): bool
    {
        return $appearance === 'dark';
    }
}
