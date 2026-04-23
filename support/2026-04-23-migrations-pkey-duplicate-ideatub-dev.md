# `migrations` primary key duplicate and partial migration — support investigation

**Date**: 2026-04-23  
**Status**: Resolved  
**Reported by**: Internal (migrate failure on `ideatub_dev`)

## Issue description

`php artisan migrate` failed with:

- `UniqueConstraintViolationException` on `migrations_pkey` — `Key (id)=(8) already exists` when inserting a row into `migrations`.
- After fixing the sequence, a second run failed with duplicate column `content_sha256` on `thoughts` (column existed but the migration was not recorded).

## Root cause

1. **Postgres serial sequence out of sync**: `migrations_id_seq` was behind the actual `MAX(id)` in `migrations` (common after a dump/restore, manual `INSERT` into `migrations`, or copying data). The next `nextval()` still produced an `id` that was already in use.
2. **Non-transactional DDL + failed bookkeeping**: The `ALTER TABLE` adding `content_sha256` committed, but the insert into `migrations` failed, so the column existed while Laravel had no record of the migration.

## Resolution

1. Resync the sequence (run on the affected database):

   ```sql
   SELECT setval('migrations_id_seq', (SELECT COALESCE(MAX(id), 1) FROM migrations), true);
   ```

   (Confirmed `max(id)` was 46; sequence was reset so the next id is 47.)

2. Made migrations idempotent: `2026_04_22_000100_add_content_sha256_to_thoughts` and `2026_04_23_153830_add_content_sha_index_on_thoughts_table` skip with `Schema::hasColumn` / `Schema::hasIndex` when the object already exists, so a half-applied state can be completed safely.

3. Re-ran `php artisan migrate` successfully.

## Prevention

- After restoring a Postgres DB, verify `migrations_id_seq` matches `MAX(id)` (or use `pg_restore` options that preserve sequences correctly).
- Prefer idempotent guards on migrations that may run against partially migrated dev databases (optional; use sparingly in production to avoid hiding real issues).

## References

- Migrations: `database/migrations/2026_04_22_000100_add_content_sha256_to_thoughts.php`, `database/migrations/2026_04_23_153830_add_content_sha_index_on_thoughts_table.php`
