# CompactionVersionWriter validation hard-failed (meeting compaction observation mode) - Customer Support Investigation

**Date**: 2026-05-08  
**Status**: Resolved (behavior confirmed; no data-loss incident)  
**Customer**: User ID 3 (anonymized)  
**Priority**: Medium  
**Reported By**: Internal

## Issue Description

`CompactionVersionWriter` emitted:

- `CompactionVersionWriter validation hard-failed`
- `build_type=compaction:meeting`
- `scope_type=tag`
- `scope_key=websecure`
- `enforced=false`
- `message=Missing required sections: Decisions, Action Items, Risks / Blockers, Open Questions`
- `reason_codes=[empty_required_section, missing_citation]`

## Customer Impact

- Compaction data was **not dropped** in this case.
- Because `working_memory.compaction_validation_enforced=false`, writer behavior is observation-only for hard failures.
- Impact was operational/noise-related (warning looked like a blocking failure), not a user-facing outage.

## Investigation Steps

1. Reviewed `app/Services/WorkingMemory/Compactions/CompactionVersionWriter.php` validation gate logic.
2. Confirmed hard-fail result from `WorkingMemoryOutputValidator` only aborts persistence when `compaction_validation_enforced=true`.
3. Verified test coverage in `tests/Unit/Services/WorkingMemory/Compactions/CompactionVersionWriterTest.php` for both observation mode and enforced mode.
4. Re-ran the writer unit test suite and confirmed pass:
   - observation mode: warning + persist
   - enforcement mode: warning + abort
5. Reviewed meeting compaction prompt contract and validator behavior for required sections and citations.

## Root Cause Analysis

This was a **validation contract mismatch**, not a storage failure:

- Meeting compaction output often contains only `Summary` with empty arrays for other required sections when no explicit decisions/actions/risks/questions are present.
- `WorkingMemoryOutputValidator` treats empty required sections as hard-fail (`empty_required_section`).
- Meeting compaction prompt currently allows empty citations (`citations: []`), while validator requires resolvable citations in required section items (`missing_citation`).

In observation mode (`enforced=false`), this combination predictably generates hard-fail warnings even when persistence succeeds.

## Resolution

No emergency code rollback required. Current behavior is functioning as configured:

- hard-fail diagnostics are logged
- compaction version is still persisted in observation mode

Support resolution is to classify this event as **expected warning noise** under current compaction prompt contract.

## Customer Communication

- 2026-05-08: Confirmed this event did not block compaction persistence because enforcement was disabled. Shared root cause as prompt/validator mismatch and documented follow-up remediation options.

## Prevention & Follow-up

- [ ] Align meeting compaction prompt and validator expectations:
  - either require non-empty required sections and citations in prompt output, or
  - make compaction-specific validator rules explicit (e.g., allow empty section placeholders / relaxed citation requirement pre-linking).
- [ ] Reduce alert ambiguity by rewording observation-mode log line (avoid implying a blocking failure when `enforced=false`).
- [ ] Add an explicit metric counter split by `enforced=true|false` to separate blocking failures from observational diagnostics.

## Related Issues

- `docs/superpowers/specs/2026-05-07-working-memory-compactions-design.md`
- `config/working_memory.php` (compaction validator gate comments)

## Lessons Learned

- "hard-fail" as a validator classification is not the same as "write aborted"; enforcement flag determines persistence behavior.
- Compaction prompt contracts and validator contracts must evolve together to avoid persistent false-positive warning noise.

## References

- `app/Services/WorkingMemory/Compactions/CompactionVersionWriter.php`
- `app/Services/WorkingMemory/WorkingMemoryOutputValidator.php`
- `app/Services/WorkingMemory/Compactions/MeetingCompactionPromptBuilder.php`
- `tests/Unit/Services/WorkingMemory/Compactions/CompactionVersionWriterTest.php`
