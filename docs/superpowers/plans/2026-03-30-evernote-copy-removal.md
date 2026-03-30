# Evernote Copy Removal Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Remove Evernote from the logged-out marketing copy and replace it with broader browser/MCP/integrations messaging without changing backend Evernote code.

**Architecture:** This is a copy-only change on the guest landing experience. Add focused feature coverage for the `/welcome` route first, then update the two Blade templates that currently emit Evernote-facing public text, and finally verify that public `resources/views` copy no longer mentions Evernote while out-of-scope backend/docs references remain untouched.

**Tech Stack:** Laravel 12, Blade views, PHPUnit via `php artisan test`

---

## File Structure

### Files to modify

- `resources/views/home.blade.php`
  - Guest homepage description, schema description, and value-prop card copy
- `resources/views/layouts/app.blade.php`
  - Shared default meta description and footer/about copy used by the guest homepage

### Files to create

- `tests/Feature/HomePageTest.php`
  - Focused guest homepage assertions for Evernote removal and replacement messaging

### Files to reference only

- `docs/superpowers/specs/2026-03-30-evernote-copy-removal-design.md`
  - Approved scope and messaging guardrails
- `routes/web.php`
  - Confirms the guest homepage route is `route('home')` at `/welcome`
- `app/Http/Controllers/HomeController.php`
  - Confirms the route renders `view('home')`

## Task 1: Add focused guest homepage coverage

**Files:**
- Create: `tests/Feature/HomePageTest.php`
- Reference: `routes/web.php`
- Reference: `app/Http/Controllers/HomeController.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/HomePageTest.php` with focused assertions against `route('home')`.

```php
<?php

namespace Tests\Feature;

use Tests\TestCase;

class HomePageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_guest_home_page_no_longer_mentions_evernote(): void
    {
        $response = $this->get(route('home'));

        $response->assertOk();
        $response->assertDontSee('Evernote');
    }

    public function test_guest_home_page_uses_generic_mcp_and_integrations_language(): void
    {
        $response = $this->get(route('home'));

        $response->assertOk();
        $response->assertSee('Use the web app or connect via MCP.');
        $response->assertSee('Claude');
        $response->assertSee('Cursor');
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test tests/Feature/HomePageTest.php`

Expected: FAIL because the current guest homepage still renders `Evernote` in `resources/views/home.blade.php` and `resources/views/layouts/app.blade.php`.

- [ ] **Step 3: Commit the failing test scaffold**

```bash
git add tests/Feature/HomePageTest.php
git commit -m "test: cover guest home Evernote copy removal"
```

## Task 2: Replace public Evernote messaging in Blade templates

**Files:**
- Modify: `resources/views/home.blade.php`
- Modify: `resources/views/layouts/app.blade.php`
- Test: `tests/Feature/HomePageTest.php`

- [ ] **Step 1: Update homepage-specific copy in `resources/views/home.blade.php`**

Replace Evernote-specific strings in:

- `@section('description', ...)`
- schema `description`
- the value-prop card title currently labeled `MCP & Evernote`
- the value-prop card body currently describing Evernote mirroring

Use copy aligned with the spec, for example:

```php
@section('description', 'IdeaTub is your thinking space. Capture thoughts, find them with semantic search, and connect from the browser or via MCP.')
```

```json
"description": "Capture and search thoughts with semantic search. Use IdeaTub in the browser or through MCP-connected workflows."
```

```html
<h3 class="text-lg font-semibold text-deep-indigo mb-2">MCP & Integrations</h3>
<p class="text-sm text-slate-brand leading-relaxed">Use from Claude, Cursor, or other MCP clients as part of connected workflows.</p>
```

- [ ] **Step 2: Update shared guest-facing layout copy in `resources/views/layouts/app.blade.php`**

Replace the default meta description and footer/about text so they stay consistent with the homepage copy and no longer mention `Evernote mirror`.

Use wording anchored in supported flows:

```php
<meta name="description" content="@yield('description', 'Capture and search thoughts via web or MCP. Semantic search for your ideas and notes.')">
```

```php
{{ config('app.name', 'IdeaTub') }} — capture and search thoughts via web or MCP. Semantic search for your ideas and notes.
```

- [ ] **Step 3: Run the focused test to verify it passes**

Run: `php artisan test tests/Feature/HomePageTest.php`

Expected: PASS with both guest homepage assertions green.

- [ ] **Step 4: Run a scoped search for approved in-scope templates**

Run:

```bash
rg -n "Evernote|evernote" resources/views/home.blade.php resources/views/layouts/app.blade.php
```

Expected: no matches in the two approved marketing templates.

- [ ] **Step 5: Commit the copy update**

```bash
git add resources/views/home.blade.php resources/views/layouts/app.blade.php tests/Feature/HomePageTest.php
git commit -m "fix: remove Evernote from guest homepage copy"
```

## Task 3: Final verification and scope guard

**Files:**
- Modify: none
- Verify: `resources/views/home.blade.php`
- Verify: `resources/views/layouts/app.blade.php`
- Verify: `tests/Feature/HomePageTest.php`
- Reference: `docs/superpowers/specs/2026-03-30-evernote-copy-removal-design.md`

- [ ] **Step 1: Run the targeted verification commands together**

Run:

```bash
php artisan test tests/Feature/HomePageTest.php
rg -n "Evernote|evernote" resources/views/home.blade.php resources/views/layouts/app.blade.php
```

Expected:

- the test file passes
- the scoped two-file search returns no matches

- [ ] **Step 2: Run one wider repo search to confirm remaining hits are out of scope**

Run: `rg -n "Evernote|evernote" .`

Expected: remaining hits may still exist in backend code and internal materials such as `app/`, `config/`, `docs/`, `decisions/`, `dev/`, migrations, or metadata like `composer.json`; those do not block this copy-only task.

- [ ] **Step 3: Sanity-check rendered wording**

Open the guest homepage at `/welcome` and confirm:

- the page reads naturally without Evernote
- metadata-aligned copy matches the visible messaging
- the value-prop card title/body feel specific without overpromising unsupported integrations

- [ ] **Step 4: Commit any wording polish from verification**

```bash
git add resources/views/home.blade.php resources/views/layouts/app.blade.php tests/Feature/HomePageTest.php
git commit -m "docs: polish guest homepage integration messaging"
```

## Notes

- Keep this plan within the approved `copy-only` boundary.
- Do not modify `app/Services/EvernoteService.php`, `app/Jobs/SyncThoughtToEvernote.php`, `config/services.php`, or schema fields such as `evernote_note_guid`.
- Do not expand the task into backend deprecation work just because wider repo searches still show Evernote references.
- If the chosen card title `MCP & Integrations` feels too broad during implementation, prefer `Browser & MCP` over inventing new product claims.
