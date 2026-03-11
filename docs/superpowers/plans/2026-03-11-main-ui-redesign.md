# Main UI Redesign Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Redesign the IdeaTub main app page with a soft-gradient brand identity, frosted-glass capture box, soft thought cards, and a right-aligned nav with search pill.

**Architecture:** Three focused changes — (1) Tailwind brand tokens + Inter font, (2) a new `layouts/idea.blade.php` used only by the idea page (leaving other pages untouched), (3) a rewritten `idea/index.blade.php` that wires all existing server-side behaviour into the new UI. Alpine.js (already installed) handles the search pill toggle and ⌘+Enter keyboard shortcut.

**Deliberate deviations from spec:**
- The spec lists `layouts/app.blade.php` as a file to modify. Instead, we create a new `layouts/idea.blade.php` so other pages (login, pricing, dashboard) are not affected.
- The spec lists `resources/css/app.css` as needing changes. All gradient and frosted-glass effects are achieved via Tailwind utilities and inline `style=""` attributes — no custom CSS is required.
- The spec names the slate token `slate`. It is registered as `slate-brand` in Tailwind to avoid colliding with Tailwind's built-in `slate` colour palette.

**Tech Stack:** Laravel 12 Blade, Tailwind CSS 3, Alpine.js 3, Vite, PHP 8.2

---

## Chunk 1: Tailwind Brand Tokens + Inter Font

### Task 1: Extend Tailwind config with brand colours and Inter font

**Files:**
- Modify: `tailwind.config.js`

- [ ] **Step 1: Open `tailwind.config.js`** and verify current contents (font is Figtree, no custom colours).

- [ ] **Step 2: Replace the theme.extend block** with the brand tokens below.

```js
// tailwind.config.js
import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';
import typography from '@tailwindcss/typography';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.js',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['Inter', ...defaultTheme.fontFamily.sans],
            },
            colors: {
                'deep-indigo':    '#1E2547',
                'neural-teal':    '#2A8C8C',
                'memory-violet':  '#6D6AF7',
                'cloud-white':    '#F5F7FB',
                'slate-brand':    '#5B6472',
            },
        },
    },

    plugins: [forms, typography],
};
```

- [ ] **Step 3: Run the build to confirm no errors**

```bash
cd /Users/rosstweedie/Sites/ideatub && npm run build 2>&1 | tail -20
```

Expected: build succeeds, no errors. CSS output includes the new colour utilities.

- [ ] **Step 4: Commit**

```bash
git add tailwind.config.js
git commit -m "feat: add brand colour tokens and Inter font to Tailwind config"
```

---

## Chunk 2: New Idea Layout

### Task 2: Create `layouts/idea.blade.php`

This layout is used only by the idea page. It provides:
- Inter font via Google Fonts
- Gradient background filling the full viewport
- New sticky, frosted nav (logo left; Example Prompts · Help · divider · Find a memory pill · avatar right)
- Alpine.js search overlay toggled by the pill
- No footer (kept clean and minimal)

**Files:**
- Create: `resources/views/layouts/idea.blade.php`

- [ ] **Step 1: Write a feature test** that asserts the idea index page loads for an authenticated user and contains key UI landmarks.

```bash
cat > /Users/rosstweedie/Sites/ideatub/tests/Feature/IdeaPageTest.php << 'EOF'
<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IdeaPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_idea_page_loads_for_authenticated_user(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('idea.index'));

        $response->assertStatus(200);
        $response->assertSee('IdeaTub');
        $response->assertSee('What are you thinking?');
        $response->assertSee('Store thought');
        $response->assertSee('Example Prompts');
        $response->assertSee('Help');
        $response->assertSee('Find a memory');
    }

    public function test_idea_page_redirects_guests(): void
    {
        $response = $this->get(route('idea.index'));

        $response->assertRedirect(route('login'));
    }
}
EOF
```

- [ ] **Step 2: Run the test to confirm it fails** (old UI not yet replaced)

```bash
cd /Users/rosstweedie/Sites/ideatub && php artisan test tests/Feature/IdeaPageTest.php --stop-on-failure 2>&1 | tail -30
```

Expected: FAIL — `assertSee('Store thought')` fails because the current submit button says "Save" (confirmed in `idea/index.blade.php` line 56).

- [ ] **Step 3: Create `resources/views/layouts/idea.blade.php`**

