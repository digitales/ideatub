<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

class Thought extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'content',
        'metadata',
        'parent_id',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    /**
     * Get the user that owns the thought.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the parent thought (for comments).
     */
    public function parent()
    {
        return $this->belongsTo(Thought::class, 'parent_id');
    }

    /**
     * Get child thoughts (comments on this thought).
     */
    public function comments()
    {
        return $this->hasMany(Thought::class, 'parent_id');
    }

    /**
     * Scope to top-level thoughts only (no parent).
     */
    public function scopeTopLevel(Builder $query): Builder
    {
        return $query->whereNull('parent_id');
    }

    /**
     * Scope to thoughts that are replies to the given thought.
     */
    public function scopeRepliesTo(Builder $query, Thought $thought): Builder
    {
        return $query->where('parent_id', $thought->id);
    }
}
