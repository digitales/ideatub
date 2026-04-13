<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Cashier\Billable;

class User extends Authenticatable
{
    use Billable, HasFactory, Notifiable;

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
     * Get the thoughts for the user.
     */
    public function thoughts()
    {
        return $this->hasMany(Thought::class);
    }

    /**
     * Get the MCP keys for the user.
     */
    public function userMcpKeys()
    {
        return $this->hasMany(UserMcpKey::class);
    }

    /**
     * Get the inbound email addresses for the user (for capture-by-email).
     */
    public function userInboundAddresses()
    {
        return $this->hasMany(UserInboundAddress::class);
    }

    /**
     * Get the connected mail accounts for the user.
     */
    public function mailAccounts()
    {
        return $this->hasMany(MailAccount::class);
    }

    /**
     * Get the per-user exact-sender email rules.
     */
    public function emailSenderRules()
    {
        return $this->hasMany(EmailSenderRule::class);
    }

    /**
     * Matched Postmark inbound emails captured for this user.
     */
    public function capturedInboundEmails()
    {
        return $this->hasMany(CapturedInboundEmail::class);
    }

    /**
     * Get the user's agent inbox items.
     */
    public function inboxItems()
    {
        return $this->hasMany(InboxItem::class);
    }

    /**
     * Get the user's Jira credentials (one per user when connected).
     */
    public function jiraCredential(): HasOne
    {
        return $this->hasOne(UserJiraCredential::class);
    }

    /**
     * Get the user's preferences (key-value).
     */
    public function preferences()
    {
        return $this->hasMany(UserPreference::class);
    }

    /**
     * Persisted research skills owned by this user.
     *
     * @return HasMany<ResearchSkill, $this>
     */
    public function researchSkills(): HasMany
    {
        return $this->hasMany(ResearchSkill::class);
    }

    /**
     * Research workflow runs initiated by this user.
     *
     * @return HasMany<ResearchRun, $this>
     */
    public function researchRuns(): HasMany
    {
        return $this->hasMany(ResearchRun::class);
    }

    /**
     * @return HasMany<Project, $this>
     */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    /**
     * @return HasMany<ThoughtLink, $this>
     */
    public function thoughtLinks(): HasMany
    {
        return $this->hasMany(ThoughtLink::class);
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