```blade
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>@yield('title', config('app.name', 'IdeaTub') . ' — Your thinking space')</title>

    <!-- Inter font -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap" rel="stylesheet">

    @if (file_exists(public_path('build/manifest.json')))
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    @endif
</head>
<body class="font-sans antialiased min-h-screen" style="background: linear-gradient(135deg, #eef2ff 0%, #f3f0ff 50%, #f0f5ff 100%);">

    {{-- Nav --}}
    <nav
        x-data="{ searching: false, query: '{{ old('q', $query ?? '') }}' }"
        class="sticky top-0 z-20 flex items-center justify-between px-6 md:px-8 py-4 border-b border-memory-violet/10"
        style="background: rgba(238,242,255,0.82); backdrop-filter: blur(12px);"
    >
        {{-- Logo --}}
        <a href="{{ route('idea.index') }}" class="text-xs font-semibold tracking-[0.1em] uppercase text-memory-violet flex-shrink-0">
            IdeaTub
        </a>

        {{-- Search overlay (shown when searching) --}}
        <form
            x-show="searching"
            x-transition
            method="GET"
            action="{{ route('idea.index') }}"
            class="absolute inset-x-0 top-0 bottom-0 flex items-center px-6 md:px-8 z-10"
            style="background: rgba(238,242,255,0.95); backdrop-filter: blur(12px);"
            @click.away="searching = false"
        >
            <div class="flex items-center gap-3 w-full max-w-lg mx-auto">
                <svg class="w-4 h-4 text-neural-teal flex-shrink-0" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                    <circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/>
                </svg>
                <input
                    type="search"
                    name="q"
                    x-model="query"
                    x-ref="searchInput"
                    x-init="$watch('searching', v => v && $nextTick(() => $refs.searchInput.focus()))"
                    placeholder="Find a memory…"
                    class="flex-1 bg-transparent border-none outline-none text-deep-indigo placeholder-slate-brand/50 text-sm"
                >
                <button type="button" @click="searching = false" class="text-slate-brand/60 hover:text-slate-brand text-xs">
                    Cancel
                </button>
            </div>
        </form>

        {{-- Right nav items --}}
        <div class="flex items-center gap-1" x-show="!searching">
            <a href="#" class="text-[12.5px] font-medium text-slate-brand hover:text-memory-violet hover:bg-memory-violet/8 px-3 py-1.5 rounded-lg transition-colors">
                Example Prompts
            </a>
            <a href="#" class="text-[12.5px] font-medium text-slate-brand hover:text-memory-violet hover:bg-memory-violet/8 px-3 py-1.5 rounded-lg transition-colors">
                Help
            </a>

            <div class="w-px h-4 bg-memory-violet/20 mx-2"></div>

            {{-- Search pill --}}
            <button
                @click="searching = true"
                class="flex items-center gap-1.5 text-xs text-slate-brand bg-white/70 border border-neural-teal/20 rounded-full px-3.5 py-1.5 hover:bg-white hover:border-neural-teal/40 transition-all"
            >
                <svg class="w-3 h-3 text-neural-teal" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                    <circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/>
                </svg>
                Find a memory
            </button>

            {{-- Avatar / logout --}}
            @auth
                <div x-data="{ open: false }" class="relative ml-1">
                    <button
                        @click="open = !open"
                        class="w-8 h-8 rounded-full text-white text-[11px] font-semibold flex items-center justify-center flex-shrink-0"
                        style="background: linear-gradient(135deg, #6D6AF7, #2A8C8C);"
                    >
                        {{ strtoupper(substr(auth()->user()->name ?? auth()->user()->email, 0, 2)) }}
                    </button>
                    <div
                        x-show="open"
                        x-transition
                        @click.away="open = false"
                        class="absolute right-0 mt-2 w-40 bg-white rounded-xl shadow-lg border border-memory-violet/10 py-1 z-30"
                    >
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit" class="w-full text-left px-4 py-2 text-sm text-slate-brand hover:text-deep-indigo hover:bg-memory-violet/5 transition-colors">
                                Log out
                            </button>
                        </form>
                    </div>
                </div>
            @endauth
        </div>
    </nav>

    {{-- Page content --}}
    <main>
        @yield('content')
    </main>

</body>
</html>
```

- [ ] **Step 4: Run the build** to ensure Tailwind compiles without errors

```bash
cd /Users/rosstweedie/Sites/ideatub && npm run build 2>&1 | tail -10
```

Expected: successful build, no errors.

- [ ] **Step 5: Commit**

