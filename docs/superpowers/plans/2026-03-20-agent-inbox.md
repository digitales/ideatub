# Agent Inbox Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a web-first agent inbox in IdeaTub that stores generated inbox items, lets the user mark them done, snooze them, or save them as thoughts, and generates the first scheduled/rule-based items on a recurring command.

**Architecture:** Add dedicated `inbox_items` and `inbox_item_actions` tables plus focused Eloquent models, a small generator framework, one scheduled command, and a simple authenticated Blade inbox page. Reuse existing patterns already present in the repo: policies for ownership, controller-driven web routes, `ThoughtCaptureService` for thought creation, `UserPreference` for lightweight config if needed later, and `routes/console.php` for recurring commands.

**Tech Stack:** Laravel 12, Blade, Tailwind, Eloquent, Artisan scheduler/commands, Laravel feature/unit tests, SQLite in tests and PostgreSQL in app environments.

**Spec:** `docs/superpowers/specs/2026-03-20-inbox-agent-design.md`

---

## File structure

| File | Responsibility |
|------|----------------|
| `database/migrations/2026_03_20_000001_create_inbox_tables.php` | Create `inbox_items` and `inbox_item_actions`, indexes, and active dedupe uniqueness |
| `app/Models/InboxItem.php` | Inbox item model, casts, relationships, actionable/owned scopes |
| `app/Models/InboxItemAction.php` | Action log model and relationships |
| `database/factories/InboxItemFactory.php` | Test factory for inbox items |
| `app/Models/User.php` | Add `inboxItems()` relationship |
| `app/Policies/InboxItemPolicy.php` | Owner-only view/update policy for inbox actions |
| `app/Http/Controllers/InboxController.php` | Inbox page + `done`, `snooze`, `save as thought` POST handlers |
| `routes/web.php` | Add inbox routes inside auth middleware |
| `resources/views/inbox/index.blade.php` | Inbox list UI with simple action forms and empty state |
| `resources/views/layouts/idea.blade.php` | Add Inbox nav entry and badge |
| `app/Providers/AppServiceProvider.php` | Share actionable inbox count with `layouts.idea` |
| `config/inbox.php` | Ordered generator registration and per-run cap |
| `app/Services/Inbox/Contracts/InboxGenerator.php` | Contract for generators |
| `app/Services/Inbox/Generators/WeeklyRevisitInboxGenerator.php` | Scheduled generator built from existing revisit ideas logic |
| `app/Services/Inbox/Generators/NeglectedIdeaInboxGenerator.php` | Rule-based generator for older incomplete ideas |
| `app/Services/Inbox/InboxGenerationService.php` | Runs generators for users, applies cap, and inserts deduplicated items |
| `app/Services/Inbox/InboxActionService.php` | Encapsulates done/snooze/save-as-thought behavior and action log writes |
| `app/Console/Commands/GenerateInboxItemsCommand.php` | Manual/scheduled generation entry point |
| `routes/console.php` | Schedule `inbox:generate` |
| `tests/Unit/Models/InboxItemTest.php` | Model scopes and active/actionable semantics |
| `tests/Feature/InboxPageTest.php` | Auth, visibility, empty state, badge/link behavior |
| `tests/Feature/InboxActionsTest.php` | Done, snooze, save-as-thought, ownership, idempotency |
| `tests/Unit/Services/Inbox/WeeklyRevisitInboxGeneratorTest.php` | Weekly revisit generator output and dedupe key |
| `tests/Unit/Services/Inbox/NeglectedIdeaInboxGeneratorTest.php` | Neglected idea generator output and thresholds |
| `tests/Feature/GenerateInboxItemsCommandTest.php` | Command, dedupe, cap, and schedule-oriented behavior |

---

## Chunk 1: Persistence, page shell, and navigation

### Task 1: Add inbox schema, models, policy, and factory

**Files:**
- Create: `database/migrations/2026_03_20_000001_create_inbox_tables.php`
- Create: `app/Models/InboxItem.php`
- Create: `app/Models/InboxItemAction.php`
- Create: `database/factories/InboxItemFactory.php`
- Modify: `app/Models/User.php`
- Create: `app/Policies/InboxItemPolicy.php`
- Test: `tests/Unit/Models/InboxItemTest.php`

- [ ] **Step 1: Write the failing model test**

Create `tests/Unit/Models/InboxItemTest.php`:

```php
<?php

namespace Tests\Unit\Models;

use App\Models\InboxItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class InboxItemTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function actionable_scope_excludes_done_and_future_snoozed_items(): void
    {
        $user = User::factory()->create();

        $actionable = InboxItem::factory()->create([
            'user_id' => $user->id,
            'status' => 'pending',
            'snoozed_until' => null,
        ]);

        InboxItem::factory()->create([
            'user_id' => $user->id,
            'status' => 'done',
        ]);

        InboxItem::factory()->create([
            'user_id' => $user->id,
            'status' => 'pending',
            'snoozed_until' => now()->addDay(),
        ]);

        $results = InboxItem::query()->forUser($user)->actionable()->get();

        $this->assertCount(1, $results);
        $this->assertSame($actionable->id, $results->first()->id);
    }

    #[Test]
    public function active_scope_keeps_future_snoozed_pending_items(): void
    {
        $user = User::factory()->create();

        InboxItem::factory()->create([
            'user_id' => $user->id,
            'status' => 'pending',
            'dedupe_key' => 'weekly-revisit',
            'snoozed_until' => now()->addWeek(),
        ]);

        $results = InboxItem::query()->forUser($user)->active()->get();

        $this->assertCount(1, $results);
        $this->assertSame('weekly-revisit', $results->first()->dedupe_key);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test tests/Unit/Models/InboxItemTest.php -v`

