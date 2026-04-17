# Unified skills settings UI (execution plan)

This document captures the agreed hybrid approach: **`/settings/skills`** as the hub, **Research** and **Meeting** as separate skill families (existing tables), **per-type default** + **per-type global auto-run** preference (research already has one; meeting adds one).

## Preconditions

Cursor **Agent mode** must be enabled so non-markdown files can be edited. Plan-only mode blocks PHP/Blade/route changes.

## 1. User preferences

- Add `UserPreference::KEY_MEETING_AUTO_RUN_ENABLED = 'meeting_auto_run_enabled'` in [`app/Models/UserPreference.php`](app/Models/UserPreference.php).
- In [`app/Services/Meetings/MeetingService.php`](app/Services/Meetings/MeetingService.php), inside `queueAutoRunForMeetingThought`, after `hasEligibleDefaultAutoRunSkillForUser`, require `(bool) UserPreference::get($user, KEY_MEETING_AUTO_RUN_ENABLED, false)` or return `null`.

## 2. Controllers

- **`SkillSettingsController`**
  - `index`: load `ResearchSkill` and `MeetingSkill` (with `latestVersion`), ordered like current research index; pass `researchAutoRunEnabled`, `meetingAutoRunEnabled` from `UserPreference`.
  - `updatePreferences`: validate `research_auto_run_enabled` and `meeting_auto_run_enabled` as required booleans; save both keys; redirect to `settings.skills.index` with success.

- **`MeetingSkillSettingsController`**
  - Mirror [`ResearchSkillSettingsController`](app/Http/Controllers/ResearchSkillSettingsController.php): `create`, `store`, `edit`, `update`, `setDefault`, using [`MeetingSkillManager`](app/Services/Meetings/MeetingSkillManager.php).
  - Redirects to `settings.skills.index` with `->withFragment('meeting-skills')` where appropriate.

- **`ResearchSkillSettingsController`**
  - Remove `index` and `updatePreferences` (moved to `SkillSettingsController`).
  - Point all redirects to `route('settings.skills.index')` with fragment `research-skills` where appropriate.

## 3. Form requests

- **`StoreMeetingSkillRequest` / `UpdateMeetingSkillRequest`**
  - Fields aligned with `MeetingSkillManager`: `name`, `description`, `workflow_type` in `meeting_brief`, `instructions`, `intensity`, `context_options` (optional), `output_sections` → merged to `output_shape`, `core_categories` / `custom_categories` lists, behaviour toggles.
  - `authorize` on update: `meetingSkill` belongs to current user.

## 4. Routes ([`routes/web.php`](routes/web.php))

- `GET /settings/skills` → `SkillSettingsController@index` → `name('settings.skills.index')`
- `PUT /settings/skills/preferences` → `SkillSettingsController@updatePreferences` → `name('settings.skills.preferences')`
- `PUT /settings/research-skills/preferences` → same controller (legacy name `settings.research-skills.preferences`)

Canonical CRUD:

- Research: `/settings/skills/research/create`, `POST /settings/skills/research`, `/settings/skills/research/{researchSkill}/edit`, `PUT ...`, `POST .../default`
- Meeting: `/settings/skills/meeting/create`, `POST /settings/skills/meeting`, `/settings/skills/meeting/{meetingSkill}/edit`, `PUT ...`, `POST .../default`

Legacy (keep for bookmarks/tests):

- `GET /settings/research-skills` → redirect to `settings.skills.index` (`name('settings.research-skills.index')`)
- `GET /settings/research-skills/create` → redirect to `settings.skills.research.create`
- `GET /settings/research-skills/{researchSkill}/edit` → redirect to `settings.skills.research.edit`
- Duplicate POST/PUT routes on old paths: `settings.research-skills.store`, `update`, `default` (same controller methods)

Register `use App\Models\ResearchSkill` in route closure type-hints or use full namespace.

## 5. Views

- [`resources/views/settings/skills/index.blade.php`](resources/views/settings/skills/index.blade.php): one page with:
  - Combined **Automation** card: both toggles, single form to `settings.skills.preferences`
  - **Research** block `id="research-skills"` with list + link to `settings.skills.research.create`
  - **Meeting** block `id="meeting-skills"` with list + link to `settings.skills.meeting.create`
- [`resources/views/settings/meeting-skills/create.blade.php`](resources/views/settings/meeting-skills/create.blade.php), `edit.blade.php`, `_form.blade.php` (pattern from research `_form`).
- Update research create/edit to use canonical routes and cancel links to `settings.skills.index` + fragment.
- [`resources/views/settings/profile.blade.php`](resources/views/settings/profile.blade.php): link text **Skills** → `settings.skills.index`.

## 6. Tests

- [`tests/Feature/ResearchSkillSettingsControllerTest.php`](tests/Feature/ResearchSkillSettingsControllerTest.php): use `settings.skills.index` for GET index; assert new copy; preferences PUT must send **both** booleans (or adjust validation to `sometimes` — prefer required both for simplicity).
- [`tests/Feature/McpApiTest.php`](tests/Feature/McpApiTest.php): `test_capture_plan_meeting_auto_queues_meeting_run` — set `UserPreference::KEY_MEETING_AUTO_RUN_ENABLED` true before capture.
- Add `tests/Feature/MeetingSkillSettingsControllerTest.php` (or `SkillSettingsControllerTest`) for auth, create, default toggle.
- Optional: test that capture does **not** auto-queue when meeting auto-run is off.

## 7. Verification

- `php artisan route:list --path=settings/skills`
- `vendor/bin/pest` for touched tests (full suite if Postgres available).