```bash
git add resources/views/layouts/idea.blade.php tests/Feature/IdeaPageTest.php
git commit -m "feat: add idea layout with brand nav, search pill, frosted glass"
```

---

## Chunk 3: Redesigned `idea/index.blade.php`

### Task 3: Rewrite `idea/index.blade.php`

Replace the existing content with the new hero + capture box + thought cards. All existing server-side behaviour (flash messages, form actions, thought list, comments, search state) is preserved.

**Files:**
- Modify: `resources/views/idea/index.blade.php`

- [ ] **Step 1: Write additional feature test assertions** for the thought list rendering. Append to `tests/Feature/IdeaPageTest.php`:

```php
public function test_idea_page_shows_stored_thoughts(): void
{
    $user = User::factory()->create();
    $thought = \App\Models\Thought::factory()->create([
        'user_id' => $user->id,
        'content' => 'This is a test thought about semantic search',
    ]);

    $response = $this->actingAs($user)->get(route('idea.index'));

    $response->assertStatus(200);
    $response->assertSee('This is a test thought about semantic search');
    $response->assertSee('Recent thoughts');
}

public function test_idea_page_shows_search_results(): void
{
    $user = User::factory()->create();
    \App\Models\Thought::factory()->create([
        'user_id' => $user->id,
        'content' => 'pgvector is great for embeddings',
    ]);

    $response = $this->actingAs($user)->get(route('idea.index', ['q' => 'pgvector']));

    $response->assertStatus(200);
    $response->assertSee('pgvector is great for embeddings');
}
```

- [ ] **Step 2: Add `HasFactory` trait to the `Thought` model**

The `Thought` model (`app/Models/Thought.php`) does not yet use `HasFactory`. Add it so `Thought::factory()` works in tests.

In `app/Models/Thought.php`, add to the imports:
```php
use Illuminate\Database\Eloquent\Factories\HasFactory;
```

And add `use HasFactory;` inside the class body alongside the existing `use HasUuids;`.

- [ ] **Step 3: Check if a Thought factory exists**

```bash
ls /Users/rosstweedie/Sites/ideatub/database/factories/ThoughtFactory.php 2>/dev/null || echo "missing"
```

If missing, create it. Note: `user_id` is not included in `definition()` — callers must always pass it explicitly (as the tests below do), since `user_id` has a `NOT NULL` foreign key constraint.

```bash
cat > /Users/rosstweedie/Sites/ideatub/database/factories/ThoughtFactory.php << 'EOF'
<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class ThoughtFactory extends Factory
{
    public function definition(): array
    {
        return [
            'content'   => $this->faker->sentence(10),
            'metadata'  => null,
            'embedding' => null,
        ];
    }
}
EOF
```

- [ ] **Step 4: Run the tests to confirm current failures**

```bash
cd /Users/rosstweedie/Sites/ideatub && php artisan test tests/Feature/IdeaPageTest.php 2>&1 | tail -30
```

Expected: the `assertSee('Store thought')` test fails (old UI says "Save"), and the thought-factory tests may fail if `HasFactory` wasn't yet added.

- [ ] **Step 5: Replace `resources/views/idea/index.blade.php`** with the new design

