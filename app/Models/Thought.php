<?php

namespace App\Models;

use App\Jobs\SyncThoughtToEvernote;
use App\Services\EvernoteService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Pgvector\Laravel\Distance;
use Pgvector\Laravel\HasNeighbors;
use Pgvector\Laravel\Vector;

class Thought extends Model
{
    use HasFactory;
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
        'source',
        'source_metadata',
        'parent_id',
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
            'source_metadata' => 'array',
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
     * Scope: nearest thoughts within a max cosine distance (only relevant results).
     * Use for search so the list is not padded with low-relevance items.
     * Caller should apply ->paginate() or ->take() as needed.
     *
     * @param  array<float>|Vector  $embedding
     */
    public function scopeNearestWithin(Builder $query, array|Vector $embedding, float $maxDistance): void
    {
        $vector = is_array($embedding) ? json_encode($embedding) : (string) $embedding;
        $query->whereNotNull('embedding')
            ->whereRaw('embedding <=> ?::vector <= ?', [$vector, $maxDistance])
            ->orderByRaw('embedding <=> ?::vector', [$vector]);
    }

    /**
     * Get content with HTML entities decoded for display.
     * Decodes repeatedly so double-encoded entities (e.g. &amp;#039;) also render correctly.
     * Use with e() in views to avoid showing literal &quot;, &#039;, etc.
     */
    public function getDecodedContent(): string
    {
        $decoded = $this->content;
        $flags = ENT_QUOTES | ENT_HTML5;
        while (true) {
            $prev = $decoded;
            $decoded = html_entity_decode($decoded, $flags, 'UTF-8');
            if ($decoded === $prev) {
                break;
            }
        }

        return $decoded;
    }

    /**
     * Get the user that owns the thought.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the parent thought (for comments).
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Thought::class, 'parent_id');
    }

    /**
     * Get child thoughts (comments on this thought).
     */
    public function comments(): HasMany
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

    /**
     * Scope to thoughts that have no tags (metadata null, or tags missing/empty).
     * Used by the periodic extract-untagged command so only untagged content is reprocessed.
     */
    public function scopeWithoutTags(Builder $query): Builder
    {
        $driver = \Illuminate\Support\Facades\DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            return $query->whereRaw("metadata IS NULL OR metadata->'tags' IS NULL OR (metadata->'tags')::jsonb = '[]'::jsonb");
        }

        // SQLite: json_array_length() takes the JSON value, not the column + path
        return $query->whereRaw("metadata IS NULL OR json_extract(metadata, '$.tags') IS NULL OR json_array_length(json_extract(metadata, '$.tags')) = 0");
    }

    /**
     * Normalize metadata so tags are lowercase (and trimmed). Returns a new array.
     *
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    public static function normalizeMetadataTags(array $metadata): array
    {
        if (! isset($metadata['tags']) || ! is_array($metadata['tags'])) {
            return $metadata;
        }
        $metadata['tags'] = array_values(array_map(
            fn ($tag) => mb_strtolower(trim((string) $tag)),
            array_filter($metadata['tags'], fn ($t) => trim((string) $t) !== '')
        ));

        return $metadata;
    }
}
