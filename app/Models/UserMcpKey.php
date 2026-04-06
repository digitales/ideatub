<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserMcpKey extends Model
{
    /**
     * Algorithm used to hash MCP keys for storage and lookup.
     * Must be deterministic so the same plain key hashes to the same value.
     */
    public const KEY_HASH_ALGO = 'sha256';

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'user_mcp_keys';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'key_hash',
        'label',
        'last_used_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
        ];
    }

    /**
     * Get the user that owns the MCP key.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Hash a plain MCP key for storage or lookup.
     * Uses HMAC-SHA256 keyed on app.key so hashes are secret-dependent and
     * cannot be brute-forced from the hash alone.
     */
    public static function hashKey(string $plainKey): string
    {
        return hash_hmac('sha256', $plainKey, config('app.key'));
    }

    /**
     * Find a UserMcpKey by its plain key (hashes and looks up).
     * Returns null if not found.
     */
    public static function findByPlainKey(string $plainKey): ?self
    {
        $hash = self::hashKey($plainKey);

        return self::query()->where('key_hash', $hash)->first();
    }
}