```blade
@extends('layouts.idea')

@section('title', $query ? 'Search — IdeaTub' : 'IdeaTub')

@section('content')
<div class="max-w-[600px] mx-auto px-6 pt-16 pb-24">

    {{-- Flash messages --}}
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

    {{-- Hero --}}
    <p class="text-center text-[11px] font-semibold tracking-[0.12em] uppercase text-memory-violet mb-2.5">Your thinking space</p>
    <h1 class="text-center text-[28px] font-semibold text-deep-indigo leading-snug mb-1.5">A calm archive for your ideas</h1>
    <p class="text-center text-sm text-slate-brand mb-9">Capture thoughts before they disappear.</p>

    {{-- Capture box --}}
    <div
        x-data="{ content: '{{ old('content') }}' }"
        class="rounded-2xl border border-memory-violet/20 bg-white/80 backdrop-blur p-4 shadow-[0_4px_24px_rgba(109,106,247,0.08)] mb-3 transition-shadow focus-within:shadow-[0_4px_32px_rgba(109,106,247,0.16)] focus-within:border-memory-violet/50"
    >
        <form
            method="POST"
            action="{{ route('thoughts.store') }}"
            @keydown.meta.enter.prevent="$el.submit()"
        >
            @csrf
            <input type="hidden" name="parent_id" value="{{ isset($replyingTo) && $replyingTo ? $replyingTo->id : '' }}">

            @if (isset($replyingTo) && $replyingTo)
                <p class="text-xs text-slate-brand mb-2">
                    Replying to: <span class="text-deep-indigo">{{ Str::limit($replyingTo->content, 80) }}</span>
                    <a href="{{ route('idea.index') }}" class="text-memory-violet hover:underline ml-1">Cancel</a>
                </p>
            @endif

            <textarea
                name="content"
                id="content"
                rows="3"
                required
                x-model="content"
                @if($errors->has('content')) aria-describedby="content-error" aria-invalid="true" @endif
                placeholder="What are you thinking?"
                class="w-full bg-transparent border-none outline-none resize-none text-sm text-deep-indigo placeholder-slate-brand/40 leading-relaxed"
            >{{ old('content') }}</textarea>

            @error('content')
                <p id="content-error" class="mt-1 text-xs text-red-500">{{ $message }}</p>
            @enderror

            <div class="flex items-center justify-between mt-2.5 pt-2.5 border-t border-memory-violet/8">
                <span class="text-[11px] text-slate-brand/40">⌘ + Enter to store</span>
                <button
                    type="submit"
                    class="text-xs font-medium text-white px-4 py-1.5 rounded-lg transition-opacity hover:opacity-90"
                    style="background: linear-gradient(135deg, #6D6AF7, #2A8C8C);"
                >
                    Store thought
                </button>
            </div>
        </form>
    </div>

    {{-- Thoughts list --}}
    <div class="flex items-center justify-between mt-9 mb-3.5">
        <span class="text-[11px] font-semibold tracking-[0.1em] uppercase text-slate-brand/50">
            @if ($query)
                Results for "{{ e($query) }}"
            @else
                Recent thoughts
            @endif
        </span>
        <span class="text-[11px] text-slate-brand/30">{{ $thoughts->total() ?? count($thoughts) }} stored</span>
    </div>

    @forelse ($thoughts as $thought)
        @php
            $tags = $thought->metadata['tags'] ?? [];
            $tagColors = ['violet', 'teal', 'indigo'];
            $tagMap = [
                'violet' => 'bg-memory-violet/10 text-memory-violet',
                'teal'   => 'bg-neural-teal/10 text-neural-teal',
                'indigo' => 'bg-deep-indigo/8 text-slate-brand',
            ];
        @endphp

        <div class="rounded-xl border border-memory-violet/10 bg-white/68 backdrop-blur px-4 py-3.5 mb-2 hover:bg-white/90 hover:border-memory-violet/20 hover:shadow-[0_2px_12px_rgba(109,106,247,0.08)] transition-all cursor-pointer">

            @if ($thought->parent_id && $thought->relationLoaded('parent') && $thought->parent)
                <p class="text-[11px] text-slate-brand/50 mb-1">
                    Comment on: {{ Str::limit($thought->parent->content, 80) }}
                </p>
            @endif

            <p class="text-[13.5px] text-deep-indigo leading-relaxed mb-2">{{ e($thought->content) }}</p>

            <div class="flex items-center gap-2 flex-wrap">
                <span class="text-[10.5px] text-slate-brand/40">{{ $thought->created_at->diffForHumans() }}</span>

                @foreach ($tags as $i => $tag)
                    <span class="text-[10px] font-medium px-2 py-0.5 rounded-full {{ $tagMap[$tagColors[$i % 3]] }}">
                        #{{ $tag }}
                    </span>
                @endforeach

                @if (!$thought->parent_id)
                    <a href="{{ route('idea.index', ['parent_id' => $thought->id]) }}"
                       class="text-[10.5px] text-memory-violet/60 hover:text-memory-violet transition-colors ml-auto">
                        Reply
                    </a>
                @endif
            </div>

            {{-- Nested comments --}}
            @if ($thought->relationLoaded('comments') && $thought->comments->isNotEmpty())
                <ul class="mt-3 ml-3 pl-3 border-l border-memory-violet/15 space-y-2">
                    @foreach ($thought->comments as $comment)
                        <li>
                            <p class="text-[12.5px] text-slate-brand leading-relaxed">{{ e(Str::limit($comment->content, 200)) }}</p>
                            <p class="text-[10px] text-slate-brand/40 mt-0.5">{{ $comment->created_at->diffForHumans() }}</p>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>

    @empty
        <div class="rounded-xl border border-memory-violet/10 bg-white/50 px-4 py-8 text-center text-sm text-slate-brand/50">
            @if ($query)
                No thoughts match your search. Try different words or capture a new one above.
            @else
                No thoughts yet. What are you thinking?
            @endif
        </div>
    @endforelse

    {{-- Pagination / load more --}}
    @if ($thoughts instanceof \Illuminate\Pagination\LengthAwarePaginator && $thoughts->hasMorePages())
        <div class="text-center pt-4">
            {{ $thoughts->links() }}
        </div>
    @endif

</div>
@endsection
```

