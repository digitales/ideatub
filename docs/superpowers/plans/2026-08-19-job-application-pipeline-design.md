# Job Application Pipeline Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a feature-flagged Job Application module to `ideatub` — `Prospect`/`Company`/`Application`/`Interaction`/`Achievement` models, MCP tools, and a Blade+Alpine UI — so job-search state, research, and CV/cover-letter assembly live in `ideatub` instead of scattered markdown and a bespoke LaTeX pipeline.

**Architecture:** Standard Laravel MVC extension of the existing app. Two-stage funnel: cheap `job_prospects` (source → score → shortlist/dismiss) promotes into `applications` (research → build → log → track → debrief). `Thought` is reused as-is for research/debrief content via plain nullable FK columns (no new linking service — the existing pattern, confirmed in `EmailNewsletterResearchService::persistSuccessMetadata`, is just `$model->research_thought_id = $thought->id; $model->save();`). Everything sits behind `config('features.job_search')`, checked in middleware, controllers, and every MCP handler.

**Tech Stack:** Laravel 13 / PHP ^8.3, Blade + Alpine.js (no new frontend framework), PHPUnit (method-style, `#[Test]` attribute), `spatie/browsershot` (new dependency) for markdown→HTML→PDF.

**Spec:** `docs/plans/2026-08-19-job-application-pipeline-design.md`

## Global Constraints

