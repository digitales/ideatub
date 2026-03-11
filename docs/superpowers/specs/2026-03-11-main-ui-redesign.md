# IdeaTub — Main UI Redesign Spec
Date: 2026-03-11

## Overview

Redesign the main app interface (`idea/index.blade.php`) using the brand guidelines, Tailwind CSS, and a minimal, calm aesthetic. The page centres on a single thought-capture input with a list of recent thoughts below.

---

## Design Decisions

| Decision | Choice |
|----------|--------|
| Visual direction | Soft gradient (indigo→violet wash, frosted glass) |
| Thought list style | Soft frosted cards with timestamp + tags |
| Search placement | Small pill in the nav bar (right side) |

---

## Brand Tokens

Apply these across the UI via the Tailwind config:

| Token | Value | Usage |
|-------|-------|-------|
| `deep-indigo` | `#1E2547` | Headings, primary text |
| `neural-teal` | `#2A8C8C` | Search pill, teal tags, accents |
| `memory-violet` | `#6D6AF7` | Logo, buttons, violet tags, focus rings |
| `cloud-white` | `#F5F7FB` | Base background tint |
| `slate` | `#5B6472` | Body text, nav links |

**Gradient:** `linear-gradient(135deg, #6D6AF7, #2A8C8C)` — used on the Store button and avatar.

**Background:** `linear-gradient(135deg, #eef2ff 0%, #f3f0ff 50%, #f0f5ff 100%)`

**Font:** Inter (replace current Figtree default in `tailwind.config.js`)

---

## Layout

### Navigation (sticky, frosted)
- **Left:** Logo — `IDEATUB` in uppercase, Memory Violet, semibold
- **Right (in order):** Example Prompts link · Help link · divider · Find a memory pill · user avatar
- Background: semi-transparent with `backdrop-blur`
- Bottom border: faint violet tint

### Main Content (centred, max-width 600px)
1. **Hero area**
   - Small uppercase label: "Your thinking space" (Memory Violet)
   - Heading: "A calm archive for your ideas" (Deep Indigo, 28px semibold)
   - Subtext: "Capture thoughts before they disappear." (Slate)

2. **Capture box** (frosted glass card)
   - Multi-line textarea, no border, transparent background
   - Placeholder: "What are you thinking?"
   - Footer row: `⌘ + Enter to store` hint (left) + "Store thought" gradient button (right)
   - Focus state: stronger violet border + deeper shadow

3. **Thoughts list**
   - Section header: "Recent thoughts" label (left) + count (right)
   - Each thought: frosted card with body text, timestamp, and colour-coded tag pill
   - Tag colours: violet (`#dev`, `#product`), teal (`#todo`), slate (other)
   - "Show more →" footer link

### Search (pill in nav)
- Clicking the "Find a memory" pill opens a search experience (implementation detail TBD — modal or inline expansion)
- Pill shows a teal search icon + label

---

## Tailwind Config Changes

- Add `fontFamily.sans: ['Inter', ...defaultTheme.fontFamily.sans]`
- Extend `colors` with brand tokens (`deep-indigo`, `neural-teal`, `memory-violet`, `cloud-white`, `slate`)
- Add Inter via Google Fonts in the main layout

---

## Files to Change

| File | Change |
|------|--------|
| `tailwind.config.js` | Add Inter font, brand color tokens |
| `resources/views/layouts/app.blade.php` | New nav markup, Inter font import, gradient background |
| `resources/views/idea/index.blade.php` | New hero + capture box + thought cards |
| `resources/css/app.css` | Any custom utilities (gradient bg, frosted glass helper) |

---

## Out of Scope

- Search results UI (beyond the pill trigger)
- Mobile responsive breakpoints (follow-up)
- Dark mode (follow-up)
- Other pages (login, pricing, dashboard) — styled separately later
