<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class MailAccount extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'provider',
        'display_name',
        'account_email',
        'status',
        'credentials_json',
        'settings_json',
        'provider_checkpoint_json',
        'last_synced_at',
        'last_successful_sync_at',
    ];

    protected function casts(): array
    {
        return [
            'credentials_json' => 'encrypted:array',
            'settings_json' => 'array',
            'provider_checkpoint_json' => 'array',
            'last_synced_at' => 'datetime',
            'last_successful_sync_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function syncRuns(): HasMany
    {
        return $this->hasMany(MailSyncRun::class);
    }

    public function latestSyncRun(): HasOne
    {
        return $this->hasOne(MailSyncRun::class)->latestOfMany('started_at');
    }

    public function importedEmails(): HasMany
    {
        return $this->hasMany(ImportedEmail::class);
    }
}