- Feature-gated: every route, controller action, and MCP tool checks `config('features.job_search')` (spec §2c). Off by default: `FEATURE_JOB_SEARCH=false`.
- No native DB enums — stage/status/type/source columns are `string` with app-level validation (`in:...` rules), matching `commitment_items.status`/`commitment_items.type` convention.
- All new tables use `uuid` primary keys (`$table->uuid('id')->primary()`, matching every table FK'd to `thoughts`), `foreignId('user_id')->constrained()->cascadeOnDelete()` for ownership (matches `commitment_items`), `foreignUuid(...)->nullOnDelete()` for optional cross-links.
- `Thought` gets no new columns or methods. Linking is a plain nullable FK on the *other* model (`research_thought_id`, `debrief_thought_id`) assigned directly after `Thought::create(...)`.
- CV/cover-letter content is markdown, never LaTeX (spec §2a). Styling lives once in `App\Services\Documents\CvStyle`, a constants class (spec §2b).
- PHPUnit method-style tests (`#[Test]` attribute, `use RefreshDatabase;`, `extends Tests\TestCase`), split `tests/Unit` (models/policies/services) vs `tests/Feature` (controllers/MCP/routes), matching `tests/Feature/McpUpdateProjectSettingsTest.php`.
- Reviewed after every task: code-quality review, spec-compliance review against `docs/plans/2026-08-19-job-application-pipeline-design.md`, final review before merge (spec §7).

---

## Task 1: Migrations

**Files:**
- Create: `database/migrations/2026_08_19_090000_create_companies_table.php`
- Create: `database/migrations/2026_08_19_090100_create_job_prospects_table.php`
- Create: `database/migrations/2026_08_19_090200_create_applications_table.php`
- Create: `database/migrations/2026_08_19_090300_add_promoted_application_fk_to_job_prospects_table.php`
- Create: `database/migrations/2026_08_19_090400_create_interactions_table.php`
- Create: `database/migrations/2026_08_19_090500_create_achievements_table.php`
- Test: `tests/Unit/JobSearchMigrationsTest.php`

**Interfaces:**
- Produces: five tables (`companies`, `job_prospects`, `applications`, `interactions`, `achievements`) with the exact columns below — every later task's models/factories/migrations reference these column names verbatim.

`job_prospects.promoted_application_id` and `applications.job_prospect_id` are mutually referential, so `job_prospects` is created first with a plain nullable `uuid` column (no FK yet), `applications` is created next with its FK to `job_prospects`, and a follow-up migration adds the FK constraint back onto `job_prospects.promoted_application_id`.

- [ ] **Step 1: Write migration test (fails — tables don't exist)**

```php
<?php

namespace Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class JobSearchMigrationsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function test_job_search_tables_exist_with_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('companies'));
        $this->assertTrue(Schema::hasColumns('companies', ['id', 'user_id', 'name', 'website', 'notes', 'research_thought_id']));

        $this->assertTrue(Schema::hasTable('job_prospects'));
        $this->assertTrue(Schema::hasColumns('job_prospects', [
            'id', 'user_id', 'company', 'role_title', 'source', 'url', 'salary_signal',
            'fit_score', 'status', 'discovered_at', 'scored_at', 'notes', 'promoted_application_id',
        ]));

        $this->assertTrue(Schema::hasTable('applications'));
        $this->assertTrue(Schema::hasColumns('applications', [
            'id', 'user_id', 'company_id', 'job_prospect_id', 'role_title', 'stage', 'source',
            'salary_min', 'salary_max', 'applied_at', 'last_activity_at', 'research_thought_id',
            'cv_markdown', 'cover_letter_markdown', 'cv_pdf_path', 'cover_letter_pdf_path',
            'cv_exported_at', 'cover_letter_exported_at',
        ]));

        $this->assertTrue(Schema::hasTable('interactions'));
        $this->assertTrue(Schema::hasColumns('interactions', [
            'id', 'user_id', 'application_id', 'type', 'occurred_at', 'summary', 'debrief_thought_id',
        ]));

        $this->assertTrue(Schema::hasTable('achievements'));
        $this->assertTrue(Schema::hasColumns('achievements', [
            'id', 'user_id', 'tag', 'bullet_text', 'times_used', 'last_used_at', 'retired_at',
        ]));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Unit/JobSearchMigrationsTest.php`
Expected: FAIL (tables missing).

- [ ] **Step 3: Write the migrations**

`database/migrations/2026_08_19_090000_create_companies_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 255);
            $table->string('website', 500)->nullable();
            $table->text('notes')->nullable();
            $table->foreignUuid('research_thought_id')->nullable()->constrained('thoughts')->nullOnDelete();
            $table->timestamps();

            $table->index(['user_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
```

`database/migrations/2026_08_19_090100_create_job_prospects_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_prospects', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('company', 255);
            $table->string('role_title', 255);
            $table->string('source', 20);
            $table->string('url', 500)->nullable();
            $table->string('salary_signal', 255)->nullable();
            $table->unsignedTinyInteger('fit_score')->nullable();
            $table->string('status', 20)->default('new');
            $table->timestamp('discovered_at')->useCurrent();
            $table->timestamp('scored_at')->nullable();
            $table->text('notes')->nullable();
            $table->uuid('promoted_application_id')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_prospects');
    }
};
```

`database/migrations/2026_08_19_090200_create_applications_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('applications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUuid('job_prospect_id')->nullable()->constrained('job_prospects')->nullOnDelete();
            $table->string('role_title', 255);
            $table->string('stage', 20)->default('researching');
            $table->string('source', 20)->nullable();
            $table->integer('salary_min')->nullable();
            $table->integer('salary_max')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->foreignUuid('research_thought_id')->nullable()->constrained('thoughts')->nullOnDelete();
            $table->longText('cv_markdown')->nullable();
            $table->longText('cover_letter_markdown')->nullable();
            $table->string('cv_pdf_path', 500)->nullable();
            $table->string('cover_letter_pdf_path', 500)->nullable();
            $table->timestamp('cv_exported_at')->nullable();
            $table->timestamp('cover_letter_exported_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'stage']);
            $table->index(['user_id', 'last_activity_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('applications');
    }
};
```

`database/migrations/2026_08_19_090300_add_promoted_application_fk_to_job_prospects_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_prospects', function (Blueprint $table): void {
            $table->foreign('promoted_application_id')->references('id')->on('applications')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('job_prospects', function (Blueprint $table): void {
            $table->dropForeign(['promoted_application_id']);
        });
    }
};
```

`database/migrations/2026_08_19_090400_create_interactions_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('interactions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('application_id')->constrained('applications')->cascadeOnDelete();
            $table->string('type', 20);
            $table->timestamp('occurred_at');
            $table->text('summary');
            $table->foreignUuid('debrief_thought_id')->nullable()->constrained('thoughts')->nullOnDelete();
            $table->timestamps();

            $table->index(['application_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('interactions');
    }
};
```

`database/migrations/2026_08_19_090500_create_achievements_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('achievements', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('tag', 100);
            $table->text('bullet_text');
            $table->integer('times_used')->default(0);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('retired_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'tag']);
            $table->index(['user_id', 'retired_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('achievements');
    }
};
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Unit/JobSearchMigrationsTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add database/migrations/2026_08_19_* tests/Unit/JobSearchMigrationsTest.php
git commit -m "feat: add job application pipeline migrations"
```

---

## Task 2: `job_search` feature flag and middleware

**Files:**
- Modify: `config/features.php`
- Create: `app/Http/Middleware/EnsureJobSearchEnabled.php`
- Modify: `bootstrap/app.php`
- Test: `tests/Feature/EnsureJobSearchEnabledTest.php`

**Interfaces:**
- Produces: `config('features.job_search')` (bool), middleware alias `'job.search'` registered in `bootstrap/app.php`, usable as `Route::middleware('job.search')->group(...)` in Task 6 and checked directly in every MCP handler in Task 5.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EnsureJobSearchEnabledTest extends TestCase
{
    #[Test]
    public function test_route_behind_job_search_middleware_404s_when_flag_off(): void
    {
        config(['features.job_search' => false]);
        Route::middleware('job.search')->get('/__job_search_probe', fn () => 'ok');

        $this->get('/__job_search_probe')->assertNotFound();
    }

    #[Test]
    public function test_route_behind_job_search_middleware_passes_when_flag_on(): void
    {
        config(['features.job_search' => true]);
        Route::middleware('job.search')->get('/__job_search_probe', fn () => 'ok');

        $this->get('/__job_search_probe')->assertOk();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/EnsureJobSearchEnabledTest.php`
Expected: FAIL (`Target class [job.search] does not exist` / route middleware not found).

- [ ] **Step 3: Add the config flag**

Edit `config/features.php`, add alongside the other flags:

```php
    'job_search' => env('FEATURE_JOB_SEARCH', false),
```

- [ ] **Step 4: Write the middleware**

```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureJobSearchEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('features.job_search')) {
            abort(404);
        }

        return $next($request);
    }
}
```

- [ ] **Step 5: Register the middleware alias**

In `bootstrap/app.php`, add `use App\Http\Middleware\EnsureJobSearchEnabled;` near the other `Ensure*Enabled` imports, and inside the `->withMiddleware()` alias array add:

```php
            'job.search' => EnsureJobSearchEnabled::class,
```

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test tests/Feature/EnsureJobSearchEnabledTest.php`
Expected: PASS

- [ ] **Step 7: Commit**

```bash
git add config/features.php app/Http/Middleware/EnsureJobSearchEnabled.php bootstrap/app.php tests/Feature/EnsureJobSearchEnabledTest.php
git commit -m "feat: add job_search feature flag and middleware"
```

---

## Task 3: Models, relationships, factories

**Files:**
- Create: `app/Models/Company.php`
- Create: `app/Models/JobProspect.php`
- Create: `app/Models/Application.php`
- Create: `app/Models/Interaction.php`
- Create: `app/Models/Achievement.php`
- Create: `database/factories/CompanyFactory.php`
- Create: `database/factories/JobProspectFactory.php`
- Create: `database/factories/ApplicationFactory.php`
- Create: `database/factories/InteractionFactory.php`
- Create: `database/factories/AchievementFactory.php`
- Test: `tests/Unit/Models/CompanyTest.php`
- Test: `tests/Unit/Models/JobProspectTest.php`
- Test: `tests/Unit/Models/ApplicationTest.php`
- Test: `tests/Unit/Models/InteractionTest.php`
- Test: `tests/Unit/Models/AchievementTest.php`

**Interfaces:**
- Consumes: tables from Task 1.
- Produces: `JobProspect::STATUSES` (`['new', 'scored', 'shortlisted', 'dismissed', 'promoted']`), `JobProspect::SOURCES` (`['linkedin', 'job_board', 'referral', 'direct']`), `Application::STAGES` (`['researching', 'applied', 'screening', 'interviewing', 'offer', 'rejected', 'withdrawn']`), `Interaction::TYPES` (`['interview', 'follow_up', 'rejection', 'offer', 'note']`) — every validation rule in Tasks 5–6 references these constants. Relations: `Company::applications()`, `Company::researchThought()`; `JobProspect::promotedApplication()`; `Application::company()`, `Application::jobProspect()`, `Application::researchThought()`, `Application::interactions()`; `Interaction::application()`, `Interaction::debriefThought()`; `Achievement::scopeActive()`, `Achievement::scopeTagged(string $tag)`.

- [ ] **Step 1: Write failing model tests**

```php
<?php
// tests/Unit/Models/JobProspectTest.php

namespace Tests\Unit\Models;

use App\Models\JobProspect;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class JobProspectTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function test_belongs_to_user_and_optionally_a_promoted_application(): void
    {
        $user = User::factory()->create();
        $prospect = JobProspect::factory()->for($user)->create();

        $this->assertTrue($prospect->user->is($user));
        $this->assertNull($prospect->promotedApplication);

        $application = \App\Models\Application::factory()->for($user)->create();
        $prospect->update(['promoted_application_id' => $application->id, 'status' => 'promoted']);

        $this->assertTrue($prospect->fresh()->promotedApplication->is($application));
    }

    #[Test]
    public function test_status_constant_lists_five_states(): void
    {
        $this->assertSame(['new', 'scored', 'shortlisted', 'dismissed', 'promoted'], JobProspect::STATUSES);
    }
}
```

```php
<?php
// tests/Unit/Models/ApplicationTest.php

namespace Tests\Unit\Models;

use App\Models\Application;
use App\Models\Company;
use App\Models\Interaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ApplicationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function test_belongs_to_company_and_has_many_interactions(): void
    {
        $user = User::factory()->create();
        $company = Company::factory()->for($user)->create();
        $application = Application::factory()->for($user)->create(['company_id' => $company->id]);
        Interaction::factory()->for($user)->create(['application_id' => $application->id]);

        $this->assertTrue($application->company->is($company));
        $this->assertCount(1, $application->interactions);
    }

    #[Test]
    public function test_stages_constant_lists_seven_stages(): void
    {
        $this->assertSame(
            ['researching', 'applied', 'screening', 'interviewing', 'offer', 'rejected', 'withdrawn'],
            Application::STAGES
        );
    }
}
```

```php
<?php
// tests/Unit/Models/AchievementTest.php

namespace Tests\Unit\Models;

use App\Models\Achievement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AchievementTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function test_scope_active_excludes_retired_and_scope_tagged_filters_by_tag(): void
    {
        $user = User::factory()->create();
        Achievement::factory()->for($user)->create(['tag' => 'laravel', 'retired_at' => null]);
        Achievement::factory()->for($user)->create(['tag' => 'laravel', 'retired_at' => now()]);
        Achievement::factory()->for($user)->create(['tag' => 'leadership', 'retired_at' => null]);

        $this->assertCount(2, Achievement::query()->active()->get());
        $this->assertCount(1, Achievement::query()->active()->tagged('laravel')->get());
    }
}
```

(Write `CompanyTest`/`InteractionTest` analogously — belongs-to-user, and `Company::applications()` / `Interaction::TYPES` respectively; omitted here for brevity but required before Step 2.)

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test tests/Unit/Models`
Expected: FAIL (classes don't exist).

- [ ] **Step 3: Write the models**

```php
<?php
// app/Models/Company.php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Company extends Model
{
    use HasFactory;
    use HasUuids;

    protected $fillable = ['user_id', 'name', 'website', 'notes', 'research_thought_id'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function applications(): HasMany
    {
        return $this->hasMany(Application::class);
    }

    public function researchThought(): BelongsTo
    {
        return $this->belongsTo(Thought::class, 'research_thought_id');
    }
}
```

```php
<?php
// app/Models/JobProspect.php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JobProspect extends Model
{
    use HasFactory;
    use HasUuids;

    public const STATUSES = ['new', 'scored', 'shortlisted', 'dismissed', 'promoted'];

    public const SOURCES = ['linkedin', 'job_board', 'referral', 'direct'];

    protected $fillable = [
        'user_id', 'company', 'role_title', 'source', 'url', 'salary_signal',
        'fit_score', 'status', 'discovered_at', 'scored_at', 'notes', 'promoted_application_id',
    ];

    protected function casts(): array
    {
        return [
            'fit_score' => 'integer',
            'discovered_at' => 'datetime',
            'scored_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function promotedApplication(): BelongsTo
    {
        return $this->belongsTo(Application::class, 'promoted_application_id');
    }
}
```

```php
<?php
// app/Models/Application.php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Application extends Model
{
    use HasFactory;
    use HasUuids;

    public const STAGES = ['researching', 'applied', 'screening', 'interviewing', 'offer', 'rejected', 'withdrawn'];

    protected $fillable = [
        'user_id', 'company_id', 'job_prospect_id', 'role_title', 'stage', 'source',
        'salary_min', 'salary_max', 'applied_at', 'last_activity_at', 'research_thought_id',
        'cv_markdown', 'cover_letter_markdown', 'cv_pdf_path', 'cover_letter_pdf_path',
        'cv_exported_at', 'cover_letter_exported_at',
    ];

    protected function casts(): array
    {
        return [
            'applied_at' => 'datetime',
            'last_activity_at' => 'datetime',
            'cv_exported_at' => 'datetime',
            'cover_letter_exported_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function jobProspect(): BelongsTo
    {
        return $this->belongsTo(JobProspect::class);
    }

    public function researchThought(): BelongsTo
    {
        return $this->belongsTo(Thought::class, 'research_thought_id');
    }

    public function interactions(): HasMany
    {
        return $this->hasMany(Interaction::class)->orderByDesc('occurred_at');
    }
}
```

```php
<?php
// app/Models/Interaction.php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Interaction extends Model
{
    use HasFactory;
    use HasUuids;

    public const TYPES = ['interview', 'follow_up', 'rejection', 'offer', 'note'];

    protected $fillable = ['user_id', 'application_id', 'type', 'occurred_at', 'summary', 'debrief_thought_id'];

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function debriefThought(): BelongsTo
    {
        return $this->belongsTo(Thought::class, 'debrief_thought_id');
    }
}
```

```php
<?php
// app/Models/Achievement.php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Achievement extends Model
{
    use HasFactory;
    use HasUuids;

    protected $fillable = ['user_id', 'tag', 'bullet_text', 'times_used', 'last_used_at', 'retired_at'];

    protected function casts(): array
    {
        return [
            'times_used' => 'integer',
            'last_used_at' => 'datetime',
            'retired_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('retired_at');
    }

    public function scopeTagged(Builder $query, string $tag): Builder
    {
        return $query->where('tag', $tag);
    }

    public function markUsed(): void
    {
        $this->increment('times_used');
        $this->update(['last_used_at' => now()]);
    }
}
```

- [ ] **Step 4: Write the factories**

```php
<?php
// database/factories/CompanyFactory.php

namespace Database\Factories;

use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Company> */
class CompanyFactory extends Factory
{
    protected $model = Company::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->company(),
            'website' => fake()->optional()->url(),
            'notes' => fake()->optional()->paragraph(),
        ];
    }
}
```

```php
<?php
// database/factories/JobProspectFactory.php

namespace Database\Factories;

use App\Models\JobProspect;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<JobProspect> */
class JobProspectFactory extends Factory
{
    protected $model = JobProspect::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'company' => fake()->company(),
            'role_title' => fake()->jobTitle(),
            'source' => fake()->randomElement(JobProspect::SOURCES),
            'url' => fake()->optional()->url(),
            'status' => 'new',
            'discovered_at' => now(),
        ];
    }
}
```

```php
<?php
// database/factories/ApplicationFactory.php

namespace Database\Factories;

use App\Models\Application;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Application> */
class ApplicationFactory extends Factory
{
    protected $model = Application::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'company_id' => Company::factory(),
            'role_title' => fake()->jobTitle(),
            'stage' => 'researching',
            'last_activity_at' => now(),
        ];
    }
}
```

```php
<?php
// database/factories/InteractionFactory.php

namespace Database\Factories;

use App\Models\Application;
use App\Models\Interaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Interaction> */
class InteractionFactory extends Factory
{
    protected $model = Interaction::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'application_id' => Application::factory(),
            'type' => fake()->randomElement(Interaction::TYPES),
            'occurred_at' => now(),
            'summary' => fake()->sentence(),
        ];
    }
}
```

```php
<?php
// database/factories/AchievementFactory.php

namespace Database\Factories;