- [ ] **Step 6: Run the full build** to confirm Tailwind JIT picks up all new classes

```bash
cd /Users/rosstweedie/Sites/ideatub && npm run build 2>&1 | tail -10
```

Expected: build succeeds, no errors.

- [ ] **Step 7: Run feature tests**

```bash
cd /Users/rosstweedie/Sites/ideatub && php artisan test tests/Feature/IdeaPageTest.php 2>&1 | tail -30
```

Expected: all tests pass.

- [ ] **Step 8: Smoke-test in the browser at https://ideatub.test**

Verify:
- Gradient background fills viewport
- Nav: IdeaTub logo (left), Example Prompts · Help · divider · Find a memory pill · avatar (right)
- Clicking "Find a memory" pill opens the search overlay input
- Capture box is centred with frosted glass style
- ⌘+Enter submits the form
- Thought cards render with timestamp and tags (if any metadata present)
- Reply link appears on top-level thoughts
- Flash success/error messages appear styled correctly

- [ ] **Step 9: Commit**

```bash
git add app/Models/Thought.php resources/views/idea/index.blade.php tests/Feature/IdeaPageTest.php database/factories/ThoughtFactory.php
git commit -m "feat: redesign main idea page with brand gradient, frosted capture box, soft thought cards"
```

---

## Chunk 4: Cleanup

### Task 4: Add `.superpowers/` to `.gitignore`

**Files:**
- Modify: `.gitignore`

- [ ] **Step 1: Check if `.superpowers/` is already ignored**

```bash
grep -n "superpowers" /Users/rosstweedie/Sites/ideatub/.gitignore || echo "not found"
```

- [ ] **Step 2: If not found, append it**

```bash
echo ".superpowers/" >> /Users/rosstweedie/Sites/ideatub/.gitignore
```

- [ ] **Step 3: Commit**

```bash
git add .gitignore
git commit -m "chore: ignore .superpowers/ brainstorm directory"
```

---

## Chunk 5: Auth Pages (Login + Register)

Both auth pages currently extend `layouts.app`, have wrong titles ("PDF Tool Suite"), use `bg-gray-50` and generic `bg-indigo-600` — visually disconnected from the main app. This chunk creates a shared `layouts/auth.blade.php` and updates both views to match the brand.

### Task 5: Create `layouts/auth.blade.php`

**Files:**
- Create: `resources/views/layouts/auth.blade.php`

- [ ] **Step 1: Write a feature test** for both auth pages rendering with brand elements

```bash
cat > /Users/rosstweedie/Sites/ideatub/tests/Feature/AuthPagesTest.php << 'EOF'
<?php

namespace Tests\Feature;

use Tests\TestCase;

class AuthPagesTest extends TestCase
{
    public function test_login_page_shows_brand_identity(): void
    {
        $response = $this->get(route('login'));

        $response->assertStatus(200);
        $response->assertSee('IdeaTub');
        $response->assertSee('Sign in');
        $response->assertSee('Google');
        $response->assertSee('GitHub');
    }

    public function test_register_page_shows_brand_identity(): void
    {
        $response = $this->get(route('register'));

        $response->assertStatus(200);
        $response->assertSee('IdeaTub');
        $response->assertSee('Create your account');
        $response->assertSee('Google');
        $response->assertSee('GitHub');
    }
}
EOF
```

- [ ] **Step 2: Run tests to confirm they currently pass** (pages load) but note the missing brand identity

```bash
cd /Users/rosstweedie/Sites/ideatub && php artisan test tests/Feature/AuthPagesTest.php 2>&1 | tail -20
```

Expected: PASS (pages load and contain those strings already — the test baseline is correct).

- [ ] **Step 3: Create `resources/views/layouts/auth.blade.php`**

