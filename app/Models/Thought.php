<?php

namespace App\Models;

use App\Jobs\SyncThoughtToEvernote;
use App\Services\EvernoteService;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Pgvector\Laravel\Distance;
use Pgvector\Laravel\HasNeighbors;
use Pgvector\Laravel\Vector;

class Thought extends Model
{
    use HasNeighbors;
    use HasUuids;

    /**
     * Boot the model and register sync job dispatch on created/updated.
     */
    protected static function boot(): void
    {
        parent::boot();

        $dispatchSync = function (Thought $thought): void {
            if (app(EvernoteService::class)->isConfigured()) {
                SyncThoughtToEvernote::dispatch($thought->id);
            }
        };

        static::created($dispatchSync);
        static::updated($dispatchSync);
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'content',
        'embedding',
        'metadata',
        'user_id',
        'evernote_note_guid',
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
            'embedding' => Vector::class,
        ];
    }

    /**
     * Scope: order by nearest to the given embedding (cosine distance).
     *
     * @param  array<float>|Vector  $embedding
     */
    public function scopeNearestTo(\Illuminate\Database\Eloquent\Builder $query, array|Vector $embedding, int $limit = 10): void
    {
        $query->nearestNeighbors('embedding', $embedding, Distance::Cosine);
        $query->take($limit);
    }

    /**
     * Get the user that owns the thought.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
