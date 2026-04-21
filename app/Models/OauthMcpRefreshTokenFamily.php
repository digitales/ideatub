<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OauthMcpRefreshTokenFamily extends Model
{
    use HasUlids;

    protected $table = 'oauth_mcp_refresh_token_families';

    protected $fillable = [
        'user_id',
        'client_id',
        'resource',
        'scope',
        'user_agent',
        'ip_address',
        'last_used_at',
        'issued_at',
        'absolute_expires_at',
        'revoked_at',
        'revoked_reason',
    ];

    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
            'issued_at' => 'datetime',
            'absolute_expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(OauthMcpClient::class, 'client_id');
    }

    public function refreshTokens(): HasMany
    {
        return $this->hasMany(OauthMcpRefreshToken::class, 'family_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query
            ->whereNull('revoked_at')
            ->where('absolute_expires_at', '>', now());
    }
}
