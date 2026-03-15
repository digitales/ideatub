# Thought showing literal &#039; on dashboard (e.g. Daphne&#039;s) — Customer Support

**Date**: 2026-03-15  
**Status**: Resolved  
**Related**: [2026-03-13-thoughts-html-entities-encoding.md](2026-03-13-thoughts-html-entities-encoding.md)  

## Report

Thought displayed as:

- **Seen**: `Daphne&#039;s breathing 30 per minute`
- **Expected**: `Daphne's breathing 30 per minute`

("Dashboard" here refers to the main idea list — idea index or stream — where thoughts are shown.)

## Cause

Content was **double-encoded**: stored as `Daphne&amp;#039;s` (or similar). A single `html_entity_decode` produced `Daphne&#039;s`, then `e()` escaped `&` to `&amp;`, so the browser rendered the literal `&#039;` instead of an apostrophe.

## Fix

`Thought::getDecodedContent()` was updated to decode **repeatedly until the string no longer changes**, so both single-encoded (`Daphne&#039;s`) and double-encoded (`Daphne&amp;#039;s`) content display correctly as `Daphne's`.

## Files changed

- `app/Models/Thought.php` — `getDecodedContent()` now loops `html_entity_decode` until stable.

No view or API changes required; all display paths already use `getDecodedContent()`.
