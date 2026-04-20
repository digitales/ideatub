<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ResearchShare extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'thought_id',
        'token',
        'password_hash',
        'expires_at',
        'allow_comments',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'allow_comments' => 'bool',
        ];
    }

    /**
     * Get the user that owns the share.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the thought that is shared.
     */
    public function thought(): BelongsTo
    {
        return $this->belongsTo(Thought::class);
    }

    /**
     * Generate a new URL-safe token for a share.
     */
    public static function generateToken(): string
    {
        return Str::random(32);
    }

    /**
     * Whether this share has passed its expiry time.
     */
    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
