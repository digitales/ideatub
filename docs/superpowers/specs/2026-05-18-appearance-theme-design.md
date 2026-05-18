# Design: Appearance theme (Light / Dark / System)

**Status:** Approved (brainstorming) — pending implementation  
**Date:** 2026-05-18

## Goal

Let authenticated users choose **Light**, **Dark**, or **System** appearance. The choice is:

1. **Authoritative at runtime** in the Laravel **session** (`appearance`).
2. **Persisted** in **`user_preferences`** so it survives logout/login and new browsers.
3. **Controllable** from the **account menu** (quick) and **Profile** settings (full).

Guests and unauthenticated pages default to **System** (follow OS) with no stored preference.

## Context (existing codebase)

- Main app shell uses `layouts.idea` with `ideatub-app` / `ideatub-*` component classes in `resources/css/app.css`.
- Dark styling today is tied to **`prefers-color-scheme`** (`darkMode: 'media'` in `tailwind.config.js` and `@media (prefers-color-scheme: dark)` in `app.css`). This design **migrates** to **class-based** dark (`dark` on `<html>`) so user overrides work independently of OS.
- Precedent for session UI prefs: `stream_layout` via `StreamLayoutController` + `AppServiceProvider` view composer.
- Precedent for durable prefs: `UserPreference` model (`get` / `set` with JSON values).

## Decisions (from brainstorming)

| Topic | Decision |
|-------|----------|
| Options | Light, Dark, System |
| Persistence | `user_preferences` on change; session hydrated at login |
| Session role | Runtime source on every authenticated request |
| Default (new users) | `system` |
| UI | Account menu quick control + Profile Appearance section |
| Approach | Class on `<html>` + Tailwind `darkMode: 'class'` (not CSS-variables rewrite) |

## Non-goals (v1)

- Per-device sync beyond normal login (no separate “sync service”).
- Theme scheduling (e.g. auto-dark at night).
- Separate themes for marketing `layouts.app` beyond basic readability (guest = system only).
- Raster image dark variants (no hero photos in main shell; inline SVGs only).

## Architecture

```text
User changes theme (nav or profile)
        │
        ▼
POST settings.appearance.store
        │
        ├── session['appearance'] = light|dark|system
        └── UserPreference::set(user, 'appearance', value)
        │
        ▼
Client: add/remove `dark` on <html>; if system, listen to matchMedia

Login
        │
        ▼
AppearanceService::hydrateSession(user, session)
        └── session['appearance'] = UserPreference::get(...) ?? 'system'

Each request (authenticated, web)
        │
        ▼
Middleware ensures session key exists (hydrate if missing)
        │
        ▼
Layout: data-appearance on <html> + head script + optional class="dark"
```

## Data model

### Session

| Key | Type | Values |
|-----|------|--------|
| `appearance` | string | `light`, `dark`, `system` |

### UserPreference

| Constant | Key string | Stored value |
|----------|------------|--------------|
| `UserPreference::KEY_APPEARANCE` | `appearance` | JSON string: `"light"`, `"dark"`, or `"system"` |

No migration required (existing `user_preferences` table).

## Backend components

### `AppearanceService`

| Method | Responsibility |
|--------|----------------|
| `allowed(): array` | `['light', 'dark', 'system']` |
| `default(): string` | `'system'` |
| `getStored(User): string` | Read `UserPreference`, else default |
| `hydrateSession(User, Session): void` | `session->put('appearance', getStored())` |
| `set(User, Session, string $appearance): void` | Validate, write session + `UserPreference::set` |
| `isEffectivelyDark(string $appearance): bool` | `true` only for `dark`; `false` for `light`; for `system` use request hint or defer to client (see below) |

**Server-side effective dark for Blade:** For `light` / `dark`, set `$appearanceEffectiveDark` in view composer. For `system`, server may default `false` on first paint; **head script** corrects before paint using `matchMedia`.

### `AppearanceController`

- `store(Request $request): Response`
- Validates: `appearance` required, `Rule::in(AppearanceService::allowed())`
- Calls `AppearanceService::set(auth()->user(), $request->session(), ...)`
- Returns `204 No Content` (same as stream layout)

### Route

```php
Route::post('/settings/appearance', [AppearanceController::class, 'store'])
    ->middleware(['auth'])
    ->name('settings.appearance.store');
```

### Middleware: `EnsureAppearanceInSession`

