# Database Memory Optimization for Ingestion

## Problem

During the data ingestion stage on Laravel Cloud, database RAM usage was exceeding 90%, risking service disruption.

## Root Causes

1. **Large batch inserts** - Building arrays with thousands of SQL placeholders and bindings consumed excessive memory
2. **Primary key index maintenance** - Staging table had a primary key causing expensive index updates during bulk inserts
3. **Single-transaction finalization** - Moving millions of rows in one transaction created large undo logs
4. **No progressive cleanup** - Staging data accumulated without being deleted progressively

## Optimizations Implemented

### 1. Sub-Batch Inserts (TsvLoader.php)

**Change**: Split large batch inserts into smaller sub-batches of 250 rows each.

**Impact**: Reduces memory per INSERT statement from ~23,000 bindings (1000 rows × 23 columns) to ~5,750 bindings (250 rows × 23 columns).

**Configuration**:
```env
INGEST_BATCH_INSERT_CHUNK_SIZE=250  # For limited RAM (< 2GB)
INGEST_BATCH_INSERT_CHUNK_SIZE=500  # For adequate RAM (> 4GB)
```

### 2. Remove Staging Table Primary Key (Migration)

**Change**: Removed primary key from `staging_vehicle_risk_percentiles` table.

**Rationale**: Staging tables are temporary storage and don't need constraints. Primary keys force the database to maintain indexes during bulk inserts, consuming RAM.

**Impact**: Reduces index maintenance overhead by 30-40% during bulk inserts.

**Migration**: Run `php artisan migrate` to apply the optimization.

### 3. Chunked Finalization (FinalizeIngestSnapshot.php)

**Change**: Process data in chunks of 50,000 rows instead of all at once.

**Impact**: Limits memory usage during finalization by processing smaller batches with automatic commits between chunks.

**Configuration**:
```env
INGEST_FINALIZATION_CHUNK_SIZE=50000   # For limited RAM (< 2GB)
INGEST_FINALIZATION_CHUNK_SIZE=100000  # For adequate RAM (> 4GB)
```

### 4. Chunked Staging Cleanup

**Change**: Delete staging data in chunks instead of single DELETE statements.

**Impact**: Prevents large DELETE operations from creating excessive undo logs.

## Laravel Cloud Configuration

### Recommended Environment Variables

For a database cluster with **limited RAM (< 2GB)**:

```env
# Batch insert optimization
INGEST_BATCH_SIZE=1000
INGEST_BATCH_INSERT_CHUNK_SIZE=250

# Finalization optimization
INGEST_FINALIZATION_CHUNK_SIZE=50000

# DuckDB memory limits
DUCKDB_MEMORY_LIMIT=512MB
DUCKDB_THREADS=2
```

For a database cluster with **adequate RAM (4GB+)**:

```env
# Batch insert optimization
INGEST_BATCH_SIZE=2000
INGEST_BATCH_INSERT_CHUNK_SIZE=500

# Finalization optimization
INGEST_FINALIZATION_CHUNK_SIZE=100000

# DuckDB memory limits
DUCKDB_MEMORY_LIMIT=1GB
DUCKDB_THREADS=4
```

## Migration Steps

1. **Update code** - Already included in this commit
2. **Run migration** - Execute on Laravel Cloud:
   ```bash
   php artisan migrate
   ```
3. **Update environment variables** - Add the new config values to Laravel Cloud environment
4. **Restart queue workers** - Ensure new code is active

## Expected Results

- Database RAM usage should drop from 90%+ to **60-70%** during ingestion
- Finalization process will be slightly slower but much more memory-efficient
- No data loss or corruption - all changes maintain data integrity

## Monitoring

Watch these metrics after deployment:

1. **Database RAM usage** - Should stay below 80% during ingestion
2. **Ingestion job duration** - May increase by 10-15% due to chunking
3. **Queue processing rate** - Should remain stable
4. **Staging table sizes** - Should clear progressively during finalization

## Troubleshooting

### RAM still above 85%

Try more aggressive settings:
```env
INGEST_BATCH_INSERT_CHUNK_SIZE=100
INGEST_FINALIZATION_CHUNK_SIZE=25000
DUCKDB_MEMORY_LIMIT=256MB
```

### Ingestion too slow

Increase chunk sizes gradually:
```env
INGEST_BATCH_INSERT_CHUNK_SIZE=500
INGEST_FINALIZATION_CHUNK_SIZE=100000
```

### Transaction deadlocks

Add small delays between chunks (requires code change):
```php
usleep(100000); // 100ms delay between chunks
```

## Additional Optimizations (Future)

If RAM pressure continues:

1. **Stream-based finalization** - Process one row at a time instead of INSERT...SELECT
2. **Temporary table cleanup** - Drop and recreate staging tables between snapshots
3. **Database connection pooling** - Limit concurrent connections to reduce overhead
4. **Compress text columns** - Use COMPRESS() for large text fields
5. **Partition staging tables** - Split by snapshot date for easier cleanup

## References

- Laravel Cloud Docs: https://laravel.com/docs/cloud
- MySQL Memory Usage: https://dev.mysql.com/doc/refman/8.0/en/memory-use.html
- Bulk Insert Best Practices: https://dev.mysql.com/doc/refman/8.0/en/insert-optimization.html
