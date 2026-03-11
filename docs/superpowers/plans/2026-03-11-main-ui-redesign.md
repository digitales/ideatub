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

## Done

All four chunks produce a working, visually redesigned idea page that:
- Uses brand colours (Memory Violet, Neural Teal, Deep Indigo, Slate) via Tailwind tokens
- Loads Inter from Google Fonts
- Has a sticky frosted nav with right-aligned links, search pill, and avatar dropdown
- Centres on a single frosted-glass capture textarea
- Displays thoughts as soft cards with tags and timestamps
- Preserves all existing server-side behaviour (search, capture, comments, flash messages)