Expected: FAIL because `InboxItem` model / migration / factory do not exist yet.

- [ ] **Step 3: Add the migration**

Create `database/migrations/2026_03_20_000001_create_inbox_tables.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inbox_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('generator_type', 100);
            $table->string('title', 255);
            $table->text('body');
            $table->string('status', 20)->default('pending');
            $table->timestamp('snoozed_until')->nullable();
            $table->timestamp('generated_at');
            $table->timestamp('actioned_at')->nullable();
            $table->string('dedupe_key', 191);
            $table->json('source_data')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['user_id', 'snoozed_until']);
            $table->index(['user_id', 'generated_at']);
        });

        Schema::create('inbox_item_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inbox_item_id')->constrained()->cascadeOnDelete();
            $table->string('action_type', 50);
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        // Active dedupe: only one pending item per user + dedupe key.
        DB::statement("CREATE UNIQUE INDEX inbox_items_user_dedupe_pending_unique ON inbox_items (user_id, dedupe_key) WHERE status = 'pending'");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS inbox_items_user_dedupe_pending_unique');
        Schema::dropIfExists('inbox_item_actions');
        Schema::dropIfExists('inbox_items');
    }
};
```

- [ ] **Step 4: Add the models and factory**

Create `app/Models/InboxItem.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InboxItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'generator_type',
        'title',
        'body',
        'status',
        'snoozed_until',
        'generated_at',
        'actioned_at',
        'dedupe_key',
        'source_data',
    ];

    protected function casts(): array
    {
        return [
            'snoozed_until' => 'datetime',
            'generated_at' => 'datetime',
            'actioned_at' => 'datetime',
            'source_data' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function actions(): HasMany
    {
        return $this->hasMany(InboxItemAction::class);
    }

    public function scopeForUser(Builder $query, User $user): Builder
    {
        return $query->where('user_id', $user->id);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'pending');
    }

    public function scopeActionable(Builder $query): Builder
    {
        return $query
            ->where('status', 'pending')
            ->where(function (Builder $q): void {
                $q->whereNull('snoozed_until')
                    ->orWhere('snoozed_until', '<=', now());
            });
    }
}
```

Create `app/Models/InboxItemAction.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InboxItemAction extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'inbox_item_id',
        'action_type',
        'metadata',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function inboxItem(): BelongsTo
    {
        return $this->belongsTo(InboxItem::class);
    }
}
```

Create `database/factories/InboxItemFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\InboxItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class InboxItemFactory extends Factory
{
    protected $model = InboxItem::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'generator_type' => 'weekly_revisit',
            'title' => 'Weekly revisit',
            'body' => 'Review a few older ideas this week.',
            'status' => 'pending',
            'snoozed_until' => null,
            'generated_at' => now(),
            'actioned_at' => null,
            'dedupe_key' => 'weekly-revisit-default',
            'source_data' => null,
        ];
    }
}
```

Modify `app/Models/User.php`:

```php
public function inboxItems()
{
    return $this->hasMany(InboxItem::class);
}
```

Create `app/Policies/InboxItemPolicy.php`:

```php
<?php

namespace App\Policies;

use App\Models\InboxItem;
use App\Models\User;

class InboxItemPolicy
{
    public function view(User $user, InboxItem $inboxItem): bool
    {
        return $inboxItem->user_id === $user->id;
    }

    public function update(User $user, InboxItem $inboxItem): bool
    {
        return $inboxItem->user_id === $user->id;
    }
}
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `php artisan test tests/Unit/Models/InboxItemTest.php -v`

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_03_20_000001_create_inbox_tables.php app/Models/InboxItem.php app/Models/InboxItemAction.php database/factories/InboxItemFactory.php app/Models/User.php app/Policies/InboxItemPolicy.php tests/Unit/Models/InboxItemTest.php
git commit -m "Add inbox item persistence and ownership model"
```

---

### Task 2: Add the inbox page shell, auth route, and nav badge

**Files:**
- Create: `app/Http/Controllers/InboxController.php`
- Modify: `routes/web.php`
- Create: `resources/views/inbox/index.blade.php`
- Modify: `resources/views/layouts/idea.blade.php`
- Modify: `app/Providers/AppServiceProvider.php`
- Test: `tests/Feature/InboxPageTest.php`

- [ ] **Step 1: Write the failing page test**

Create `tests/Feature/InboxPageTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\InboxItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InboxPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_inbox_requires_authentication(): void
    {
        $response = $this->get(route('inbox.index'));

        $response->assertRedirect(route('login'));
    }

    public function test_inbox_shows_empty_state_when_user_has_no_items(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('inbox.index'));

        $response->assertOk();
        $response->assertSee('No inbox items right now.');
    }

    public function test_inbox_shows_only_actionable_items_for_current_user(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        InboxItem::factory()->create([
            'user_id' => $user->id,
            'title' => 'Visible item',
            'dedupe_key' => 'visible-item',
            'status' => 'pending',
            'snoozed_until' => null,
        ]);

        InboxItem::factory()->create([
            'user_id' => $user->id,
            'title' => 'Future snoozed item',
            'dedupe_key' => 'future-snoozed-item',
            'status' => 'pending',
            'snoozed_until' => now()->addDay(),
        ]);

        InboxItem::factory()->create([
            'user_id' => $other->id,
            'title' => 'Other users item',
            'dedupe_key' => 'other-users-item',
        ]);

        $response = $this->actingAs($user)->get(route('inbox.index'));

        $response->assertOk();
        $response->assertSee('Inbox');
        $response->assertSee('Visible item');
        $response->assertDontSee('Future snoozed item');
        $response->assertDontSee('Other users item');
    }

    public function test_layout_shows_inbox_nav_link_and_badge_for_actionable_items(): void
    {
        $user = User::factory()->create();

        InboxItem::factory()->create([
            'user_id' => $user->id,
            'title' => 'One actionable item',
            'dedupe_key' => 'one-actionable-item',
            'status' => 'pending',
        ]);

        $response = $this->actingAs($user)->get(route('idea.ideas'));

        $response->assertOk();
        $response->assertSee(route('inbox.index'), false);
        $response->assertSee('data-testid="inbox-badge"', false);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test tests/Feature/InboxPageTest.php -v`

