# Research microsite YAML front matter visible in reader — Customer Support Investigation

**Date**: 2026-04-29  
**Status**: Resolved  
**Customer**: N/A (internal / reported via product)  
**Priority**: Low  
**Reported By**: Internal  

## Issue Description

On research microsites (in-app and shared read-only views), text intended as static-site YAML front matter (`layout`, `title`, `description`, sometimes wrapped in `---`) appeared as visible body content above the real heading — for example a bold-looking block: `layout: doc title: … description: …`.

## Customer Impact

- Cosmetic / readability: metadata duplicated above the document title.
- Affected microsite pages whose imported Markdown still contained Jekyll/VitePress-style front matter.

## Investigation Steps

1. Confirmed microsite HTML is produced via `SafeCommonMarkConverter` on raw thought `content` in `IdeaController::showMicrositeResearch` and `SharedResearchViewController::renderReadonlyMicrosite`.
2. Compared with help skill Markdown: `HelpController` already stripped HTML comments and **single-line** `---` blocks; microsite paths had no stripping.
3. Root cause: front matter was passed through CommonMark as literal paragraphs; multiline `--- … ---` blocks were not stripped reliably (prior regex only matched one inner line).

## Root Cause Analysis

No preamble stripping on the research microsite render path; imported or pasted Markdown retained front matter intended for static-site generators.

## Resolution

- Added `App\Support\MarkdownDisplayHelper::stripPreambleForMarkdownDisplay()` to strip: HTML comment preambles, multiline YAML between `---` fences, consecutive leading lines using known front-matter keys, and a common single-line concatenated pattern (`layout:` / `title:` / `description:` on one line).
- Applied before Markdown conversion for in-app and shared microsite pages.
- Refactored `HelpController` to use the same helper (fixes multiline front matter on skill pages).

## Prevention & Follow-up

- [ ] Optional: strip at microsite **import** time so stored content never contains front matter (would require import-path changes and backfill decision).

## References

- `app/Support/MarkdownDisplayHelper.php`
- `app/Http/Controllers/IdeaController.php` (`showMicrositeResearch`)
- `app/Http/Controllers/SharedResearchViewController.php` (`renderReadonlyMicrosite`)
