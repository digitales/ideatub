# Evernote mirror

IdeaTub can mirror thoughts to Evernote as notes. When enabled, creating or updating a thought dispatches a queued job that creates or updates the corresponding Evernote note. Sync is optional: if Evernote is not configured, the app works normally and no sync runs.

## Overview

- **Create:** New thoughts get a new Evernote note in the mapped notebook; the thought’s `evernote_note_guid` is stored.
- **Update:** Edits to a thought update the existing Evernote note when `evernote_note_guid` is set.
- **Queued:** Sync runs via the `SyncThoughtToEvernote` job. You must run a queue worker when using a queue driver other than `sync`.

## Environment variables

| Variable | Required | Description |
|----------|----------|-------------|
| `EVERNOTE_ACCESS_TOKEN` | Yes (to enable mirror) | Evernote API personal auth token. If empty or unset, Evernote sync is skipped. |
| `EVERNOTE_NOTEBOOK_GUID_DEFAULT` | No | Notebook GUID used when no type/tag mapping matches. |
| `EVERNOTE_NOTEBOOK_GUID_IDEA` | No | Notebook GUID for thoughts with type or tag `idea`. |
| `EVERNOTE_NOTEBOOK_GUID_TASK` | No | Notebook GUID for thoughts with type or tag `task`. |

Add any of these to `.env` (see `.env.example`). Only set the notebook GUIDs you use. You can add more mappings in `config/services.php` under `evernote.notebook_mapping` and corresponding `EVERNOTE_NOTEBOOK_GUID_*` env vars.

## Notebook mapping

The app resolves the target Evernote notebook from a thought’s metadata:

1. **Type:** If the thought has `metadata.type` (e.g. `idea`, `task`), that value is looked up in the notebook mapping. If found and the GUID is set, that notebook is used.
2. **Tags:** If no type match, the first of `metadata.tags` that exists in the mapping (with a non-empty GUID) is used.
3. **Default:** If nothing matches, the `default` mapping is used.

Mapping keys in config are lowercase; type and tags are compared case-insensitively. Set only the keys you need in `.env` (e.g. `EVERNOTE_NOTEBOOK_GUID_IDEA`, `EVERNOTE_NOTEBOOK_GUID_TASK`).

## Queued sync

Sync is performed by the `SyncThoughtToEvernote` job. By default `QUEUE_CONNECTION=sync` runs jobs immediately. To use a queue:

1. Set `QUEUE_CONNECTION=redis` (or `database`, etc.) in `.env`.
2. Run a worker so jobs are processed:

   ```bash
   php artisan queue:work
   ```

If no worker is running and the connection is not `sync`, sync jobs will sit in the queue until a worker runs.

## Getting notebook GUIDs and token

- **Notebook GUIDs:** From Evernote: open the notebook, check its URL or use the Evernote API to list notebooks and copy the GUID.
- **Access token:** Create a personal auth token in your Evernote account settings (or use Evernote’s OAuth flow if you implement it). The token must have note create/update scope.

Keep `EVERNOTE_ACCESS_TOKEN` secret; do not commit it or expose it to the client.