Expected: FAIL because the route, controller, view, and shared badge count do not exist yet.

- [ ] **Step 3: Add route and controller**

Modify `routes/web.php` inside the auth group:

```php
use App\Http\Controllers\InboxController;

Route::get('/inbox', [InboxController::class, 'index'])->name('inbox.index');
```

Create `app/Http/Controllers/InboxController.php` with just the page method for now:

```php
<?php

namespace App\Http\Controllers;

use App\Models\InboxItem;
use Illuminate\View\View;

class InboxController extends Controller
{
    public function index(): View
    {
        $items = InboxItem::query()
            ->forUser(auth()->user())
            ->actionable()
            ->orderByDesc('generated_at')
            ->paginate(20);

        return view('inbox.index', ['items' => $items]);
    }
}
```

- [ ] **Step 4: Add the inbox view**

Create `resources/views/inbox/index.blade.php`:

```blade
@extends('layouts.idea')

@section('title', 'Inbox — IdeaTub')

@section('content')
<div class="max-w-4xl mx-auto px-6 pt-16 pb-24">
    @if (session('success'))
        <div class="mb-6 rounded-xl bg-neural-teal/10 border border-neural-teal/25 px-4 py-3 text-sm text-neural-teal">
            {{ session('success') }}
        </div>
    @endif
    @if (session('error'))
        <div class="mb-6 rounded-xl bg-red-50 border border-red-200 px-4 py-3 text-sm text-red-600">
            {{ session('error') }}
        </div>
    @endif

    <div class="mb-8">
        <h1 class="text-[28px] font-semibold text-deep-indigo leading-snug">Inbox</h1>
        <p class="mt-2 text-sm text-slate-brand">Agent-generated prompts that need triage.</p>
    </div>

    @if ($items->isEmpty())
        <div class="rounded-2xl border border-memory-violet/20 bg-white/80 p-8 text-sm text-slate-brand shadow-[0_4px_24px_rgba(109,106,247,0.08)]">
            No inbox items right now.
        </div>
    @else
        <div class="space-y-4">
            @foreach ($items as $item)
                <article class="rounded-2xl border border-memory-violet/20 bg-white/90 p-5 shadow-[0_4px_24px_rgba(109,106,247,0.08)]">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <p class="text-[11px] font-semibold uppercase tracking-[0.1em] text-memory-violet/80">{{ str_replace('_', ' ', $item->generator_type) }}</p>
                            <h2 class="mt-1 text-lg font-semibold text-deep-indigo">{{ $item->title }}</h2>
                        </div>
                        <p class="text-xs text-slate-brand/60">{{ $item->generated_at?->diffForHumans() }}</p>
                    </div>

                    <p class="mt-3 whitespace-pre-line text-sm leading-6 text-slate-brand">{{ $item->body }}</p>

                    <p class="mt-4 text-[11px] text-slate-brand/50">Action buttons are added in Chunk 3.</p>
                </article>
            @endforeach
        </div>

        <div class="mt-8">
            {{ $items->links() }}
        </div>
    @endif
</div>
@endsection
```

- [ ] **Step 5: Add nav link and shared count**

In `app/Providers/AppServiceProvider.php`, add:

```php
use App\Models\InboxItem;
use Illuminate\Support\Facades\View;
```

Then in `boot()` add:

```php
View::composer('layouts.idea', function ($view): void {
    $count = 0;

    if (auth()->check()) {
        $count = InboxItem::query()
            ->forUser(auth()->user())
            ->actionable()
            ->count();
    }

    $view->with('inboxActionableCount', $count);
});
```

In `resources/views/layouts/idea.blade.php`, add the Inbox link next to Ideas / Stream:

```blade
<a href="{{ route('inbox.index') }}" class="text-[12.5px] font-medium text-slate-brand hover:text-memory-violet hover:bg-memory-violet/8 px-3 py-1.5 rounded-lg transition-colors inline-flex items-center gap-2">
    <span>Inbox</span>
    @if (($inboxActionableCount ?? 0) > 0)
        <span data-testid="inbox-badge" class="inline-flex min-w-[1.25rem] items-center justify-center rounded-full bg-memory-violet/15 px-1.5 py-0.5 text-[10px] font-semibold text-memory-violet">
            {{ $inboxActionableCount }}
        </span>
    @endif
</a>
```

- [ ] **Step 6: Run the test to verify it passes**

