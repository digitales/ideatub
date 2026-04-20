<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Comment extends Model
{
    use HasFactory;

    protected $fillable = [
        'commentable_type',
        'commentable_id',
        'author_user_id',
        'author_name',
        'content',
        'format',
        'ip_hash',
        'import_source',
    ];

    public function commentable(): MorphTo
    {
        return $this->morphTo();
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }

    public function isGuest(): bool
    {
        return $this->author_user_id === null;
    }

    public function displayName(): string
    {
        return $this->isGuest()
            ? (string) $this->author_name
            : (string) $this->author?->name;
    }

    public function scopeChronological($query)
    {
        return $query->orderBy('created_at');
    }
}
