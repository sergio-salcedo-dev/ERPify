# Backoffice App Shell — chrome hardening (design)

**Date:** 2026-06-03
**Status:** approved (brainstorming) — ready for implementation plan
**Surface:** `pwa/src/app/backoffice/BackOfficeLayoutClient.tsx` (the backoffice chrome) + the shared `pwa/src/components/erpify/SidebarItem.tsx`
**Phase:** Part of the broader backoffice UI/UX overhaul. This is **Phase A (App shell / chrome)**; phase B (Dashboard) shipped 2026-06-03 (PR #129). Phases C (entity pattern) and D (design-system hardening) are deferred to their own spec → plan → PR cycles.

## Context

The backoffice chrome is rendered by the **bespoke** client component
`BackOfficeLayoutClient` (a desktop sidebar + a mobile header/`Sheet`). It works,
but it is missing the table-stakes app-shell affordances and carries token
violations:

- **No desktop top bar.** Only a mobile header exists; on desktop the page content
  starts flush under the viewport.
- **Sidebar collapse is not persisted** and has **no keyboard shortcut**. The
  `isCompact` state resets to expanded on every reload, and there are two awkward
  collapse controls (a header chevron + a floating expand chevron).
- **No skip-to-content link** for keyboard / screen-reader users.
- **Token violations:** `font-bold` (weight 700) appears 8× — DESIGN.md caps the
  system at weight 600. `rounded-xl` (12 px, a *panel* radius) is used on nav rows,
  which DESIGN.md classes as functional/list elements (`--radius-md` 6 px).

A **`<AppShell>` primitive already exists** (`@/components/erpify/AppShell`,
barrel-exported, documented in `pwa/CLAUDE.md`) and already implements four of the
five items below — skip link, persisted collapse (`erpify:sidebar-open`),
`Cmd/Ctrl+B`, and a desktop top bar. **But nothing imports it**, and its nav model
is *flat* (`href`/`label`/`icon`/`active`, real `<a>` links) with **no nav groups,
no expandable sub-items, no mobile sheet, no logout** — all of which the live
backoffice and its e2e suite (`tests/e2e/backoffice/sidebar.spec.ts`) depend on.

**Owner intent (decided during brainstorming):**

1. **Enhance the bespoke `BackOfficeLayoutClient` in place** rather than adopt or
   extend `AppShell`. This keeps the change surgical, preserves the grouped nav +
   expandable sub-items + mobile sheet + logout, and keeps the e2e suite green.
   `AppShell` is left untouched (see *AppShell disposition*).
2. The new top bar **may carry fake, active-looking** search / notifications /
   user-auth controls (see *Recorded design-system override*).

## Recorded design-system override

DESIGN.md's first principle is **"Honest over delightful. No optimistic theater."**
— the same principle that, one commit earlier (Phase B), drove the removal of the
fabricated `Welcome back, Admin` subtitle and the invented KPI tiles.

The owner has **deliberately chosen** to give the new top bar **fully-fake,
active-looking** controls: a search affordance, a notifications bell, and a
user/auth control that render at full opacity with real hover/focus states but are
wired to nothing yet (no-op on click). This is a **conscious, scoped override** of
the "no optimistic theater" principle, limited to the **shell chrome**. The Phase B
dashboard stays honest; this override does not extend to page content.

Rationale on record: the owner wants the shell to *look* like a complete ERP top bar
now; the real behaviors are expected to land soon. When auth / notifications / search
ship, those controls gain real behavior and the override naturally resolves (see
*Future hook*).

## Decision

Five changes to the bespoke layout, plus one shared-primitive radius fix.

### 1. Desktop top bar (new)

A new `<header>` inside the main column, **desktop-only** (`hidden md:flex`), height
`h-16` so its bottom border aligns with the sidebar header's. Token-styled
(`bg-card`, `border-b border-border`).

- **Left:** a single collapse toggle (`PanelLeftClose` / `PanelLeftOpen`,
  `<Button variant="ghost" size="icon-sm">`) followed by the **current section
  title** derived from the route (`Dashboard` / `Banks` / `Service Health` /
  `Dev Tools` / `Administration` / `User Profile`) via a small pure helper
  `sectionTitleFor(pathname)`.
- **Right:** three fake-but-active icon buttons — **Search**, **Bell**
  (notifications, with a small static unread dot — purely decorative, `aria-hidden`
  — to reinforce the "active-looking" intent), and **User** (auth) — each a
  `<Button variant="ghost" size="icon-sm">` at full opacity with
  hover/focus states and a no-op `onClick`. Each still satisfies the a11y rules
  (descriptive `title`, short static `aria-label`, `aria-hidden` on the decorative
  icon). Default: **Search is an icon button** (minimal code); a real-looking
  search *input* is an acceptable alternative if preferred at implementation time.

### 2. Sidebar collapse: persist + `Cmd/Ctrl+B` + single toggle

- **Persist** the collapsed state to `localStorage` under **`erpify:sidebar-open`**
  (`"1"` = expanded, `"0"` = compact) — the **same key and semantics `AppShell`
  already uses**, so the stored value stays compatible if `AppShell` is ever adopted
  later. SSR fallback = expanded; the `localStorage` read happens in the `useState`
  initializer (client only), mirroring `AppShell` (same minor hydration tradeoff —
  acceptable for an internal tool).
- **`Cmd/Ctrl+B`** — a global `keydown` listener toggles collapse and calls
  `preventDefault()`, mirroring `AppShell`'s effect.
- **Consolidate to one toggle:** the **top-bar** button becomes the single desktop
  collapse control. Remove the two in-sidebar chevrons (the header `ChevronLeft` and
  the floating `ChevronRight` expand button). No e2e covers those controls, so
  nothing breaks.

### 3. Skip-to-content link

Add, as the **first focusable element** of the layout, an
`<a href="#main-content">Skip to main content</a>` that is visually hidden until
focused (the token-styled `sr-only focus:not-sr-only …` pattern `AppShell` already
uses). Add `id="main-content"` to the existing `<main>`. Works on mobile and desktop.

### 4. Token-compliance fixes

- **`font-bold` → `font-semibold`** (weight 700 → 600) for all 8 occurrences in
  `BackOfficeLayoutClient` (desktop + mobile nav-group labels and mobile nav links).
- **`rounded-xl` → `rounded-md`** on nav rows: `SidebarItem` (the primary nav
  button) and the mobile primary nav links. Normalize the `rounded-lg` sub-item rows
  to `rounded-md` too, so all nav rows share one functional radius.

### 5. Mobile

Structurally unchanged: the existing `h-14` mobile header + `Sheet` stay (grouped
nav, sub-items, logout). They inherit the §4 font/radii fixes. The new top bar is
`hidden md:flex`, so mobile chrome is otherwise untouched.

## Scope (files touched)

1. **`pwa/src/app/backoffice/BackOfficeLayoutClient.tsx`** — add the top bar; add
   collapse persistence + `Cmd/Ctrl+B`; remove the two in-sidebar chevron toggles;
   add the skip link + `id="main-content"`; apply the `font-bold`/`rounded-*` fixes.
2. **`pwa/src/components/erpify/SidebarItem.tsx`** — one radius fix
   (`rounded-xl` → `rounded-md`); used only by the backoffice, so the change is
   contained.
3. **`pwa/src/app/backoffice/_lib/sectionTitle.ts`** (new, small pure helper) +
   **`pwa/tests/.../sectionTitle.test.ts`** (vitest) — route → section-title map.
4. **e2e** — extend `tests/e2e/backoffice/sidebar.spec.ts` (or a new
   `app-shell.spec.ts`) with the new assertions below.

## AppShell disposition

`@/components/erpify/AppShell` is left **as-is, unused** — the owner chose
"enhance in place," not the delete variant. This spec records it as **known
duplication** to reconcile in a **separate decision** (a natural fit for Phase D
design-system hardening, or a dedicated cleanup PR). It is explicitly **not** part
of this PR. Reusing its `erpify:sidebar-open` localStorage key (§2) keeps a future
adoption cheap.

## Explicitly out of scope (YAGNI)

- **Real auth / notifications / global-search backends.** The top-bar controls are
  fake by decision; wiring them is future work.
- **Adopting, extending, or deleting `AppShell`.**
- **Phase C** (turning banks into a replicable entity pattern) and **Phase D**
  (the residual font-weight / radii sweep across the rest of the app, plus the
  deferred ESLint rules `composite-over-primitive` / `no-raw-palette`, and the
  full dark-mode / a11y audit).
- **No new shell component, no new dependencies.**

## Testing

TDD: write the failing e2e/unit first, then implement.

- **Stay green, untouched behavior:** `tests/e2e/backoffice/sidebar.spec.ts` (nav,
  sub-item expand/collapse, mobile sheet) and `dashboard.spec.ts`.
- **New e2e** (extend `sidebar.spec.ts` or add `app-shell.spec.ts`):
  - The desktop top bar is visible; the **section title tracks the route** (e.g.
    navigate to `/backoffice/banks` → title shows `Banks`).
  - The three top-bar icon buttons (search, notifications, user) are **visible and
    enabled** (active-looking).
  - **Collapse persists across reload** (toggle compact → reload → still compact),
    via the `erpify:sidebar-open` key.
  - **`Cmd/Ctrl+B`** toggles the sidebar state.
  - The **skip link** becomes visible on the first `Tab` and moves focus into the
    main content region.
- **New unit (vitest):** `sectionTitleFor()` route → label mapping, including the
  fallback for an unknown path.

## Acceptance criteria

- [ ] `/backoffice` shows a desktop top bar with a working collapse toggle, the
      route-derived section title, and three active-looking (fake) search /
      notifications / user controls.
- [ ] Sidebar collapse state **persists across reloads** and toggles with
      `Cmd/Ctrl+B`; only one collapse control remains.
- [ ] A skip-to-content link is the first focusable element and targets
      `#main-content`.
- [ ] No `font-bold` (weight 700) remains in the backoffice chrome; nav rows use
      `rounded-md` (no `rounded-xl` on nav items).
- [ ] Existing `sidebar.spec.ts` and `dashboard.spec.ts` pass unchanged; the new
      e2e + unit assertions pass.
- [ ] `make pwa.quality` (ESLint + Prettier) passes.
- [ ] Light and dark modes both render correctly (token-driven; visually verified),
      desktop and mobile.

## Future hook (not built here)

When auth, notifications, and global search ship, the fake top-bar controls gain
real behavior (a search route/palette, a notifications panel, a user/account menu).
At that point the *Recorded design-system override* above is resolved — the chrome
becomes honest again because the affordances are real. The persisted
`erpify:sidebar-open` key and the `sectionTitleFor` helper carry forward unchanged.