```blade
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>@yield('title', config('app.name', 'IdeaTub'))</title>

    <!-- Inter font -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap" rel="stylesheet">

    @if (file_exists(public_path('build/manifest.json')))
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    @endif
</head>
<body class="font-sans antialiased min-h-screen flex flex-col items-center justify-center px-4 py-12"
      style="background: linear-gradient(135deg, #eef2ff 0%, #f3f0ff 50%, #f0f5ff 100%);">

    <!-- Logo -->
    <a href="{{ route('home') }}" class="text-xs font-semibold tracking-[0.1em] uppercase text-memory-violet mb-8 block">
        IdeaTub
    </a>

    <!-- Card -->
    <div class="w-full max-w-sm bg-white/80 backdrop-blur rounded-2xl border border-memory-violet/20 shadow-[0_4px_24px_rgba(109,106,247,0.08)] p-8">
        @yield('content')
    </div>

</body>
</html>
```

- [ ] **Step 4: Commit the layout**

```bash
git add resources/views/layouts/auth.blade.php tests/Feature/AuthPagesTest.php
git commit -m "feat: add auth layout with brand gradient and frosted card"
```

---

### Task 6: Restyle `login.blade.php`

**Files:**
- Modify: `resources/views/auth/login.blade.php`

- [ ] **Step 1: Replace `resources/views/auth/login.blade.php`**

```blade
@extends('layouts.auth')

@section('title', 'Sign in — IdeaTub')

@section('content')
    <h2 class="text-xl font-semibold text-deep-indigo text-center mb-6">Sign in to IdeaTub</h2>

    <form method="POST" action="{{ route('login') }}" class="space-y-4">
        @csrf

        <div>
            <label for="email" class="block text-xs font-medium text-slate-brand mb-1">Email</label>
            <input id="email" name="email" type="email" autocomplete="email" required
                   value="{{ old('email') }}"
                   placeholder="you@example.com"
                   class="w-full rounded-lg border border-memory-violet/20 bg-white/60 px-3 py-2.5 text-sm text-deep-indigo placeholder-slate-brand/40 focus:border-memory-violet/50 focus:ring-2 focus:ring-memory-violet/20 outline-none transition">
            @error('email')
                <p class="mt-1 text-xs text-red-500">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label for="password" class="block text-xs font-medium text-slate-brand mb-1">Password</label>
            <input id="password" name="password" type="password" autocomplete="current-password" required
                   placeholder="••••••••"
                   class="w-full rounded-lg border border-memory-violet/20 bg-white/60 px-3 py-2.5 text-sm text-deep-indigo placeholder-slate-brand/40 focus:border-memory-violet/50 focus:ring-2 focus:ring-memory-violet/20 outline-none transition">
            @error('password')
                <p class="mt-1 text-xs text-red-500">{{ $message }}</p>
            @enderror
        </div>

        <div class="flex items-center justify-between">
            <label class="flex items-center gap-2 text-xs text-slate-brand cursor-pointer">
                <input name="remember" type="checkbox" class="rounded border-memory-violet/30 text-memory-violet focus:ring-memory-violet/30">
                Remember me
            </label>
            <a href="{{ route('password.request') }}" class="text-xs text-memory-violet hover:opacity-80 transition">
                Forgot password?
            </a>
        </div>

        <button type="submit"
                class="w-full py-2.5 rounded-lg text-sm font-medium text-white transition hover:opacity-90"
                style="background: linear-gradient(135deg, #6D6AF7, #2A8C8C);">
            Sign in
        </button>
    </form>

    <!-- Divider -->
    <div class="relative my-6">
        <div class="absolute inset-0 flex items-center">
            <div class="w-full border-t border-memory-violet/10"></div>
        </div>
        <div class="relative flex justify-center">
            <span class="bg-white/80 px-3 text-xs text-slate-brand/50">or continue with</span>
        </div>
    </div>

    <!-- OAuth -->
    <div class="grid grid-cols-2 gap-3">
        <a href="{{ route('auth.google') }}"
           class="flex items-center justify-center gap-2 py-2 px-3 rounded-lg border border-memory-violet/15 bg-white/60 text-xs font-medium text-slate-brand hover:bg-white hover:border-memory-violet/30 transition">
            <svg class="h-4 w-4" viewBox="0 0 24 24">
                <path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/>
                <path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/>
                <path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"/>
                <path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"/>
            </svg>
            Google
        </a>
        <a href="{{ route('auth.github') }}"
           class="flex items-center justify-center gap-2 py-2 px-3 rounded-lg border border-memory-violet/15 bg-white/60 text-xs font-medium text-slate-brand hover:bg-white hover:border-memory-violet/30 transition">
            <svg class="h-4 w-4" fill="currentColor" viewBox="0 0 24 24">
                <path fill-rule="evenodd" d="M12 2C6.477 2 2 6.484 2 12.017c0 4.425 2.865 8.18 6.839 9.504.5.092.682-.217.682-.483 0-.237-.008-.868-.013-1.703-2.782.605-3.369-1.343-3.369-1.343-.454-1.158-1.11-1.466-1.11-1.466-.908-.62.069-.608.069-.608 1.003.07 1.531 1.032 1.531 1.032.892 1.53 2.341 1.088 2.91.832.092-.647.35-1.088.636-1.338-2.22-.253-4.555-1.113-4.555-4.951 0-1.093.39-1.988 1.029-2.688-.103-.253-.446-1.272.098-2.65 0 0 .84-.27 2.75 1.026A9.564 9.564 0 0112 6.844c.85.004 1.705.115 2.504.337 1.909-1.296 2.747-1.027 2.747-1.027.546 1.379.202 2.398.1 2.651.64.7 1.028 1.595 1.028 2.688 0 3.848-2.339 4.695-4.566 4.943.359.309.678.92.678 1.855 0 1.338-.012 2.419-.012 2.747 0 .268.18.58.688.482A10.019 10.019 0 0022 12.017C22 6.484 17.522 2 12 2z" clip-rule="evenodd"/>
            </svg>
            GitHub
        </a>
    </div>

    <p class="mt-6 text-center text-xs text-slate-brand/50">
        No account?
        <a href="{{ route('register') }}" class="text-memory-violet hover:opacity-80 transition font-medium">Sign up</a>
    </p>
@endsection
```

