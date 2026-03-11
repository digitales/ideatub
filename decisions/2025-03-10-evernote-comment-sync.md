# Evernote comment sync behaviour

**Date**: 2025-03-10  
**Status**: Accepted  
**Context**: Comment-on-thought feature; thoughts can have `parent_id` (comments). Evernote mirror must decide how to sync comments.

**Decision:** Comments are synced to Evernote by **appending to the parent thought’s note (option B)**. A comment does not create a new Evernote note; when a thought with `parent_id` is synced, the parent thought’s Evernote note (if it exists) is updated to append the comment content. If the parent has no `evernote_note_guid`, comment sync is skipped (or parent note is created first, then append—implementation choice when Evernote is added).

**When implementing Evernote:** Use `EvernoteService::appendToNote($noteGuid, $content)` (or equivalent) for thoughts with `parent_id`; in `SyncThoughtToEvernote` job, branch on `$thought->parent_id` and run the append path instead of create/update own note.
