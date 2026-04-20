# Research Comments (Polymorphic) Design (v1)

## Goal

Allow authors to add replies and inline annotations to research detail pages, and allow public viewers on shared research links to leave comments. Replace the existing ad-hoc thought-reply UI with a single polymorphic comments system that can be reused on other models (Projects, Meetings, Ideas) later.

## Scope

### In scope (v1)

- New polymorphic `comments` table and model with a `HasComments` trait.
- Morph map registered for `thought` and `project`. Meetings and Ideas are stored as Thoughts, so the `thought` alias covers them.
- Research detail page (`/research/{thought}`):
  - Page-level comment thread at the bottom of the research article card.
  - Section-level comments in a right-margin side-rail on `lg+` screens; inline collapsed `<details>` disclosure on narrow screens.
- Public shared view (`/r/{token}`):
  - Same two-level layout.
  - Guest comments gated by a per-share `allow_comments` flag (default on).
  - Guest comments: display name required, plain text, length ≤ 2,000 chars, honeypot field, rate-limited per IP.
- Owner comments everywhere: markdown content, length ≤ 10,000 chars. Owner may edit and delete own comments; may delete guest comments on their research; cannot edit guest comments.
- Unread indicator on `/research/{thought}` and in Stream cards until the owner views the page. Owner's own comments excluded from the count.
- Replace existing `thought_detail_replies` UI on all thought detail pages with the new polymorphic comment partial.
- Backfill existing reply-shaped child Thoughts into the `comments` table and hide the original rows from Stream / Ideas / search via a global scope. Hard deletion of the archived rows is a separate follow-up.

### Out of scope (v1)

- Threading / nested replies.
- Reactions, @mentions, file attachments.
- Captcha.
- Email or push notifications for comments.
- Moderation / approval queue.
- Guest editing of their own comments.
- Project / Meeting comment UI surfaces (plumbing is registered; UI lives in a later spec).
- Hard-deleting the archived reply Thought rows (cleanup migration follows after a monitoring period).

## Architecture

### Polymorphic comments

One `comments` table, attached to a parent model via a `commentable` morph relation. Eloquent morph aliases are enforced so type strings are stable:

```php
Relation::enforceMorphMap([
    'thought' => Thought::class,
    'project' => Project::class,
]);
```

A `HasComments` trait adds `comments(): MorphMany<Comment>` to commentable models. A `Commentable` interface expresses "who can comment on me": each commentable model implements `commentableOwnerId(): int` and `authorizeCommentCreation(?User, ?ShareContext): bool` so the `CommentPolicy` and controllers don't need per-type branching.

`ShareContext` is a small value object (`research_thought_id`, `share_id`, `allow_comments`) that the `SharedResearchCommentController` builds from the resolved `ResearchShare` and passes through the authorization layer. For authenticated requests on `/research/{thought}`, `ShareContext` is `null`.

### Relationship to the existing reply system

The current reply UI on thought detail pages reads child `Thought` rows through `ThoughtDetailPresenter::replyRows()`, filtering out structured document sections. Under the new design:

- The UI partial (`comments._thread`) reads from `comments`.
- A one-time backfill copies qualifying child Thoughts into `comments`.
- The archived Thought rows are flagged `metadata.migrated_to_comment = true` and hidden from user-facing queries via a global scope.
- A later cleanup migration removes the archived rows once we are confident.

## Data model

### `comments`

```
id                  primary key (matches existing PK convention)
commentable_type    string        -- morph alias
commentable_id      uuid          -- matches Thought/Project PK
author_user_id      bigint null   -- fk users.id, set null on user delete
author_name         varchar(100) null
content             text
format              varchar(16) not null default 'plain'   -- 'plain' | 'markdown'
ip_hash             varchar(64) null   -- sha256(ip . app.key), guest posts only
import_source       varchar(32) null   -- set to 'thought_reply_backfill' by the backfill migration; null for normal writes
created_at          timestamp
updated_at          timestamp

index (commentable_type, commentable_id, created_at)
index (author_user_id, created_at)
```

Invariants enforced at the application layer (and by CHECK constraint where the DB supports it):

