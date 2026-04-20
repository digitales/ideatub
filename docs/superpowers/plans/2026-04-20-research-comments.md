# Research Comments (Polymorphic) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship a polymorphic `comments` system and use it to add page-level and section-level discussion on research detail pages (authenticated and public `/r/{token}` views), while migrating the existing thought-reply UI onto the same system.

**Architecture:** New `comments` table with a morph (`commentable_type`, `commentable_id`) relation. `HasComments` trait + `Commentable` interface let any model opt in. A `CommentPolicy` centralizes authorization; per-type rules live on the parent model. Research pages consume the system through a new `ResearchCommentsPresenter`. Guests on `/r/{token}` post via a dedicated controller with rate limiting + honeypot; owners post/edit/delete via a generic `CommentController`.

**Tech Stack:** Laravel 11, Eloquent polymorphic relations, PostgreSQL, Blade + Tailwind, Alpine.js (small helper for side-rail alignment), PHPUnit with `RefreshDatabase`.

**Spec:** `docs/superpowers/specs/2026-04-20-research-comments-design.md`

**Conventions observed:**
- Migrations live in `database/migrations/` with timestamp prefix `YYYY_MM_DD_NNNNNN_*`.
- Tests use `RefreshDatabase` and `withoutVite()` in `setUp()` when rendering Blade (see `tests/Feature/ResearchShowTest.php`).
- Models with UUID PKs use `HasUuids` (e.g. `Thought`).
- Policies live in `app/Policies/` and are auto-discovered by Laravel.
- `DB_CONNECTION=pgsql` is the default; the DB uses `->` / `->>` JSON operators.

---

## Task 1: Scaffold `comments` table, model, and trait

**Files:**
- Create: `database/migrations/2026_04_20_000001_create_comments_table.php`
- Create: `app/Models/Comment.php`
- Create: `app/Models/Concerns/HasComments.php`
- Create: `app/Contracts/Commentable.php`
- Test: `tests/Unit/CommentTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit;

use App\Models\Comment;
use App\Models\Thought;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommentTest extends TestCase
{
    use RefreshDatabase;

    public function test_comment_belongs_to_commentable_thought(): void
    {
        $user = User::factory()->create();
        $thought = Thought::factory()->create(['user_id' => $user->id]);

        $comment = Comment::create([
            'commentable_type' => 'thought',
            'commentable_id' => $thought->id,
            'author_user_id' => $user->id,
            'content' => 'Hello',
            'format' => 'markdown',
        ]);

        $this->assertTrue($comment->commentable->is($thought));
        $this->assertTrue($comment->author->is($user));
        $this->assertFalse($comment->isGuest());
        $this->assertSame($user->name, $comment->displayName());
    }

    public function test_guest_comment_has_author_name_and_no_user(): void
    {
        $thought = Thought::factory()->create();

        $comment = Comment::create([
            'commentable_type' => 'thought',
            'commentable_id' => $thought->id,
            'author_user_id' => null,
            'author_name' => 'Guest Jane',
            'content' => 'Cool research',
            'format' => 'plain',
            'ip_hash' => str_repeat('a', 64),
        ]);

        $this->assertTrue($comment->isGuest());
        $this->assertSame('Guest Jane', $comment->displayName());
        $this->assertNull($comment->author);
    }

    public function test_has_comments_trait_exposes_morph_many(): void
    {
        $thought = Thought::factory()->create();
        Comment::create([
            'commentable_type' => 'thought',
            'commentable_id' => $thought->id,
            'author_user_id' => $thought->user_id,
            'content' => 'a',
            'format' => 'markdown',
        ]);
        Comment::create([
            'commentable_type' => 'thought',
            'commentable_id' => $thought->id,
            'author_user_id' => $thought->user_id,
            'content' => 'b',
            'format' => 'markdown',
        ]);

        $this->assertCount(2, $thought->comments()->get());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter CommentTest`
Expected: FAIL with "Class 'App\Models\Comment' not found" or similar.

- [ ] **Step 3: Create the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comments', function (Blueprint $table) {
            $table->id();
            $table->string('commentable_type', 64);
            $table->string('commentable_id', 36);
            $table->foreignId('author_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('author_name', 100)->nullable();
            $table->text('content');
            $table->string('format', 16)->default('plain');
            $table->string('ip_hash', 64)->nullable();
            $table->string('import_source', 32)->nullable();
            $table->timestamps();

            $table->index(['commentable_type', 'commentable_id', 'created_at'], 'comments_commentable_created_idx');
            $table->index(['author_user_id', 'created_at'], 'comments_author_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comments');
    }
};
```

- [ ] **Step 4: Create the `Commentable` contract**

```php
<?php

namespace App\Contracts;

use App\Support\Comments\ShareContext;
use App\Models\User;

interface Commentable
{
    public function commentableOwnerId(): ?int;

    public function authorizeCommentCreation(?User $user, ?ShareContext $shareContext): bool;
}
```

- [ ] **Step 5: Create the `ShareContext` value object**

```php
<?php

namespace App\Support\Comments;

final class ShareContext
{
    public function __construct(
        public readonly string $researchThoughtId,
        public readonly int $shareId,
        public readonly bool $allowComments,
    ) {}
}
```

File: `app/Support/Comments/ShareContext.php`

- [ ] **Step 6: Create the `HasComments` trait**

```php
<?php

namespace App\Models\Concerns;

use App\Models\Comment;
use Illuminate\Database\Eloquent\Relations\MorphMany;

trait HasComments
{
    public function comments(): MorphMany
    {
        return $this->morphMany(Comment::class, 'commentable');
    }
}
```

- [ ] **Step 7: Create the `Comment` model**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Comment extends Model
{
    use HasFactory;

    protected $fillable = [
        'commentable_type',
        'commentable_id',
        'author_user_id',
        'author_name',
        'content',
        'format',
        'ip_hash',
        'import_source',
    ];

    public function commentable(): MorphTo
    {
        return $this->morphTo();
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }

    public function isGuest(): bool
    {
        return $this->author_user_id === null;
    }

    public function displayName(): string
    {
        return $this->isGuest()
            ? (string) $this->author_name
            : (string) $this->author?->name;
    }

    public function scopeChronological($query)
    {
        return $query->orderBy('created_at');
    }
}
```

- [ ] **Step 8: Add `HasComments` to `Thought` and register morph map**

Modify `app/Models/Thought.php` — add `use App\Models\Concerns\HasComments;` at the top and `use HasComments;` in the `use` list inside the class (alongside `HasFactory`, `HasNeighbors`, `HasUuids`).

Modify `app/Providers/AppServiceProvider.php::boot()` — add at the start of `boot()`:

```php
\Illuminate\Database\Eloquent\Relations\Relation::enforceMorphMap([
    'thought' => \App\Models\Thought::class,
    'project' => \App\Models\Project::class,
]);
```

Also add `use App\Models\Concerns\HasComments;` and `use HasComments;` to `app/Models/Project.php`.

- [ ] **Step 9: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter CommentTest`
Expected: PASS (3 tests).

- [ ] **Step 10: Commit**

```bash
git add database/migrations/2026_04_20_000001_create_comments_table.php \
        app/Models/Comment.php \
        app/Models/Concerns/HasComments.php \
        app/Contracts/Commentable.php \
        app/Support/Comments/ShareContext.php \
        app/Models/Thought.php \
        app/Models/Project.php \
        app/Providers/AppServiceProvider.php \
        tests/Unit/CommentTest.php
git commit -m "feat(comments): add polymorphic comments table, model, and trait"
```

---

## Task 2: Implement `Commentable` on `Thought` + `Project`

**Files:**
- Modify: `app/Models/Thought.php`
- Modify: `app/Models/Project.php`
- Test: `tests/Unit/ThoughtCommentableTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit;