Run: `php artisan test tests/Feature/InboxPageTest.php -v`

Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/InboxController.php routes/web.php resources/views/inbox/index.blade.php resources/views/layouts/idea.blade.php app/Providers/AppServiceProvider.php tests/Feature/InboxPageTest.php
git commit -m "Add inbox page shell and navigation badge"
```

---

## Chunk 2: Generation pipeline and first generators

### Task 3: Add the generator contract and weekly revisit generator

**Files:**
- Create: `app/Services/Inbox/Contracts/InboxGenerator.php`
- Create: `app/Services/Inbox/Generators/WeeklyRevisitInboxGenerator.php`
- Test: `tests/Unit/Services/Inbox/WeeklyRevisitInboxGeneratorTest.php`

- [ ] **Step 1: Write the failing generator test**

Create `tests/Unit/Services/Inbox/WeeklyRevisitInboxGeneratorTest.php`:

```php
<?php

namespace Tests\Unit\Services\Inbox;

use App\Models\Thought;
use App\Models\User;
use App\Models\UserPreference;
use App\Services\Inbox\Generators\WeeklyRevisitInboxGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WeeklyRevisitInboxGeneratorTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function returns_a_single_candidate_with_revisit_ideas_in_body(): void
    {
        $user = User::factory()->create();
        UserPreference::set($user, 'ideas_to_revisit_limit', 3);

        Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Old idea',
            'metadata' => ['type' => 'idea', 'completed' => false, 'logged_date' => '2025-01-01'],
        ]);

        Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Another old idea',
            'metadata' => ['type' => 'idea', 'completed' => false, 'logged_date' => '2025-01-02'],
        ]);

        $generator = app(WeeklyRevisitInboxGenerator::class);
        $candidates = $generator->generate($user);

        $this->assertCount(1, $candidates);
        $this->assertSame('weekly_revisit', $candidates[0]['generator_type']);
        $this->assertSame('weekly-revisit', $candidates[0]['dedupe_key']);
        $this->assertStringContainsString('Old idea', $candidates[0]['body']);
        $this->assertStringContainsString('Another old idea', $candidates[0]['body']);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test tests/Unit/Services/Inbox/WeeklyRevisitInboxGeneratorTest.php -v`

Expected: FAIL because the generator contract and class do not exist yet.

- [ ] **Step 3: Add the contract and generator**

Create `app/Services/Inbox/Contracts/InboxGenerator.php`:

```php
<?php

namespace App\Services\Inbox\Contracts;

use App\Models\User;

interface InboxGenerator
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function generate(User $user): array;
}
```

Create `app/Services/Inbox/Generators/WeeklyRevisitInboxGenerator.php`:

```php
<?php

namespace App\Services\Inbox\Generators;

use App\Models\User;
use App\Services\IdeasToRevisitService;
use App\Services\Inbox\Contracts\InboxGenerator;

class WeeklyRevisitInboxGenerator implements InboxGenerator
{
    public function __construct(
        private IdeasToRevisitService $revisitService
    ) {}

    public function generate(User $user): array
    {
        $ideas = collect($this->revisitService->forUser($user))->values();

        if ($ideas->isEmpty()) {
            return [];
        }

        $lines = $ideas->map(fn ($idea) => '- '.$idea->content)->implode("\n");

        return [[
            'generator_type' => 'weekly_revisit',
            'title' => 'Weekly revisit',
            'body' => "Review these older ideas:\n".$lines,
            'dedupe_key' => 'weekly-revisit',
            'generated_at' => now(),
            'source_data' => [
                'idea_ids' => $ideas->pluck('id')->all(),
            ],
        ]];
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test tests/Unit/Services/Inbox/WeeklyRevisitInboxGeneratorTest.php -v`

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Inbox/Contracts/InboxGenerator.php app/Services/Inbox/Generators/WeeklyRevisitInboxGenerator.php tests/Unit/Services/Inbox/WeeklyRevisitInboxGeneratorTest.php
git commit -m "Add weekly revisit inbox generator"
```

---

### Task 4: Add the neglected idea generator

**Files:**
- Create: `app/Services/Inbox/Generators/NeglectedIdeaInboxGenerator.php`
- Test: `tests/Unit/Services/Inbox/NeglectedIdeaInboxGeneratorTest.php`

- [ ] **Step 1: Write the failing generator test**

Create `tests/Unit/Services/Inbox/NeglectedIdeaInboxGeneratorTest.php`:

```php
<?php

namespace Tests\Unit\Services\Inbox;

use App\Models\Thought;
use App\Models\User;
use App\Services\Inbox\Generators\NeglectedIdeaInboxGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class NeglectedIdeaInboxGeneratorTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function returns_candidates_for_old_incomplete_ideas_only(): void
    {
        $user = User::factory()->create();

        $oldIdea = Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Neglected idea',
            'metadata' => ['type' => 'idea', 'completed' => false, 'logged_date' => now()->subDays(45)->toDateString()],
        ]);

        Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'Recent idea',
            'metadata' => ['type' => 'idea', 'completed' => false, 'logged_date' => now()->subDays(5)->toDateString()],
        ]);

        $generator = app(NeglectedIdeaInboxGenerator::class);
        $candidates = $generator->generate($user);

        $this->assertCount(1, $candidates);
        $this->assertSame('neglected_idea:'.$oldIdea->id, $candidates[0]['dedupe_key']);
        $this->assertStringContainsString('Neglected idea', $candidates[0]['body']);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test tests/Unit/Services/Inbox/NeglectedIdeaInboxGeneratorTest.php -v`

Expected: FAIL because the generator does not exist yet.

- [ ] **Step 3: Add the generator**

Create `app/Services/Inbox/Generators/NeglectedIdeaInboxGenerator.php`:

```php
<?php

namespace App\Services\Inbox\Generators;

use App\Models\Thought;
use App\Models\User;
use App\Services\Inbox\Contracts\InboxGenerator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class NeglectedIdeaInboxGenerator implements InboxGenerator
{
    public function generate(User $user): array
    {
        $cutoff = Carbon::today()->subDays(30)->toDateString();
        $driver = DB::connection()->getDriverName();
        $effectiveDateSql = $driver === 'pgsql'
            ? "COALESCE((metadata->>'logged_date')::date, created_at::date)"
            : "COALESCE(json_extract(metadata, '$.logged_date'), date(created_at))";

        $ideas = Thought::query()
            ->where('user_id', $user->id)
            ->ideas()
            ->where(function ($query): void {
                $query->whereNull('metadata->completed')
                    ->orWhere('metadata->completed', '!=', true);
            })
            ->whereRaw("({$effectiveDateSql}) <= ?", [$cutoff])
            ->orderByRaw($effectiveDateSql.' ASC')
            ->limit(2)
            ->get();

        return $ideas->map(function (Thought $idea): array {
            return [
                'generator_type' => 'neglected_idea',
                'title' => 'Neglected idea',
                'body' => "This idea has been sitting for a while:\n".$idea->content,
                'dedupe_key' => 'neglected_idea:'.$idea->id,
                'generated_at' => now(),
                'source_data' => [
                    'idea_id' => $idea->id,
                    'logged_date' => $idea->metadata['logged_date'] ?? null,
                ],
            ];
        })->all();
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test tests/Unit/Services/Inbox/NeglectedIdeaInboxGeneratorTest.php -v`

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Inbox/Generators/NeglectedIdeaInboxGenerator.php tests/Unit/Services/Inbox/NeglectedIdeaInboxGeneratorTest.php
git commit -m "Add neglected idea inbox generator"
```

---

### Task 5: Add generation service, command, and schedule

**Files:**
- Create: `app/Services/Inbox/InboxGenerationService.php`
- Create: `app/Console/Commands/GenerateInboxItemsCommand.php`
- Create: `config/inbox.php`
- Modify: `routes/console.php`
- Test: `tests/Feature/GenerateInboxItemsCommandTest.php`

- [ ] **Step 1: Write the failing command test**

Create `tests/Feature/GenerateInboxItemsCommandTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Thought;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GenerateInboxItemsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_generates_items_without_duplicates_for_pending_keys(): void
    {
        $user = User::factory()->create();

        Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'An old idea',
            'metadata' => ['type' => 'idea', 'completed' => false, 'logged_date' => now()->subDays(40)->toDateString()],
        ]);

