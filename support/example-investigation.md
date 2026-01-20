# Example: Vehicle Valuation API Timeout - Customer Support Investigation

**Date**: 2025-01-15
**Status**: Resolved
**Customer**: Customer ID: 12345 (anonymized)
**Priority**: High
**Reported By**: Customer

## Issue Description
Customer reported that vehicle valuation API requests were timing out after 30 seconds. Multiple attempts failed with HTTP 504 Gateway Timeout errors. Customer was unable to generate vehicle reports.

## Customer Impact
- **Users Affected**: 1 customer (but potential for wider impact)
- **Severity**: High - Core functionality unavailable
- **Business Impact**: Customer unable to complete purchase flow, potential revenue loss

## Investigation Steps
1. Checked application logs for errors around reported time
2. Reviewed API endpoint performance metrics
3. Checked database query performance
4. Verified external service dependencies (DVLA, market data APIs)
5. Reviewed queue worker status and job processing times
6. Checked Redis cache status and memory usage

## Root Cause Analysis
The issue was caused by a slow database query in the `VehicleValuationService` that was performing a full table scan on the `vehicles` table. The query was missing an index on the `vin` column, causing query times to exceed 25+ seconds for certain VIN lookups.

Additionally, the external market data API was experiencing latency (2-3 second response times), which compounded the issue.

## Resolution
1. **Immediate Fix**: Added database index on `vehicles.vin` column
   ```sql
   CREATE INDEX idx_vehicles_vin ON vehicles(vin);
   ```

2. **Short-term**: Increased API timeout from 30s to 60s temporarily
3. **Long-term**: 
   - Implemented query result caching (24-hour TTL)
   - Added circuit breaker pattern for external API calls
   - Optimized the vehicle lookup query to use indexed columns

4. **Customer Communication**: 
   - Apologized for the issue
   - Explained the root cause (without technical details)
   - Confirmed resolution and offered credit/refund if applicable

## Customer Communication
- **2025-01-15 10:30**: Initial report received via support email
- **2025-01-15 11:00**: Acknowledged issue, investigating
- **2025-01-15 14:30**: Root cause identified, working on fix
- **2025-01-15 16:00**: Fix deployed, confirmed working
- **2025-01-15 16:15**: Follow-up email to customer confirming resolution

## Prevention & Follow-up
- [x] Add database index on `vehicles.vin`
- [x] Implement query performance monitoring
- [x] Add API timeout alerts
- [ ] Review all database queries for missing indexes
- [ ] Add performance regression tests
- [ ] Document query optimization guidelines

## Related Issues
- Similar timeout issues reported by 2 other customers (investigating)
- Database performance degradation noted in monitoring (related)

## Lessons Learned
1. **Database Indexing**: Critical queries must have proper indexes - add to code review checklist
2. **Monitoring**: Need better alerting for slow queries before customers report issues
3. **External Dependencies**: Circuit breaker pattern essential for external API calls
4. **Customer Communication**: Proactive communication during investigation reduces frustration

## Technical Details
- **Affected Service**: `VehicleValuationService::getValuation()`
- **Database**: PostgreSQL production
- **Query Time Before**: 25-30 seconds
- **Query Time After**: < 100ms
- **Deployment**: Hotfix deployed via Laravel migrations

## References
- [Specification: Vehicle Valuation API](/specs/example-feature-spec.md)
- [Implementation Notes: Vehicle Valuation Service](/dev/example-implementation-notes.md)
- [Decision: Database Indexing Strategy](/decisions/2025-01-15-example-decision-record.md)