- [ ] **Step 2: Run the tests**

```bash
cd /Users/rosstweedie/Sites/ideatub && php artisan test tests/Feature/AuthPagesTest.php 2>&1 | tail -20
```

Expected: all tests pass.

- [ ] **Step 3: Commit**

```bash
git add resources/views/auth/login.blade.php
git commit -m "feat: restyle login page with brand layout and Inter font"
```

---

### Task 7: Restyle `register.blade.php`

**Files:**
- Modify: `resources/views/auth/register.blade.php`

- [ ] **Step 1: Replace `resources/views/auth/register.blade.php`**

```blade
@extends('layouts.auth')

@section('title', 'Create account — IdeaTub')

@section('content')
    <h2 class="text-xl font-semibold text-deep-indigo text-center mb-6">Create your account</h2>

    <form method="POST" action="{{ route('register') }}" class="space-y-4">
        @csrf

        <div>
            <label for="name" class="block text-xs font-medium text-slate-brand mb-1">Name</label>
            <input id="name" name="name" type="text" autocomplete="name" required
                   value="{{ old('name') }}"
                   placeholder="Your name"
                   class="w-full rounded-lg border border-memory-violet/20 bg-white/60 px-3 py-2.5 text-sm text-deep-indigo placeholder-slate-brand/40 focus:border-memory-violet/50 focus:ring-2 focus:ring-memory-violet/20 outline-none transition">
            @error('name')
                <p class="mt-1 text-xs text-red-500">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label for="email" class="block text-xs font-medium text-slate-brand mb-1">Email</label>
            <input id="email" name="email" type="email" autocomplete="email" required
                   value="{{ old('email') }}"
                   placeholder="you@example.com"
                   class="w-full rounded-lg border border-memory-violet/20 bg-white/60 px-3 py-2.5 text-sm text-deep-indigo placeholder-slate-brand/40 focus:border-memory-violet/50 focus:ring-2 focus:ring-memory-violet/20 outline-none transition">
            @error('email')
                <p class="mt-1 text-xs text-red-500">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label for="password" class="block text-xs font-medium text-slate-brand mb-1">Password</label>
            <input id="password" name="password" type="password" autocomplete="new-password" required
                   placeholder="••••••••"
                   class="w-full rounded-lg border border-memory-violet/20 bg-white/60 px-3 py-2.5 text-sm text-deep-indigo placeholder-slate-brand/40 focus:border-memory-violet/50 focus:ring-2 focus:ring-memory-violet/20 outline-none transition">
            @error('password')
                <p class="mt-1 text-xs text-red-500">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label for="password_confirmation" class="block text-xs font-medium text-slate-brand mb-1">Confirm password</label>
            <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required
                   placeholder="••••••••"
                   class="w-full rounded-lg border border-memory-violet/20 bg-white/60 px-3 py-2.5 text-sm text-deep-indigo placeholder-slate-brand/40 focus:border-memory-violet/50 focus:ring-2 focus:ring-memory-violet/20 outline-none transition">
        </div>

        <button type="submit"
                class="w-full py-2.5 rounded-lg text-sm font-medium text-white transition hover:opacity-90"
                style="background: linear-gradient(135deg, #6D6AF7, #2A8C8C);">
            Create account
        </button>
    </form>

    <!-- Divider -->
    <div class="relative my-6">
        <div class="absolute inset-0 flex items-center">
            <div class="w-full border-t border-memory-violet/10"></div>
        </div>
        <div class="relative flex justify-center">
            <span class="bg-white/80 px-3 text-xs text-slate-brand/50">or continue with</span>
        </div>
    </div>

    <!-- OAuth -->
    <div class="grid grid-cols-2 gap-3">
        <a href="{{ route('auth.google') }}"
           class="flex items-center justify-center gap-2 py-2 px-3 rounded-lg border border-memory-violet/15 bg-white/60 text-xs font-medium text-slate-brand hover:bg-white hover:border-memory-violet/30 transition">
            <svg class="h-4 w-4" viewBox="0 0 24 24">
                <path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/>
                <path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/>
                <path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"/>
                <path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"/>
            </svg>
            Google
        </a>
        <a href="{{ route('auth.github') }}"
           class="flex items-center justify-center gap-2 py-2 px-3 rounded-lg border border-memory-violet/15 bg-white/60 text-xs font-medium text-slate-brand hover:bg-white hover:border-memory-violet/30 transition">
            <svg class="h-4 w-4" fill="currentColor" viewBox="0 0 24 24">
                <path fill-rule="evenodd" d="M12 2C6.477 2 2 6.484 2 12.017c0 4.425 2.865 8.18 6.839 9.504.5.092.682-.217.682-.483 0-.237-.008-.868-.013-1.703-2.782.605-3.369-1.343-3.369-1.343-.454-1.158-1.11-1.466-1.11-1.466-.908-.62.069-.608.069-.608 1.003.07 1.531 1.032 1.531 1.032.892 1.53 2.341 1.088 2.91.832.092-.647.35-1.088.636-1.338-2.22-.253-4.555-1.113-4.555-4.951 0-1.093.39-1.988 1.029-2.688-.103-.253-.446-1.272.098-2.65 0 0 .84-.27 2.75 1.026A9.564 9.564 0 0112 6.844c.85.004 1.705.115 2.504.337 1.909-1.296 2.747-1.027 2.747-1.027.546 1.379.202 2.398.1 2.651.64.7 1.028 1.595 1.028 2.688 0 3.848-2.339 4.695-4.566 4.943.359.309.678.92.678 1.855 0 1.338-.012 2.419-.012 2.747 0 .268.18.58.688.482A10.019 10.019 0 0022 12.017C22 6.484 17.522 2 12 2z" clip-rule="evenodd"/>
            </svg>
            GitHub
        </a>
    </div>

    <p class="mt-6 text-center text-xs text-slate-brand/50">
        Already have an account?
        <a href="{{ route('login') }}" class="text-memory-violet hover:opacity-80 transition font-medium">Sign in</a>
    </p>
@endsection
```

- [ ] **Step 2: Run the tests**

```bash
cd /Users/rosstweedie/Sites/ideatub && php artisan test tests/Feature/AuthPagesTest.php 2>&1 | tail -20
```

Expected: all tests pass.

- [ ] **Step 3: Smoke-test both pages in the browser**

Verify at `https://ideatub.test/login` and `https://ideatub.test/register`:
- Gradient background matches the main app
- IdeaTub logo above the card links back to home
- Frosted white card, Inter font, brand-coloured inputs and button
- Google + GitHub OAuth buttons present and functional
- "Sign up" / "Sign in" cross-links work

- [ ] **Step 4: Commit**

```bash
git add resources/views/auth/register.blade.php
git commit -m "feat: restyle register page with brand layout and Inter font"
```

---

## Done

All five chunks produce a consistent, on-brand experience across the full user journey:
- **Tailwind** extended with brand colour tokens and Inter font
- **Main app page** — gradient background, frosted capture box, soft thought cards, right-aligned nav
- **Auth pages** — same gradient, centred frosted card, IdeaTub logo, matching inputs and OAuth buttons
- All existing server-side behaviour (search, capture, comments, OAuth, flash messages) preserved
