# Email Sender Rules Settings Filtering Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add URL-backed action + sender search filters and server pagination to `/settings/email-sender-rules`, preserving filters across CRUD redirects.

**Architecture:** Extend `EmailSenderRuleSettingsController` and its Blade view. Query params `action` and `q` filter the user’s `emailSenderRules` relation; results paginate at 25 with `withQueryString()`. Mutation forms POST `filter_action` / `filter_q` (not `action`/`q`, which collide with rule fields) so redirects reapply list filters.

**Tech Stack:** Laravel 12, Blade, Pest/PHPUnit feature tests (`tests/Feature/EmailSenderRuleSettingsTest.php`)

**Spec:** `docs/superpowers/specs/2026-07-24-email-sender-rules-filtering-design.md`

## Global Constraints

- Page size: 25
- Query params: `action`, `q`, `page` (GET); mutation preserve fields: `filter_action`, `filter_q`
- Invalid GET `action` → ignore (treat as all), do not 422
- `q`: nullable string max 255; case-insensitive substring on `sender_email`; escape `%` and `_`
- Feature flag `services.email_sender_policy.enabled` still 404s when off
- No schema migration; no Inertia rewrite; no change to rule evaluation semantics

## File map

| File | Responsibility |
|------|----------------|
| `app/Http/Controllers/EmailSenderRuleSettingsController.php` | Filter + paginate on index; map filter preserve fields on redirects |
| `resources/views/settings/email-sender-rules.blade.php` | Filter form, empty states, pagination, hidden preserve fields |
| `tests/Feature/EmailSenderRuleSettingsTest.php` | Feature coverage for filters, pagination query string, redirect preserve |

---

### Task 1: Index filtering and pagination (controller + tests)

**Files:**
- Modify: `app/Http/Controllers/EmailSenderRuleSettingsController.php`
- Modify: `tests/Feature/EmailSenderRuleSettingsTest.php`

**Interfaces:**
- Produces: `index` returns paginator as `rules`; view also receives `filterAction` (`?string`) and `filterQ` (`string`)
- Produces: private helpers usable in Task 3:
  - `resolvedFilterAction(Request $request): ?string` — from GET `action` or POST `filter_action`
  - `resolvedFilterQ(Request $request): string` — trimmed GET `q` or POST `filter_q`, max 255 enforced via validation on index
  - `filterRedirectQuery(Request $request): array` — `['action' => ..., 'q' => ...]` with empty values omitted

- [ ] **Step 1: Write failing filter/pagination tests**

Add to `tests/Feature/EmailSenderRuleSettingsTest.php`:

```php
public function test_settings_page_filters_rules_by_action(): void
{
    $user = User::factory()->create();
    $user->emailSenderRules()->create([
        'sender_email' => 'allow@example.com',
        'action' => EmailSenderRule::ACTION_ALLOW,
    ]);
    $user->emailSenderRules()->create([
        'sender_email' => 'ignore@example.com',
        'action' => EmailSenderRule::ACTION_IGNORE,
    ]);

    $this->actingAs($user)
        ->get(route('settings.email-sender-rules.index', ['action' => EmailSenderRule::ACTION_IGNORE]))
        ->assertOk()
        ->assertSee('ignore@example.com')
        ->assertDontSee('allow@example.com');
}

public function test_settings_page_filters_rules_by_sender_substring_case_insensitive(): void
{
    $user = User::factory()->create();
    $user->emailSenderRules()->create([
        'sender_email' => 'newsletter@substack.com',
        'action' => EmailSenderRule::ACTION_ALLOW,
    ]);
    $user->emailSenderRules()->create([
        'sender_email' => 'alerts@example.com',
        'action' => EmailSenderRule::ACTION_ALLOW,
    ]);

    $this->actingAs($user)
        ->get(route('settings.email-sender-rules.index', ['q' => 'SUBSTACK']))
        ->assertOk()
        ->assertSee('newsletter@substack.com')
        ->assertDontSee('alerts@example.com');
}

public function test_settings_page_combines_action_and_q_filters(): void
{
    $user = User::factory()->create();
    $user->emailSenderRules()->create([
        'sender_email' => 'a@substack.com',
        'action' => EmailSenderRule::ACTION_IGNORE,
    ]);
    $user->emailSenderRules()->create([
        'sender_email' => 'b@substack.com',
        'action' => EmailSenderRule::ACTION_ALLOW,
    ]);
    $user->emailSenderRules()->create([
        'sender_email' => 'c@other.com',
        'action' => EmailSenderRule::ACTION_IGNORE,
    ]);

    $this->actingAs($user)
        ->get(route('settings.email-sender-rules.index', [
            'action' => EmailSenderRule::ACTION_IGNORE,
            'q' => 'substack',
        ]))
        ->assertOk()
        ->assertSee('a@substack.com')
        ->assertDontSee('b@substack.com')
        ->assertDontSee('c@other.com');
}

public function test_invalid_action_query_param_is_ignored(): void
{
    $user = User::factory()->create();
    $user->emailSenderRules()->create([
        'sender_email' => 'allow@example.com',
        'action' => EmailSenderRule::ACTION_ALLOW,
    ]);

    $this->actingAs($user)
        ->get(route('settings.email-sender-rules.index', ['action' => 'not-a-real-action']))
        ->assertOk()
        ->assertSee('allow@example.com');
}

public function test_settings_page_paginates_and_preserves_filters_in_links(): void
{
    $user = User::factory()->create();
    for ($i = 0; $i < 26; $i++) {
        $user->emailSenderRules()->create([
            'sender_email' => sprintf('ignore-%02d@example.com', $i),
            'action' => EmailSenderRule::ACTION_IGNORE,
        ]);
    }
    $user->emailSenderRules()->create([
        'sender_email' => 'allow@example.com',
        'action' => EmailSenderRule::ACTION_ALLOW,
    ]);

    $response = $this->actingAs($user)
        ->get(route('settings.email-sender-rules.index', [
            'action' => EmailSenderRule::ACTION_IGNORE,
            'q' => 'example.com',
        ]));

    $response->assertOk();
    $response->assertSee('page=2');
    $response->assertSee('action=ignore');
    $response->assertSee('q=example.com');
    $response->assertDontSee('allow@example.com');
}

public function test_empty_filtered_state_differs_from_empty_account(): void
{
    $user = User::factory()->create();
    $user->emailSenderRules()->create([
        'sender_email' => 'allow@example.com',
        'action' => EmailSenderRule::ACTION_ALLOW,
    ]);

    $this->actingAs($user)
        ->get(route('settings.email-sender-rules.index', ['action' => EmailSenderRule::ACTION_IGNORE]))
        ->assertOk()
        ->assertSee('No rules match these filters.')
        ->assertDontSee('No sender rules yet.');
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=EmailSenderRuleSettingsTest`

Expected: new tests FAIL (no filtering / still shows all or no empty-filter copy).

- [ ] **Step 3: Implement controller filtering helpers + index**

Replace `index` and add helpers on `EmailSenderRuleSettingsController`:

```php
public function index(Request $request): View
{
    $validated = $request->validate([
        'q' => ['nullable', 'string', 'max:255'],
        'action' => ['nullable', 'string'],
    ]);

    $filterAction = $this->resolvedFilterAction($request);
    $filterQ = trim((string) ($validated['q'] ?? ''));

    $query = $request->user()->emailSenderRules()->orderBy('sender_email');

    if ($filterAction !== null) {
        $query->where('action', $filterAction);
    }

    if ($filterQ !== '') {
        $escaped = addcslashes($filterQ, '%_\\');
        $query->whereRaw('LOWER(sender_email) LIKE ?', ['%'.mb_strtolower($escaped).'%']);
    }

    $rules = $query->paginate(25)->withQueryString();

    return view('settings.email-sender-rules', [
        'rules' => $rules,
        'filterAction' => $filterAction,
        'filterQ' => $filterQ,
    ]);
}

/**
 * @return array{action?: string, q?: string}
 */
private function filterRedirectQuery(Request $request): array
{
    $query = [];
    $action = $this->resolvedFilterAction($request);
    if ($action !== null) {
        $query['action'] = $action;
    }

    $q = $this->resolvedFilterQ($request);
    if ($q !== '') {
        $query['q'] = $q;
    }

    return $query;
}

private function resolvedFilterAction(Request $request): ?string
{
    $raw = $request->input('filter_action', $request->query('action'));
    if (! is_string($raw) || $raw === '' || $raw === 'all') {
        return null;
    }

    return EmailSenderRule::isValidAction($raw) ? $raw : null;
}

private function resolvedFilterQ(Request $request): string
{
    $raw = $request->input('filter_q', $request->query('q', ''));
    if (! is_string($raw)) {
        return '';
    }

    return trim(mb_substr($raw, 0, 255));
}
```

