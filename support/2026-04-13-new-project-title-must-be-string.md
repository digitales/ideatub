# New project title “must be a string” when adding to an existing project

**Date**: 2026-04-13  
**Status**: Resolved  
**Customer**: Internal / user report  
**Priority**: Medium  
**Reported By**: Customer  

## Issue Description

When saving a thought to an **existing** project from the thought detail “Add to project” form, validation failed with: **The new project title field must be a string.**

## Customer Impact

Users could not attach thoughts to projects they already had, unless they avoided the form behaviour that submits empty “new project” fields.

## Investigation Steps

1. Located validation in `app/Http/Controllers/ThoughtProjectController.php` (`new_project_title` rules).
2. Compared browser behaviour vs tests: feature tests posted only `project_id`, while the Blade form always includes `new_project_title` / `new_project_description` (hidden via Alpine `x-show` but still submitted).
3. Reproduced with `validator([...], [...])`: `new_project_title => null` with a non-`__new__` `project_id` fails the `string` rule; omitting the key passes.

## Root Cause Analysis

`ConvertEmptyStringsToNull` turns the submitted empty `new_project_title` into `null`. For an existing project, `prohibited_unless:project_id,__new__` correctly allows an empty value, but the `string` rule still runs and rejects `null` (not a PHP string), producing the misleading message.

## Resolution

Added `nullable` before `string` on `new_project_title` so `null` skips `string`/`max` when the field is effectively unused, while `required_if:project_id,__new__` still enforces a title when creating a new project. Regression test: `attaching to existing project accepts empty new project fields from browser form` in `tests/Feature/ThoughtLinkAndProjectOnDetailTest.php`.

## Prevention & Follow-up

- [ ] Consider the same pattern for other optional text fields that are always present in the DOM but hidden.

## References

- `app/Http/Controllers/ThoughtProjectController.php`
- `resources/views/idea/partials/thought_detail_add_to_project.blade.php`