use App\Models\Achievement;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Achievement> */
class AchievementFactory extends Factory
{
    protected $model = Achievement::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'tag' => fake()->word(),
            'bullet_text' => fake()->sentence(),
            'times_used' => 0,
        ];
    }
}
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test tests/Unit/Models`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add app/Models/Company.php app/Models/JobProspect.php app/Models/Application.php app/Models/Interaction.php app/Models/Achievement.php database/factories/CompanyFactory.php database/factories/JobProspectFactory.php database/factories/ApplicationFactory.php database/factories/InteractionFactory.php database/factories/AchievementFactory.php tests/Unit/Models
git commit -m "feat: add job application pipeline models and factories"
```

---

## Task 4: Policies

**Files:**
- Create: `app/Policies/CompanyPolicy.php`
- Create: `app/Policies/JobProspectPolicy.php`
- Create: `app/Policies/ApplicationPolicy.php`
- Create: `app/Policies/InteractionPolicy.php`
- Create: `app/Policies/AchievementPolicy.php`
- Test: `tests/Unit/Policies/ApplicationPolicyTest.php`

**Interfaces:**
- Consumes: models from Task 3.
- Produces: standard `viewAny`/`view`/`create`/`update`/`delete` policies, ownership-checked via `user_id`, mirroring `ProjectPolicy`. Consumed by controllers in Task 6 (`$this->authorize(...)`) and Laravel's auto-discovery (`App\Models\X` → `App\Policies\XPolicy`, no manual registration needed since `ProjectPolicy` isn't manually registered either — confirm no `AuthServiceProvider::$policies` map exists before assuming auto-discovery; if one does exist, add entries there instead).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit\Policies;