use App\Models\Thought;
use App\Models\User;
use App\Support\Comments\ShareContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ThoughtCommentableTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_comment_on_own_thought(): void
    {
        $user = User::factory()->create();
        $thought = Thought::factory()->create(['user_id' => $user->id]);

        $this->assertTrue($thought->authorizeCommentCreation($user, null));
        $this->assertSame($user->id, $thought->commentableOwnerId());
    }

    public function test_non_owner_cannot_comment(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $thought = Thought::factory()->create(['user_id' => $owner->id]);

        $this->assertFalse($thought->authorizeCommentCreation($other, null));
    }

    public function test_guest_can_comment_when_share_context_matches_and_allows(): void
    {
        $owner = User::factory()->create();
        $thought = Thought::factory()->create(['user_id' => $owner->id]);

        $context = new ShareContext(
            researchThoughtId: $thought->id,
            shareId: 1,
            allowComments: true,
        );

        $this->assertTrue($thought->authorizeCommentCreation(null, $context));
    }

    public function test_guest_cannot_comment_when_share_disables_comments(): void
    {
        $owner = User::factory()->create();
        $thought = Thought::factory()->create(['user_id' => $owner->id]);

        $context = new ShareContext(
            researchThoughtId: $thought->id,
            shareId: 1,
            allowComments: false,
        );

        $this->assertFalse($thought->authorizeCommentCreation(null, $context));
    }

    public function test_guest_can_comment_on_section_child_of_shared_root(): void
    {
        $owner = User::factory()->create();
        $root = Thought::factory()->create(['user_id' => $owner->id]);
        $section = Thought::factory()->create([
            'user_id' => $owner->id,
            'parent_id' => $root->id,
            'source_metadata' => ['section_index' => 1],
        ]);

        $context = new ShareContext(
            researchThoughtId: $root->id,
            shareId: 1,
            allowComments: true,
        );

        $this->assertTrue($section->authorizeCommentCreation(null, $context));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter ThoughtCommentableTest`
Expected: FAIL with "Call to undefined method authorizeCommentCreation".

- [ ] **Step 3: Implement `Commentable` on `Thought`**

Modify `app/Models/Thought.php` — add `use App\Contracts\Commentable;` and `use App\Support\Comments\ShareContext;` at the top; declare `class Thought extends Model implements Commentable`; add methods at the end of the class body:

```php
public function commentableOwnerId(): ?int
{
    return $this->user_id;
}

public function authorizeCommentCreation(?User $user, ?ShareContext $shareContext): bool
{
    if ($user !== null && $this->user_id === $user->id) {
        return true;
    }

    if ($shareContext === null || ! $shareContext->allowComments) {
        return false;
    }

    if ($shareContext->researchThoughtId === $this->id) {
        return true;
    }

    return $this->parent_id === $shareContext->researchThoughtId;
}
```

Import `App\Models\User` if not already imported.

- [ ] **Step 4: Implement `Commentable` on `Project`**

Modify `app/Models/Project.php` — implement the interface with a simple rule: only the owner can comment (projects have no public share surface in v1):

```php
public function commentableOwnerId(): ?int
{
    return $this->user_id;
}

public function authorizeCommentCreation(?User $user, ?ShareContext $shareContext): bool
{
    return $user !== null && $this->user_id === $user->id;
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter ThoughtCommentableTest`
Expected: PASS (5 tests).

- [ ] **Step 6: Commit**

```bash
git add app/Models/Thought.php app/Models/Project.php tests/Unit/ThoughtCommentableTest.php
git commit -m "feat(comments): implement Commentable contract on Thought and Project"
```

---

## Task 3: `CommentPolicy` for update/delete

**Files:**
- Create: `app/Policies/CommentPolicy.php`
- Test: `tests/Unit/CommentPolicyTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit;

use App\Models\Comment;
use App\Models\Thought;
use App\Models\User;
use App\Policies\CommentPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommentPolicyTest extends TestCase
{
    use RefreshDatabase;

    private CommentPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new CommentPolicy;
    }

    public function test_author_can_update_own_comment(): void
    {
        $user = User::factory()->create();
        $thought = Thought::factory()->create(['user_id' => $user->id]);
        $comment = $this->ownerComment($user, $thought);

        $this->assertTrue($this->policy->update($user, $comment));
    }

    public function test_other_user_cannot_update_comment(): void
    {
        $author = User::factory()->create();
        $other = User::factory()->create();
        $thought = Thought::factory()->create(['user_id' => $author->id]);
        $comment = $this->ownerComment($author, $thought);

        $this->assertFalse($this->policy->update($other, $comment));
    }

    public function test_nobody_can_update_guest_comment(): void
    {
        $owner = User::factory()->create();
        $thought = Thought::factory()->create(['user_id' => $owner->id]);
        $comment = Comment::create([
            'commentable_type' => 'thought',
            'commentable_id' => $thought->id,
            'author_user_id' => null,
            'author_name' => 'Visitor',
            'content' => 'hi',
            'format' => 'plain',
        ]);

        $this->assertFalse($this->policy->update($owner, $comment));
    }

    public function test_author_can_delete_own_comment(): void
    {
        $user = User::factory()->create();
        $thought = Thought::factory()->create(['user_id' => $user->id]);
        $comment = $this->ownerComment($user, $thought);

        $this->assertTrue($this->policy->delete($user, $comment));
    }

    public function test_commentable_owner_can_delete_any_comment_on_their_content(): void
    {
        $owner = User::factory()->create();
        $thought = Thought::factory()->create(['user_id' => $owner->id]);
        $comment = Comment::create([
            'commentable_type' => 'thought',
            'commentable_id' => $thought->id,
            'author_user_id' => null,
            'author_name' => 'Visitor',
            'content' => 'hi',
            'format' => 'plain',
        ]);

        $this->assertTrue($this->policy->delete($owner, $comment));
    }

    public function test_unrelated_user_cannot_delete_comment(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $thought = Thought::factory()->create(['user_id' => $owner->id]);
        $comment = $this->ownerComment($owner, $thought);

        $this->assertFalse($this->policy->delete($other, $comment));
    }

    private function ownerComment(User $user, Thought $thought): Comment
    {
        return Comment::create([
            'commentable_type' => 'thought',
            'commentable_id' => $thought->id,
            'author_user_id' => $user->id,
            'content' => 'hi',
            'format' => 'markdown',
        ]);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter CommentPolicyTest`
Expected: FAIL with "Class 'App\Policies\CommentPolicy' not found".

- [ ] **Step 3: Implement the policy**

```php
<?php

namespace App\Policies;

use App\Models\Comment;
use App\Models\User;

class CommentPolicy
{
    public function update(User $user, Comment $comment): bool
    {
        return $comment->author_user_id === $user->id;
    }

    public function delete(User $user, Comment $comment): bool
    {
        if ($comment->author_user_id === $user->id) {
            return true;
        }

        $commentable = $comment->commentable;

        if ($commentable === null) {
            return false;
        }

        $ownerId = method_exists($commentable, 'commentableOwnerId')
            ? $commentable->commentableOwnerId()
            : null;

        return $ownerId !== null && $ownerId === $user->id;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter CommentPolicyTest`
Expected: PASS (6 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Policies/CommentPolicy.php tests/Unit/CommentPolicyTest.php
git commit -m "feat(comments): add CommentPolicy for update and delete"
```

---

## Task 4: Owner `CommentController` (store / update / destroy)

**Files:**
- Create: `app/Http/Controllers/CommentController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/CommentControllerTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\Comment;
use App\Models\Thought;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommentControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_owner_can_post_comment_on_own_thought(): void
    {
        $user = User::factory()->create();
        $thought = Thought::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)->post(route('comments.store'), [
            'commentable_type' => 'thought',
            'commentable_id' => $thought->id,
            'content' => 'My **markdown** reply',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('comments', [
            'commentable_type' => 'thought',
            'commentable_id' => $thought->id,
            'author_user_id' => $user->id,
            'format' => 'markdown',
        ]);
    }

    public function test_non_owner_cannot_post_comment(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $thought = Thought::factory()->create(['user_id' => $owner->id]);

        $response = $this->actingAs($other)->post(route('comments.store'), [
            'commentable_type' => 'thought',
            'commentable_id' => $thought->id,
            'content' => 'sneaky',
        ]);

        $response->assertForbidden();
        $this->assertDatabaseCount('comments', 0);
    }

    public function test_unknown_morph_type_returns_422(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('comments.store'), [
            'commentable_type' => 'not_a_type',
            'commentable_id' => 'abc',
            'content' => 'x',
        ]);

        $response->assertStatus(422);
    }

    public function test_author_can_update_own_comment(): void
    {
        $user = User::factory()->create();
        $thought = Thought::factory()->create(['user_id' => $user->id]);
        $comment = Comment::create([
            'commentable_type' => 'thought',
            'commentable_id' => $thought->id,
            'author_user_id' => $user->id,
            'content' => 'orig',
            'format' => 'markdown',
        ]);

        $response = $this->actingAs($user)->patch(route('comments.update', $comment), [
            'content' => 'edited',
        ]);

        $response->assertRedirect();
        $this->assertSame('edited', $comment->fresh()->content);
    }

    public function test_commentable_owner_can_delete_guest_comment(): void
    {
        $owner = User::factory()->create();
        $thought = Thought::factory()->create(['user_id' => $owner->id]);
        $comment = Comment::create([
            'commentable_type' => 'thought',
            'commentable_id' => $thought->id,
            'author_user_id' => null,
            'author_name' => 'Visitor',
            'content' => 'spam',
            'format' => 'plain',
        ]);

        $response = $this->actingAs($owner)->delete(route('comments.destroy', $comment));

        $response->assertRedirect();
        $this->assertDatabaseMissing('comments', ['id' => $comment->id]);
    }

    public function test_content_too_long_returns_422(): void
    {
        $user = User::factory()->create();
        $thought = Thought::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)->post(route('comments.store'), [
            'commentable_type' => 'thought',
            'commentable_id' => $thought->id,
            'content' => str_repeat('a', 10_001),
        ]);

        $response->assertStatus(422);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter CommentControllerTest`
Expected: FAIL on route not defined.

- [ ] **Step 3: Add routes**

Modify `routes/web.php` — inside the main `Route::middleware('auth')` group, add:

```php
use App\Http\Controllers\CommentController;

Route::post('/comments', [CommentController::class, 'store'])->name('comments.store');
Route::patch('/comments/{comment}', [CommentController::class, 'update'])->name('comments.update');
Route::delete('/comments/{comment}', [CommentController::class, 'destroy'])->name('comments.destroy');
```

- [ ] **Step 4: Implement the controller**

```php
<?php

namespace App\Http\Controllers;

use App\Contracts\Commentable;
use App\Models\Comment;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CommentController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $morphMap = Relation::morphMap();

        $validated = $request->validate([
            'commentable_type' => ['required', 'string', Rule::in(array_keys($morphMap))],
            'commentable_id' => ['required', 'string', 'max:36'],
            'content' => ['required', 'string', 'max:10000'],
        ]);

        $modelClass = $morphMap[$validated['commentable_type']];
        $commentable = $modelClass::find($validated['commentable_id']);

        abort_unless($commentable !== null, 404);
        abort_unless($commentable instanceof Commentable, 422);
        abort_unless(
            $commentable->authorizeCommentCreation($request->user(), null),
            403
        );

        Comment::create([
            'commentable_type' => $validated['commentable_type'],
            'commentable_id' => $validated['commentable_id'],
            'author_user_id' => $request->user()->id,
            'author_name' => null,
            'content' => $validated['content'],
            'format' => 'markdown',
        ]);

        return redirect()->back()->with('success', 'Comment posted.');
    }

    public function update(Request $request, Comment $comment): RedirectResponse
    {
        $this->authorize('update', $comment);

        $validated = $request->validate([
            'content' => ['required', 'string', 'max:10000'],
        ]);

        $comment->update(['content' => $validated['content']]);

        return redirect()->back()->with('success', 'Comment updated.');
    }

    public function destroy(Request $request, Comment $comment): RedirectResponse
    {
        $this->authorize('delete', $comment);

        $comment->delete();

        return redirect()->back()->with('success', 'Comment deleted.');
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter CommentControllerTest`
Expected: PASS (6 tests).

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/CommentController.php routes/web.php tests/Feature/CommentControllerTest.php
git commit -m "feat(comments): add CommentController for owner CRUD"
```

---

## Task 5: `research_shares.allow_comments` + `thought_comment_reads` tables

**Files:**
- Create: `database/migrations/2026_04_20_000002_add_allow_comments_to_research_shares.php`
- Create: `database/migrations/2026_04_20_000003_create_thought_comment_reads_table.php`
- Modify: `app/Models/ResearchShare.php`
- Create: `app/Models/ThoughtCommentRead.php`
- Test: `tests/Unit/ThoughtCommentReadTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit;

use App\Models\Thought;
use App\Models\ThoughtCommentRead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ThoughtCommentReadTest extends TestCase
{
    use RefreshDatabase;

    public function test_upsert_creates_or_updates_last_read_at(): void
    {
        $user = User::factory()->create();
        $thought = Thought::factory()->create(['user_id' => $user->id]);

        ThoughtCommentRead::markRead($user->id, $thought->id);

        $row = ThoughtCommentRead::where('user_id', $user->id)
            ->where('thought_id', $thought->id)
            ->first();

        $this->assertNotNull($row);
        $this->assertNotNull($row->last_read_at);

        $before = $row->last_read_at;
        sleep(1);
        ThoughtCommentRead::markRead($user->id, $thought->id);
        $this->assertTrue($row->fresh()->last_read_at->greaterThan($before));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter ThoughtCommentReadTest`
Expected: FAIL on missing table/model.

- [ ] **Step 3: Create migration for `research_shares.allow_comments`**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('research_shares', function (Blueprint $table) {
            $table->boolean('allow_comments')->default(true)->after('expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('research_shares', function (Blueprint $table) {
            $table->dropColumn('allow_comments');
        });
    }
};
```

- [ ] **Step 4: Create migration for `thought_comment_reads`**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('thought_comment_reads', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('thought_id')->constrained('thoughts')->cascadeOnDelete();
            $table->timestamp('last_read_at');
            $table->primary(['user_id', 'thought_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('thought_comment_reads');
    }
};
```

- [ ] **Step 5: Create the model**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ThoughtCommentRead extends Model
{
    public $timestamps = false;

    protected $fillable = ['user_id', 'thought_id', 'last_read_at'];

    protected $casts = [
        'last_read_at' => 'datetime',
    ];

    public static function markRead(int $userId, string $thoughtId): void
    {
        static::updateOrCreate(
            ['user_id' => $userId, 'thought_id' => $thoughtId],
            ['last_read_at' => now()],
        );
    }
}
```

File: `app/Models/ThoughtCommentRead.php`

- [ ] **Step 6: Add `allow_comments` to `ResearchShare`**

Modify `app/Models/ResearchShare.php` — add `'allow_comments'` to `$fillable` and `'allow_comments' => 'bool'` to `$casts`.

- [ ] **Step 7: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter ThoughtCommentReadTest`
Expected: PASS (1 test).

- [ ] **Step 8: Commit**

```bash
git add database/migrations/2026_04_20_000002_add_allow_comments_to_research_shares.php \
        database/migrations/2026_04_20_000003_create_thought_comment_reads_table.php \
        app/Models/ThoughtCommentRead.php \
        app/Models/ResearchShare.php \
        tests/Unit/ThoughtCommentReadTest.php
git commit -m "feat(comments): add share toggle and read-tracking storage"
```

---

## Task 6: `ResearchCommentsPresenter`

**Files:**
- Create: `app/View/Presenters/Comments/ResearchCommentsPresenter.php`
- Test: `tests/Unit/ResearchCommentsPresenterTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit;

use App\Models\Comment;
use App\Models\Thought;
use App\Models\ThoughtCommentRead;
use App\Models\User;
use App\Support\Comments\ShareContext;
use App\View\Presenters\Comments\ResearchCommentsPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResearchCommentsPresenterTest extends TestCase
{
    use RefreshDatabase;

    public function test_splits_page_level_and_section_level_rows(): void
    {
        $user = User::factory()->create();
        $root = Thought::factory()->create(['user_id' => $user->id]);
        $section = Thought::factory()->create([
            'user_id' => $user->id,
            'parent_id' => $root->id,
            'source_metadata' => ['section_index' => 1],
        ]);
        Comment::create([
            'commentable_type' => 'thought',
            'commentable_id' => $root->id,
            'author_user_id' => $user->id,
            'content' => 'page-level',
            'format' => 'markdown',
        ]);
        Comment::create([
            'commentable_type' => 'thought',
            'commentable_id' => $section->id,
            'author_user_id' => $user->id,
            'content' => 'section-level',
            'format' => 'markdown',
        ]);

        $presenter = new ResearchCommentsPresenter($root, $user, null);

        $this->assertCount(1, $presenter->pageLevelRows());
        $this->assertCount(1, $presenter->sectionRowsFor($section));
        $this->assertStringContainsString('page-level', $presenter->pageLevelRows()[0]['content_html']);
    }

    public function test_unread_count_excludes_current_user_comments(): void
    {
        $owner = User::factory()->create();
        $root = Thought::factory()->create(['user_id' => $owner->id]);

        Comment::create([
            'commentable_type' => 'thought',
            'commentable_id' => $root->id,
            'author_user_id' => $owner->id,
            'content' => 'self',
            'format' => 'markdown',
        ]);
        Comment::create([
            'commentable_type' => 'thought',
            'commentable_id' => $root->id,
            'author_user_id' => null,
            'author_name' => 'Guest',
            'content' => 'guest',
            'format' => 'plain',
        ]);

        $presenter = new ResearchCommentsPresenter($root, $owner, null);

        $this->assertSame(1, $presenter->unreadCount());

        ThoughtCommentRead::markRead($owner->id, $root->id);
        $presenter = new ResearchCommentsPresenter($root->fresh(), $owner->fresh(), null);
        $this->assertSame(0, $presenter->unreadCount());
    }

    public function test_allow_guest_comments_reflects_share_context(): void
    {
        $owner = User::factory()->create();
        $root = Thought::factory()->create(['user_id' => $owner->id]);

        $ctxOn = new ShareContext($root->id, 1, true);
        $ctxOff = new ShareContext($root->id, 1, false);

        $this->assertTrue((new ResearchCommentsPresenter($root, null, $ctxOn))->allowGuestComments());
        $this->assertFalse((new ResearchCommentsPresenter($root, null, $ctxOff))->allowGuestComments());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter ResearchCommentsPresenterTest`
Expected: FAIL on missing class.

- [ ] **Step 3: Implement the presenter**

```php
<?php

namespace App\View\Presenters\Comments;

use App\Models\Comment;
use App\Models\Thought;
use App\Models\ThoughtCommentRead;
use App\Models\User;
use App\Support\Comments\ShareContext;
use Illuminate\Support\Collection;
use League\CommonMark\CommonMarkConverter;

class ResearchCommentsPresenter
{
    private ?CommonMarkConverter $converter = null;

    public function __construct(
        private readonly Thought $root,
        private readonly ?User $viewer,
        private readonly ?ShareContext $shareContext,
    ) {}

    /** @return array<int, array<string, mixed>> */
    public function pageLevelRows(): array
    {
        return $this->rowsForIds([$this->root->id]);
    }

    /** @return array<int, array<string, mixed>> */
    public function sectionRowsFor(Thought $section): array
    {
        return $this->rowsForIds([$section->id]);
    }

    public function canCommentOnPage(): bool
    {
        return $this->root->authorizeCommentCreation($this->viewer, $this->shareContext);
    }

    public function canCommentOnSection(Thought $section): bool
    {
        return $section->authorizeCommentCreation($this->viewer, $this->shareContext);
    }

    public function unreadCount(): int
    {
        if ($this->viewer === null) {
            return 0;
        }

        $lastRead = ThoughtCommentRead::query()
            ->where('user_id', $this->viewer->id)
            ->where('thought_id', $this->root->id)
            ->value('last_read_at');

        $ids = collect([$this->root->id])
            ->merge($this->root->comments->pluck('id'))
            ->merge(
                Thought::query()
                    ->where('parent_id', $this->root->id)
                    ->pluck('id')
            )
            ->unique()
            ->values();

        $q = Comment::query()
            ->whereIn('commentable_id', $ids)
            ->where('commentable_type', 'thought')
            ->where(function ($q) {
                $q->whereNull('author_user_id')
                    ->orWhere('author_user_id', '<>', $this->viewer->id);
            });

        if ($lastRead !== null) {
            $q->where('created_at', '>', $lastRead);
        }

        return (int) $q->count();
    }

    public function allowGuestComments(): bool
    {
        return $this->shareContext !== null && $this->shareContext->allowComments;
    }

    /**
     * @param  array<int, string>  $ids
     * @return array<int, array<string, mixed>>
     */
    private function rowsForIds(array $ids): array
    {
        return Comment::query()
            ->where('commentable_type', 'thought')
            ->whereIn('commentable_id', $ids)
            ->orderBy('created_at')
            ->get()
            ->map(fn (Comment $c) => $this->row($c))
            ->all();
    }

    /** @return array<string, mixed> */
    private function row(Comment $c): array
    {
        $isOwner = $c->author_user_id !== null
            && $c->author_user_id === $this->root->user_id;

        $contentHtml = $c->format === 'markdown'
            ? $this->converter()->convert($c->content)->getContent()
            : nl2br(e($c->content));

        $canEdit = $this->viewer !== null
            && $c->author_user_id === $this->viewer->id;

        $canDelete = $canEdit
            || ($this->viewer !== null && $this->viewer->id === $this->root->user_id);

        return [
            'id' => $c->id,
            'author_name' => $c->displayName(),
            'is_owner' => $isOwner,
            'is_guest' => $c->isGuest(),
            'content_html' => $contentHtml,
            'created_at_human' => $c->created_at->diffForHumans(),
            'updated_label' => $c->updated_at->greaterThan($c->created_at->copy()->addMinute())
                ? '(edited)'
                : null,
            'can_edit' => $canEdit,
            'can_delete' => $canDelete,
        ];
    }

    private function converter(): CommonMarkConverter
    {
        return $this->converter ??= new CommonMarkConverter([
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter ResearchCommentsPresenterTest`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add app/View/Presenters/Comments/ResearchCommentsPresenter.php tests/Unit/ResearchCommentsPresenterTest.php
git commit -m "feat(comments): add ResearchCommentsPresenter"
```

---

## Task 7: Generic `comments` view partials

**Files:**
- Create: `resources/views/comments/_thread.blade.php`
- Create: `resources/views/comments/_row.blade.php`
- Create: `resources/views/comments/_form.blade.php`

No dedicated test for the partials themselves — they're exercised by the rendering tests in later tasks.

- [ ] **Step 1: Create `resources/views/comments/_row.blade.php`**

```blade
{{--
  Expects: $row (array from ResearchCommentsPresenter::row()).
  Optional: $showControls (bool, default true).
--}}
@php($showControls = $showControls ?? true)
<li id="comment-{{ $row['id'] }}" class="rounded-xl border border-memory-violet/15 bg-white/70 px-4 py-3">
    <div class="flex items-baseline justify-between gap-3">
        <p class="text-[12px] font-semibold text-deep-indigo">{{ $row['author_name'] }}</p>
        <p class="text-[10px] text-slate-brand/40">
            {{ $row['created_at_human'] }}@if($row['updated_label']) {{ $row['updated_label'] }}@endif
        </p>
    </div>
    <div class="mt-2 prose prose-sm max-w-none text-[13px] text-slate-brand">{!! $row['content_html'] !!}</div>
    @if($showControls && ($row['can_edit'] || $row['can_delete']))
        <div class="mt-2 flex gap-2">
            @if($row['can_delete'])
                <form method="POST" action="{{ route('comments.destroy', $row['id']) }}">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="text-[11px] text-rose-600 hover:underline">Delete</button>
                </form>
            @endif
        </div>
    @endif
</li>
```

- [ ] **Step 2: Create `resources/views/comments/_form.blade.php`**

```blade
{{--
  Expects:
    $formAction (string)
    $commentableType (string)    -- used only for owner form
    $commentableId (string)
    $mode ('owner' | 'guest')
    $disabledMessage (string|null) -- when non-null, shown in place of the form
--}}
@if($disabledMessage)
    <p class="mt-4 text-[12px] text-slate-brand/50">{{ $disabledMessage }}</p>
@else
    <form method="POST" action="{{ $formAction }}" class="mt-4 space-y-3">
        @csrf
        @if($mode === 'owner')
            <input type="hidden" name="commentable_type" value="{{ $commentableType }}">
            <input type="hidden" name="commentable_id" value="{{ $commentableId }}">
        @else
            <input type="hidden" name="commentable_id" value="{{ $commentableId }}">
            <input type="text" name="website_url" tabindex="-1" autocomplete="off" style="display:none !important" aria-hidden="true">
            <input
                type="text"
                name="author_name"
                required
                maxlength="100"
                placeholder="Your name"
                class="w-full rounded-xl border border-memory-violet/15 bg-white/80 px-4 py-2 text-sm text-deep-indigo placeholder:text-slate-brand/40"
            >
        @endif
        <textarea
            name="content"
            rows="3"
            maxlength="{{ $mode === 'owner' ? 10000 : 2000 }}"
            required
            placeholder="{{ $mode === 'owner' ? 'Add a comment (markdown supported)' : 'Add a comment' }}"
            class="w-full rounded-xl border border-memory-violet/15 bg-white/80 px-4 py-3 text-sm text-deep-indigo placeholder:text-slate-brand/40 focus:border-memory-violet/40 focus:outline-none focus:ring-2 focus:ring-memory-violet/20"
        ></textarea>
        <div class="flex justify-end">
            <button type="submit" class="text-xs font-medium text-white px-4 py-2 rounded-lg transition-opacity hover:opacity-90" style="background: linear-gradient(135deg, #6D6AF7, #2A8C8C);">
                Post
            </button>
        </div>
        @error('content') <p class="text-[11px] text-rose-600">{{ $message }}</p> @enderror
        @error('author_name') <p class="text-[11px] text-rose-600">{{ $message }}</p> @enderror
    </form>
@endif
```

- [ ] **Step 3: Create `resources/views/comments/_thread.blade.php`**

```blade
{{--
  Expects:
    $rows (array<array>)
    $formAction (string)
    $commentableType (string|null)
    $commentableId (string)
    $mode ('owner' | 'guest')
    $disabledMessage (string|null)
    $title (string, default 'Comments')
    $showControls (bool, default true)
--}}
@php
    $title = $title ?? 'Comments';
    $showControls = $showControls ?? true;
@endphp
<section class="rounded-2xl border border-memory-violet/20 bg-white/80 backdrop-blur p-6 shadow-[0_4px_24px_rgba(109,106,247,0.08)]">
    <p class="text-[11px] font-semibold tracking-[0.1em] uppercase text-memory-violet/80">{{ $title }}</p>
    @if (count($rows) > 0)
        <ul class="mt-4 space-y-3">
            @foreach ($rows as $row)
                @include('comments._row', ['row' => $row, 'showControls' => $showControls])
            @endforeach
        </ul>
    @else
        <p class="mt-4 text-sm text-slate-brand/50">No comments yet.</p>
    @endif

    @include('comments._form', [
        'formAction' => $formAction,
        'commentableType' => $commentableType ?? 'thought',
        'commentableId' => $commentableId,
        'mode' => $mode,
        'disabledMessage' => $disabledMessage ?? null,
    ])
</section>
```

- [ ] **Step 4: Commit**

```bash
git add resources/views/comments/
git commit -m "feat(comments): add generic comments thread partials"
```

---

## Task 8: Wire page-level comments into `/research/{thought}`

**Files:**
- Modify: `app/Http/Controllers/IdeaController.php` (method `showResearch`)
- Modify: `resources/views/idea/research_show.blade.php`
- Test: `tests/Feature/ResearchCommentsPageTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\Comment;
use App\Models\Thought;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResearchCommentsPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_research_page_renders_page_level_comments_and_form(): void
    {
        $user = User::factory()->create();
        $root = Thought::factory()->create([
            'user_id' => $user->id,
            'parent_id' => null,
            'content' => '# Doc',
            'metadata' => ['type' => 'research'],
        ]);
        Comment::create([
            'commentable_type' => 'thought',
            'commentable_id' => $root->id,
            'author_user_id' => $user->id,
            'content' => 'my comment body',
            'format' => 'markdown',
        ]);

        $response = $this->actingAs($user)->get(route('idea.research.show', $root));

        $response->assertStatus(200);
        $response->assertSee('my comment body', false);
        $response->assertSee('name="content"', false);
        $response->assertSee(route('comments.store'), false);
    }

    public function test_opening_research_page_marks_comments_read(): void
    {
        $user = User::factory()->create();
        $root = Thought::factory()->create([
            'user_id' => $user->id,
            'parent_id' => null,
            'metadata' => ['type' => 'research'],
        ]);

        $this->actingAs($user)->get(route('idea.research.show', $root))->assertOk();

        $this->assertDatabaseHas('thought_comment_reads', [
            'user_id' => $user->id,
            'thought_id' => $root->id,
        ]);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter ResearchCommentsPageTest`
Expected: FAIL — page renders 200 but "my comment body" not present.

- [ ] **Step 3: Modify `showResearch` to pass the presenter and mark read**

Edit `app/Http/Controllers/IdeaController.php` around line 1341 (method `showResearch`). Just before `return view('idea.research_show', [...])`:

```php
$commentsPresenter = new \App\View\Presenters\Comments\ResearchCommentsPresenter(
    $thought, auth()->user(), null
);
\App\Models\ThoughtCommentRead::markRead((int) auth()->id(), $thought->id);
```

Add to the view payload:

```php
'commentsPresenter' => $commentsPresenter,
```

- [ ] **Step 4: Update the Blade view**

Modify `resources/views/idea/research_show.blade.php` — after the closing `</div>` of the main article card (line 43 `</div>` closing `rounded-2xl`), but before the outer wrapper's `</div>`, insert:

```blade
    <div class="mt-8">
        @include('comments._thread', [
            'rows' => $commentsPresenter->pageLevelRows(),
            'formAction' => route('comments.store'),
            'commentableType' => 'thought',
            'commentableId' => $root->id,
            'mode' => 'owner',
            'disabledMessage' => $commentsPresenter->canCommentOnPage() ? null : 'Comments are disabled.',
            'title' => 'Comments',
            'showControls' => true,
        ])
    </div>
```

- [ ] **Step 5: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter ResearchCommentsPageTest`
Expected: PASS (2 tests).

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/IdeaController.php resources/views/idea/research_show.blade.php tests/Feature/ResearchCommentsPageTest.php
git commit -m "feat(comments): render page-level comments on research detail"
```

---

## Task 9: Per-section side-rail (wide) + inline disclosure (narrow)

**Files:**
- Modify: `resources/views/idea/partials/research_content.blade.php`
- Test: `tests/Feature/ResearchSectionCommentsTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\Comment;
use App\Models\Thought;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResearchSectionCommentsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_section_comments_render_next_to_their_section(): void
    {
        $user = User::factory()->create();
        $root = Thought::factory()->create([
            'user_id' => $user->id,
            'parent_id' => null,
            'metadata' => ['type' => 'research'],
        ]);
        $section = Thought::factory()->create([
            'user_id' => $user->id,
            'parent_id' => $root->id,
            'content' => 'Section body',
            'source_metadata' => ['section_index' => 1],
        ]);
        Comment::create([
            'commentable_type' => 'thought',
            'commentable_id' => $section->id,
            'author_user_id' => $user->id,
            'content' => 'section-level-body',
            'format' => 'markdown',
        ]);

        $response = $this->actingAs($user)->get(route('idea.research.show', $root));

        $response->assertOk();
        $response->assertSee('Section body', false);
        $response->assertSee('section-level-body', false);
        // Side-rail container rendered on wide screens; section anchor exposed via id attribute
        $response->assertSee('id="section-'.$section->id.'"', false);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter ResearchSectionCommentsTest`
Expected: FAIL — "section-level-body" not present.

- [ ] **Step 3: Update `research_content.blade.php`**

Replace the contents of `resources/views/idea/partials/research_content.blade.php` with:

```blade
{{--
  Expects:
    $root_html, $sections (Collection of sections with ->content_html and source row),
    $commentsPresenter (ResearchCommentsPresenter)
--}}
<div class="lg:grid lg:grid-cols-[minmax(0,1fr)_320px] lg:gap-10">
    <div>
        <div class="prose prose-sm prose-slate max-w-none prose-headings:text-deep-indigo prose-headings:font-semibold prose-headings:tracking-tight prose-p:text-deep-indigo prose-p:leading-relaxed prose-li:text-slate-brand prose-strong:text-deep-indigo prose-pre:bg-slate-100/90 prose-pre:border prose-pre:border-memory-violet/10 prose-pre:rounded-lg prose-pre:py-3 prose-pre:px-4 prose-code:text-deep-indigo prose-code:bg-slate-100/90 prose-code:px-1.5 prose-code:py-0.5 prose-code:rounded prose-code:text-[12px] prose-a:text-memory-violet prose-a:no-underline hover:prose-a:underline prose-blockquote:border-memory-violet/30 prose-blockquote:bg-memory-violet/5 prose-blockquote:py-1 prose-blockquote:px-4 prose-blockquote:rounded-r-lg text-[14px] md:text-[15px]">
            {!! $root_html !!}
        </div>
        @stack('research-after-root')
        @if($sections->isNotEmpty())
            <ul class="mt-8 space-y-8 border-t border-memory-violet/10 pt-8 list-none pl-0">
                @foreach($sections as $section)
                    <li id="section-{{ $section->id }}">
                        <div class="prose prose-sm prose-slate max-w-none text-[13px] md:text-[14px]">
                            {!! $section->content_html !!}
                        </div>
                        {{-- Narrow-screen inline disclosure --}}
                        <details class="mt-3 lg:hidden">
                            <summary class="cursor-pointer text-[11px] font-semibold uppercase tracking-wider text-memory-violet/80">
                                {{ count($commentsPresenter->sectionRowsFor($section)) }} comments
                            </summary>
                            <div class="mt-3">
                                @include('comments._thread', [
                                    'rows' => $commentsPresenter->sectionRowsFor($section),
                                    'formAction' => route('comments.store'),
                                    'commentableType' => 'thought',
                                    'commentableId' => $section->id,
                                    'mode' => 'owner',
                                    'disabledMessage' => $commentsPresenter->canCommentOnSection($section) ? null : 'Comments are disabled.',
                                    'title' => 'Section comments',
                                    'showControls' => true,
                                ])
                            </div>
                        </details>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
    <aside class="hidden lg:block">
        @if($sections->isNotEmpty())
            <div class="space-y-6 sticky top-6">
                @foreach($sections as $section)
                    <div data-section-anchor="{{ $section->id }}">
                        <p class="text-[11px] font-semibold uppercase tracking-wider text-memory-violet/80 mb-2">
                            Section {{ $loop->iteration }}
                        </p>
                        @include('comments._thread', [
                            'rows' => $commentsPresenter->sectionRowsFor($section),
                            'formAction' => route('comments.store'),
                            'commentableType' => 'thought',
                            'commentableId' => $section->id,
                            'mode' => 'owner',
                            'disabledMessage' => $commentsPresenter->canCommentOnSection($section) ? null : 'Comments are disabled.',
                            'title' => 'Comments',
                            'showControls' => true,
                        ])
                    </div>
                @endforeach
            </div>
        @endif
    </aside>
</div>
```

- [ ] **Step 4: Pass sections as raw model collection to the partial**

The existing controller builds `$sectionsWithHtml` (a mapped stdClass collection without section IDs). Update `IdeaController::showResearch` so the section view model includes `id`:

Replace the mapping:

```php
$sectionsWithHtml = $sections->map(function (Thought $section) use ($converter) {
    return (object) [
        'id' => $section->id,
        'content_html' => $this->renderDemoSafeMarkdown(
            $converter,
            $section->content,
            'research_show_section'
        ),
    ];
});
```

- [ ] **Step 5: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter ResearchSectionCommentsTest`
Expected: PASS.

- [ ] **Step 6: Regression check**

Run: `vendor/bin/phpunit --filter ResearchShowTest`
Expected: PASS (existing tests still green).

- [ ] **Step 7: Commit**

```bash
git add resources/views/idea/partials/research_content.blade.php \
        app/Http/Controllers/IdeaController.php \
        tests/Feature/ResearchSectionCommentsTest.php
git commit -m "feat(comments): section-level side-rail with narrow-screen fallback"
```

---

## Task 10: Guest comments on `/r/{token}` (controller + rate limiter)

**Files:**
- Create: `app/Http/Controllers/SharedResearchCommentController.php`
- Modify: `app/Providers/AppServiceProvider.php` (register rate limiter)
- Modify: `routes/web.php`
- Test: `tests/Feature/SharedResearchCommentTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\ResearchShare;
use App\Models\Thought;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SharedResearchCommentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_guest_can_post_comment_when_share_allows(): void
    {
        $owner = User::factory()->create();
        $root = Thought::factory()->create([
            'user_id' => $owner->id,
            'parent_id' => null,
            'metadata' => ['type' => 'research'],
        ]);
        $share = ResearchShare::create([
            'user_id' => $owner->id,
            'thought_id' => $root->id,
            'token' => Str::random(32),
            'password_hash' => null,
            'allow_comments' => true,
        ]);

        $response = $this->post(route('shared-research.comment', $share->token), [
            'commentable_id' => $root->id,
            'author_name' => 'Jane',
            'content' => 'Nice research',
            'website_url' => '',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('comments', [
            'commentable_id' => $root->id,
            'author_user_id' => null,
            'author_name' => 'Jane',
            'format' => 'plain',
        ]);
    }

    public function test_guest_cannot_post_when_allow_comments_false(): void
    {
        $owner = User::factory()->create();
        $root = Thought::factory()->create(['user_id' => $owner->id]);
        $share = ResearchShare::create([
            'user_id' => $owner->id,
            'thought_id' => $root->id,
            'token' => Str::random(32),
            'password_hash' => null,
            'allow_comments' => false,
        ]);

        $response = $this->post(route('shared-research.comment', $share->token), [
            'commentable_id' => $root->id,
            'author_name' => 'Jane',
            'content' => 'hi',
            'website_url' => '',
        ]);

        $response->assertForbidden();
        $this->assertDatabaseCount('comments', 0);
    }

    public function test_honeypot_drops_submission_silently(): void
    {
        $owner = User::factory()->create();
        $root = Thought::factory()->create(['user_id' => $owner->id]);
        $share = ResearchShare::create([
            'user_id' => $owner->id,
            'thought_id' => $root->id,
            'token' => Str::random(32),
            'password_hash' => null,
            'allow_comments' => true,
        ]);

        $response = $this->post(route('shared-research.comment', $share->token), [
            'commentable_id' => $root->id,
            'author_name' => 'Bot',
            'content' => 'spam',
            'website_url' => 'http://evil.example',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseCount('comments', 0);
    }

    public function test_commentable_must_belong_to_share(): void
    {
        $owner = User::factory()->create();
        $root = Thought::factory()->create(['user_id' => $owner->id]);
        $otherRoot = Thought::factory()->create(['user_id' => $owner->id]);
        $share = ResearchShare::create([
            'user_id' => $owner->id,
            'thought_id' => $root->id,
            'token' => Str::random(32),
            'password_hash' => null,
            'allow_comments' => true,
        ]);

        $response = $this->post(route('shared-research.comment', $share->token), [
            'commentable_id' => $otherRoot->id,
            'author_name' => 'Jane',
            'content' => 'hi',
            'website_url' => '',
        ]);

        $response->assertStatus(422);
    }

    public function test_content_exceeding_2000_chars_returns_422(): void
    {
        $owner = User::factory()->create();
        $root = Thought::factory()->create(['user_id' => $owner->id]);
        $share = ResearchShare::create([
            'user_id' => $owner->id,
            'thought_id' => $root->id,
            'token' => Str::random(32),
            'password_hash' => null,
            'allow_comments' => true,
        ]);

        $response = $this->post(route('shared-research.comment', $share->token), [
            'commentable_id' => $root->id,
            'author_name' => 'Jane',
            'content' => str_repeat('a', 2001),
            'website_url' => '',
        ]);

        $response->assertStatus(422);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter SharedResearchCommentTest`
Expected: FAIL — route not defined.

- [ ] **Step 3: Register rate limiter**

Modify `app/Providers/AppServiceProvider.php::boot()` — add after the existing `shared-research-password` limiter block:

```php
RateLimiter::for('shared-research-comment', function (Request $request) {
    return [
        Limit::perMinute(5)->by($request->ip()),
        Limit::perHour(30)->by($request->ip()),
    ];
});
```

- [ ] **Step 4: Implement the controller**

```php
<?php

namespace App\Http\Controllers;

use App\Models\Comment;
use App\Models\ResearchShare;
use App\Models\Thought;
use App\Support\Comments\ShareContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;

class SharedResearchCommentController extends Controller
{
    public function store(Request $request, string $token): RedirectResponse
    {
        $share = ResearchShare::where('token', $token)->firstOrFail();

        abort_unless($share->allow_comments, 403, 'Comments are disabled on this share.');

        if ($request->filled('website_url')) {
            return Redirect::to('/r/'.$token.'#comment');
        }

        $validated = $request->validate([
            'commentable_id' => ['required', 'string', 'max:36'],
            'author_name' => [
                'required', 'string', 'max:100',
                function ($attr, $value, $fail) {
                    if (preg_match('/https?:\/\//i', $value)) {
                        $fail('Name cannot contain URLs.');
                    }
                    if (preg_match('/[\p{Cc}]/u', $value)) {
                        $fail('Name contains invalid characters.');
                    }
                },
            ],
            'content' => ['required', 'string', 'max:2000'],
        ]);

        $target = Thought::find($validated['commentable_id']);

        abort_unless($target !== null, 422);
        abort_unless(
            $target->id === $share->thought_id || $target->parent_id === $share->thought_id,
            422
        );

        $context = new ShareContext(
            researchThoughtId: $share->thought_id,
            shareId: $share->id,
            allowComments: (bool) $share->allow_comments,
        );

        abort_unless($target->authorizeCommentCreation(null, $context), 403);

        Comment::create([
            'commentable_type' => 'thought',
            'commentable_id' => $target->id,
            'author_user_id' => null,
            'author_name' => trim($validated['author_name']),
            'content' => $validated['content'],
            'format' => 'plain',
            'ip_hash' => hash('sha256', $request->ip().'|'.config('app.key')),
        ]);

        return Redirect::to('/r/'.$token.'#comments');
    }
}
```

- [ ] **Step 5: Add the route**

Modify `routes/web.php` — near the existing `/r/{token}` route (around line 61), add:

```php
use App\Http\Controllers\SharedResearchCommentController;

Route::post('/r/{token}/comments', [SharedResearchCommentController::class, 'store'])
    ->middleware('throttle:shared-research-comment')
    ->name('shared-research.comment');
```

- [ ] **Step 6: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter SharedResearchCommentTest`
Expected: PASS (5 tests).

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/SharedResearchCommentController.php \
        app/Providers/AppServiceProvider.php \
        routes/web.php \
        tests/Feature/SharedResearchCommentTest.php
git commit -m "feat(comments): add guest comment endpoint on /r/{token}"
```

---

## Task 11: Render comments on `/r/{token}` readonly view

**Files:**
- Modify: `app/Http/Controllers/SharedResearchViewController.php`
- Modify: `resources/views/shared_research/readonly.blade.php`
- Test: `tests/Feature/SharedResearchReadonlyCommentsTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\Comment;
use App\Models\ResearchShare;
use App\Models\Thought;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SharedResearchReadonlyCommentsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_shared_view_shows_existing_comments_and_form_when_allowed(): void
    {
        $owner = User::factory()->create();
        $root = Thought::factory()->create([
            'user_id' => $owner->id,
            'parent_id' => null,
            'content' => '# Doc',
            'metadata' => ['type' => 'research'],
        ]);
        $share = ResearchShare::create([
            'user_id' => $owner->id,
            'thought_id' => $root->id,
            'token' => Str::random(32),
            'password_hash' => null,
            'allow_comments' => true,
        ]);
        Comment::create([
            'commentable_type' => 'thought',
            'commentable_id' => $root->id,
            'author_user_id' => null,
            'author_name' => 'Ada',
            'content' => 'public-reply',
            'format' => 'plain',
        ]);

        $response = $this->get(route('shared-research.show', $share->token));
        $response->assertOk();
        $response->assertSee('public-reply', false);
        $response->assertSee('Ada', false);
        $response->assertSee('name="author_name"', false);
    }

    public function test_shared_view_hides_form_when_disabled(): void
    {
        $owner = User::factory()->create();
        $root = Thought::factory()->create([
            'user_id' => $owner->id,
            'parent_id' => null,
            'metadata' => ['type' => 'research'],
        ]);
        $share = ResearchShare::create([
            'user_id' => $owner->id,
            'thought_id' => $root->id,
            'token' => Str::random(32),
            'password_hash' => null,
            'allow_comments' => false,
        ]);

        $response = $this->get(route('shared-research.show', $share->token));
        $response->assertOk();
        $response->assertDontSee('name="author_name"', false);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter SharedResearchReadonlyCommentsTest`
Expected: FAIL — "public-reply" not seen.

- [ ] **Step 3: Update `SharedResearchViewController::renderReadonly`**

Modify `app/Http/Controllers/SharedResearchViewController.php` — inside `renderReadonly`, just before the `return view(...)`:

```php
$shareContext = new \App\Support\Comments\ShareContext(
    researchThoughtId: $thought->id,
    shareId: $share->id,
    allowComments: (bool) $share->allow_comments,
);
$commentsPresenter = new \App\View\Presenters\Comments\ResearchCommentsPresenter(
    $thought, null, $shareContext
);
```

Add to the view payload:

```php
'commentsPresenter' => $commentsPresenter,
'share' => $share,
```

- [ ] **Step 4: Update `resources/views/shared_research/readonly.blade.php`**

After the existing research body renders, append a section similar to the authed view:

```blade
<div class="mt-8 max-w-4xl mx-auto">
    @include('comments._thread', [
        'rows' => $commentsPresenter->pageLevelRows(),
        'formAction' => route('shared-research.comment', $share->token),
        'commentableId' => $root->id,
        'mode' => 'guest',
        'disabledMessage' => $commentsPresenter->allowGuestComments() ? null : 'Comments are disabled on this share.',
        'title' => 'Comments',
        'showControls' => false,
    ])
</div>
```

If the readonly view already iterates sections, wrap each section in an anchor div (`id="section-{{ $section->id }}"`) and include a narrow-only `<details>` disclosure for per-section threads mirroring Task 9. If the readonly view does not render sections in a per-row structure, add only the doc-level thread for v1 and note it as a post-MVP polish.

- [ ] **Step 5: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter SharedResearchReadonlyCommentsTest`
Expected: PASS (2 tests).

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/SharedResearchViewController.php \
        resources/views/shared_research/readonly.blade.php \
        tests/Feature/SharedResearchReadonlyCommentsTest.php
git commit -m "feat(comments): render comments on /r/{token} readonly view"
```

---

## Task 12: Backfill existing thought-replies into `comments`

**Files:**
- Create: `database/migrations/2026_04_20_000004_backfill_thought_replies_into_comments.php`
- Modify: `app/Models/Thought.php` (global scope)
- Test: `tests/Feature/ThoughtRepliesBackfillTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\Comment;
use App\Models\Thought;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class ThoughtRepliesBackfillTest extends TestCase
{
    use RefreshDatabase;

    public function test_reply_shaped_children_backfilled_into_comments_and_hidden_from_queries(): void
    {
        $user = User::factory()->create();
        $parent = Thought::factory()->create([
            'user_id' => $user->id,
            'parent_id' => null,
            'content' => 'root',
        ]);
        $reply = Thought::factory()->create([
            'user_id' => $user->id,
            'parent_id' => $parent->id,
            'content' => 'a plain reply',
            'source_metadata' => null,
        ]);
        $section = Thought::factory()->create([
            'user_id' => $user->id,
            'parent_id' => $parent->id,
            'content' => '## Section body',
            'source_metadata' => ['section_index' => 1],
        ]);

        Artisan::call('comments:backfill-thought-replies');

        $this->assertDatabaseHas('comments', [
            'commentable_type' => 'thought',
            'commentable_id' => $parent->id,
            'author_user_id' => $user->id,
            'content' => 'a plain reply',
            'format' => 'markdown',
            'import_source' => 'thought_reply_backfill',
        ]);

        $refreshedReply = Thought::withoutGlobalScope('non_migrated')->find($reply->id);
        $this->assertSame(true, data_get($refreshedReply->metadata, 'migrated_to_comment'));

        // Default scope hides the migrated reply from normal queries.
        $this->assertNull(Thought::find($reply->id));
        // Section is NOT migrated.
        $this->assertNotNull(Thought::find($section->id));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter ThoughtRepliesBackfillTest`
Expected: FAIL — command not defined.

- [ ] **Step 3: Create the backfill migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            INSERT INTO comments (
                commentable_type, commentable_id, author_user_id, author_name,
                content, format, ip_hash, import_source, created_at, updated_at
            )
            SELECT 'thought', t.parent_id, t.user_id, NULL,
                t.content, 'markdown', NULL, 'thought_reply_backfill',
                t.created_at, t.updated_at
            FROM thoughts t
            WHERE t.parent_id IS NOT NULL
              AND (t.source_metadata IS NULL OR t.source_metadata->>'section_index' IS NULL)
              AND (t.metadata IS NULL OR t.metadata->>'video_section_type' IS NULL)
              AND (t.metadata IS NULL OR t.metadata->>'migrated_to_comment' IS DISTINCT FROM 'true')
        SQL);

        DB::statement(<<<'SQL'
            UPDATE thoughts
            SET metadata = COALESCE(metadata, '{}'::jsonb) || '{"migrated_to_comment": true}'::jsonb
            WHERE parent_id IS NOT NULL
              AND (source_metadata IS NULL OR source_metadata->>'section_index' IS NULL)
              AND (metadata IS NULL OR metadata->>'video_section_type' IS NULL)
              AND (metadata IS NULL OR metadata->>'migrated_to_comment' IS DISTINCT FROM 'true')
        SQL);
    }

    public function down(): void
    {
        DB::table('comments')->where('import_source', 'thought_reply_backfill')->delete();
        DB::statement(<<<'SQL'
            UPDATE thoughts
            SET metadata = metadata - 'migrated_to_comment'
            WHERE metadata->>'migrated_to_comment' = 'true'
        SQL);
    }
};
```

- [ ] **Step 4: Add an Artisan command that re-runs the backfill idempotently**

Create `app/Console/Commands/BackfillThoughtRepliesCommand.php`:

```php
<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillThoughtRepliesCommand extends Command
{
    protected $signature = 'comments:backfill-thought-replies';
    protected $description = 'Copy existing reply-shaped child thoughts into the comments table.';

    public function handle(): int
    {
        DB::statement(<<<'SQL'
            INSERT INTO comments (
                commentable_type, commentable_id, author_user_id, author_name,
                content, format, ip_hash, import_source, created_at, updated_at
            )
            SELECT 'thought', t.parent_id, t.user_id, NULL,
                t.content, 'markdown', NULL, 'thought_reply_backfill',
                t.created_at, t.updated_at
            FROM thoughts t
            WHERE t.parent_id IS NOT NULL
              AND (t.source_metadata IS NULL OR t.source_metadata->>'section_index' IS NULL)
              AND (t.metadata IS NULL OR t.metadata->>'video_section_type' IS NULL)
              AND (t.metadata IS NULL OR t.metadata->>'migrated_to_comment' IS DISTINCT FROM 'true')
        SQL);

        DB::statement(<<<'SQL'
            UPDATE thoughts
            SET metadata = COALESCE(metadata, '{}'::jsonb) || '{"migrated_to_comment": true}'::jsonb
            WHERE parent_id IS NOT NULL
              AND (source_metadata IS NULL OR source_metadata->>'section_index' IS NULL)
              AND (metadata IS NULL OR metadata->>'video_section_type' IS NULL)
              AND (metadata IS NULL OR metadata->>'migrated_to_comment' IS DISTINCT FROM 'true')
        SQL);

        $this->info('Backfill complete.');
        return self::SUCCESS;
    }
}
```

- [ ] **Step 5: Add a global scope hiding migrated thoughts**

Modify `app/Models/Thought.php` — extend `boot()` to register a global scope:

```php
static::addGlobalScope('non_migrated', function (\Illuminate\Database\Eloquent\Builder $q) {
    $q->where(function ($inner) {
        $inner->whereNull('metadata')
            ->orWhereRaw("metadata->>'migrated_to_comment' IS DISTINCT FROM 'true'");
    });
});
```

- [ ] **Step 6: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter ThoughtRepliesBackfillTest`
Expected: PASS.

- [ ] **Step 7: Regression sweep**

Run: `vendor/bin/phpunit`
Expected: All tests pass. If any legitimate query now needs migrated rows, use `Thought::withoutGlobalScope('non_migrated')` at the call site. Fix any failures.

- [ ] **Step 8: Commit**

```bash
git add database/migrations/2026_04_20_000004_backfill_thought_replies_into_comments.php \
        app/Console/Commands/BackfillThoughtRepliesCommand.php \
        app/Models/Thought.php \
        tests/Feature/ThoughtRepliesBackfillTest.php
git commit -m "feat(comments): backfill existing thought replies and hide migrated rows"
```

---

## Task 13: Replace `thought_detail_replies` partial with `comments._thread`

**Files:**
- Modify: `resources/views/idea/show.blade.php`
- Modify: `app/Http/Controllers/IdeaController.php` (pass presenter into thought detail)
- Test: `tests/Feature/ThoughtDetailCommentsTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\Comment;
use App\Models\Thought;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ThoughtDetailCommentsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_thought_detail_renders_comments_from_new_system(): void
    {
        $user = User::factory()->create();
        $thought = Thought::factory()->create([
            'user_id' => $user->id,
            'parent_id' => null,
            'content' => 'root',
        ]);
        Comment::create([
            'commentable_type' => 'thought',
            'commentable_id' => $thought->id,
            'author_user_id' => $user->id,
            'content' => 'new-system-comment',
            'format' => 'markdown',
        ]);

        $response = $this->actingAs($user)->get(route('thoughts.show', $thought));
        $response->assertOk();
        $response->assertSee('new-system-comment', false);
        $response->assertSee(route('comments.store'), false);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter ThoughtDetailCommentsTest`
Expected: FAIL — the old reply partial is still in place and doesn't post to `comments.store`.

- [ ] **Step 3: Build the presenter call for thought detail**

Modify `app/Http/Controllers/IdeaController.php`, inside the method that renders the thought detail view (the one that includes `thought_detail_replies`), before `return view(...)`:

```php
$detailCommentsPresenter = new \App\View\Presenters\Comments\ResearchCommentsPresenter(
    $thought, auth()->user(), null
);
```

Add `'detailCommentsPresenter' => $detailCommentsPresenter` to the view payload.

- [ ] **Step 4: Replace the partial include**

Modify `resources/views/idea/show.blade.php` — replace the `@include('idea.partials.thought_detail_replies', ...)` line with:

```blade
@include('comments._thread', [
    'rows' => $detailCommentsPresenter->pageLevelRows(),
    'formAction' => route('comments.store'),
    'commentableType' => 'thought',
    'commentableId' => $thought->id,
    'mode' => 'owner',
    'disabledMessage' => $detailCommentsPresenter->canCommentOnPage() ? null : 'Comments are disabled.',
    'title' => 'Comments',
    'showControls' => true,
])
```

- [ ] **Step 5: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter ThoughtDetailCommentsTest`
Expected: PASS.

- [ ] **Step 6: Run the full test suite**

Run: `vendor/bin/phpunit`
Expected: all tests pass. The old `thought_detail_replies.blade.php` file and `ThoughtDetailPresenter::replyRows()` are no longer exercised.

- [ ] **Step 7: Delete the old partial and method**

Delete `resources/views/idea/partials/thought_detail_replies.blade.php`.

Remove `replyRows()` from `app/View/Presenters/Thoughts/ThoughtDetailPresenter.php` (and the `isStructuredDocumentSection` helper if now unused — verify with a grep first).

- [ ] **Step 8: Re-run full suite**

Run: `vendor/bin/phpunit`
Expected: still green.

- [ ] **Step 9: Commit**

```bash
git add resources/views/idea/show.blade.php \
        app/Http/Controllers/IdeaController.php \
        app/View/Presenters/Thoughts/ThoughtDetailPresenter.php \
        tests/Feature/ThoughtDetailCommentsTest.php
git rm resources/views/idea/partials/thought_detail_replies.blade.php
git commit -m "refactor(comments): replace thought_detail_replies with generic comments thread"
```

---

## Task 14: Unread count exposed to Stream + research page

**Files:**
- Modify: `resources/views/idea/research_show.blade.php`
- Test: `tests/Feature/UnreadCommentIndicatorTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\Comment;
use App\Models\Thought;
use App\Models\ThoughtCommentRead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UnreadCommentIndicatorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_unread_count_renders_until_page_visited(): void
    {
        $owner = User::factory()->create();
        $root = Thought::factory()->create([
            'user_id' => $owner->id,
            'parent_id' => null,
            'metadata' => ['type' => 'research'],
        ]);
        Comment::create([
            'commentable_type' => 'thought',
            'commentable_id' => $root->id,
            'author_user_id' => null,
            'author_name' => 'Guest',
            'content' => 'hi',
            'format' => 'plain',
        ]);
        ThoughtCommentRead::markRead($owner->id, $root->id);
        // New comment arrives after read
        $this->travel(1)->minutes();
        Comment::create([
            'commentable_type' => 'thought',
            'commentable_id' => $root->id,
            'author_user_id' => null,
            'author_name' => 'Guest',
            'content' => 'new',
            'format' => 'plain',
        ]);

        $response = $this->actingAs($owner)->get(route('idea.research.show', $root));
        $response->assertOk();
        // First visit after the new comment should show count 1 in the page
        $response->assertSeeInOrder(['Comments', '1'], false);

        // After visiting, open again — unread count zero.
        $response2 = $this->actingAs($owner)->get(route('idea.research.show', $root));
        $response2->assertOk();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter UnreadCommentIndicatorTest`
Expected: FAIL — unread count not rendered yet.

- [ ] **Step 3: Render the count in the research view**

Modify `resources/views/idea/research_show.blade.php` — in the outer card near the top, render the badge when `$commentsPresenter->unreadCount() > 0`:

```blade
@if($commentsPresenter->unreadCount() > 0)
    <p class="mb-4 text-[12px] font-semibold text-memory-violet">
        {{ $commentsPresenter->unreadCount() }} new comment(s) since your last visit.
    </p>
@endif
```

Note: the `markRead` call in the controller runs at the end of the request, so the presenter's `unreadCount()` — constructed **before** that call — already reflects the unread number on first load. No additional plumbing is needed for the test to pass. If you relocate `markRead`, ensure the presenter is instantiated first.

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter UnreadCommentIndicatorTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add resources/views/idea/research_show.blade.php tests/Feature/UnreadCommentIndicatorTest.php
git commit -m "feat(comments): surface unread count on research detail"
```

---

## Task 15: Final regression pass + manual smoke notes

**Files:** None (verification only).

- [ ] **Step 1: Run the full suite**

Run: `vendor/bin/phpunit`
Expected: all tests pass.

- [ ] **Step 2: Boot the app and smoke-test**

Run: `php artisan migrate:fresh --seed && php artisan serve` in one terminal, `npm run dev` in another.

Manual checks (tick each):
- [ ] `/research/{id}` renders owner page-level comments + form.
- [ ] Owner posts a markdown comment; it renders as rendered HTML below the article.
- [ ] Owner posts a per-section comment via the right-hand side-rail on a wide screen.
- [ ] Shrink the window below `lg` breakpoint — side-rail vanishes, inline disclosure appears.
- [ ] Create a research share with `allow_comments=true`; open `/r/{token}` incognito; post as guest with a name; refresh sees the guest comment.
- [ ] Flip the share to `allow_comments=false`; form disappears on `/r/{token}`; POST rejected with 403.
- [ ] Try submitting with a filled `website_url` hidden field (via curl) — receive 302 and no row inserted.
- [ ] Burst 6 guest comments in < 60s — 6th returns 429.

- [ ] **Step 3: Commit any manual fixes, or close out**

```bash
git log --oneline -20
```

---

## Self-review summary

- **Spec coverage:** Tasks 1–5 build the data layer; 6 builds the presenter; 7 builds shared partials; 8 wires page-level rendering; 9 wires section-level rendering; 10–11 handle the public share; 12 migrates legacy data; 13 retires the legacy reply UI; 14 adds the unread indicator; 15 verifies.
- **Open questions deferred to implementation (from the spec):** unread-in-Stream-card presentation, section re-run orphaning, "(edited)" label threshold are all handled inline in the presenter (`updated_label`) and on the research view — no separate tasks needed.
- **Types/signatures:** `ShareContext`, `ResearchCommentsPresenter`, and `Commentable::authorizeCommentCreation(?User, ?ShareContext)` use identical signatures across tasks.