- Exactly one of `author_user_id` / `author_name` is non-null.
- `format = 'markdown'` requires `author_user_id IS NOT NULL`.
- `ip_hash IS NOT NULL` implies `author_user_id IS NULL`.

### `research_shares` additions

```
allow_comments  boolean not null default true
```

### `thought_comment_reads`

Tracks when a user last viewed a thought's comments so we can compute unread counts.

```
user_id       bigint references users on delete cascade
thought_id    uuid references thoughts on delete cascade
last_read_at  timestamp
primary key (user_id, thought_id)
```

Unread count for a research root = count of comments attached to the root thought **or any of its section children** where `created_at > last_read_at` and `author_user_id <> :current_user`. Opening `/research/{thought}` upserts `last_read_at = now()` for the current user.

### Models and traits

- `App\Models\Comment` — fillable: `content`, `format`, `author_name`; relations: `commentable(): MorphTo`, `author(): BelongsTo<User>`; helpers: `isGuest()`, `displayName()`, `canBeEditedBy(?User)`, `canBeDeletedBy(?User)`; scope: `chronological()`.
- `App\Models\Concerns\HasComments` — trait adding `comments(): MorphMany<Comment>`.
- `App\Contracts\Commentable` — interface with `commentableOwnerId(): ?int` and `authorizeCommentCreation(?User, ?ShareContext): bool`.

## HTTP

### Owner routes (auth)

```
POST   /comments               CommentController@store
PATCH  /comments/{comment}     CommentController@update
DELETE /comments/{comment}     CommentController@destroy
```

`store` body:

- `commentable_type` (string; must be in registered morph map)
- `commentable_id` (uuid)
- `content` (string, ≤ 10,000)
- `format` (optional, defaults to `markdown` for authenticated users)

Authorization flow: resolve the commentable, `Gate::authorize('comment', $commentable)`, which delegates to the commentable model's `authorizeCommentCreation`. For a research section Thought, the check walks up to the root and authorizes against it. `update` / `destroy` are governed by `CommentPolicy`.

### Public (guest) route

```
POST /r/{token}/comments   SharedResearchCommentController@store
```

- Protected by the existing share-password session gate.
- Throttled by a new named limiter `shared-research-comment`:
  - 5 requests/min per IP
  - 30 requests/hour per IP