use App\Models\Application;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ApplicationPolicyTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function test_view_and_update_require_ownership(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $application = Application::factory()->for($owner)->create();

        $this->assertTrue($owner->can('view', $application));
        $this->assertFalse($stranger->can('view', $application));
        $this->assertTrue($owner->can('update', $application));
        $this->assertFalse($stranger->can('update', $application));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Unit/Policies/ApplicationPolicyTest.php`
Expected: FAIL (policy not resolved / assertion false).

- [ ] **Step 3: Write the policies** (one shown; repeat identically for `CompanyPolicy`, `JobProspectPolicy`, `InteractionPolicy`, `AchievementPolicy`, substituting the model class)

```php
<?php
// app/Policies/ApplicationPolicy.php

namespace App\Policies;

use App\Models\Application;
use App\Models\User;

class ApplicationPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Application $application): bool
    {
        return $application->user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Application $application): bool
    {
        return $application->user_id === $user->id;
    }

    public function delete(User $user, Application $application): bool
    {
        return $application->user_id === $user->id;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Unit/Policies/ApplicationPolicyTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Policies/CompanyPolicy.php app/Policies/JobProspectPolicy.php app/Policies/ApplicationPolicy.php app/Policies/InteractionPolicy.php app/Policies/AchievementPolicy.php tests/Unit/Policies/ApplicationPolicyTest.php
git commit -m "feat: add job application pipeline policies"
```

---

## Task 5: Promotion + document-assembly services

**Files:**
- Create: `app/Services/JobSearch/ProspectPromotionService.php`
- Create: `app/Services/JobSearch/CvStyle.php` *(namespaced under `JobSearch\Documents` per spec §2b `App\Services\Documents\CvStyle`; use `App\Services\Documents\CvStyle` exactly — see note below)*
- Create: `app/Services/Documents/CvStyle.php`
- Create: `app/Services/Documents/DocumentAssemblyService.php`
- Test: `tests/Unit/Services/ProspectPromotionServiceTest.php`
- Test: `tests/Unit/Services/DocumentAssemblyServiceTest.php`

**Interfaces:**
- Consumes: `Application`, `JobProspect`, `Company`, `Achievement`, `Thought` (Task 3).
- Produces: `ProspectPromotionService::promote(JobProspect $prospect, ?string $stage = null): Application` — used by MCP `promote_prospect` (Task 6) and the "Mark Applied" UI action (Task 7). `DocumentAssemblyService::assemble(Application $application, array $tags = []): array{cv_markdown: string, cover_letter_markdown: string}` — used by MCP `generate_application_documents` (Task 6) and the Build-stage UI action (Task 7). `CvStyle::FONT_FAMILY`, `CvStyle::css(): string` — used by `DocumentAssemblyService` and by Task 8's PDF export.

Drop the stray `app/Services/JobSearch/CvStyle.php` line above — only `app/Services/Documents/CvStyle.php` is created, matching spec §2b's exact namespace. `ProspectPromotionService` lives under `App\Services\JobSearch` since it's pipeline logic, not document styling.

- [ ] **Step 1: Write the failing promotion test**

```php
<?php

namespace Tests\Unit\Services;

use App\Models\JobProspect;
use App\Models\User;
use App\Services\JobSearch\ProspectPromotionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProspectPromotionServiceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function test_promote_creates_application_at_researching_by_default_and_links_back(): void
    {
        $user = User::factory()->create();
        $prospect = JobProspect::factory()->for($user)->create([
            'company' => 'Acme Ltd',
            'role_title' => 'Staff Engineer',
            'notes' => 'Recruiter mentioned £120k base.',
        ]);

        $application = app(ProspectPromotionService::class)->promote($prospect);

        $this->assertSame('researching', $application->stage);
        $this->assertSame('Staff Engineer', $application->role_title);
        $this->assertSame('Acme Ltd', $application->company->name);
        $this->assertTrue($prospect->fresh()->promotedApplication->is($application));
        $this->assertSame('promoted', $prospect->fresh()->status);
        $this->assertNotNull($application->research_thought_id);
        $this->assertStringContainsString('Recruiter mentioned £120k base.', $application->researchThought->content);
    }

    #[Test]
    public function test_promote_with_applied_stage_override_sets_applied_at(): void
    {
        $user = User::factory()->create();
        $prospect = JobProspect::factory()->for($user)->create();

        $application = app(ProspectPromotionService::class)->promote($prospect, 'applied');

        $this->assertSame('applied', $application->stage);
        $this->assertNotNull($application->applied_at);
    }

    #[Test]
    public function test_promote_without_notes_does_not_create_research_thought(): void
    {
        $user = User::factory()->create();
        $prospect = JobProspect::factory()->for($user)->create(['notes' => null]);

        $application = app(ProspectPromotionService::class)->promote($prospect);

        $this->assertNull($application->research_thought_id);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Unit/Services/ProspectPromotionServiceTest.php`
Expected: FAIL (class doesn't exist).

- [ ] **Step 3: Write `ProspectPromotionService`**

Reuses `Company::firstOrCreate` by name+user (no dedup requirement in spec beyond that) and the plain-FK linking pattern confirmed in `EmailNewsletterResearchService::persistSuccessMetadata`.

```php
<?php

namespace App\Services\JobSearch;

use App\Models\Application;
use App\Models\Company;
use App\Models\JobProspect;
use App\Models\Thought;
use Illuminate\Support\Facades\DB;

class ProspectPromotionService
{
    /**
     * Promote a shortlisted prospect into an Application. Defaults to the
     * `researching` stage; pass `applied` for the "already applied elsewhere"
     * fast path (sets applied_at, spec §4/§5).
     */
    public function promote(JobProspect $prospect, ?string $stage = null): Application
    {
        $stage = $stage ?? 'researching';
        if (! in_array($stage, Application::STAGES, true)) {
            throw new \InvalidArgumentException("Invalid stage: {$stage}");
        }

        return DB::transaction(function () use ($prospect, $stage) {
            $company = Company::query()->firstOrCreate(
                ['user_id' => $prospect->user_id, 'name' => $prospect->company],
                []
            );

            $application = Application::query()->create([
                'user_id' => $prospect->user_id,
                'company_id' => $company->id,
                'job_prospect_id' => $prospect->id,
                'role_title' => $prospect->role_title,
                'stage' => $stage,
                'source' => $prospect->source,
                'applied_at' => $stage === 'applied' ? now() : null,
                'last_activity_at' => now(),
            ]);

            if (trim((string) $prospect->notes) !== '') {
                $thought = Thought::create([
                    'user_id' => $prospect->user_id,
                    'content' => $prospect->notes,
                    'source' => 'job_search',
                ]);
                $application->research_thought_id = $thought->id;
                $application->save();
            }

            $prospect->update([
                'status' => 'promoted',
                'promoted_application_id' => $application->id,
            ]);

            return $application->fresh(['company', 'researchThought']);
        });
    }
}
```

- [ ] **Step 4: Run promotion test to verify it passes**

Run: `php artisan test tests/Unit/Services/ProspectPromotionServiceTest.php`
Expected: PASS

- [ ] **Step 5: Write the failing document-assembly test**

```php
<?php

namespace Tests\Unit\Services;

use App\Models\Achievement;
use App\Models\Application;
use App\Models\Thought;
use App\Models\User;
use App\Services\Documents\DocumentAssemblyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DocumentAssemblyServiceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function test_assemble_includes_tagged_achievements_and_marks_them_used(): void
    {
        $user = User::factory()->create();
        $research = Thought::create(['user_id' => $user->id, 'content' => 'Company builds fintech tooling.']);
        $application = Application::factory()->for($user)->create(['research_thought_id' => $research->id]);
        $laravelBullet = Achievement::factory()->for($user)->create(['tag' => 'laravel', 'bullet_text' => 'Shipped a Laravel MCP server.', 'times_used' => 0]);
        Achievement::factory()->for($user)->create(['tag' => 'design', 'bullet_text' => 'Ran design workshops.']);

        $result = app(DocumentAssemblyService::class)->assemble($application, ['laravel']);

        $this->assertStringContainsString('Shipped a Laravel MCP server.', $result['cv_markdown']);
        $this->assertStringNotContainsString('Ran design workshops.', $result['cv_markdown']);
        $this->assertStringContainsString('Company builds fintech tooling.', $result['cover_letter_markdown']);
        $this->assertSame(1, $laravelBullet->fresh()->times_used);
    }
}
```

- [ ] **Step 6: Run test to verify it fails**

Run: `php artisan test tests/Unit/Services/DocumentAssemblyServiceTest.php`
Expected: FAIL (class doesn't exist).

- [ ] **Step 7: Write `CvStyle`**

```php
<?php

namespace App\Services\Documents;

class CvStyle
{
    public const FONT_FAMILY = 'Helvetica, Arial, sans-serif';

    public const SIZE_NAME = '22px';

    public const SIZE_HEADING = '14px';

    public const SIZE_BODY = '11px';

    public const LINE_HEIGHT = '1.4';

    public const HEADING_SPACING_TOP = '18px';

    /**
     * CSS applied to the markdown-derived HTML before PDF export (Task 8).
     * One definition, referenced everywhere — never hand-touch a generated document (spec §2b).
     */
    public static function css(): string
    {
        return <<<CSS
            body { font-family: {self::FONT_FAMILY}; font-size: {self::SIZE_BODY}; line-height: {self::LINE_HEIGHT}; color: #111; }
            h1 { font-size: {self::SIZE_NAME}; font-weight: bold; margin: 0 0 4px; }
            h2, h3 { font-size: {self::SIZE_HEADING}; font-weight: bold; margin: {self::HEADING_SPACING_TOP} 0 6px; }
            p, li { font-size: {self::SIZE_BODY}; }
            ul { margin: 0 0 8px; padding-left: 18px; }
            CSS;
    }
}
```

(Interpolate the constants with string concatenation rather than heredoc variable interpolation, since `self::X` doesn't interpolate in PHP heredocs — build the string with `sprintf` or concatenation in the real implementation, e.g. `sprintf('body { font-family: %s; ... }', self::FONT_FAMILY)`.)

- [ ] **Step 8: Write `DocumentAssemblyService`**

```php
<?php

namespace App\Services\Documents;

use App\Models\Achievement;
use App\Models\Application;

class DocumentAssemblyService
{
    /**
     * Assemble cv_markdown / cover_letter_markdown from tagged Achievements + the
     * research brief, save as the draft on the Application, and mark achievements used.
     *
     * @param  list<string>  $tags
     * @return array{cv_markdown: string, cover_letter_markdown: string}
     */
    public function assemble(Application $application, array $tags = []): array
    {
        $achievements = Achievement::query()
            ->where('user_id', $application->user_id)
            ->active()
            ->when($tags !== [], fn ($q) => $q->whereIn('tag', $tags))
            ->get();

        $bullets = $achievements->map(fn (Achievement $a) => '- '.$a->bullet_text)->implode("\n");

        $cvMarkdown = "# {$application->user->name}\n\n## Experience\n\n{$bullets}\n";

        $brief = $application->researchThought?->content ?? '';
        $coverLetterMarkdown = "Dear Hiring Team,\n\n{$brief}\n\nBest regards,\n{$application->user->name}\n";

        $application->update([
            'cv_markdown' => $cvMarkdown,
            'cover_letter_markdown' => $coverLetterMarkdown,
        ]);

        $achievements->each(fn (Achievement $a) => $a->markUsed());

        return ['cv_markdown' => $cvMarkdown, 'cover_letter_markdown' => $coverLetterMarkdown];
    }
}
```

- [ ] **Step 9: Run test to verify it passes**

Run: `php artisan test tests/Unit/Services/DocumentAssemblyServiceTest.php`
Expected: PASS

- [ ] **Step 10: Commit**

```bash
git add app/Services/JobSearch/ProspectPromotionService.php app/Services/Documents/CvStyle.php app/Services/Documents/DocumentAssemblyService.php tests/Unit/Services
git commit -m "feat: add prospect promotion and document assembly services"
```

---

## Task 6: MCP tools

**Files:**
- Modify: `app/Http/Controllers/Api/McpController.php`
- Test: `tests/Feature/Mcp/JobSearchMcpTest.php`

**Interfaces:**
- Consumes: `ProspectPromotionService::promote()`, `DocumentAssemblyService::assemble()` (Task 5), models (Task 3).
- Produces: ten tools listed below, each name and JSON-RPC method entry in `dispatch()`. Consumed by `ai-job-search` (Task 10) and directly from chat.

Every handler starts with the same guard (matches spec §6's closing paragraph):

```php
if (! config('features.job_search')) {
    throw new \InvalidArgumentException('Job search feature is disabled.');
}
```

- [ ] **Step 1: Write the failing MCP test**

```php
<?php

namespace Tests\Feature\Mcp;

use App\Models\JobProspect;
use App\Models\User;
use App\Models\UserMcpKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class JobSearchMcpTest extends TestCase
{
    use RefreshDatabase;

    private function validKeyAndUser(): array
    {
        $user = User::factory()->create();
        $plain = 'ideatub_'.str_repeat('x', 32);
        UserMcpKey::query()->create(['user_id' => $user->id, 'key_hash' => UserMcpKey::hashKey($plain)]);

        return [$plain, $user];
    }

    private function mcpPost(string $key, array $data): TestResponse
    {
        return $this->postJson('/api/mcp', $data, ['x-ideatub-key' => $key]);
    }

    #[Test]
    public function test_add_prospect_creates_a_prospect_when_flag_enabled(): void
    {
        config(['features.job_search' => true]);
        [$key, $user] = $this->validKeyAndUser();

        $response = $this->mcpPost($key, [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'add_prospect',
            'params' => ['company' => 'Acme Ltd', 'role_title' => 'Staff Engineer', 'source' => 'linkedin'],
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('job_prospects', [
            'user_id' => $user->id, 'company' => 'Acme Ltd', 'status' => 'new',
        ]);
    }

    #[Test]
    public function test_add_prospect_fails_when_flag_disabled(): void
    {
        config(['features.job_search' => false]);
        [$key] = $this->validKeyAndUser();

        $response = $this->mcpPost($key, [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'add_prospect',
            'params' => ['company' => 'Acme Ltd', 'role_title' => 'Staff Engineer', 'source' => 'linkedin'],
        ]);

        $response->assertOk();
        $this->assertNotNull($response->json('error'));
    }

    #[Test]
    public function test_promote_prospect_returns_application_id(): void
    {
        config(['features.job_search' => true]);
        [$key, $user] = $this->validKeyAndUser();
        $prospect = JobProspect::factory()->for($user)->create();

        $response = $this->mcpPost($key, [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'promote_prospect',
            'params' => ['prospect_id' => (string) $prospect->id],
        ]);

        $response->assertOk();
        $this->assertNotNull($response->json('result.data.application_id'));
    }

    #[Test]
    public function test_get_pipeline_status_groups_by_stage(): void
    {
        config(['features.job_search' => true]);
        [$key, $user] = $this->validKeyAndUser();
        \App\Models\Application::factory()->for($user)->create(['stage' => 'applied']);

        $response = $this->mcpPost($key, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'get_pipeline_status']);

        $response->assertOk();
        $this->assertArrayHasKey('applied', $response->json('result.data.applications'));
    }

    #[Test]
    public function test_tools_list_includes_all_job_search_tools(): void
    {
        [$key] = $this->validKeyAndUser();

        $response = $this->mcpPost($key, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']);

        $names = collect($response->json('result.tools'))->pluck('name')->all();
        foreach ([
            'add_prospect', 'score_prospect', 'promote_prospect', 'create_application',
            'update_application_stage', 'log_interaction', 'get_pipeline_status',
            'search_applications', 'add_achievement', 'retire_achievement', 'get_achievements',
            'generate_application_documents', 'export_application_pdf',
        ] as $name) {
            $this->assertContains($name, $names, "Missing tool: {$name}");
        }
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Mcp/JobSearchMcpTest.php`
Expected: FAIL (`Unknown method`).

- [ ] **Step 3: Add tool schemas to the `tools/list` array**

Append to the `$tools` array in `McpController` (same file/method that builds `search_thoughts`, `capture_thought`, etc., ~line 460 onward):

```php
            [
                'name' => 'add_prospect',
                'description' => 'Add a job prospect: cheap, fire-and-forget sourcing entry, no research yet.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'company' => ['type' => 'string'],
                        'role_title' => ['type' => 'string'],
                        'source' => ['type' => 'string', 'enum' => \App\Models\JobProspect::SOURCES],
                        'url' => ['type' => 'string'],
                    ],
                    'required' => ['company', 'role_title', 'source'],
                ],
            ],
            [
                'name' => 'score_prospect',
                'description' => 'Set a fit score and optional notes on a prospect, moving it to scored.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'prospect_id' => ['type' => 'string'],
                        'fit_score' => ['type' => 'integer'],
                        'notes' => ['type' => 'string'],
                    ],
                    'required' => ['prospect_id', 'fit_score'],
                ],
            ],
            [
                'name' => 'promote_prospect',
                'description' => 'Promote a shortlisted prospect into an Application. Defaults to researching; pass applied for the already-applied-elsewhere fast path.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'prospect_id' => ['type' => 'string'],
                        'stage' => ['type' => 'string', 'enum' => \App\Models\Application::STAGES],
                    ],
                    'required' => ['prospect_id'],
                ],
            ],
            [
                'name' => 'create_application',
                'description' => 'Create an Application directly, bypassing the prospect stage, for a role that does not need sourcing.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'company' => ['type' => 'string'],
                        'role_title' => ['type' => 'string'],
                        'source' => ['type' => 'string'],
                    ],
                    'required' => ['company', 'role_title'],
                ],
            ],
            [
                'name' => 'update_application_stage',
                'description' => 'Move an Application to a new stage, optionally logging a note.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'application_id' => ['type' => 'string'],
                        'stage' => ['type' => 'string', 'enum' => \App\Models\Application::STAGES],
                        'note' => ['type' => 'string'],
                    ],
                    'required' => ['application_id', 'stage'],
                ],
            ],
            [
                'name' => 'log_interaction',
                'description' => 'Log an interaction (interview, follow_up, rejection, offer, note) against an Application.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'application_id' => ['type' => 'string'],
                        'type' => ['type' => 'string', 'enum' => \App\Models\Interaction::TYPES],
                        'summary' => ['type' => 'string'],
                    ],
                    'required' => ['application_id', 'type', 'summary'],
                ],
            ],
            [
                'name' => 'get_pipeline_status',
                'description' => 'Return all open prospects and applications grouped by stage.',
                'inputSchema' => ['type' => 'object', 'properties' => (object) []],
            ],
            [
                'name' => 'search_applications',
                'description' => 'Search applications by company or role title.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => ['query' => ['type' => 'string']],
                    'required' => ['query'],
                ],
            ],
            [
                'name' => 'add_achievement',
                'description' => 'Add a reusable Achievement bullet, tagged for later CV assembly.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'tag' => ['type' => 'string'],
                        'bullet_text' => ['type' => 'string'],
                    ],
                    'required' => ['tag', 'bullet_text'],
                ],
            ],
            [
                'name' => 'retire_achievement',
                'description' => 'Soft-retire an Achievement so it stops appearing in new document assembly.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => ['achievement_id' => ['type' => 'string']],
                    'required' => ['achievement_id'],
                ],
            ],
            [
                'name' => 'get_achievements',
                'description' => 'Query Achievements, optionally filtered by tag, for CV assembly from chat.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => ['tags' => ['type' => 'array', 'items' => ['type' => 'string']]],
                ],
            ],
            [
                'name' => 'generate_application_documents',
                'description' => 'Assemble cv_markdown / cover_letter_markdown from Achievement + the research brief, save as draft, return for review.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'application_id' => ['type' => 'string'],
                        'tags' => ['type' => 'array', 'items' => ['type' => 'string']],
                    ],
                    'required' => ['application_id'],
                ],
            ],
            [
                'name' => 'export_application_pdf',
                'description' => 'Render the current markdown to PDF via CvStyle.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'application_id' => ['type' => 'string'],
                        'document' => ['type' => 'string', 'enum' => ['cv', 'cover_letter']],
                    ],
                    'required' => ['application_id', 'document'],
                ],
            ],
