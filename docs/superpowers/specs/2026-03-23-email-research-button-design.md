# Email Research Button — Design Spec

**Date:** 2026-03-23
**Status:** Approved

## Overview

Add two "run research" actions to the email detail page's existing `...` actions menu, allowing users to manually trigger research for any email regardless of its current processing status.

## Problem

Emails can miss research processing (e.g. sender rule not set at ingest time, job failure, skipped due to low content). There is no UI path to re-trigger either type of research after the fact.

## Solution

Add two new menu items to the existing `thought_card_actions` dropdown — visible on any email thought — that POST to a dedicated `EmailResearchController`:

1. **Run idea research** — triggers AI-generated research on the email content via the existing `IdeaResearchRequested` event
2. **Run newsletter research** — resets processing state and re-dispatches the `ProcessExtraEmailResearch` job

---

## Backend

### New Controller: `App\Http\Controllers\EmailResearchController`

**`ideaResearch(Thought $thought)`**
- Route: `POST /emails/{thought}/idea-research` (named `emails.idea-research`)
- Auth: `authorize('update', $thought)`
- Guard: verify `$thought->source === 'email'`; abort 403 otherwise
- Action:
  1. Merge `['research_pending' => true]` into `$thought->metadata` and save (required for the in-progress UI indicator; consistent with `IdeaController::research()`)
  2. Dispatch `IdeaResearchRequested::dispatch($thought, 'email')`
- Response: `redirect()->back()->with('success', '...')`

**`newsletterResearch(Thought $thought)`**
- Route: `POST /emails/{thought}/newsletter-research` (named `emails.newsletter-research`)
- Auth: `authorize('update', $thought)`
- Guard: verify `$thought->source === 'email'`; abort 403 otherwise
- Action:
  1. Resolve the underlying email row: `ImportedEmail::where('thought_id', $thought->id)->first()` then `CapturedInboundEmail::where('thought_id', $thought->id)->first()`; abort 404 if neither found
  3. On the stored email row: set `processing_status = 'research_queued'`, clear `research_thought_id = null`
  4. On the thought: clear `source_metadata.newsletter_research` block
  5. Dispatch `ProcessExtraEmailResearch` with the appropriate ID (`importedEmailId` or `capturedInboundEmailId`)
- Response: `redirect()->back()->with('success', '...')`
- Note: any previously generated research thought is left in the DB as an unlinked orphan; no deletion needed
- Note: `failure_count` and `failure_reason` columns on `ImportedEmail` are intentionally not cleared on re-trigger

### Routes

Both routes added inside the existing `auth` middleware group in `routes/web.php`:

```php
Route::post('/emails/{thought}/idea-research', [EmailResearchController::class, 'ideaResearch'])->name('emails.idea-research');
Route::post('/emails/{thought}/newsletter-research', [EmailResearchController::class, 'newsletterResearch'])->name('emails.newsletter-research');
```

---

## Frontend

### `resources/views/idea/partials/thought_card_actions.blade.php`

Add a conditional block inside the dropdown, above the Edit button, shown only when `$thought->source === 'email'`:

```blade
@if (($thought->source ?? null) === 'email')
    <form method="POST" action="{{ route('emails.idea-research', $thought) }}">
        @csrf
        <button type="submit" class="w-full text-left px-3 py-1.5 text-[12px] text-slate-brand hover:bg-slate-brand/5 rounded">
            Run idea research
        </button>
    </form>
    <form method="POST" action="{{ route('emails.newsletter-research', $thought) }}">
        @csrf
        <button type="submit" class="w-full text-left px-3 py-1.5 text-[12px] text-slate-brand hover:bg-slate-brand/5 rounded">
            Run newsletter research
        </button>
    </form>
@endif
```

No new Alpine.js state required. Submitting closes the menu naturally via full-page redirect.

---

## Data Flow

```
User clicks "Run newsletter research"
  → POST /emails/{thought}/newsletter-research
  → EmailResearchController::newsletterResearch()
    → resolve ImportedEmail or CapturedInboundEmail by thought_id
    → reset processing_status = 'research_queued', research_thought_id = null
    → clear thought.source_metadata.newsletter_research
    → dispatch ProcessExtraEmailResearch job
  → redirect back with flash
  → (async) job runs EmailNewsletterResearchService
    → updates processing_status, research_thought_id, source_metadata on completion
```

```
User clicks "Run idea research"
  → POST /emails/{thought}/idea-research
  → EmailResearchController::ideaResearch()
    → dispatch IdeaResearchRequested event
  → redirect back with flash
  → (async) RunResearchForIdeaListener runs ResearchService
    → creates research thought linked to email thought
```

---

## Out of Scope

- Disabling/hiding buttons while research is in-progress (no polling; user can see status badge)
- Deleting old research thoughts when re-triggering newsletter research
- Confirmation dialog before re-triggering (always-visible, low-risk action)