        $this->artisan('inbox:generate')->assertExitCode(0);
        $this->artisan('inbox:generate')->assertExitCode(0);

        $this->assertDatabaseCount('inbox_items', 2); // weekly_revisit + neglected_idea
    }

    public function test_snoozed_pending_items_still_block_duplicate_generation(): void
    {
        $user = User::factory()->create();

        \App\Models\InboxItem::factory()->create([
            'user_id' => $user->id,
            'generator_type' => 'weekly_revisit',
            'dedupe_key' => 'weekly-revisit',
            'status' => 'pending',
            'snoozed_until' => now()->addDay(),
        ]);

        Thought::factory()->create([
            'user_id' => $user->id,
            'content' => 'An old idea',
            'metadata' => ['type' => 'idea', 'completed' => false, 'logged_date' => now()->subDays(40)->toDateString()],
        ]);

        $this->artisan('inbox:generate')->assertExitCode(0);

        $this->assertSame(1, \App\Models\InboxItem::query()->where('dedupe_key', 'weekly-revisit')->count());
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test tests/Feature/GenerateInboxItemsCommandTest.php -v`

Expected: FAIL because the command and generation service do not exist yet.

- [ ] **Step 3: Add the generation service**

Create `app/Services/Inbox/InboxGenerationService.php`:

```php
<?php

namespace App\Services\Inbox;

use App\Models\InboxItem;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

class InboxGenerationService
{
    public function runForUser(User $user, int $maxNewItems = 5): int
    {
        $generatorClasses = config('inbox.generators', []);
        $maxNewItems = (int) config('inbox.max_new_items_per_user_per_run', $maxNewItems);

        $inserted = 0;

        foreach ($generatorClasses as $generatorClass) {
            $generator = app($generatorClass);

            try {
                $candidates = $generator->generate($user);
            } catch (\Throwable $e) {
                Log::error('Inbox generator failed.', [
                    'user_id' => $user->id,
                    'generator' => $generatorClass,
                    'message' => $e->getMessage(),
                ]);
                report($e);
                continue;
            }

            foreach ($candidates as $candidate) {
                if ($inserted >= $maxNewItems) {
                    return $inserted;
                }

                try {
                    InboxItem::query()->create([
                        'user_id' => $user->id,
                        'generator_type' => $candidate['generator_type'],
                        'title' => $candidate['title'],
                        'body' => $candidate['body'],
                        'status' => 'pending',
                        'snoozed_until' => null,
                        'generated_at' => $candidate['generated_at'] ?? now(),
                        'actioned_at' => null,
                        'dedupe_key' => $candidate['dedupe_key'],
                        'source_data' => $candidate['source_data'] ?? null,
                    ]);

                    $inserted++;
                } catch (QueryException $e) {
                    $sqlState = (string) ($e->errorInfo[0] ?? '');
                    $message = strtolower($e->getMessage());
                    $isDedupeViolation = ($sqlState === '23000' || $sqlState === '23505')
                        && str_contains($message, 'inbox_items_user_dedupe_pending_unique');

                    if (! $isDedupeViolation) {
                        throw $e;
                    }
                }
            }
        }

        return $inserted;
    }
}
```

- [ ] **Step 4: Add the command and schedule**

Create `app/Console/Commands/GenerateInboxItemsCommand.php`:

```php
<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Inbox\InboxGenerationService;
use Illuminate\Console\Command;

class GenerateInboxItemsCommand extends Command
{
    protected $signature = 'inbox:generate';

    protected $description = 'Generate pending inbox items for enabled users.';

    public function handle(InboxGenerationService $service): int
    {
        $created = 0;

        User::query()->orderBy('id')->chunk(100, function ($users) use ($service, &$created): void {
            foreach ($users as $user) {
                $created += $service->runForUser($user);
            }
        });

        $this->info("Generated {$created} inbox item(s).");

        return self::SUCCESS;
    }
}
```

Create `config/inbox.php`:

```php
<?php

use App\Services\Inbox\Generators\NeglectedIdeaInboxGenerator;
use App\Services\Inbox\Generators\WeeklyRevisitInboxGenerator;

return [
    'max_new_items_per_user_per_run' => 5,
    'generators' => [
        WeeklyRevisitInboxGenerator::class,
        NeglectedIdeaInboxGenerator::class,
    ],
];
```

Modify `routes/console.php` by adding a new schedule line alongside the existing scheduled commands (do not replace the current ones):

```php
Schedule::command('inbox:generate')->hourly();
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `php artisan test tests/Feature/GenerateInboxItemsCommandTest.php -v`

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Services/Inbox/InboxGenerationService.php app/Console/Commands/GenerateInboxItemsCommand.php config/inbox.php routes/console.php tests/Feature/GenerateInboxItemsCommandTest.php
git commit -m "Add inbox generation service and scheduled command"
```

---

## Chunk 3: User actions and thought conversion

### Task 6: Add done and snooze actions

**Files:**
- Modify: `routes/web.php`
- Create: `app/Services/Inbox/InboxActionService.php`
- Modify: `app/Http/Controllers/InboxController.php`
- Modify: `resources/views/inbox/index.blade.php`
- Test: `tests/Feature/InboxActionsTest.php`

- [ ] **Step 1: Write the failing action tests**

Create `tests/Feature/InboxActionsTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\InboxItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InboxActionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_mark_own_inbox_item_done(): void
    {
        $user = User::factory()->create();
        $item = InboxItem::factory()->create([
            'user_id' => $user->id,
            'dedupe_key' => 'done-item',
        ]);

        $response = $this->actingAs($user)->post(route('inbox.done', $item));

        $response->assertRedirect(route('inbox.index'));
        $this->assertDatabaseHas('inbox_items', [
            'id' => $item->id,
            'status' => 'done',
        ]);
        $this->assertDatabaseHas('inbox_item_actions', [
            'inbox_item_id' => $item->id,
            'action_type' => 'done',
        ]);
    }

    public function test_user_can_snooze_own_inbox_item_until_tomorrow(): void
    {
        $user = User::factory()->create();
        $item = InboxItem::factory()->create([
            'user_id' => $user->id,
            'dedupe_key' => 'snooze-item',
        ]);

        $response = $this->actingAs($user)->post(route('inbox.snooze', $item), [
            'preset' => 'tomorrow',
        ]);

        $response->assertRedirect(route('inbox.index'));
        $item->refresh();

        $this->assertSame('pending', $item->status);
        $this->assertNotNull($item->snoozed_until);
        $this->assertDatabaseHas('inbox_item_actions', [
            'inbox_item_id' => $item->id,
            'action_type' => 'snooze',
        ]);
    }

    public function test_user_can_snooze_own_inbox_item_until_next_week(): void
    {
        $user = User::factory()->create();
        $item = InboxItem::factory()->create([
            'user_id' => $user->id,
            'dedupe_key' => 'snooze-next-week-item',
        ]);

        $response = $this->actingAs($user)->post(route('inbox.snooze', $item), [
            'preset' => 'next_week',
        ]);

        $response->assertRedirect(route('inbox.index'));
        $item->refresh();

        $this->assertNotNull($item->snoozed_until);
        $this->assertTrue($item->snoozed_until->greaterThan(now()));
    }

    public function test_user_cannot_mutate_another_users_inbox_item(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $item = InboxItem::factory()->create([
            'user_id' => $owner->id,
            'dedupe_key' => 'forbidden-item',
        ]);

        $this->actingAs($other)->post(route('inbox.done', $item))->assertForbidden();
        $this->actingAs($other)->post(route('inbox.snooze', $item), ['preset' => 'tomorrow'])->assertForbidden();
        $this->actingAs($other)->post(route('inbox.save-thought', $item))->assertForbidden();
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test tests/Feature/InboxActionsTest.php -v`

Expected: FAIL because the action service and controller methods do not exist yet.

- [ ] **Step 3: Add the action service**

Create `app/Services/Inbox/InboxActionService.php`:

```php
<?php

namespace App\Services\Inbox;

use App\Models\InboxItem;
use App\Models\InboxItemAction;

class InboxActionService
{
    public function markDone(InboxItem $item): void
    {
        $item->update([
            'status' => 'done',
            'actioned_at' => now(),
        ]);

        InboxItemAction::query()->create([
            'inbox_item_id' => $item->id,
            'action_type' => 'done',
            'metadata' => null,
            'created_at' => now(),
        ]);
    }

    public function snooze(InboxItem $item, string $preset): void
    {
        $until = match ($preset) {
            'tomorrow' => now('UTC')->addDay()->startOfDay(),
            'next_week' => now('UTC')->addWeek()->startOfDay(),
            default => throw new \InvalidArgumentException('Invalid snooze preset.'),
        };

        $item->update([
            'status' => 'pending',
            'snoozed_until' => $until,
        ]);

        InboxItemAction::query()->create([
            'inbox_item_id' => $item->id,
            'action_type' => 'snooze',
            'metadata' => ['snoozed_until' => $until->toIso8601String(), 'preset' => $preset],
            'created_at' => now(),
        ]);
    }

}
```

- [ ] **Step 4: Add controller action methods**

Update `app/Http/Controllers/InboxController.php`:

```php
use App\Models\InboxItem;
use App\Services\Inbox\InboxActionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
```

Add the POST routes now in `routes/web.php` inside the auth group:

```php
Route::post('/inbox/{inboxItem}/done', [InboxController::class, 'markDone'])->name('inbox.done');
Route::post('/inbox/{inboxItem}/snooze', [InboxController::class, 'snooze'])->name('inbox.snooze');
Route::post('/inbox/{inboxItem}/save-thought', [InboxController::class, 'saveAsThought'])->name('inbox.save-thought');
```

Update `resources/views/inbox/index.blade.php` to replace the Chunk 1 placeholder text with the action forms:

```blade
<div class="mt-4 flex flex-wrap gap-2">
    <form method="POST" action="{{ route('inbox.done', $item) }}">
        @csrf
        <button type="submit" class="rounded-lg bg-neural-teal px-3 py-1.5 text-xs font-medium text-white">Done</button>
    </form>

    <form method="POST" action="{{ route('inbox.snooze', $item) }}">
        @csrf
        <input type="hidden" name="preset" value="tomorrow">
        <button type="submit" class="rounded-lg border border-memory-violet/20 px-3 py-1.5 text-xs font-medium text-slate-brand">Tomorrow</button>
    </form>

    <form method="POST" action="{{ route('inbox.snooze', $item) }}">
        @csrf
        <input type="hidden" name="preset" value="next_week">
        <button type="submit" class="rounded-lg border border-memory-violet/20 px-3 py-1.5 text-xs font-medium text-slate-brand">Next week</button>
    </form>

    <form method="POST" action="{{ route('inbox.save-thought', $item) }}">
        @csrf
        <button type="submit" class="rounded-lg border border-memory-violet/20 px-3 py-1.5 text-xs font-medium text-memory-violet">Save as thought</button>
    </form>
</div>
```

Add methods:

```php
public function markDone(InboxItem $inboxItem, InboxActionService $actionService): RedirectResponse
{
    $this->authorize('update', $inboxItem);

    $actionService->markDone($inboxItem);

    return redirect()->route('inbox.index')->with('success', 'Inbox item marked done.');
}

public function snooze(Request $request, InboxItem $inboxItem, InboxActionService $actionService): RedirectResponse
{
    $this->authorize('update', $inboxItem);

    $validated = $request->validate([
        'preset' => 'required|in:tomorrow,next_week',
    ]);

    $actionService->snooze($inboxItem, $validated['preset']);

    return redirect()->route('inbox.index')->with('success', 'Inbox item snoozed.');
}

public function saveAsThought(InboxItem $inboxItem): RedirectResponse
{
    $this->authorize('update', $inboxItem);

    return redirect()->route('inbox.index')->with('error', 'Save as thought is added in the next task.');
}
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `php artisan test tests/Feature/InboxActionsTest.php -v`

Expected: PASS for done, snooze, and owner-only access.

- [ ] **Step 6: Commit**

```bash
git add routes/web.php resources/views/inbox/index.blade.php app/Services/Inbox/InboxActionService.php app/Http/Controllers/InboxController.php tests/Feature/InboxActionsTest.php
git commit -m "Add inbox done and snooze actions"
```

---

### Task 7: Add save-as-thought action and idempotency

**Files:**
- Modify: `app/Services/Inbox/InboxActionService.php`
- Modify: `app/Http/Controllers/InboxController.php`
- Modify: `tests/Feature/InboxActionsTest.php`

- [ ] **Step 1: Add failing save-as-thought tests**

Append to `tests/Feature/InboxActionsTest.php`:

```php
public function test_user_can_save_inbox_item_as_thought_and_item_is_completed(): void
{
    $user = User::factory()->create();
    $item = InboxItem::factory()->create([
        'user_id' => $user->id,
        'title' => 'Turn this into a thought',
        'body' => 'Body content for the thought.',
        'dedupe_key' => 'save-thought-item',
    ]);

    $response = $this->actingAs($user)->post(route('inbox.save-thought', $item));

    $response->assertRedirect(route('inbox.index'));
    $this->assertDatabaseHas('thoughts', [
        'user_id' => $user->id,
        'source' => 'inbox',
    ]);
    $this->assertDatabaseHas('inbox_items', [
        'id' => $item->id,
        'status' => 'done',
    ]);
    $this->assertDatabaseHas('inbox_item_actions', [
        'inbox_item_id' => $item->id,
        'action_type' => 'save_as_thought',
    ]);
}

public function test_save_as_thought_is_idempotent_for_a_single_item(): void
{
    $user = User::factory()->create();
    $item = InboxItem::factory()->create([
        'user_id' => $user->id,
        'title' => 'Only save once',
        'body' => 'Duplicate submissions should not duplicate thoughts.',
        'dedupe_key' => 'save-once-item',
    ]);

    $this->actingAs($user)->post(route('inbox.save-thought', $item));
    $this->actingAs($user)->post(route('inbox.save-thought', $item));

    $this->assertSame(1, \App\Models\Thought::query()->where('source', 'inbox')->count());
    $this->assertSame(1, \App\Models\InboxItemAction::query()->where('inbox_item_id', $item->id)->where('action_type', 'save_as_thought')->count());
}

public function test_save_as_thought_failure_keeps_item_pending(): void
{
    $user = User::factory()->create();
    $item = InboxItem::factory()->create([
        'user_id' => $user->id,
        'title' => 'Will fail',
        'body' => 'Capture should fail.',
        'dedupe_key' => 'save-failure-item',
    ]);

    $this->mock(\App\Services\ThoughtCaptureService::class, function ($mock): void {
        $mock->shouldReceive('create')->andThrow(new \RuntimeException('capture failed'));
    });

    $response = $this->actingAs($user)->post(route('inbox.save-thought', $item));

    $response->assertRedirect(route('inbox.index'));
    $response->assertSessionHas('error');
    $this->assertDatabaseHas('inbox_items', [
        'id' => $item->id,
        'status' => 'pending',
    ]);
    $this->assertDatabaseMissing('inbox_item_actions', [
        'inbox_item_id' => $item->id,
        'action_type' => 'save_as_thought',
    ]);
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test tests/Feature/InboxActionsTest.php -v`

Expected: FAIL because the placeholder `saveAsThought()` route handler does not create thoughts yet.

- [ ] **Step 3: Extend the action service for save-as-thought**

Update `app/Services/Inbox/InboxActionService.php` imports:

```php
use App\Services\ThoughtCaptureService;
```

Update the class constructor:

```php
public function __construct(
    private ThoughtCaptureService $thoughtCaptureService
) {}
```

Add the method:

```php
public function saveAsThought(InboxItem $item): string
{
    $existing = $item->actions()->where('action_type', 'save_as_thought')->latest('id')->first();
    if ($existing !== null) {
        return (string) ($existing->metadata['thought_id'] ?? '');
    }

    $content = $item->title."\n\n".$item->body;
    if (! empty($item->source_data)) {
        $content .= "\n\nSource data:\n".json_encode($item->source_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    $result = $this->thoughtCaptureService->create([
        'content' => $content,
        'user_id' => $item->user_id,
        'source' => 'inbox',
        'source_metadata' => [
            'inbox_item_id' => $item->id,
            'generator_type' => $item->generator_type,
        ],
        'no_chunking' => true,
    ]);

    $thoughtId = $result['thought']->id;

    InboxItemAction::query()->create([
        'inbox_item_id' => $item->id,
        'action_type' => 'save_as_thought',
        'metadata' => ['thought_id' => $thoughtId],
        'created_at' => now(),
    ]);

    $item->update([
        'status' => 'done',
        'actioned_at' => now(),
    ]);

    return $thoughtId;
}
```

- [ ] **Step 4: Replace the placeholder route handler with the real save-as-thought flow**

Update `app/Http/Controllers/InboxController.php`:

```php
public function saveAsThought(InboxItem $inboxItem, InboxActionService $actionService): RedirectResponse
{
    $this->authorize('update', $inboxItem);

    try {
        $actionService->saveAsThought($inboxItem);
    } catch (\Throwable $e) {
        report($e);

        return redirect()->route('inbox.index')->with('error', 'Unable to save inbox item as a thought.');
    }

    return redirect()->route('inbox.index')->with('success', 'Saved as thought.');
}
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `php artisan test tests/Feature/InboxActionsTest.php -v`

Expected: PASS, including the idempotency assertion.

- [ ] **Step 6: Commit**

```bash
git add app/Services/Inbox/InboxActionService.php app/Http/Controllers/InboxController.php tests/Feature/InboxActionsTest.php
git commit -m "Add save-as-thought inbox action"
```

---

### Task 8: Final verification

**Files:**
- Modify: `docs/superpowers/plans/2026-03-20-agent-inbox.md` (check off tasks during execution only)

- [ ] **Step 1: Run the focused test set**

Run:

```bash
php artisan test tests/Unit/Models/InboxItemTest.php tests/Feature/InboxPageTest.php tests/Feature/InboxActionsTest.php tests/Unit/Services/Inbox/WeeklyRevisitInboxGeneratorTest.php tests/Unit/Services/Inbox/NeglectedIdeaInboxGeneratorTest.php tests/Feature/GenerateInboxItemsCommandTest.php -v
```

Expected: PASS.

- [ ] **Step 2: Run the full test suite**

Run:

```bash
php artisan test -v
```

Expected: PASS.

- [ ] **Step 3: Manual verification**

1. Visit `/inbox` while signed in and confirm the empty state renders.
2. Run `php artisan inbox:generate` and reload `/inbox`.
3. Confirm the Inbox nav badge matches the visible actionable item count.
4. Mark one item done and verify it disappears from the list.
5. Snooze one item to tomorrow and verify it disappears until due.
6. Save one item as a thought and verify a new `thoughts` row exists with `source = inbox`.
7. Run `php artisan inbox:generate` again and verify pending items are not duplicated.

---

## Execution handoff

Plan complete and saved to `docs/superpowers/plans/2026-03-20-agent-inbox.md`. Ready to execute?
