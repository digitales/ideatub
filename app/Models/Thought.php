<?php

namespace App\Models;

use App\Contracts\Commentable;
use App\Events\ThoughtCreated;
use App\Jobs\SyncThoughtToEvernote;
use App\Models\Concerns\HasComments;
use App\Services\EvernoteService;
use App\Support\Comments\ShareContext;
use App\Support\ThoughtTypeNavigation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Pgvector\Laravel\Distance;
use Pgvector\Laravel\HasNeighbors;
use Pgvector\Laravel\Vector;

class Thought extends Model implements Commentable
{
    use HasComments;
    use HasFactory;
    use HasNeighbors;
    use HasUuids;

    public const VISIBILITY_REASON_IGNORED_SENDER = 'ignored_sender';

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

        static::deleting(function (Thought $thought): void {
            if ($thought->source !== 'email') {
                return;
            }

            ImportedEmail::query()
                ->where('thought_id', $thought->id)
                ->update([
                    'thought_id' => null,
                    'thought_deleted_at' => now(),
                ]);
        });

        // Hide thoughts that were migrated into the polymorphic comments table
        // (old reply-shaped child thoughts). Use withoutGlobalScope('non_migrated')
        // to inspect them (e.g. backfill verification or rollback).
        static::addGlobalScope('non_migrated', function (Builder $query): void {
            $driver = $query->getModel()->getConnection()->getDriverName();
            $query->where(function (Builder $inner) use ($driver): void {
                $inner->whereNull('metadata');
                if ($driver === 'pgsql') {
                    $inner->orWhereRaw("metadata->>'migrated_to_comment' IS DISTINCT FROM 'true'");
                } else {
                    // sqlite / mysql-ish fallback; keeps tests runnable without pgsql.
                    $inner->orWhereRaw("COALESCE(json_extract(metadata, '$.migrated_to_comment'), '') <> 'true'");
                }
            });
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
        'is_visible_in_stream',
        'visibility_reason',
        'content_fingerprint',
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
            'is_visible_in_stream' => 'boolean',
        ];
    }

