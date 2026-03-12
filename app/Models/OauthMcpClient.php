<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OauthMcpClient extends Model
{
    use HasUlids;

    protected $table = 'oauth_mcp_clients';

    protected $fillable = ['redirect_uris'];

    protected function casts(): array
    {
        return [
            'redirect_uris' => 'array',
        ];
    }

    public function authorizationCodes(): HasMany
    {
        return $this->hasMany(OauthMcpAuthorizationCode::class, 'client_id');
    }
}
