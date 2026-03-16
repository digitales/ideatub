<?php

namespace App\Models;

use App\Events\ThoughtCreated;
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
            if ($thought->source === 'jira') {
                return;
            }
            if (app(EvernoteService::class)->isConfigured()) {
                SyncThoughtToEvernote::dispatch($thought->id);
            }
        };

        static::created($dispatchSync);
        static::updated($dispatchSync);

        static::created(function (Thought $thought): void {
            if (config('realtime.driver') === 'reverb') {
                broadcast(new ThoughtCreated($thought))->toOthers();
            }
        });
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
     * Decode HTML entities in a string (e.g. &#039; → ', &quot; → ").
     * Decodes repeatedly so double-encoded entities (e.g. &amp;#039;) are normalized.
     * Normalizes numeric entities missing a trailing semicolon (e.g. &#039s → &#039;s) so PHP can decode them.
     */
    public static function decodeContentEntities(string $content): string
    {
        $decoded = $content;
        $flags = ENT_QUOTES | ENT_HTML5;

        // PHP's html_entity_decode does not decode numeric entities without trailing semicolon (e.g. &#039s, &#x27s).
        // Add semicolon so they decode (e.g. &#039s → &#039;s → ').
        $decoded = preg_replace_callback('/&#(\d+)(?![;\d])/u', fn ($m) => '&#'.$m[1].';', $decoded);
        $decoded = preg_replace_callback('/&#x([0-9a-fA-F]+)(?![;0-9a-fA-F])/u', fn ($m) => '&#x'.$m[1].';', $decoded);

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
     * Normalize content before saving: decode HTML entities so we store plain text.
     */
    protected function setContentAttribute(mixed $value): void
    {
        $this->attributes['content'] = static::decodeContentEntities((string) $value);
    }

    /**
     * Return content with HTML entities decoded (plain text). Never exposes raw DB value.
     * Use with e() in views: {{ e($thought->content) }} so output is safe for HTML.
     */
    protected function getContentAttribute(mixed $value): string
    {
        return static::decodeContentEntities((string) ($value ?? ''));
    }

    /**
     * Raw stored value (for migrations, normalize command, debugging). Do not use for display.
     */
    public function getRawContent(): string
    {
        return (string) ($this->attributes['content'] ?? '');
    }

    /**
     * @deprecated Use $thought->content (accessor now returns decoded). Kept for compatibility.
     */
    public function getDecodedContent(): string
    {
        return $this->content;
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
     * Scope to thoughts with metadata type 'idea'.
     */
    public function scopeIdeas(Builder $query): Builder
    {
        return $query->where('metadata->type', 'idea');
    }

    /**
     * Scope to exclude thoughts with metadata type 'research' (e.g. from recent/feed).
     */
    public function scopeExcludingResearch(Builder $query): Builder
    {
        return $query->where(function (Builder $q) {
            $q->where('metadata->type', '!=', 'research')
                ->orWhereNull('metadata->type');
        });
    }

    /**
     * Scope to research thoughts linked to the given idea (metadata type research, idea_id = $ideaId).
     */
    public function scopeResearchForIdea(Builder $query, string $ideaId): Builder
    {
        return $query->where('metadata->type', 'research')->where('metadata->idea_id', $ideaId);
    }

    /**
     * Get logged date: metadata.logged_date if set, otherwise created_at date.
     */
    public function getLoggedDate(): string
    {
        return $this->metadata['logged_date'] ?? $this->created_at->toDateString();
    }

    /**
     * Whether this idea is completed (metadata.completed === true).
     */
    public function isIdeaCompleted(): bool
    {
        return ($this->metadata['completed'] ?? false) === true;
    }

    /**
     * Whether research is currently running for this idea (metadata.research_pending === true).
     */
    public function isResearchPending(): bool
    {
        return ($this->metadata['research_pending'] ?? false) === true;
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
     * Scope to thoughts that have at least one tag equal to the normalized query or containing it (substring).
     * Query must be normalized (trimmed, lowercase) by the caller.
     * Null-safe for missing metadata or metadata->tags.
     *
     * @param  Builder<Thought>  $query
     * @return Builder<Thought>
     */
    public function scopeTagMatchesQuery(Builder $query, string $normalizedQuery): Builder
    {
        $normalizedQuery = mb_strtolower(trim($normalizedQuery));
        if ($normalizedQuery === '') {
            return $query->whereRaw('0 = 1');
        }

        $driver = \Illuminate\Support\Facades\DB::connection()->getDriverName();
        $likePattern = '%'.static::escapeForLike($normalizedQuery).'%';

        if ($driver === 'pgsql') {
            // Exact: jsonb array contains the string. Contains: any element LIKE %query%.
            $query->where(function (Builder $q) use ($normalizedQuery, $likePattern): void {
                $q->whereJsonContains('metadata->tags', $normalizedQuery)
                    ->orWhereRaw(
                        "EXISTS (SELECT 1 FROM jsonb_array_elements_text(COALESCE((metadata->'tags')::jsonb, '[]'::jsonb)) AS t WHERE t LIKE ?)",
                        [$likePattern]
                    );
            });

            return $query;
        }

        // SQLite: json_each(metadata, '$.tags') exposes key, value; use value for match.
        $query->where(function (Builder $q) use ($normalizedQuery, $likePattern): void {
            $q->whereRaw(
                "EXISTS (SELECT 1 FROM json_each(COALESCE(json_extract(metadata, '$.tags'), '[]')) WHERE value = ?)",
                [$normalizedQuery]
            )->orWhereRaw(
                "EXISTS (SELECT 1 FROM json_each(COALESCE(json_extract(metadata, '$.tags'), '[]')) WHERE value LIKE ?)",
                [$likePattern]
            );
        });

        return $query;
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

    /**
     * Escape % and _ for safe use in LIKE patterns. Use with parameter binding.
     */
    public static function escapeForLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
