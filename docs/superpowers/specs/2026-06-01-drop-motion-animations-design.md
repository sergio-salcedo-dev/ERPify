# Drop `motion` from the PWA, re-express animations with CSS

**Date:** 2026-06-01
**Scope:** `pwa/`
**Type:** refactor (dependency removal + animation rework)
**Branch:** `refactor/pwa-drop-motion-animations` (off `main`, in worktree `.claude/worktrees/refactor+pwa-drop-motion-animations`)

## Goal

Remove the `motion` dependency (`motion` `^12.40.0`, framer's `motion/react`) from the
PWA entirely, replacing every animation it powers with utilities already present in the
bundle — `tw-animate-css` (imported at `pwa/src/app/globals.css:2`, used app-wide by
dialog / dropdown / spinner) — or plain Tailwind / CSS. The payoff is a real code **and**
dependency reduction: `motion` leaves `package.json`.

## Why this scope

`motion/react` is imported in exactly four files (grep-confirmed; none in tests):

- `pwa/src/app/page.tsx`
- `pwa/src/app/_components/Navbar.tsx`
- `pwa/src/app/_components/FeatureCard.tsx`
- `pwa/src/components/erpify/StatCard.tsx` — **backoffice** dashboard primitive

Dropping only the landing-page *entrance* animations, or even all landing animations,
leaves `StatCard` importing `motion`, so the dependency would stay in `package.json`.
The dependency only actually leaves if **all four** files are converted. Hence the scope
reaches one backoffice file by necessity.

## Replacements (per file)

| File | Current (motion) | Replacement | Visual delta |
|---|---|---|---|
| `app/page.tsx` | hero `<motion.h1>` / `<motion.p>` fade + slide-up (subtitle `delay: 0.1`); health-status `<motion.div>` `height: 0 → auto` + fade | `<h1>` / `<p>` + `animate-in fade-in-0 slide-in-from-bottom-4 duration-700` (subtitle adds `delay-100 fill-mode-both`); health `<div>` + `animate-in fade-in-0 slide-in-from-top-1 duration-300` | none meaningful; health uses fade/slide instead of height-grow (it mounts on button click → enter-only, no collapse needed) |
| `app/_components/FeatureCard.tsx` | `<motion.div whileHover={{ scale: 1.02 }}>` | `<div>` + `transition-transform duration-200 hover:scale-[1.02]` | identical |
| `app/_components/Navbar.tsx` | mobile menu `AnimatePresence` enter **and exit** (`y: -10` ⇄ `0`, fade) | conditional `<div>` + `animate-in fade-in-0 slide-in-from-top-2 duration-200` | **exit animation dropped** — menu closes instantly on toggle |
| `components/erpify/StatCard.tsx` | fade + slide-up, staggered `transition={{ delay: index * 0.1 }}` | `<div>` + `animate-in fade-in-0 slide-in-from-bottom-4 fill-mode-both duration-500` + inline `style={{ animationDelay: \`${index * 100}ms\` }}` | identical staggered cascade; `index` prop retained |

After the four conversions: remove `"motion"` from `pwa/package.json` dependencies,
refresh the lockfile (`npm install` / `npm ci` via `make pwa.install`), and confirm
`grep -rn "motion/react" pwa/src/` returns nothing.

## Deliberate calls

1. **Mobile menu loses its exit animation.** Preserving exit-on-close in CSS requires
   keeping the node mounted plus a delayed-unmount mechanism — *more* code, which defeats
   the goal. Instant close on toggle is standard for a hamburger menu and is the right
   trade. Enter animation is preserved via `tw-animate-css`.
2. **StatCard stagger preserved** via inline `animationDelay` computed from the numeric
   `index` prop (no user input → no XSS surface). `fill-mode-both` prevents a
   pre-delay opacity flash. `"use client"` may be dropped from `StatCard` if no hooks
   remain after conversion and the build stays green; otherwise leave it.

## Free properties

- **Reduced motion:** the global `@media (prefers-reduced-motion: reduce)` block at
  `pwa/src/app/globals.css:303` already zeroes `animation-duration` / `transition-duration`,
  so all CSS replacements inherit reduced-motion handling that previously lived inside
  `motion`. No per-component handling needed.
- **Design language intact:** the raw-palette landing surface and BEM block names are
  untouched; only the animation engine changes.
- **Test IDs intact:** `data-testid="frontoffice-health-status"` is preserved on the
  converted health `<div>`, so `tests/e2e/frontoffice/landing.spec.ts` and
  `tests/e2e/helpers/health-assertions.ts` keep passing. No test imports `motion`.

## Verification

1. `grep -rn "motion/react" pwa/src/` → empty.
2. `grep` for `"motion"` in `pwa/package.json` → absent; lockfile refreshed.
3. `make pwa.quality` (ESLint + Prettier) → clean.
4. `make pwa.production.build` → succeeds (proves `tw-animate-css` classes survive
   purge and TypeScript is happy with the `<div>` conversions + inline style).
5. Landing E2E (`pwa/tests/e2e/frontoffice/landing.spec.ts`) → green.
6. Visual eyeball on the running stack: hero fade-up, feature-card hover scale, mobile
   menu open, and the backoffice dashboard stat-card staggered cascade.

## Out of scope

- `tw-animate-css` stays — it backs core UI primitives (dialog, dropdown, spinner)
  across the whole app and is not removable by touching animations here.
- No changes to the visual design, copy, layout, or colour palette.
- No new animation library or custom keyframes added to `globals.css`.
