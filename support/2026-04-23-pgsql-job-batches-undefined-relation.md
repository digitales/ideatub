# `job_batches` does not exist (pgsql) - Customer Support Investigation

**Date**: 2026-04-23  
**Status**: Resolved (code) — pending production migrate  
**Customer**: N/A (internal / production error)  
**Priority**: High (imports / batched jobs fail)  
**Reported By**: Monitoring / application logs

## Issue Description

PostgreSQL error: `SQLSTATE[42P01]: Undefined table: 7 ERROR: relation "job_batches" does not exist` on insert into `job_batches` during a batched import (`import:…` batch name, `FinaliseImportBatch` in serialized closure).

## Customer Impact

- Batched queue operations that use `Bus::batch()` cannot run until the table exists.
- Affected: import flows (and any other code paths using job batching) against the `pgsql` connection.

## Investigation Steps

1. Confirmed `config/queue.php` uses `job_batches` for the batching table on the default DB connection.
2. Searched `database/migrations/`: `create_jobs_table` and `create_failed_jobs_table` exist, but there was no `create_job_batches_table` migration in the repository.
3. Ran `php artisan queue:batches-table` to add the standard Laravel migration (`2026_04_23_181046_create_job_batches_table.php`).
4. Ran `php artisan migrate` locally; migration applied successfully.

## Root Cause Analysis

The application uses Laravel [job batching](https://laravel.com/docs/queues#job-batching), which requires the `job_batches` table on the same database connection used for batching (`config('queue.batching')`). The migration to create that table was never committed, so production PostgreSQL (e.g. Laravel Cloud) was never given a migration that creates `job_batches`, even if other queue tables (`jobs`, `failed_jobs`) were migrated.

## Resolution

1. **Repository**: Commit `database/migrations/2026_04_23_181046_create_job_batches_table.php` (from `php artisan queue:batches-table`).
2. **Production**: Deploy and run migrations: `php artisan migrate` (or ensure your Laravel Cloud / CI deploy step runs pending migrations for `main`).

No data backfill is required; this is a new empty table for batch metadata.

## Prevention & Follow-up

- [ ] Add deploy checklist: all new queue-related tables have migrations (jobs, job_batches, failed_jobs).
- [ ] Consider a staging DB that runs the full migration set to catch “works locally on sqlite” / partial migrate issues.

## References

- `config/queue.php` — `batching` array (`job_batches` table name).
- Laravel docs: [Job Batching](https://laravel.com/docs/queues#job-batching).