```

- [ ] **Step 4: Wire the dispatch table**

Add to the `match ($method)` block in `dispatch()`:

```php
            'add_prospect' => $this->addProspect($params),
            'score_prospect' => $this->scoreProspect($params),
            'promote_prospect' => $this->promoteProspect($params),
            'create_application' => $this->createApplication($params),
            'update_application_stage' => $this->updateApplicationStage($params),
            'log_interaction' => $this->logInteraction($params),
            'get_pipeline_status' => $this->getPipelineStatus($params),
            'search_applications' => $this->searchApplications($params),
            'add_achievement' => $this->addAchievement($params),
            'retire_achievement' => $this->retireAchievement($params),
            'get_achievements' => $this->getAchievements($params),
            'generate_application_documents' => $this->generateApplicationDocuments($params),
            'export_application_pdf' => $this->exportApplicationPdf($params),
```

- [ ] **Step 5: Implement the handlers**

Add these private methods to `McpController` (below the existing handler methods), matching the `Validator::make` + `\InvalidArgumentException` + `auth()->id()` pattern from `captureThought()`:

```php
    private function addProspect(array $params): array
    {
        if (! config('features.job_search')) {
            throw new \InvalidArgumentException('Job search feature is disabled.');
        }
        $v = Validator::make($params, [
            'company' => 'required|string|max:255',
            'role_title' => 'required|string|max:255',
            'source' => ['required', 'string', Rule::in(\App\Models\JobProspect::SOURCES)],
            'url' => 'sometimes|nullable|string|max:500',
        ]);
        if ($v->fails()) {
            throw new \InvalidArgumentException($v->errors()->first());
        }

        $prospect = \App\Models\JobProspect::query()->create([
            'user_id' => auth()->id(),
            'company' => $params['company'],
            'role_title' => $params['role_title'],
            'source' => $params['source'],
            'url' => $params['url'] ?? null,
            'status' => 'new',
            'discovered_at' => now(),
        ]);

        return ['data' => ['prospect_id' => $prospect->id]];
    }

    private function scoreProspect(array $params): array
    {
        if (! config('features.job_search')) {
            throw new \InvalidArgumentException('Job search feature is disabled.');
        }
        $v = Validator::make($params, [
            'prospect_id' => 'required|uuid',
            'fit_score' => 'required|integer|min:0|max:100',
            'notes' => 'sometimes|nullable|string',
        ]);
        if ($v->fails()) {
            throw new \InvalidArgumentException($v->errors()->first());
        }

        $prospect = \App\Models\JobProspect::query()
            ->where('user_id', auth()->id())
            ->findOrFail($params['prospect_id']);

        $prospect->update([
            'fit_score' => $params['fit_score'],
            'notes' => $params['notes'] ?? $prospect->notes,
            'status' => 'scored',
            'scored_at' => now(),
        ]);

        return ['data' => ['prospect_id' => $prospect->id]];
    }

    private function promoteProspect(array $params): array
    {
        if (! config('features.job_search')) {
            throw new \InvalidArgumentException('Job search feature is disabled.');
        }
        $v = Validator::make($params, [
            'prospect_id' => 'required|uuid',
            'stage' => ['sometimes', 'nullable', 'string', Rule::in(\App\Models\Application::STAGES)],
        ]);
        if ($v->fails()) {
            throw new \InvalidArgumentException($v->errors()->first());
        }

        $prospect = \App\Models\JobProspect::query()
            ->where('user_id', auth()->id())
            ->findOrFail($params['prospect_id']);

        $application = app(\App\Services\JobSearch\ProspectPromotionService::class)
            ->promote($prospect, $params['stage'] ?? null);

        return ['data' => ['application_id' => $application->id]];
    }

    private function createApplication(array $params): array
    {
        if (! config('features.job_search')) {
            throw new \InvalidArgumentException('Job search feature is disabled.');
        }
        $v = Validator::make($params, [
            'company' => 'required|string|max:255',
            'role_title' => 'required|string|max:255',
            'source' => 'sometimes|nullable|string|max:20',
        ]);
        if ($v->fails()) {
            throw new \InvalidArgumentException($v->errors()->first());
        }

        $company = \App\Models\Company::query()->firstOrCreate(
            ['user_id' => auth()->id(), 'name' => $params['company']],
            []
        );

        $application = \App\Models\Application::query()->create([
            'user_id' => auth()->id(),
            'company_id' => $company->id,
            'role_title' => $params['role_title'],
            'stage' => 'researching',
            'source' => $params['source'] ?? null,
            'last_activity_at' => now(),
        ]);

        return ['data' => ['application_id' => $application->id]];
    }

    private function updateApplicationStage(array $params): array
    {
        if (! config('features.job_search')) {
            throw new \InvalidArgumentException('Job search feature is disabled.');
        }
        $v = Validator::make($params, [
            'application_id' => 'required|uuid',
            'stage' => ['required', 'string', Rule::in(\App\Models\Application::STAGES)],
            'note' => 'sometimes|nullable|string',
        ]);
        if ($v->fails()) {
            throw new \InvalidArgumentException($v->errors()->first());
        }

        $application = \App\Models\Application::query()
            ->where('user_id', auth()->id())
            ->findOrFail($params['application_id']);

        $application->update([
            'stage' => $params['stage'],
            'applied_at' => $params['stage'] === 'applied' && $application->applied_at === null ? now() : $application->applied_at,
            'last_activity_at' => now(),
        ]);

        if (! empty($params['note'])) {
            \App\Models\Interaction::query()->create([
                'user_id' => auth()->id(),
                'application_id' => $application->id,
                'type' => 'note',
                'occurred_at' => now(),
                'summary' => $params['note'],
            ]);
        }

        return ['data' => ['application_id' => $application->id, 'stage' => $application->stage]];
    }

    private function logInteraction(array $params): array
    {
        if (! config('features.job_search')) {
            throw new \InvalidArgumentException('Job search feature is disabled.');
        }
        $v = Validator::make($params, [
            'application_id' => 'required|uuid',
            'type' => ['required', 'string', Rule::in(\App\Models\Interaction::TYPES)],
            'summary' => 'required|string',
        ]);
        if ($v->fails()) {
            throw new \InvalidArgumentException($v->errors()->first());
        }

        $application = \App\Models\Application::query()
            ->where('user_id', auth()->id())
            ->findOrFail($params['application_id']);

        $interaction = \App\Models\Interaction::query()->create([
            'user_id' => auth()->id(),
            'application_id' => $application->id,
            'type' => $params['type'],
            'occurred_at' => now(),
            'summary' => $params['summary'],
        ]);

        $application->update(['last_activity_at' => now()]);

        return ['data' => ['interaction_id' => $interaction->id]];
    }

    private function getPipelineStatus(array $params): array
    {
        if (! config('features.job_search')) {
            throw new \InvalidArgumentException('Job search feature is disabled.');
        }

        $prospects = \App\Models\JobProspect::query()
            ->where('user_id', auth()->id())
            ->whereIn('status', ['new', 'scored', 'shortlisted'])
            ->get(['id', 'company', 'role_title', 'status', 'fit_score'])
            ->groupBy('status');

        $applications = \App\Models\Application::query()
            ->where('user_id', auth()->id())
            ->whereNotIn('stage', ['rejected', 'withdrawn'])
            ->with('company:id,name')
            ->get(['id', 'company_id', 'role_title', 'stage'])
            ->groupBy('stage');

        return ['data' => ['prospects' => $prospects, 'applications' => $applications]];
    }

    private function searchApplications(array $params): array
    {
        if (! config('features.job_search')) {
            throw new \InvalidArgumentException('Job search feature is disabled.');
        }
        $v = Validator::make($params, ['query' => 'required|string']);
        if ($v->fails()) {
            throw new \InvalidArgumentException($v->errors()->first());
        }

        $query = trim((string) $params['query']);
        $applications = \App\Models\Application::query()
            ->where('user_id', auth()->id())
            ->where(function ($q) use ($query) {
                $q->where('role_title', 'like', "%{$query}%")
                    ->orWhereHas('company', fn ($c) => $c->where('name', 'like', "%{$query}%"));
            })
            ->with('company:id,name')
            ->get(['id', 'company_id', 'role_title', 'stage']);

        return ['data' => ['applications' => $applications]];
    }

    private function addAchievement(array $params): array
    {
        if (! config('features.job_search')) {
            throw new \InvalidArgumentException('Job search feature is disabled.');
        }
        $v = Validator::make($params, [
            'tag' => 'required|string|max:100',
            'bullet_text' => 'required|string',
        ]);
        if ($v->fails()) {
            throw new \InvalidArgumentException($v->errors()->first());
        }

        $achievement = \App\Models\Achievement::query()->create([
            'user_id' => auth()->id(),
            'tag' => $params['tag'],
            'bullet_text' => $params['bullet_text'],
            'times_used' => 0,
        ]);

        return ['data' => ['achievement_id' => $achievement->id]];
    }

    private function retireAchievement(array $params): array
    {
        if (! config('features.job_search')) {
            throw new \InvalidArgumentException('Job search feature is disabled.');
        }
        $v = Validator::make($params, ['achievement_id' => 'required|uuid']);
        if ($v->fails()) {
            throw new \InvalidArgumentException($v->errors()->first());
        }

        $achievement = \App\Models\Achievement::query()
            ->where('user_id', auth()->id())
            ->findOrFail($params['achievement_id']);

        $achievement->update(['retired_at' => now()]);

        return ['data' => ['achievement_id' => $achievement->id]];
    }

    private function getAchievements(array $params): array
    {
        if (! config('features.job_search')) {
            throw new \InvalidArgumentException('Job search feature is disabled.');
        }

        $tags = isset($params['tags']) && is_array($params['tags']) ? $params['tags'] : [];
        $achievements = \App\Models\Achievement::query()
            ->where('user_id', auth()->id())
            ->active()
            ->when($tags !== [], fn ($q) => $q->whereIn('tag', $tags))
            ->get(['id', 'tag', 'bullet_text', 'times_used', 'last_used_at']);

        return ['data' => ['achievements' => $achievements]];
    }

    private function generateApplicationDocuments(array $params): array
    {
        if (! config('features.job_search')) {
            throw new \InvalidArgumentException('Job search feature is disabled.');
        }
        $v = Validator::make($params, [
            'application_id' => 'required|uuid',
            'tags' => 'sometimes|nullable|array',
        ]);
        if ($v->fails()) {
            throw new \InvalidArgumentException($v->errors()->first());
        }

        $application = \App\Models\Application::query()
            ->where('user_id', auth()->id())
            ->findOrFail($params['application_id']);

        $result = app(\App\Services\Documents\DocumentAssemblyService::class)
            ->assemble($application, $params['tags'] ?? []);

        return ['data' => $result];
    }

    private function exportApplicationPdf(array $params): array
    {
        if (! config('features.job_search')) {
            throw new \InvalidArgumentException('Job search feature is disabled.');
        }
        $v = Validator::make($params, [
            'application_id' => 'required|uuid',
            'document' => ['required', 'string', Rule::in(['cv', 'cover_letter'])],
        ]);
        if ($v->fails()) {
            throw new \InvalidArgumentException($v->errors()->first());
        }

        $application = \App\Models\Application::query()
            ->where('user_id', auth()->id())
            ->findOrFail($params['application_id']);

        $path = app(\App\Services\Documents\PdfExportService::class)
            ->export($application, $params['document']);

        return ['data' => ['path' => $path]];
    }
