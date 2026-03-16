# Jira integration

IdeaTub can sync your Jira Cloud activity (tickets created, updated, commented, and status/field changes) into thoughts. You can then search and filter by project in Stream, or ask in chat (via MCP) for “tickets I updated last week” before a client call.

## Enabling and disabling

- **Toggle:** Set `JIRA_ENABLED=true` (default) or `JIRA_ENABLED=false` in your environment. When `false`, Jira is removed from the interface (no settings nav link, no Jira settings page) and the MCP tool `sync_jira` is not listed or callable.
- **Config:** `JIRA_SYNC_DAYS=14` (or another number) sets the default number of days to fetch when you trigger a sync.

## Setup

1. In IdeaTub go to **Settings → Jira** (when the integration is enabled).
2. Enter your **Jira site URL** (e.g. `https://your-domain.atlassian.net`).
3. Enter the **email** of the Jira account that will own the API token (or leave blank to use your IdeaTub email).
4. Create an **API token** at [id.atlassian.com](https://id.atlassian.com/manage-profile/security/api-tokens) and paste it.
5. Click **Connect** (or **Update** if you already had credentials).

## Syncing

- **From the app:** On the Jira settings page, click **Sync Jira now**. A job runs in the background and creates thoughts for your recent activity.
- **From MCP:** Use the `sync_jira` tool (e.g. from Cursor or ChatGPT). You can pass an optional `days` argument. The tool dispatches the same sync job and returns a confirmation message.

## What gets stored

Each Jira “event” (issue created, status/field change, comment) becomes one **thought** with:

- **Type:** `jira`
- **Tags:** `jira` plus the **project key** (e.g. `proj` for project PROJ), so you can filter Stream by project.
- **Source metadata:** Issue key, summary, link, event type, and a stable `jira_event_id` so re-syncing does not create duplicates.

Existing semantic search and Stream work as usual; Jira thoughts are included. Filter by tag `jira` for all Jira activity, or by the project tag (e.g. `proj`) for that project only.