- Register on `web` group or apply to routes using `layouts.idea`.
- If `auth()->check()` and session missing `appearance`, call `hydrateSession`.

### Login hook

In `AuthenticatedSessionController@store` (after `session()->regenerate()`), call `hydrateSession`.

### View composer

Extend `AppServiceProvider` (or dedicated composer) for `layouts.idea`, `layouts.auth`, `layouts.minimal`:

- `appearance` — session value or `system` for guests
- `appearanceEffectiveDark` — boolean for `dark` / `light` only

## Frontend

### HTML / FOUC

On `<html>`:

```html
<html lang="…" data-appearance="{{ $appearance }}">
```

Inline script in `<head>` **before** `@vite` CSS:

```javascript
(function () {
  var a = document.documentElement.dataset.appearance;
  var dark = a === 'dark' || (a === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);
  if (dark) document.documentElement.classList.add('dark');
})();
```

Also set `class="dark"` on `<html>` when `$appearanceEffectiveDark` is true (redundant but helps SSR).

### `resources/js/appearance.js` (or Alpine in layout)

- Export `setAppearance(mode)` → POST + toggle `document.documentElement.classList`.
- When mode is `system`, register `matchMedia('(prefers-color-scheme: dark)').addEventListener('change', …)` to toggle class.
- Expose to nav and profile (shared module).

### Nav (account dropdown)

- Section **Appearance** above Profile link.
- Three-option control (segmented buttons or radio group): Light / Dark / System.
- `@auth` only.

### Profile (`resources/views/settings/profile.blade.php`)

- New card **Appearance** with same control and helper text: “System follows your device setting.”

### Guests

- `data-appearance="system"` on layouts without auth.
- No theme control in nav.

## CSS migration

1. **`tailwind.config.js`:** `darkMode: 'class'` (replace `'media'`).
2. **`resources/css/app.css`:** Move all rules inside `@media (prefers-color-scheme: dark) { .ideatub-app … }` to `.dark .ideatub-app …` (or equivalent `.dark` ancestor selectors).
3. Add `html.dark { color-scheme: dark; }`.
4. Remove duplicate reliance on OS media for app shell; OS only used when `data-appearance="system"` via JS.
5. Rebuild frontend assets (`npm run dev` / `npm run build`).

Existing `ideatub-surface`, `ideatub-nav`, etc. component classes remain; only the trigger for dark variants changes from media query to `.dark` ancestor.

## Error handling

| Case | Behavior |
|------|----------|
| Invalid `appearance` in POST | `422` validation error |
| Unauthenticated POST | `401` / redirect (auth middleware) |
| Missing preference row | Treat as `system` |

## Testing (Pest)

| Test | Assertion |
|------|-----------|
| `AppearanceStoreTest` | Authenticated POST sets session + DB |
| | Invalid value returns 422 |
| | Guest POST rejected |
| `AppearanceLoginTest` | After login, session matches saved preference |
| `AppearanceLayoutTest` | `dark` session → layout HTML contains `class="dark"` or `data-appearance="dark"` |
| | Profile and idea layout include theme control when auth |

## File checklist (implementation)

| File | Action |
|------|--------|
| `app/Services/AppearanceService.php` | Create |
| `app/Http/Controllers/AppearanceController.php` | Create |
| `app/Http/Middleware/EnsureAppearanceInSession.php` | Create |
| `app/Models/UserPreference.php` | Add `KEY_APPEARANCE` |
| `routes/web.php` | Register route |
| `bootstrap/app.php` or `Kernel` | Register middleware |
| `app/Http/Controllers/Auth/AuthenticatedSessionController.php` | Hydrate on login |
| `app/Providers/AppServiceProvider.php` | View composer |
| `resources/css/app.css` | Media → `.dark` migration |
| `tailwind.config.js` | `darkMode: 'class'` |
| `resources/views/layouts/idea.blade.php` | `data-appearance`, head script, nav control |
| `resources/views/layouts/auth.blade.php` | Head script + data-appearance |
| `resources/views/layouts/minimal.blade.php` | Same |
| `resources/views/settings/profile.blade.php` | Appearance card |
| `resources/js/appearance.js` | Create, import in `app.js` |
| `tests/Feature/AppearanceTest.php` | Create |

## Rollout notes

- Users on **System** should see no behavior change after migration if OS is unchanged.
- Users who relied on OS dark while app was media-only will need to pick **System** or **Dark** explicitly once class mode ships (still default **System**).
- Document in Help optional; not required for v1.