```

`PdfExportService` is built in Task 8; this handler is written now, tested against a fake/mocked service in this task's test if Task 8 hasn't landed yet in a strict sequential build, or the whole `export_application_pdf` handler + its test can be deferred to land alongside Task 8 — pick whichever matches how the executing agent sequences tasks, noted here so it isn't a surprise.

Add `use Illuminate\Validation\Rule;` to the top of `McpController.php` if not already imported (check before adding — grep for `Rule::` first).

- [ ] **Step 6: Run tests to verify they pass**

Run: `php artisan test tests/Feature/Mcp/JobSearchMcpTest.php`
Expected: PASS (export_application_pdf test may be skipped/pending until Task 8 lands `PdfExportService`, per the note above).

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/Api/McpController.php tests/Feature/Mcp/JobSearchMcpTest.php
git commit -m "feat: add job application pipeline MCP tools"
```

---

## Task 7: Controllers, Blade views, routes

**Files:**
- Create: `app/Http/Controllers/JobApplicationController.php`
- Create: `app/Http/Controllers/JobProspectController.php`
- Create: `app/Http/Controllers/AchievementController.php`
- Create: `app/Http/Requests/UpdateApplicationDocumentsRequest.php`
- Modify: `routes/web.php`
- Modify: `bootstrap/app.php` (already done in Task 2 — no further change here unless the alias needs adjusting)
- Create: `resources/views/job_pipeline/applications/board.blade.php`
- Create: `resources/views/job_pipeline/applications/show.blade.php`
- Create: `resources/views/job_pipeline/prospects/index.blade.php`
- Create: `resources/views/job_pipeline/achievements/index.blade.php`
- Modify: `resources/views/layouts/idea.blade.php` (nav link)
- Test: `tests/Feature/JobApplicationControllerTest.php`
- Test: `tests/Feature/JobProspectControllerTest.php`

**Interfaces:**
- Consumes: models/policies (Tasks 3–4), `ProspectPromotionService`, `DocumentAssemblyService` (Task 5).
- Produces: routes `job_pipeline.applications.index`, `.show`, `.export` (POST), `job_pipeline.prospects.index`, `.update` (PATCH notes), `.shortlist`/`.markApplied`/`.dismiss` (POST), `job_pipeline.achievements.index`, `.store`, `.update`, `.retire`.

- [ ] **Step 1: Write the failing controller test**

```php
<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class JobApplicationControllerTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function test_index_404s_when_feature_flag_disabled(): void
    {
        config(['features.job_search' => false]);
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('job_pipeline.applications.index'))->assertNotFound();
    }

    #[Test]
    public function test_index_shows_board_grouped_by_stage_when_enabled(): void
    {
        config(['features.job_search' => true]);
        $user = User::factory()->create();
        $company = Company::factory()->for($user)->create();
        Application::factory()->for($user)->create(['company_id' => $company->id, 'stage' => 'applied']);

        $response = $this->actingAs($user)->get(route('job_pipeline.applications.index'));

        $response->assertOk();
        $response->assertViewIs('job_pipeline.applications.board');
    }

    #[Test]
    public function test_show_404s_for_another_users_application(): void
    {
        config(['features.job_search' => true]);
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $company = Company::factory()->for($owner)->create();
        $application = Application::factory()->for($owner)->create(['company_id' => $company->id]);

        $this->actingAs($stranger)->get(route('job_pipeline.applications.show', $application))->assertForbidden();
    }
}
```

```php
<?php

namespace Tests\Feature;

use App\Models\JobProspect;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class JobProspectControllerTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function test_shortlist_action_sets_status(): void
    {
        config(['features.job_search' => true]);
        $user = User::factory()->create();
        $prospect = JobProspect::factory()->for($user)->create(['status' => 'scored']);

        $this->actingAs($user)->post(route('job_pipeline.prospects.shortlist', $prospect))->assertRedirect();

        $this->assertSame('shortlisted', $prospect->fresh()->status);
    }

    #[Test]
    public function test_mark_applied_action_promotes_directly_to_applied(): void
    {
        config(['features.job_search' => true]);
        $user = User::factory()->create();
        $prospect = JobProspect::factory()->for($user)->create();

        $this->actingAs($user)->post(route('job_pipeline.prospects.mark-applied', $prospect))->assertRedirect();

        $this->assertSame('applied', $prospect->fresh()->promotedApplication->stage);
    }

    #[Test]
    public function test_dismiss_action_sets_status(): void
    {
        config(['features.job_search' => true]);
        $user = User::factory()->create();
        $prospect = JobProspect::factory()->for($user)->create();

        $this->actingAs($user)->post(route('job_pipeline.prospects.dismiss', $prospect))->assertRedirect();

        $this->assertSame('dismissed', $prospect->fresh()->status);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test tests/Feature/JobApplicationControllerTest.php tests/Feature/JobProspectControllerTest.php`