- Body:
  - `commentable_id` (uuid — must equal the share's root thought or one of its section-children)
  - `author_name` (string, 1–100, stripped of control chars, rejects obvious URLs)
  - `content` (string, ≤ 2,000)
  - `website_url` (honeypot; must be empty)
- Insert with `author_user_id = null`, `format = 'plain'`, `ip_hash = sha256(request->ip() . config('app.key'))`.
- Honeypot non-empty: silently accept-and-drop (302 back, no DB insert).
- No public `update` / `destroy` endpoints in v1.

### Research page GET

`IdeaController::showResearch` additionally:

- Loads `$rootComments` (page-level) and `$sectionComments` (keyed by section id).
- Computes `$unreadCount` for the authenticated user.
- Upserts `thought_comment_reads.last_read_at = now()`.

`SharedResearchViewController::renderReadonly` additionally:

- Loads the same `$rootComments` / `$sectionComments`.
- Passes `$share->allow_comments` to the view as `canComment`.

## Views

### Presenter

`App\View\Presenters\ResearchCommentsPresenter`

Inputs: research root `Thought`, authenticated `User|null`, `ShareContext|null`. Exposes:

- `pageLevelRows(): array` — `id, author_name, is_owner, content_html, created_at_human, updated_label, can_edit, can_delete`.
- `sectionRowsFor(Thought $section): array` — same shape, filtered.
- `canCommentOnPage(): bool`, `canCommentOnSection(Thought $section): bool`.
- `unreadCount(): int` (owner view only).
- `allowGuestComments(): bool` (derived from share when in public context).

Markdown rendering uses `CommonMarkConverter(['html_input' => 'strip', 'allow_unsafe_links' => false])`, matching the existing research markdown safety profile. Plain-text comments go through `e()` + `nl2br`.

### New partials

```
resources/views/comments/
    _thread.blade.php   -- renders a list of rows + a form; shared across all attach points
    _row.blade.php      -- one comment row: author, timestamp, body, edit/delete controls
    _form.blade.php     -- form; variants for owner (markdown) and guest (plain + name + honeypot)
```

### Research detail page layout

`resources/views/idea/research_show.blade.php` and `resources/views/idea/partials/research_content.blade.php` change to a responsive grid:

```
<div class="research-body lg:grid lg:grid-cols-[minmax(0,1fr)_320px] lg:gap-10">
    <div class="research-prose">
        {!! $root_html !!}
        ... sections loop ...
    </div>
    <aside class="research-annotations hidden lg:block">
        ... per-section threads aligned next to each section ...
    </aside>
</div>
```

- On `lg+`: side-rail visible, each section's thread pinned beside its section via a small Alpine/IntersectionObserver helper.
- On `< lg`: the aside is hidden; each section footer gets a `<details>` disclosure labeled "N comments" that expands inline.
- Page-level thread always at the bottom of the research article card.

### Shared readonly page layout

`resources/views/shared_research/readonly.blade.php` receives the same structural changes. The thread partial renders the form only when `$share->allow_comments` is true. The guest form:

- Includes a visually hidden honeypot input `<input type="text" name="website_url" tabindex="-1" autocomplete="off" style="display:none">`.
- Required `author_name` input.
- Textarea with `maxlength="2000"`.

### Existing thought detail page

`resources/views/idea/show.blade.php` replaces `@include('idea.partials.thought_detail_replies', ...)` with `@include('comments._thread', ...)` using the root thought as the commentable. After the backfill is verified in production, delete `thought_detail_replies.blade.php` and `ThoughtDetailPresenter::replyRows()`.

## Authorization

`CommentPolicy` responsibilities:

| Ability   | Rule |
|-----------|------|
| `create`  | delegated to `commentable.authorizeCommentCreation(?User, ?ShareContext)` |
| `update`  | `comment.author_user_id === user.id` |
| `delete`  | `comment.author_user_id === user.id` OR `user.id === commentable.commentableOwnerId()` |

`Thought::authorizeCommentCreation`:

- If `user` is the thought owner: allow (markdown).
- If `shareContext` exists, matches this thought (or its research-section child), and `allow_comments` is true: allow (plain, guest only).
- Otherwise: deny.

Authorization matrix:

| Actor | Create page-level | Create section-level | Edit own | Delete own | Delete others' |
|---|---|---|---|---|---|
| Research owner | yes | yes | yes | yes | yes (on their own research) |
| Authed non-owner | no | no | n/a | n/a | no |
| Guest (share allows) | yes (plain) | yes (plain) | no | no | no |
| Guest (share disabled) | 403 | 403 | n/a | n/a | n/a |

## Validation

Server-side, always:

- `content`: required, trimmed non-empty, length ≤ 10,000 (owner) / 2,000 (guest).
- `format`: enum `plain|markdown`; guests restricted to `plain`.
- `author_name` (guest): required, 1–100 chars, stripped of control chars, rejects strings containing `http://` or `https://`.
- `commentable_id`: must exist; `commentable_type` must resolve through the morph map; actor must pass `authorizeCommentCreation`.
- Honeypot: `website_url` must be empty. Non-empty → 302 without insert.

## Rate limits

- Authed owner POSTs: `throttle:60,1` per user.
- Guest POSTs on `/r/{token}/comments`: named limiter `shared-research-comment`:

  ```php
  RateLimiter::for('shared-research-comment', function (Request $r) {
      return [
          Limit::perMinute(5)->by($r->ip()),
          Limit::perHour(30)->by($r->ip()),
      ];
  });
  ```

## Failure handling

- Unknown `commentable_type` → 422.
- Commentable not found → 404.
- `allow_comments = false` + guest POST → 403 with generic flash.
- Rate limit exceeded → 429 with retry-after.
- Validation failure → 422 with field errors; textarea keeps content, `author_name` preserved.
- Policy denial → 403.
- Concurrent edits → last write wins (no version field in v1).
- Markdown render exception → logged; UI falls back to "Content unavailable."

## Migration of existing thought replies

Three migrations:

1. **Schema:** create `comments`, `thought_comment_reads`; add `research_shares.allow_comments`.
2. **Backfill:** copy qualifying child Thoughts into `comments`:
   - Select `thoughts` where `parent_id IS NOT NULL`, `source_metadata->>'section_index' IS NULL`, `metadata->>'video_section_type' IS NULL` (matches `ThoughtDetailPresenter::replyRows` filter).
   - Insert into `comments` with `commentable_type='thought'`, `commentable_id = parent_id`, `author_user_id = user_id`, `author_name = null`, `content = thought.content`, `format = 'markdown'`, `created_at = thought.created_at`.
   - Flag the source row `metadata.migrated_to_comment = true` (content untouched for reversibility).
3. **Scope:** add a global scope (or explicit scope method `withoutMigratedReplies`) to `Thought` that filters `metadata->>'migrated_to_comment' IS DISTINCT FROM 'true'` out of Stream, Ideas, and search queries.

Down-migration reverses the backfill by clearing flags and deleting the corresponding `comments` rows (identified by a marker in an `import_source` column recorded during backfill).

A later, separate migration hard-deletes the archived rows.

## Testing

### Unit

- `CommentPolicyTest` — owner vs non-owner vs guest; create/update/delete matrix.
- `CommentTest` — `isGuest()`, `displayName()`; enforces `format` / author invariants.
- `ResearchCommentsPresenterTest` — page-level vs section-level split; unread count; `canComment` gating via share context.

### Feature

- `ResearchCommentCreateTest` — owner posts markdown page-level + section-level; renders back on `/research/{thought}`.
- `ResearchCommentEditDeleteTest` — owner edits/deletes own; owner deletes guest; owner cannot edit guest.
- `SharedResearchCommentTest` — guest post on `/r/{token}/comments` allowed when share permits; 403 when disabled; honeypot silently drops; rate limiter trips at threshold.
- `UnreadCommentIndicatorTest` — counter increments on guest comment; resets on page view; excludes own comments.
- `ThoughtRepliesMigrationTest` — qualifying children backfilled; `metadata.migrated_to_comment` flags set; `withoutMigratedReplies` scope hides them from Stream / Ideas / search.
- `NonOwnerAuthedCannotCommentTest` — a different authenticated user receives 403 across create/update/delete.

### Browser (optional)

If project has Dusk configured: responsive test that asserts side-rail visible at `lg+` and collapsed disclosure on narrow. Otherwise document a short manual verification checklist.

## Open questions noted for implementation

1. **Unread indicator in Stream cards.** Shape (numeric pill / dot / nothing) is deferred to the implementation plan. Spec requires only that the count is available via presenter.
2. **Section re-run orphaning.** If a research re-run replaces section thoughts, existing section comments will point at deleted IDs. v1 does not rerun over existing research; proposed behavior for a future spec is to re-parent orphans to the root as page-level comments.
3. **Edit label.** UI shows "(edited)" when `updated_at > created_at + 60s`. No edit history table in v1.
4. **Guest impersonation of owner name.** `author_name` is free text with no uniqueness check. Intentional per the "no author badge" decision. Noted as a minor vector for confusion.
5. **Rollback strategy.** Backfill is reversible via `import_source` marker column in `comments` and `metadata.migrated_to_comment` on Thoughts. Documented in the migration file's `down()` method.

## Related docs

- Existing reply plumbing: `app/View/Presenters/Thoughts/ThoughtDetailPresenter.php::replyRows()`, `resources/views/idea/partials/thought_detail_replies.blade.php`, `routes/web.php` → `POST /thoughts` (to be retired from the reply path after migration).
- Existing shared research: `app/Http/Controllers/SharedResearchViewController.php`, `resources/views/shared_research/readonly.blade.php`.
- Research render path: `App\Http\Controllers\IdeaController::showResearch`, `resources/views/idea/research_show.blade.php`.