Note: existing CRUD redirect assertions use `assertRedirect(route('settings.email-sender-rules.index'))` with no query — keep that working when preserve fields are absent.

- [ ] **Step 4: Run filter tests**

Run: `php artisan test --filter=EmailSenderRuleSettingsTest`

Expected: filter/pagination tests may still fail on empty-state copy until Task 2; controller filtering tests for assertSee/DontSee should PASS. If empty-state test fails only on missing string, proceed to Task 2.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/EmailSenderRuleSettingsController.php tests/Feature/EmailSenderRuleSettingsTest.php
git commit -m "feat: filter and paginate email sender rules settings"
```

---

### Task 2: Blade filter UI, empty states, pagination

**Files:**
- Modify: `resources/views/settings/email-sender-rules.blade.php`

**Interfaces:**
- Consumes: `$rules` (LengthAwarePaginator), `$filterAction` (?string), `$filterQ` (string)

- [ ] **Step 1: Update the Blade view**

Above the “Your rules” card, add a filter card GET form. Inside “Your rules”:

- If `$rules->total() === 0` and no filters active (`$filterAction === null && $filterQ === ''`): show “No sender rules yet. Add one below.”
- Else if `$rules->total() === 0`: show “No rules match these filters.”
- Else: list as today; when filters active, show “{{ $rules->total() }} matching rules” above the list
- After the list: `{{ $rules->links() }}`
- On Add / each Update / each Remove form, add:

```blade
<input type="hidden" name="filter_action" value="{{ $filterAction }}" />
<input type="hidden" name="filter_q" value="{{ $filterQ }}" />
```

Filter form sketch (match existing settings styling):

```blade
<div class="rounded-2xl border border-memory-violet/20 bg-white/80 backdrop-blur p-6 shadow-[0_4px_24px_rgba(109,106,247,0.08)] mb-6">
    <h2 class="text-lg font-semibold text-deep-indigo mb-4">Filter rules</h2>
    <form method="GET" action="{{ route('settings.email-sender-rules.index') }}" class="flex flex-wrap items-end gap-3">
        <div class="min-w-[12rem] flex-1">
            <label for="q" class="block text-sm font-medium text-deep-indigo mb-1">Search sender</label>
            <input type="search" name="q" id="q" value="{{ $filterQ }}" placeholder="name@example.com"
                class="w-full rounded-lg border border-memory-violet/20 px-3 py-2 text-sm text-deep-indigo placeholder-slate-brand/60 focus:border-neural-teal focus:ring-1 focus:ring-neural-teal" />
        </div>
        <div>
            <label for="filter-action" class="block text-sm font-medium text-deep-indigo mb-1">Action</label>
            <select name="action" id="filter-action"
                class="rounded-lg border border-memory-violet/20 px-3 py-2 text-sm text-deep-indigo focus:border-neural-teal focus:ring-1 focus:ring-neural-teal">
                <option value="">All</option>
                @foreach ($actionLabels as $value => $label)
                    <option value="{{ $value }}" @selected($filterAction === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <button type="submit" class="text-xs font-medium text-white px-4 py-2 rounded-lg transition-opacity hover:opacity-90"
            style="background: linear-gradient(135deg, #6D6AF7, #2A8C8C);">Filter</button>
        <a href="{{ route('settings.email-sender-rules.index') }}" class="text-xs font-medium text-slate-brand hover:text-memory-violet px-2 py-2">Clear</a>
    </form>
</div>
```

Ensure `@php $actionLabels = ...` remains at top; view must not error when `$filterAction` / `$filterQ` missing (controller always passes them after Task 1).

- [ ] **Step 2: Re-run settings tests**

Run: `php artisan test --filter=EmailSenderRuleSettingsTest`

Expected: PASS including empty filtered state and pagination link assertions.

- [ ] **Step 3: Commit**

```bash
git add resources/views/settings/email-sender-rules.blade.php
git commit -m "feat: add filter UI to email sender rules settings"
```

---

### Task 3: Preserve filters on store / update / destroy redirects

**Files:**
- Modify: `app/Http/Controllers/EmailSenderRuleSettingsController.php`
- Modify: `tests/Feature/EmailSenderRuleSettingsTest.php`

**Interfaces:**
- Consumes: `filterRedirectQuery(Request $request): array` from Task 1

- [ ] **Step 1: Write failing redirect-preserve tests**

```php
public function test_store_redirect_preserves_filter_query(): void
{
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('settings.email-sender-rules.store'), [
        'sender_email' => 'new@example.com',
        'action' => EmailSenderRule::ACTION_ALLOW,
        'filter_action' => EmailSenderRule::ACTION_IGNORE,
        'filter_q' => 'substack',
    ]);

    $response->assertRedirect(route('settings.email-sender-rules.index', [
        'action' => EmailSenderRule::ACTION_IGNORE,
        'q' => 'substack',
    ]));
}