Expected: FAIL (routes/controllers don't exist).

- [ ] **Step 3: Add routes**

In `routes/web.php`, inside the authenticated middleware group, add:

```php
    Route::middleware('job.search')->prefix('job-pipeline')->name('job_pipeline.')->group(function () {
        Route::get('/applications', [JobApplicationController::class, 'index'])->name('applications.index');
        Route::get('/applications/{application}', [JobApplicationController::class, 'show'])->name('applications.show');
        Route::patch('/applications/{application}', [JobApplicationController::class, 'update'])->name('applications.update');
        Route::post('/applications/{application}/export/{document}', [JobApplicationController::class, 'export'])
            ->where('document', 'cv|cover_letter')->name('applications.export');

        Route::get('/prospects', [JobProspectController::class, 'index'])->name('prospects.index');
        Route::patch('/prospects/{prospect}', [JobProspectController::class, 'update'])->name('prospects.update');
        Route::post('/prospects/{prospect}/shortlist', [JobProspectController::class, 'shortlist'])->name('prospects.shortlist');
        Route::post('/prospects/{prospect}/mark-applied', [JobProspectController::class, 'markApplied'])->name('prospects.mark-applied');
        Route::post('/prospects/{prospect}/dismiss', [JobProspectController::class, 'dismiss'])->name('prospects.dismiss');

        Route::get('/achievements', [AchievementController::class, 'index'])->name('achievements.index');
        Route::post('/achievements', [AchievementController::class, 'store'])->name('achievements.store');
        Route::patch('/achievements/{achievement}', [AchievementController::class, 'update'])->name('achievements.update');
        Route::post('/achievements/{achievement}/retire', [AchievementController::class, 'retire'])->name('achievements.retire');
    });
```

Add the matching `use App\Http\Controllers\JobApplicationController;`, `JobProspectController;`, `AchievementController;` imports at the top of `routes/web.php`.

- [ ] **Step 4: Write `JobApplicationController`**

```php
<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateApplicationDocumentsRequest;
use App\Models\Application;
use App\Services\Documents\PdfExportService;

class JobApplicationController extends Controller
{
    public function index()
    {
        $this->authorize('viewAny', Application::class);

        $applications = Application::query()
            ->where('user_id', auth()->id())
            ->with('company:id,name')
            ->orderByDesc('last_activity_at')
            ->get()
            ->groupBy('stage');

        return view('job_pipeline.applications.board', ['applicationsByStage' => $applications]);
    }

    public function show(Application $application)
    {
        $this->authorize('view', $application);

        $application->load(['company', 'interactions', 'researchThought']);

        return view('job_pipeline.applications.show', ['application' => $application]);
    }

    public function update(UpdateApplicationDocumentsRequest $request, Application $application)
    {
        $this->authorize('update', $application);

        $application->update($request->validated());

        return redirect()->route('job_pipeline.applications.show', $application)->with('success', 'Saved.');
    }

    public function export(Application $application, string $document, PdfExportService $pdfExportService)
    {
        $this->authorize('update', $application);

        $pdfExportService->export($application, $document);

        return redirect()->route('job_pipeline.applications.show', $application)->with('success', 'Exported.');
    }
}
```

```php
<?php
// app/Http/Requests/UpdateApplicationDocumentsRequest.php

namespace App\Http\Requests;

use App\Models\Application;
use Illuminate\Foundation\Http\FormRequest;

class UpdateApplicationDocumentsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && $this->user()->can('update', $this->route('application'));
    }

    public function rules(): array
    {
        return [
            'cv_markdown' => ['sometimes', 'nullable', 'string'],
            'cover_letter_markdown' => ['sometimes', 'nullable', 'string'],
            'stage' => ['sometimes', 'string', \Illuminate\Validation\Rule::in(Application::STAGES)],
        ];
    }
}
```

- [ ] **Step 5: Write `JobProspectController`**

```php
<?php

namespace App\Http\Controllers;

use App\Models\JobProspect;
use App\Services\JobSearch\ProspectPromotionService;
use Illuminate\Http\Request;

class JobProspectController extends Controller
{
    public function index()
    {
        $this->authorize('viewAny', JobProspect::class);

        $prospects = JobProspect::query()
            ->where('user_id', auth()->id())
            ->whereIn('status', ['new', 'scored', 'shortlisted'])
            ->orderByDesc('discovered_at')
            ->get();

        return view('job_pipeline.prospects.index', ['prospects' => $prospects]);
    }

    public function update(Request $request, JobProspect $prospect)
    {
        $this->authorize('update', $prospect);

        $request->validate(['notes' => ['nullable', 'string']]);
        $prospect->update(['notes' => $request->input('notes')]);

        return response()->json(['ok' => true]);
    }

    public function shortlist(JobProspect $prospect)
    {
        $this->authorize('update', $prospect);

        $prospect->update(['status' => 'shortlisted']);

        return back()->with('success', 'Shortlisted.');
    }

    public function markApplied(JobProspect $prospect, ProspectPromotionService $promotionService)
    {
        $this->authorize('update', $prospect);

        $promotionService->promote($prospect, 'applied');

        return back()->with('success', 'Marked applied.');
    }

    public function dismiss(JobProspect $prospect)
    {
        $this->authorize('update', $prospect);

        $prospect->update(['status' => 'dismissed']);

        return back()->with('success', 'Dismissed.');
    }
}
```

- [ ] **Step 6: Write `AchievementController`**

```php
<?php

namespace App\Http\Controllers;

use App\Models\Achievement;
use Illuminate\Http\Request;

class AchievementController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', Achievement::class);

        $achievements = Achievement::query()
            ->where('user_id', auth()->id())
            ->when($request->filled('tag'), fn ($q) => $q->where('tag', $request->string('tag')))
            ->orderBy('tag')
            ->get();

        return view('job_pipeline.achievements.index', ['achievements' => $achievements]);
    }

    public function store(Request $request)
    {
        $this->authorize('create', Achievement::class);

        $request->validate(['tag' => 'required|string|max:100', 'bullet_text' => 'required|string']);

        Achievement::query()->create([
            'user_id' => auth()->id(),
            'tag' => $request->string('tag'),
            'bullet_text' => $request->string('bullet_text'),
            'times_used' => 0,
        ]);

        return back()->with('success', 'Achievement added.');
    }

    public function update(Request $request, Achievement $achievement)
    {
        $this->authorize('update', $achievement);

        $request->validate(['tag' => 'required|string|max:100', 'bullet_text' => 'required|string']);
        $achievement->update($request->only('tag', 'bullet_text'));

        return back()->with('success', 'Achievement updated.');
    }

    public function retire(Achievement $achievement)
    {
        $this->authorize('update', $achievement);

        $achievement->update(['retired_at' => now()]);

        return back()->with('success', 'Achievement retired.');
    }
}
```

- [ ] **Step 7: Write the Blade views**

`resources/views/job_pipeline/applications/board.blade.php` — Kanban grouped by `Application::STAGES`, one column per stage, cards linking to `job_pipeline.applications.show`. Extends the same layout as `resources/views/idea/ideas.blade.php` (`@extends('layouts.idea')`); iterate `Application::STAGES` as column order (not `$applicationsByStage`'s natural key order, since empty stages should still render as empty columns):

```blade
@extends('layouts.idea')

@section('content')
<div class="grid grid-cols-1 md:grid-cols-4 gap-4">
    @foreach (\App\Models\Application::STAGES as $stage)
        <div>
            <h2 class="font-semibold text-sm uppercase text-gray-500 mb-2">{{ str($stage)->headline() }}</h2>
            <div class="space-y-2">
                @foreach ($applicationsByStage->get($stage, collect()) as $application)
                    <a href="{{ route('job_pipeline.applications.show', $application) }}"
                       class="block border rounded p-3 hover:bg-gray-50">
                        <div class="font-medium">{{ $application->role_title }}</div>
                        <div class="text-sm text-gray-500">{{ $application->company->name }}</div>
                    </a>
                @endforeach
            </div>
        </div>
    @endforeach
</div>
@endsection
```

`resources/views/job_pipeline/applications/show.blade.php` — detail page with `Company`/`Interaction` inline (loaded relations, not separate resources, per spec §5), the two markdown editors with Alpine live preview, and per-document Export PDF buttons:

```blade
@extends('layouts.idea')

@section('content')
<h1 class="text-xl font-semibold">{{ $application->role_title }} — {{ $application->company->name }}</h1>
<p class="text-sm text-gray-500">Stage: {{ $application->stage }}</p>

<div class="mt-6" x-data="{ cv: @js($application->cv_markdown ?? ''), coverLetter: @js($application->cover_letter_markdown ?? '') }">
    <form method="POST" action="{{ route('job_pipeline.applications.update', $application) }}">
        @csrf
        @method('PATCH')
        <label class="block font-medium">CV (markdown)</label>
        <textarea name="cv_markdown" x-model="cv" rows="12" class="w-full border rounded p-2 font-mono text-sm"></textarea>

        <label class="block font-medium mt-4">Cover letter (markdown)</label>
        <textarea name="cover_letter_markdown" x-model="coverLetter" rows="10" class="w-full border rounded p-2 font-mono text-sm"></textarea>

        <button type="submit" class="mt-4 px-4 py-2 bg-gray-900 text-white rounded">Save draft</button>
    </form>

    <div class="mt-4 flex gap-2">
        <form method="POST" action="{{ route('job_pipeline.applications.export', [$application, 'cv']) }}">
            @csrf
            <button type="submit" class="px-4 py-2 border rounded">Export CV PDF</button>
        </form>
        <form method="POST" action="{{ route('job_pipeline.applications.export', [$application, 'cover_letter']) }}">
            @csrf
            <button type="submit" class="px-4 py-2 border rounded">Export Cover Letter PDF</button>
        </form>
    </div>
</div>

<h2 class="mt-8 font-semibold">Interactions</h2>
<ul class="mt-2 space-y-1">
    @foreach ($application->interactions as $interaction)
        <li class="text-sm">{{ $interaction->occurred_at->toDateString() }} — {{ $interaction->type }} — {{ $interaction->summary }}</li>
    @endforeach
</ul>
@endsection
```

`resources/views/job_pipeline/prospects/index.blade.php` — plain list, autosave-on-blur `notes`, three row-action forms (mirror the `x-data`/blur-fetch pattern in `resources/views/idea/partials/thought_detail_title.blade.php` — read that file directly when implementing this step for exact markup):

```blade
@extends('layouts.idea')

@section('content')
<table class="w-full text-sm">
    <thead>
        <tr class="text-left text-gray-500">
            <th>Company</th><th>Role</th><th>Source</th><th>Notes</th><th>Actions</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($prospects as $prospect)
            <tr class="border-t" x-data="{ notes: @js($prospect->notes ?? '') }">
                <td>{{ $prospect->company }}</td>
                <td>{{ $prospect->role_title }}</td>
                <td>{{ $prospect->source }}</td>
                <td>
                    <textarea x-model="notes" rows="1" class="border rounded w-full text-sm"
                        @blur="fetch('{{ route('job_pipeline.prospects.update', $prospect) }}', {
                            method: 'PATCH',
                            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
                            body: JSON.stringify({ notes: notes })
                        })"></textarea>
                </td>
                <td class="flex gap-1">
                    <form method="POST" action="{{ route('job_pipeline.prospects.shortlist', $prospect) }}">@csrf<button class="px-2 py-1 border rounded text-xs">Shortlist</button></form>
                    <form method="POST" action="{{ route('job_pipeline.prospects.mark-applied', $prospect) }}">@csrf<button class="px-2 py-1 border rounded text-xs">Mark Applied</button></form>
                    <form method="POST" action="{{ route('job_pipeline.prospects.dismiss', $prospect) }}">@csrf<button class="px-2 py-1 border rounded text-xs">Dismiss</button></form>
                </td>
            </tr>
        @endforeach
    </tbody>
</table>
@endsection
```

`resources/views/job_pipeline/achievements/index.blade.php` — filterable index with add/edit/retire forms, `times_used`/`last_used_at` shown per row (straightforward CRUD table following the prospects list's structure above — omitted in full here, build it the same shape with a query-string `?tag=` filter on `AchievementController::index`).

- [ ] **Step 8: Add the nav link**

In `resources/views/layouts/idea.blade.php`, alongside the existing `@if (config('features.attention_pulse') && \Illuminate\Support\Facades\Route::has('pulse.show'))` block (two occurrences, ~lines 94 and 137), add a matching block:

```blade
@if (config('features.job_search') && \Illuminate\Support\Facades\Route::has('job_pipeline.applications.index'))
    <a href="{{ route('job_pipeline.applications.index') }}" class="...">Job Pipeline</a>
@endif
```

(Match the exact classes/markup of the sibling `attention.pulse` nav link at each of the two locations rather than inventing new markup.)

- [ ] **Step 9: Run tests to verify they pass**

Run: `php artisan test tests/Feature/JobApplicationControllerTest.php tests/Feature/JobProspectControllerTest.php`
Expected: PASS

- [ ] **Step 10: Manual UI check**

Set `FEATURE_JOB_SEARCH=true` locally, `php artisan serve`, log in, visit `/job-pipeline/applications`, `/job-pipeline/prospects`, `/job-pipeline/achievements` — confirm board renders, prospect notes autosave on blur, and the three row actions work without a full page reload where specified.

- [ ] **Step 11: Commit**

```bash
git add app/Http/Controllers/JobApplicationController.php app/Http/Controllers/JobProspectController.php app/Http/Controllers/AchievementController.php app/Http/Requests/UpdateApplicationDocumentsRequest.php routes/web.php resources/views/job_pipeline resources/views/layouts/idea.blade.php tests/Feature/JobApplicationControllerTest.php tests/Feature/JobProspectControllerTest.php
git commit -m "feat: add job application pipeline controllers, routes, and views"
```

---

## Task 8: PDF export via Browsershot

**Files:**
- Modify: `composer.json` (add `spatie/browsershot`)
- Create: `app/Services/Documents/PdfExportService.php`
- Test: `tests/Unit/Services/PdfExportServiceTest.php`

**Interfaces:**
- Consumes: `Application::cv_markdown`/`cover_letter_markdown`, `CvStyle::css()` (Task 5).
- Produces: `PdfExportService::export(Application $application, string $document): string` returning the stored relative path, and setting `cv_pdf_path`/`cv_exported_at` or `cover_letter_pdf_path`/`cover_letter_exported_at` on the `Application` — exactly the two fields Task 6's `export_application_pdf` and Task 7's `JobApplicationController::export` both call.

- [ ] **Step 1: Add the dependency**

Run: `composer require spatie/browsershot`

- [ ] **Step 2: Write the failing test**

Browsershot shells out to a headless Chrome/Node binary that isn't guaranteed present in CI — test the service's markdown→path/DB-update contract, not actual PDF rendering, by injecting a fake renderer.

```php
<?php

namespace Tests\Unit\Services;

use App\Models\Application;
use App\Models\User;
use App\Services\Documents\PdfExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PdfExportServiceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function test_export_writes_pdf_and_stamps_path_and_exported_at(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $application = Application::factory()->for($user)->create([
            'cv_markdown' => "# Test CV\n\n- Did a thing",
        ]);

        $service = new class extends PdfExportService {
            protected function renderPdf(string $html): string
            {
                return '%PDF-1.4 fake';
            }
        };

        $path = $service->export($application, 'cv');

        Storage::disk('local')->assertExists($path);
        $application->refresh();
        $this->assertSame($path, $application->cv_pdf_path);
        $this->assertNotNull($application->cv_exported_at);
    }

    #[Test]
    public function test_export_rejects_unknown_document_type(): void
    {
        $user = User::factory()->create();
        $application = Application::factory()->for($user)->create();

        $this->expectException(\InvalidArgumentException::class);
        (new PdfExportService)->export($application, 'resume');
    }
}
```

- [ ] **Step 3: Run test to verify it fails**

Run: `php artisan test tests/Unit/Services/PdfExportServiceTest.php`
Expected: FAIL (class doesn't exist).

- [ ] **Step 4: Write `PdfExportService`**

`renderPdf()` is `protected` specifically so the test above can override it without a real Chrome binary — the markdown→HTML step and `CvStyle::css()` wiring are exercised directly; only the Browsershot shell-out is stubbed.

```php
<?php

namespace App\Services\Documents;

use App\Models\Application;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Browsershot\Browsershot;

class PdfExportService
{
    public function export(Application $application, string $document): string
    {
        if (! in_array($document, ['cv', 'cover_letter'], true)) {
            throw new \InvalidArgumentException("Unknown document type: {$document}");
        }

        $markdown = $document === 'cv' ? $application->cv_markdown : $application->cover_letter_markdown;
        $html = $this->markdownToHtml((string) $markdown);
        $pdfContents = $this->renderPdf($html);

        $path = "job_pipeline/{$application->id}/{$document}.pdf";
        Storage::disk('local')->put($path, $pdfContents);

        if ($document === 'cv') {
            $application->update(['cv_pdf_path' => $path, 'cv_exported_at' => now()]);
        } else {
            $application->update(['cover_letter_pdf_path' => $path, 'cover_letter_exported_at' => now()]);
        }

        return $path;
    }

    protected function renderPdf(string $html): string
    {
        return Browsershot::html($html)->pdf();
    }

    private function markdownToHtml(string $markdown): string
    {
        $body = Str::markdown($markdown);

        return '<html><head><style>'.CvStyle::css().'</style></head><body>'.$body.'</body></html>';
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test tests/Unit/Services/PdfExportServiceTest.php`
Expected: PASS

- [ ] **Step 6: Wire the deferred `export_application_pdf` MCP test from Task 6**

If Task 6's `export_application_pdf` test was left pending, un-skip it now and run:

Run: `php artisan test tests/Feature/Mcp/JobSearchMcpTest.php`
Expected: PASS

- [ ] **Step 7: Commit**

```bash
git add composer.json composer.lock app/Services/Documents/PdfExportService.php tests/Unit/Services/PdfExportServiceTest.php
git commit -m "feat: add PDF export via Browsershot"
```

---

## Task 9: Achievement seeding (manual, no code)

Per spec §8, `Achievement` seeding is deliberately manual, not an import parser. This task has no code deliverable:

- [ ] **Step 1:** With `FEATURE_JOB_SEARCH=true` and Task 7's UI in place, open `/job-pipeline/achievements` and add achievement bullets by hand, using the old CV `.tex` source files as reference only (find them via `find ~ -iname "*.tex"` or wherever the user's existing CV lives — do not write a parser against them, per spec §2a/§7).
- [ ] **Step 2:** Confirm each added row appears in the index and is queryable by tag via `get_achievements` (Task 6) from an MCP client.

No test/commit — this is data entry, not a code change.

---

## Task 10: `ai-job-search` integration

**Files (in the separate `~/Sites/ai-job-search` repo, outside this plan's `ideatub` scope — coordinate as a follow-up task in that repo once Tasks 1–8 are merged):**
- Modify: `~/Sites/ai-job-search/.claude/settings.json` — register `ideatub`'s MCP server (same registration shape as any other MCP server entry already in that file; add `mcpServers.ideatub` pointing at `ideatub`'s `/api/mcp` endpoint with the user's `x-ideatub-key`).
- Modify: `~/Sites/ai-job-search`'s `job-scraper` skill (backing `/scrape`) — after writing to `job_scraper/seen_jobs.json` as today, also call `add_prospect(company, role_title, source, url)` per new listing found.
- Modify: `~/Sites/ai-job-search`'s `/rank` command — after computing a fit score, call `score_prospect(prospect_id, fit_score, notes)`. This requires `add_prospect`'s response (`prospect_id`) to be threaded through from the scrape step to the rank step — persist it in `seen_jobs.json` alongside the existing dedup key so `/rank` can look it up.
- Modify: `~/Sites/ai-job-search`'s `/apply` command — replace its LaTeX generation call with `generate_application_documents(application_id, tags?)`, then `export_application_pdf(application_id, 'cv')` and `export_application_pdf(application_id, 'cover_letter')`.

- [ ] **Step 1:** Read `~/Sites/ai-job-search/.claude/settings.json` and the `job-scraper` skill / `/rank` / `/apply` command definitions to confirm their current write-state shape before changing them (this plan does not have those file contents in scope — read them fresh in that repo).
- [ ] **Step 2:** Wire the three integration points above.
- [ ] **Step 3:** Manual smoke test: run `/scrape` against one real portal, confirm a matching row appears in `ideatub`'s `job_prospects` table (or via `get_pipeline_status`); run `/rank`, confirm `fit_score`/`status` update; promote manually via the UI or `promote_prospect`, run `/apply`, confirm `cv_markdown`/`cover_letter_markdown` land on the `Application` and PDFs export.

No PHPUnit tests — this task is cross-repo integration, verified by the manual smoke test in Step 3. Commit in the `ai-job-search` repo per its own conventions, not this one.

---

## Task 11: Dry run

- [ ] **Step 1:** With `FEATURE_JOB_SEARCH=true`, run one real prospect end to end: source via `ai-job-search` (or `add_prospect` directly if Task 10 isn't done yet) → `score_prospect` → shortlist via UI → `promote_prospect` (or "Mark Applied" row action) → confirm research `Thought` seeded from `notes` → fit check (manual: compare `get_achievements` tags against the research brief) → `generate_application_documents` → hand-edit in the `Application` show page → `export_application_pdf` for both documents → `update_application_stage` to `applied` → `log_interaction` for a follow-up → `update_application_stage` to `rejected` → `log_interaction` with `type: note` as the debrief, linking a `debrief_thought_id` by creating a `Thought` via `capture_thought` first and passing its id through a manual DB update (spec doesn't define a dedicated "add debrief" MCP tool beyond `log_interaction`+`Thought` — if this friction is real, flag it as a gap to the user rather than silently working around it).
- [ ] **Step 2:** Flip `FEATURE_JOB_SEARCH=false`, confirm `/job-pipeline/*` routes 404, the nav link disappears, and every row created in Step 1 is still present in the database (`php artisan tinker` — `Application::count()`, `JobProspect::count()` unchanged).
- [ ] **Step 3:** Report the dry-run outcome and the debrief-linking gap (if confirmed) back to the user before considering the module done.

No commit — this is a verification pass over the already-merged work from Tasks 1–9.

---

## Self-Review Notes

- **Spec coverage:** §1 goals → Tasks 1,3,5,6,7,8. §2a (markdown not LaTeX) → Task 5/8 (`cv_markdown` fields, no `.tex` parsing anywhere). §2b (`CvStyle`) → Task 5 Step 7, Task 8. §2c (feature flag) → Task 2, threaded through Tasks 6–7. §3 data model → Task 1 migrations, Task 3 models — column names match verbatim. §4 pipeline stages → `JobProspect::STATUSES`/`Application::STAGES` (Task 3) + MCP tools (Task 6) + UI actions (Task 7). §4a Scanner/`ai-job-search` parallel → Task 10. §5 UI → Task 7. §6 MCP → Task 6 (all 10 spec tools + `create_application`/`log_interaction` present). §7 build plan → this plan's task numbering follows it 1:1 with Task 9 renumbered from spec's Task 7 to keep migrations/flag/models/policies ordered before services that depend on them. §8 decisions → reflected as constraints throughout (no linking service invented, no achievement importer, `ideatub`-owned documents). §9 success criteria → validated in Task 11.
- **Deviation from spec's task order:** spec §7 lists policies inside Task 6 (controllers) and doesn't list a separate policy task; this plan splits policies into their own Task 4 because `writing-plans` right-sizing calls for a task per independently-reviewable deliverable, and policies are consumed by both controllers (Task 7) and are cleanly unit-testable alone. Task numbers in this plan (1–11) therefore don't map 1:1 to spec §7's Task numbers — Task 5 here (services) corresponds to spec's Tasks 8+9 merged, since `ProspectPromotionService` and `DocumentAssemblyService` share the same "business logic before the MCP/UI layers that call them" ordering rationale.
- **Placeholder scan:** no TBD/TODO left; the one open item (Task 11's debrief-linking friction) is explicitly flagged as something to surface to the user, not silently deferred.
- **Type consistency:** `Application::STAGES`, `JobProspect::STATUSES`/`SOURCES`, `Interaction::TYPES` are defined once in Task 3 and referenced by the same names in Tasks 5–8 (migrations use plain strings validated against these constants, never a second competing list).
