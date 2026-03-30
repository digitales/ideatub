# Evernote Copy Removal Design

**Date:** 2026-03-30
**Status:** Approved
**Scope:** Remove Evernote from public-facing IdeaTub messaging in the logged-out marketing experience and replace it with broader MCP/integrations positioning, without changing backend Evernote code or internal historical docs.

## Overview

Per product guidance, Evernote API keys are not available and will not be available in the future.

IdeaTub should stop presenting Evernote as part of its public product story. The goal is not to remove existing backend Evernote integration code in this change. The goal is to make sure the logged-out marketing experience no longer references Evernote or implies that Evernote is a current supported integration.

## Goals

- Remove public-facing mentions of Evernote from the logged-out marketing experience
- Replace Evernote-specific wording with durable product positioning around browser use, MCP access, and connected-tool workflows
- Keep the homepage copy coherent after the removal rather than leaving awkward gaps
- Limit the change to messaging only

## Non-Goals

- Removing or refactoring backend Evernote integration code
- Renaming internal classes, config keys, or database fields such as `EvernoteService`, `SyncThoughtToEvernote`, or `evernote_note_guid`
- Rewriting historical internal specs, plans, or decision records that mention Evernote
- Auditing every possible user-visible surface in the product
- Updating operator/developer materials such as backend docs, README-style references, env examples, or architecture notes
- Introducing new integrations or promising specific replacement integrations

## Current State

Current public-facing Evernote mentions exist in:

- `resources/views/layouts/app.blade.php`
- `resources/views/home.blade.php`

These references appear in:

- shared page metadata description text
- footer/about copy
- homepage description text
- homepage structured data description
- the homepage value-prop card currently titled `MCP & Evernote`

For this task, these templates are the explicit in-scope boundary. Other Evernote mentions elsewhere in the repo may remain after the change and do not block completion if they are outside these marketing templates.

## Proposed Solution

Update the public copy to reposition IdeaTub around its actual current strengths:

- capture thoughts in the browser
- connect from MCP clients such as Claude or Cursor
- work with connected tools in general terms, without naming Evernote

The preferred copy strategy is not a pure delete. Instead, replace Evernote-specific language with broader and safer messaging so the homepage still communicates how IdeaTub fits into a user workflow.

## Content Changes

### `resources/views/layouts/app.blade.php`

Update:

- default meta description
- footer/about paragraph

Both should remove `Evernote mirror` language and align with the broader product framing used on the homepage.

### `resources/views/home.blade.php`

Update:

- page description
- schema.org `description`
- homepage value-prop card title
- homepage value-prop card body

Recommended direction:

- keep the hero focused on semantic search and capture
- rename `MCP & Evernote` to a more durable label such as `MCP & Integrations` or `Browser & MCP`
- describe compatibility in general terms like MCP clients and connected workflows
- default to browser plus MCP wording unless product explicitly approves naming a specific integration

## Messaging Guidance

The replacement copy should:

- emphasize IdeaTub as a browser plus MCP thought capture/search tool
- use plain language that matches the product users can access today
- keep “integrations” language broad and accurate

The replacement copy should not:

- mention Evernote
- imply Evernote setup is still possible
- imply a broad integrations marketplace if one does not exist
- overpromise capabilities beyond browser use and MCP-based workflows

## Data Flow And Behavior

No application behavior changes are required.

Blade templates will render the same routes and layout structure as before. Only the user-visible strings and metadata content change.

There are no controller, model, database, queue, or API changes in scope.

## Testing

Verification should stay lightweight:

1. confirm public-facing Evernote mentions are removed from `resources/views/layouts/app.blade.php` and `resources/views/home.blade.php`
2. confirm the resulting homepage copy still reads naturally and consistently across hero text, metadata, footer copy, and the value-prop card
3. run a scoped search under `resources/views` and confirm no remaining public-facing `Evernote` mentions remain there
4. if running a wider repo search, treat remaining hits in locations such as `app/`, `config/`, `docs/`, `decisions/`, `dev/`, migrations, or env examples as expected and out of scope for this task

No new automated tests are required unless an existing view-level coverage pattern makes a focused assertion especially cheap and valuable.

## Risks

- Replacement wording could become too vague after removing a concrete integration name
- “integrations” language could accidentally imply support that does not exist
- metadata and visible homepage copy could drift if only one surface is updated

## Mitigations

- update all public copy surfaces together
- keep the new wording anchored in actual supported flows: browser plus MCP
- review rendered copy for clarity after editing

## Recommendation

Implement a small, coordinated content update across the public layout metadata and homepage copy.

This removes outdated Evernote messaging, keeps IdeaTub’s public positioning accurate, and avoids mixing a lightweight copy correction with a larger backend deprecation effort.
