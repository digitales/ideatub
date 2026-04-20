# thought_comment_reads insert fails on PostgreSQL – Customer Support Investigation

**Date**: 2026-04-20
**Status**: Resolved
**Priority**: High
**Reported By**: Laravel Cloud production logs (userId=3, GET research thought page)

## Issue Description

`Illuminate\Database\QueryException` (SQLSTATE 42703):

```
column "id" does not exist
LINE 1: ...ught_id", "last_read_at") values ($1, $2, $3) returning "id"
```

SQL:

```
insert into "thought_comment_reads" ("user_id", "thought_id", "last_read_at")
values (3, 019dab5f-3bbd-722b-9388-f2ceec897a03, 2026-04-20 15:03:53)
returning "id"
```

- **Where**: `app/Models/ThoughtCommentRead.php:19` (via `static::updateOrCreate(...)`), called from `app/Http/Controllers/IdeaController.php:1404` (`ThoughtCommentRead::markRead`) when rendering `idea.research_show`.
- **When**: Any authenticated page-load of a research thought that triggers `markRead`.

## Customer Impact

- 500 errors on the research view for every logged-in user opening a thought.
- Unread-comment indicators never get updated because the write never lands.

## Investigation Steps

1. **Schema check** — `database/migrations/2026_04_20_000003_create_thought_comment_reads_table.php` defines the table with a composite primary key and **no `id` column**:

```11:16:database/migrations/2026_04_20_000003_create_thought_comment_reads_table.php
        Schema::create('thought_comment_reads', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('thought_id')->constrained('thoughts')->cascadeOnDelete();
            $table->timestamp('last_read_at');
            $table->primary(['user_id', 'thought_id']);
        });
```

2. **Model check** — `ThoughtCommentRead` did not override `$incrementing`, so Eloquent defaulted to `true`.

3. **Laravel behaviour** — On a new model, `Model::performInsert` branches on `getIncrementing()`:
   - `true`  → `insertAndSetId()` → `Query\Builder::insertGetId()` → `PostgresProcessor::processInsertGetId()` appends `returning "$sequence"` (default `id`).
   - `false` → plain `insert()`, no `RETURNING`.

   With the default `$incrementing = true`, Postgres was told `returning "id"`, which does not exist on this table, yielding 42703.

4. **Why tests passed** — `tests/Unit/ThoughtCommentReadTest` runs under `RefreshDatabase`. Locally the SQLite fallback is tolerant of `RETURNING id` against a table without such a column in some configurations, so the defect did not surface in CI until hitting Postgres in production (Laravel Cloud / Neon).

## Root Cause Analysis

The model used an auto-incrementing key assumption (`$incrementing = true` by default) on a table that intentionally has no surrogate `id` and instead uses a composite primary key `(user_id, thought_id)`. Eloquent therefore emitted `RETURNING "id"` on insert, which PostgreSQL strictly rejected.

## Resolution

Updated `app/Models/ThoughtCommentRead.php` to:

1. Declare the model as non-incrementing with a composite-style key (`$primaryKey = null`, `$keyType = 'string'`, `$incrementing = false`).
2. Replace `updateOrCreate` with `Builder::upsert()`, which compiles to a single `INSERT ... ON CONFLICT (user_id, thought_id) DO UPDATE SET last_read_at = ...` on PostgreSQL, eliminates the `RETURNING "id"` path entirely, and is atomic (no select-then-insert race).

```7:35:app/Models/ThoughtCommentRead.php
class ThoughtCommentRead extends Model
{
    public $timestamps = false;

    // Composite primary key (user_id, thought_id) — no auto-increment "id" column.
    // Without this, PostgreSQL inserts emit `returning "id"` and fail (42703).
    public $incrementing = false;

    protected $primaryKey = null;

    public $keyType = 'string';

    protected $fillable = ['user_id', 'thought_id', 'last_read_at'];

    protected $casts = [
        'last_read_at' => 'datetime',
    ];

    public static function markRead(int $userId, string $thoughtId): void
    {
        static::query()->upsert(
            [[
                'user_id' => $userId,
                'thought_id' => $thoughtId,
                'last_read_at' => now(),
            ]],
            ['user_id', 'thought_id'],
            ['last_read_at'],
        );
    }
}
```

## Customer Communication

- No direct comms required; redeploy to Laravel Cloud to roll the fix out. Affected requests succeed immediately after deploy.

## Prevention & Follow-up

- [ ] When creating tables with a composite primary key and no `id`, set `public $incrementing = false;` on the corresponding Eloquent model.
- [ ] Prefer `upsert()` for read-tracking / counter-style writes where concurrent requests can collide.
- [ ] Run at least one feature test covering this path against the production driver (PostgreSQL). The existing SQLite-based `RefreshDatabase` run does not exercise `PostgresProcessor::processInsertGetId`.

## Related Files

- `app/Models/ThoughtCommentRead.php`
- `app/Http/Controllers/IdeaController.php` (`markRead` call at the research show action)
- `database/migrations/2026_04_20_000003_create_thought_comment_reads_table.php`
- `tests/Unit/ThoughtCommentReadTest.php`
- Plan: `docs/superpowers/plans/2026-04-20-research-comments.md`

## Lessons Learned

PostgreSQL is strict about `RETURNING` columns; silent tolerance on other drivers can hide this class of bug. Any Eloquent model backed by a table without an `id` column must explicitly opt out of incrementing, and `upsert()` is usually a cleaner fit than `updateOrCreate()` for "last seen"-type writes.

## References

- Laravel source: `Illuminate\Database\Eloquent\Model::performInsert`, `Illuminate\Database\Query\Processors\PostgresProcessor::processInsertGetId`
- Laravel docs: [Upserts](https://laravel.com/docs/eloquent#upserts), [Primary Keys](https://laravel.com/docs/eloquent#primary-keys)
