<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Cashier\Billable;
use Laravel\Cashier\Subscription;

class User extends Authenticatable
{
    use HasFactory, Notifiable, Billable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'google_id',
        'github_id',
        'lifetime_access',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'lifetime_access' => 'boolean',
            'trial_ends_at' => 'datetime',
        ];
    }

    /**
     * Get the operations for the user.
     */
    public function operations()
    {
        return $this->hasMany(Operation::class);
    }

    /**
     * Check if user has unlimited access (Pro or Lifetime).
     */
    public function hasUnlimitedAccess(): bool
    {
        if ($this->lifetime_access) {
            return true;
        }

        return $this->subscribed('default');
    }

    /**
     * Get the number of operations remaining today for free users.
     */
    public function operationsRemainingToday(): int
    {
        if ($this->hasUnlimitedAccess()) {
            return -1; // Unlimited
        }

        $operationsToday = $this->operations()
            ->whereDate('created_at', today())
            ->count();

        return max(0, 3 - $operationsToday);
    }

    /**
     * Check if user can perform an operation today.
     */
    public function canPerformOperation(): bool
    {
        if ($this->hasUnlimitedAccess()) {
            return true;
        }

        return $this->operationsRemainingToday() > 0;
    }
}