public function test_update_redirect_preserves_filter_query(): void
{
    $user = User::factory()->create();
    $rule = $user->emailSenderRules()->create([
        'sender_email' => 'update-me@example.com',
        'action' => EmailSenderRule::ACTION_REVIEW,
    ]);

    $response = $this->actingAs($user)->patch(
        route('settings.email-sender-rules.update', $rule),
        [
            'action' => EmailSenderRule::ACTION_EXTRA_PROCESS,
            'filter_action' => EmailSenderRule::ACTION_REVIEW,
            'filter_q' => 'update-me',
        ]
    );

    $response->assertRedirect(route('settings.email-sender-rules.index', [
        'action' => EmailSenderRule::ACTION_REVIEW,
        'q' => 'update-me',
    ]));
}

public function test_destroy_redirect_preserves_filter_query(): void
{
    $user = User::factory()->create();
    $rule = $user->emailSenderRules()->create([
        'sender_email' => 'remove-me@example.com',
        'action' => EmailSenderRule::ACTION_REVIEW,
    ]);

    $response = $this->actingAs($user)->delete(
        route('settings.email-sender-rules.destroy', $rule),
        [
            'filter_action' => EmailSenderRule::ACTION_IGNORE,
            'filter_q' => 'news',
        ]
    );

    $response->assertRedirect(route('settings.email-sender-rules.index', [
        'action' => EmailSenderRule::ACTION_IGNORE,
        'q' => 'news',
    ]));
}

public function test_store_ignores_invalid_filter_action_on_redirect(): void
{
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('settings.email-sender-rules.store'), [
        'sender_email' => 'new@example.com',
        'action' => EmailSenderRule::ACTION_ALLOW,
        'filter_action' => 'bogus',
        'filter_q' => 'keep-me',
    ]);

    $response->assertRedirect(route('settings.email-sender-rules.index', [
        'q' => 'keep-me',
    ]));
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=test_store_redirect_preserves_filter_query`

Expected: FAIL (redirect has no query).

- [ ] **Step 3: Wire redirects through `filterRedirectQuery`**

In `store`, `update`, and `destroy`, change success redirects to:

```php
return redirect()
    ->route('settings.email-sender-rules.index', $this->filterRedirectQuery($request))
    ->with('success', '...');
```

Apply the same query merge on the duplicate-rule error redirect in `store` (optional but consistent).

Do **not** change validation of the rule `action` field.

- [ ] **Step 4: Run full settings test file**

Run: `php artisan test --filter=EmailSenderRuleSettingsTest`

Expected: all PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/EmailSenderRuleSettingsController.php tests/Feature/EmailSenderRuleSettingsTest.php
git commit -m "feat: preserve sender-rule filters after CRUD redirects"
```

---

## Spec coverage checklist

| Spec requirement | Task |
|------------------|------|
| Filter by action via URL | 1 |
| Search `q` case-insensitive substring | 1 |
| Paginate 25 + `withQueryString` | 1 |
| Invalid action ignored | 1 |
| Filter UI + Clear + matching/empty copy | 2 |
| Hidden `filter_action` / `filter_q` | 2 |
| Redirect preserve on store/update/destroy | 3 |
| No migration / no semantics change | all |
