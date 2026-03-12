<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OauthMcpAuthorizationCode extends Model
{
    use HasUlids;

    protected $table = 'oauth_mcp_authorization_codes';

    protected $fillable = [
        'code',
        'client_id',
        'user_id',
        'redirect_uri',
        'code_challenge',
        'code_challenge_method',
        'resource',
        'scope',
        'state',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(OauthMcpClient::class, 'client_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
