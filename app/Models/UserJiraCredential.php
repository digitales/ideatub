<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserJiraCredential extends Model
{
    protected $fillable = [
        'user_id',
        'jira_site_url',
        'jira_api_token',
        'jira_email',
    ];

    protected function casts(): array
    {
        return [
            'jira_api_token' => 'encrypted',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
