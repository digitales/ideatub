# Demo mode obfuscation — v1 boundary (implementation checklist)

Concise handoff for what demo mode v1 covers versus known exclusions. See `docs/superpowers/specs/2026-03-31-demo-mode-obfuscation-design.md` for full design.

## Covered (presenter-backed HTML, session demo mode on)

- Thought detail (`thoughts.show`): main content, email subject/body, replies, email research preview HTML; inline edit suppressed where implemented.
- Idea index (`idea.index`): card bodies, parent/comment previews, AJAX `html` fragments; Alpine raw-content paths avoided in demo mode.
- Stream (`idea.stream`): same card/preview rules as index for the main stream response and AJAX fragments.
- Incomplete ideas (`idea.ideas`): idea body and research snippet rows; AJAX `html` fragments.
- Completed ideas (`idea.completed`): list excerpts; date labels remain real.
- Private research page (`idea.research.show`): research root/section bodies, related email subject, and editorial link summary narrative fields; URLs, counts, and status labels remain real.
- Project index (`projects.index`): card titles and description excerpts via `ProjectShowPresenter`; "New project" actions hidden in demo mode.
- Project show (`projects.show`): project title/description, pinned context body, member row title/excerpt; sidebar add/import and pin/remove hidden; working memory inline block hidden (not obfuscated).

## Intentional v1 exclusions / gaps

- **JSON/API payloads** beyond server-rendered HTML fragments (e.g. non-HTML API fields are not obfuscated).
- **Shared / public research pages** unless explicitly wired through the same presenter obfuscation path later.
- **Export, email, PDF, and similar outbound surfaces** not using the covered Blade presenters.
- **ImportedEmail.summary**: not rendered on the covered v1 pages; obfuscation for it is deferred until a covered surface displays it (per implementation plan).
- **Any Blade route or client prop path** that still reads raw narrative `Thought` / email fields outside the covered presenter-backed pages above remains out of scope until listed here and tested.
- **Project graph, edit**: titles and descriptions still raw until follow-up slices.

## Operational

- Toggle and banner require `config('services.demo_mode.enabled')` and authenticated session; feature-flag off means routes 404 and banner does not show even with stale session keys.