    /**
     * Scope: order by nearest to the given embedding (cosine distance).
     *
     * @param  array<float>|Vector  $embedding
     */
    public function scopeNearestTo(Builder $query, array|Vector $embedding, int $limit = 10): void
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
     * Normalise content before saving (decode HTML entities) and refresh content_sha256.
     *
     * NOTE: content_sha256 is a derived column; it MUST stay in sync with the stored,
     * decoded `content` value. Any code path that writes `content` via a raw DB query
     * (e.g. Schema::table(...)->update([...])) bypasses this mutator and will produce
     * a stale hash — re-run `php artisan thoughts:backfill-content-sha256` to heal.
     */
    protected function setContentAttribute(mixed $value): void
    {
        $decoded = static::decodeContentEntities((string) $value);
        $this->attributes['content'] = $decoded;
        $this->attributes['content_sha256'] = hash('sha256', $decoded);
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
     * Get child thoughts (e.g. document sections under a research root).
     *
     * Renamed from `comments()` to avoid collision with the polymorphic
     * `HasComments` trait. Research/meeting/document-section iteration uses
     * this; user-authored discussion is handled by `$thought->comments()`
     * (the MorphMany from the trait).
     */
    public function childThoughts(): HasMany
    {
        return $this->hasMany(Thought::class, 'parent_id');
    }

    public function isMicrositeDocumentLayout(): bool
    {
        return data_get($this->source_metadata, 'document_layout') === 'microsite';
    }

    public function isMicrositeRoot(): bool
    {
        return $this->isMicrositeDocumentLayout() && $this->parent_id === null;
    }

    /**
     * In-app research reader URL for microsite roots/pages; null to use the standard thought detail view.
     */
    public function inAppResearchReaderUrl(): ?string
    {
        if ($this->isMicrositeRoot()) {
            return route('idea.research.show', $this);
        }
        if ($this->isMicrositeDocumentLayout() && $this->parent_id !== null) {
            $parent = $this->relationLoaded('parent')
                ? $this->getRelation('parent')
                : $this->parent()->first();
            if ($parent instanceof self && $parent->isMicrositeRoot()) {
                $seg = (string) data_get($this->source_metadata, 'page_path_segment', '');
                if ($seg === '' || strcasecmp($seg, 'index') === 0) {
                    return route('idea.research.show', $parent);
                }

                return route('idea.research.page', [
                    'thought' => $parent,
                    'page' => $seg,
                ]);
            }
        }

        return null;
    }

    /**
     * URL for list links (e.g. project members): microsite research reader, or default thought page.
     */
    public function ideaTubViewUrl(): string
    {
        return $this->inAppResearchReaderUrl() ?? route('thoughts.show', $this);
    }

    /**
     * Child thoughts that belong to a microsite (same as {@see childThoughts()}; no SQL ordering).
     * Use {@see micrositePageChildrenInOrder()} for nav and other display order.
     *
     * @return HasMany<Thought, $this>
     */
    public function childThoughtsForMicrosite(): HasMany
    {
        return $this->childThoughts();
    }

    /**
     * Microsite child pages ordered by numeric `import_order` in `source_metadata` (1, 2, … 10, 11), not string sort.
     *
     * @return Collection<int, Thought>
     */
    public function micrositePageChildrenInOrder(): Collection
    {
        return self::sortByMicrositeImportOrder(
            $this->childThoughtsForMicrosite()->get()
        );
    }

    /**
     * @param  Collection<int, Thought>  $thoughts
     * @return Collection<int, Thought>
     */
    public static function sortByMicrositeImportOrder(Collection $thoughts): Collection
    {
        return $thoughts
            ->sort(function (Thought $a, Thought $b) {
                $o = self::micrositeImportOrderAsInt($a)
                    <=> self::micrositeImportOrderAsInt($b);
                if ($o !== 0) {
                    return $o;
                }

                return strcmp((string) $a->id, (string) $b->id);
            })
            ->values();
    }

    private static function micrositeImportOrderAsInt(Thought $t): int
    {
        $o = data_get($t->source_metadata, 'import_order');
        if ($o === null || $o === '') {
            return 2147483646;
        }
        if (is_int($o)) {
            return $o;
        }
        if (is_float($o)) {
            return (int) $o;
        }
        if (is_string($o) && is_numeric($o)) {
            return (int) $o;
        }

        return 2147483646;
    }

    /**
     * @param  ?string  $pagePathSegment  from query or shared URL; null/empty = index (this root)
     */
    public function findMicrositePageByPathSegment(?string $pagePathSegment): ?Thought
    {
        if ($pagePathSegment === null || $pagePathSegment === '') {
            return $this;
        }
        if ((string) data_get($this->source_metadata, 'page_path_segment') === $pagePathSegment) {
            return $this;
        }

        return $this->childThoughtsForMicrosite()
            ->get()
            ->first(
                fn (Thought $c) => (string) data_get($c->source_metadata, 'page_path_segment') === $pagePathSegment
            );
    }

    /**
     * @return BelongsToMany<Project, $this>
     */
    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class, 'project_thought')
            ->using(ProjectThought::class)
            ->withPivot('sort_order')
            ->withTimestamps()
            ->orderByPivot('sort_order');
    }

    /**
     * @return HasMany<ThoughtLink, $this>
     */
    public function linksFrom(): HasMany
    {
        return $this->hasMany(ThoughtLink::class, 'from_thought_id');
    }

    /**
     * @return HasMany<ThoughtLink, $this>
     */
    public function linksTo(): HasMany
    {
        return $this->hasMany(ThoughtLink::class, 'to_thought_id');
    }

    /**
     * Link summary rows whose source content is this thought (e.g. newsletter email).
     *
     * @return HasMany<ThoughtLinkSummary, $this>
     */
    public function sourceLinkSummaries(): HasMany
    {
        return $this->hasMany(ThoughtLinkSummary::class, 'source_thought_id');
    }

    /**
     * Link summary rows attached to this thought as parent research context.
     *
     * @return HasMany<ThoughtLinkSummary, $this>
     */
    public function researchLinkSummaries(): HasMany
    {
        return $this->hasMany(ThoughtLinkSummary::class, 'parent_research_thought_id');
    }

    /**
     * Research runs where this thought is the idea being researched.
     *
     * @return HasMany<ResearchRun, $this>
     */
    public function researchRuns(): HasMany
    {
        return $this->hasMany(ResearchRun::class, 'idea_thought_id');
    }

    /**
     * Research runs where this thought is the idea being researched.
     *
     * @return HasMany<ResearchRun, $this>
     */
    public function ideaResearchRuns(): HasMany
    {
        return $this->researchRuns();
    }

    /**
     * Meeting runs where this thought is the source meeting thought.
     *
     * @return HasMany<MeetingRun, $this>
     */
    public function meetingRuns(): HasMany
    {
        return $this->hasMany(MeetingRun::class, 'meeting_thought_id');
    }

    public function importedEmail(): ?ImportedEmail
    {
        $importedEmailId = data_get($this->source_metadata, 'imported_email_id');

        if ($importedEmailId !== null) {
            $importedEmail = ImportedEmail::query()
                ->where('user_id', $this->user_id)
                ->find($importedEmailId);

            if ($importedEmail !== null) {
                return $importedEmail;
            }
        }

        return ImportedEmail::query()
            ->where('user_id', $this->user_id)
            ->where('thought_id', $this->id)
            ->first();
    }

    /**
     * Scope to thoughts with metadata type 'idea'.
     */
    public function scopeIdeas(Builder $query): Builder
    {
        return $query->where('metadata->type', 'idea');
    }

    /**
     * Scope to exclude thoughts matching the canonical research type (e.g. from recent/feed).
     */
    public function scopeExcludingResearch(Builder $query): Builder
    {
        return $this->scopeExcludingCanonicalMetadataType($query, 'research');
    }

    /**
     * Scope to exclude thoughts matching the canonical Jira type (e.g. from homepage recent and main stream).
     */
    public function scopeExcludingJira(Builder $query): Builder
    {
        return $this->scopeExcludingCanonicalSourceType($query, 'jira');
    }

    public function scopeMatchingCanonicalSourceType(Builder $query, string $canonicalType): Builder
    {
        return self::applyCanonicalTypeMatch(
            $query,
            'LOWER(COALESCE(source, ?))',
            ThoughtTypeNavigation::storedValuesForCollection($canonicalType),
            true,
            ['']
        );
    }

    public function scopeExcludingCanonicalSourceType(Builder $query, string $canonicalType): Builder
    {
        return self::applyCanonicalTypeMatch(
            $query,
            'LOWER(COALESCE(source, ?))',
            ThoughtTypeNavigation::storedValuesForCollection($canonicalType),
            false,
            ['']
        );
    }

    public function scopeMatchingCanonicalMetadataType(Builder $query, string $canonicalType): Builder
    {
        return self::applyCanonicalTypeMatch(
            $query,
            self::canonicalMetadataTypeSqlExpression($query),
            ThoughtTypeNavigation::storedValuesForCollection($canonicalType),
            true
        );
    }

    public function scopeExcludingCanonicalMetadataType(Builder $query, string $canonicalType): Builder
    {
        return self::applyCanonicalTypeMatch(
            $query,
            self::canonicalMetadataTypeSqlExpression($query),
            ThoughtTypeNavigation::storedValuesForCollection($canonicalType),
            false
        );
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
     * Whether this idea is completed using the same semantics as completed idea listings:
     * strict completed flag, or any non-empty completed_at value.
     */
    public function isIdeaCompleted(): bool
    {
        return ($this->metadata['completed'] ?? false) === true
            || $this->hasMeaningfulIdeaCompletedAt();
    }

    /**
     * Parse metadata.completed_at to a Carbon instance, or null if missing/invalid.
     */
    public function getIdeaCompletedAt(): ?Carbon
    {
        $raw = data_get($this->metadata, 'completed_at');
        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        if (preg_match('/^(?<year>\d{4})-(?<month>\d{2})-(?<day>\d{2})$/', $raw, $matches) === 1) {
            if (! self::isValidIdeaCompletedAtDateParts($matches['year'], $matches['month'], $matches['day'])) {
                return null;
            }

            try {
                return Carbon::createFromFormat('Y-m-d H:i:sP', $raw.' 00:00:00+00:00');
            } catch (\Throwable) {
                return null;
            }
        }

        if (preg_match('/^(?<date>\d{4}-\d{2}-\d{2})(?<separator>[T ])(?<hour>\d{2}):(?<minute>\d{2}):(?<second>\d{2})(?<fraction>\.\d+)?(?<timezone>Z|[+-]\d{2}:\d{2})$/', $raw, $matches) !== 1) {
            return null;
        }

        [$year, $month, $day] = explode('-', $matches['date']);

        if (! self::isValidIdeaCompletedAtDateParts($year, $month, $day)) {
            return null;
        }

        if (! self::isValidIdeaCompletedAtTimeParts($matches['hour'], $matches['minute'], $matches['second'])) {
            return null;
        }

        if (! self::isValidIdeaCompletedAtTimezone($matches['timezone'])) {
            return null;
        }

        $normalized = $matches['date'].$matches['separator'].$matches['hour'].':'.$matches['minute'].':'.$matches['second'];

        if (($matches['fraction'] ?? null) !== null) {
            $fraction = substr(str_pad(substr($matches['fraction'], 1), 6, '0'), 0, 6);
            $normalized .= '.'.$fraction;
        }

        $normalized .= $matches['timezone'] === 'Z' ? '+00:00' : $matches['timezone'];

        try {
            return Carbon::createFromFormat(
                ($matches['separator'] === 'T' ? 'Y-m-d\TH:i:s' : 'Y-m-d H:i:s')
                .(isset($fraction) ? '.u' : '')
                .'P',
                $normalized
            );
        } catch (\Throwable) {
            return null;
        }
    }

    private function hasMeaningfulIdeaCompletedAt(): bool
    {
        $completedAt = data_get($this->metadata, 'completed_at');

        return is_string($completedAt) && trim($completedAt) !== '';
    }

    private static function isValidIdeaCompletedAtDateParts(string $year, string $month, string $day): bool
    {
        return checkdate((int) $month, (int) $day, (int) $year);
    }

    private static function isValidIdeaCompletedAtTimeParts(string $hour, string $minute, string $second): bool
    {
        return (int) $hour >= 0
            && (int) $hour <= 23
            && (int) $minute >= 0
            && (int) $minute <= 59
            && (int) $second >= 0
            && (int) $second <= 59;
    }

    private static function isValidIdeaCompletedAtTimezone(string $timezone): bool
    {
        if ($timezone === 'Z') {
            return true;
        }

        return preg_match('/^[+-](?<hour>\d{2}):(?<minute>\d{2})$/', $timezone, $matches) === 1
            && (int) $matches['hour'] >= 0
            && (int) $matches['hour'] <= 23
            && (int) $matches['minute'] >= 0
            && (int) $matches['minute'] <= 59;
    }

    /**
     * Scope to incomplete ideas:
     * metadata.completed is not strict true and metadata.completed_at is missing/null/empty.
     *
     * @param  Builder<Thought>  $query
     * @return Builder<Thought>
     */
    public function scopeIncompleteIdeas(Builder $query): Builder
    {
        $query->ideas();

        $driver = $query->getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            return $query
                ->whereRaw("(metadata->'completed')::jsonb IS DISTINCT FROM 'true'::jsonb")
                ->whereRaw("((metadata->>'completed_at') IS NULL OR TRIM(metadata->>'completed_at') = '')");
        }

        if ($driver === 'sqlite') {
            return $query
                ->whereRaw("(json_type(metadata, '$.completed') IS NULL OR json_type(metadata, '$.completed') != 'true')")
                ->whereRaw("((json_extract(metadata, '$.completed_at') IS NULL OR TRIM(COALESCE(json_extract(metadata, '$.completed_at'), '')) = ''))");
        }

        throw new \InvalidArgumentException(sprintf(
            'Unsupported database driver [%s] for incomplete idea filtering.',
            $driver
        ));
    }

    /**
     * Scope to completed ideas: metadata.type idea and either metadata.completed is strict JSON true,
     * or metadata.completed_at is non-empty after trim (same non-empty rule as {@see scopeIncompleteIdeas}).
     * Malformed timestamps still count as non-empty so they are listed with completed ideas, not orphaned.
     *
     * @param  Builder<Thought>  $query
     * @return Builder<Thought>
     */
    public function scopeCompletedIdeas(Builder $query): Builder
    {
        $query->ideas();

        $driver = $query->getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            return $query->where(function (Builder $q): void {
                $q->whereRaw("(metadata->'completed')::jsonb = 'true'::jsonb")
                    ->orWhereRaw("(metadata->>'completed_at') IS NOT NULL AND TRIM(metadata->>'completed_at') <> ''");
            });
        }

        if ($driver === 'sqlite') {
            return $query->where(function (Builder $q): void {
                $q->whereRaw("json_type(metadata, '$.completed') = 'true'")
                    ->orWhereRaw('(json_extract(metadata, \'$.completed_at\') IS NOT NULL AND TRIM(COALESCE(json_extract(metadata, \'$.completed_at\'), \'\')) != \'\')');
            });
        }

        throw new \InvalidArgumentException(sprintf(
            'Unsupported database driver [%s] for completed idea filtering.',
            $driver
        ));
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
     * Scope to thoughts that should appear in stream-style listings (index recent, stream, search, API browse, etc.).
     * Rows with is_visible_in_stream = false are excluded. Email rows additionally require is_visible_in_stream = true.
     */
    public function scopeVisibleInStream(Builder $query): Builder
    {
        $emailSourceValues = ThoughtTypeNavigation::storedValuesForCollection('email');

        return $query->where(function (Builder $q) use ($emailSourceValues): void {
            $q->where(function (Builder $q2): void {
                $q2->whereNull('is_visible_in_stream')
                    ->orWhere('is_visible_in_stream', true);
            })->where(function (Builder $q) use ($emailSourceValues): void {
                $q->where(function (Builder $nonEmail) use ($emailSourceValues): void {
                    self::applyCanonicalTypeMatch(
                        $nonEmail,
                        'LOWER(COALESCE(source, ?))',
                        $emailSourceValues,
                        false,
                        ['']
                    );
                })->orWhere(function (Builder $email) use ($emailSourceValues): void {
                    self::applyCanonicalTypeMatch(
                        $email,
                        'LOWER(COALESCE(source, ?))',
                        $emailSourceValues,
                        true,
                        ['']
                    );

                    $email->where('is_visible_in_stream', true);
                });
            });
        });
    }

    /**
     * Whether this thought is the root of a long-form capture document that may be shared (MCP doc types).
     */
    public function isShareableDocumentRoot(): bool
    {
        if ($this->parent_id !== null) {
            return false;
        }

        $sourceKey = ThoughtTypeNavigation::normalizeTypeKey($this->source);
        if ($sourceKey === 'email' || $sourceKey === 'jira') {
            return false;
        }

        $metadata = $this->metadata;
        $typeRaw = is_array($metadata) ? ($metadata['type'] ?? null) : null;
        if (! is_string($typeRaw)) {
            return false;
        }
        $normalized = mb_strtolower(trim($typeRaw));
        if ($normalized === '') {
            return false;
        }
        if ($normalized === 'video') {
            return false;
        }

        $extraTypes = ['decision', 'dev', 'support', 'spec'];
        if (! in_array($normalized, $extraTypes, true)) {
            $navKey = ThoughtTypeNavigation::normalizeTypeKey($typeRaw);
            if (! in_array($navKey, ['research', 'plan', 'meeting'], true)) {
                return false;
            }
        }

        return self::query()->whereKey($this->id)->visibleInStream()->exists();
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

        $driver = DB::connection()->getDriverName();
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
        $driver = DB::connection()->getDriverName();

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

    /**
     * @param  list<string>  $storedValues
     * @param  list<string>  $prefixBindings
     */
    private static function applyCanonicalTypeMatch(
        Builder $query,
        string $expression,
        array $storedValues,
        bool $include,
        array $prefixBindings = []
    ): Builder {
        if ($storedValues === []) {
            return $include ? $query->whereRaw('0 = 1') : $query;
        }

        $operator = $include ? 'IN' : 'NOT IN';
        $placeholders = implode(', ', array_fill(0, count($storedValues), '?'));

        return $query->whereRaw(
            $expression.' '.$operator.' ('.$placeholders.')',
            [...$prefixBindings, ...$storedValues]
        );
    }

    private static function canonicalMetadataTypeSqlExpression(Builder $query): string
    {
        return $query->getModel()->getConnection()->getDriverName() === 'pgsql'
            ? "LOWER(COALESCE(metadata->>'type', ''))"
            : "LOWER(COALESCE(json_extract(metadata, '$.type'), ''))";
    }

    public function commentableOwnerId(): ?int
    {
        return $this->user_id;
    }

    /**
     * Owner may always comment; guests may comment only when arriving via a
     * share that targets this thought's research root and has comments enabled.
     */
    public function authorizeCommentCreation(?User $user, ?ShareContext $shareContext): bool
    {
        if ($user !== null && $this->user_id === $user->id) {
            return true;
        }

        if ($shareContext === null || ! $shareContext->allowComments) {
            return false;
        }

        if ($shareContext->researchThoughtId === $this->id) {
            return true;
        }

        return $this->parent_id === $shareContext->researchThoughtId;
    }
}
