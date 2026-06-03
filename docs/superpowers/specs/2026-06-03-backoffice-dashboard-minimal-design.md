# Backoffice Dashboard — minimal honest landing (design)

**Date:** 2026-06-03
**Status:** approved (brainstorming) — ready for implementation plan
**Surface:** `pwa/src/app/backoffice/page.tsx` (the `/backoffice` landing)
**Phase:** Part of the broader backoffice UI/UX overhaul. This is **Phase B (Dashboard)**; phases A (app shell), C (entity pattern), D (design-system hardening) are deferred to their own spec → plan → PR cycles.

## Context

The current `/backoffice` dashboard renders **fabricated data**: a 4-tile stat grid with
invented numbers (`Active Projects: 24`, `Total Workforce: 156`, `Revenue Growth: +12.5%`,
`Pending Tasks: 48`) and two 280px-tall "coming soon" placeholders. These metrics map to
entities that **do not exist yet** (Projects, Employees, Revenue, Tasks).

Today only two backoffice surfaces have real data: **Bank** (full CRUD + search + realtime)
and **Service Health**. Showing invented KPIs violates the design system's first principle —
*"Honest over delightful. No optimistic theater."* — and the page also breaks the
*"density is a feature / avoid excessive whitespace"* rule.

**Owner intent (decided during brainstorming):** the dashboard should stay essentially
**empty for now** with the **minimum possible code**. Real operational figures
(profit, costs, active-project KPIs) get added later, **as those entities land** — not
speculatively today.

## Decision

Replace the fabricated dashboard with a **minimal, honest landing** (brainstorming
"Direction B" — the barest of three options the owner reviewed and chose):

- Keep `<h1>Dashboard</h1>` (real heading hierarchy; asserted by the e2e test).
- Drop the fake `Welcome back, Admin…` subtitle (false personalization — there is no auth yet).
- Render a single honest, muted, vertically-centred message in place of the stat grid and
  placeholders. **No card, no CTA** (that is what makes it Direction B). Copy (English, to
  match the rest of the product UI):

  > **No metrics to show yet**
  > Operational figures — costs, profit, active projects — will appear here as you add data
  > to the system.

  The lead line is **emphasized plain text** (e.g. a `font-semibold` ≤600 line), **not** a
  new heading element — the page keeps a single `<h1>` so the heading hierarchy stays honest
  (no `<h2>` used for visual weight).

- Convert the page from a Client Component to a **Server Component** (delete `"use client"`):
  it no longer has state, hooks, or handlers, so client JS is unnecessary.
- Use semantic tokens only (`text-foreground`, `text-muted-foreground`) — no raw palette,
  no hard-coded colours. Dark mode and contrast come for free from the token layer.

## Scope (files touched)

1. **`pwa/src/app/backoffice/page.tsx`** — rewrite as described above.
   - Remove: `"use client"`, the `stats` array, all `<StatCard>` usage, both placeholder
     `<EmptyState>` blocks, `space-y-10`, the `lucide-react` imports that become unused.
2. **`pwa/tests/e2e/backoffice/dashboard.spec.ts`** — update one assertion: the
   `getByText("Welcome back, Admin")` check (line ~20) becomes a check for the new honest
   copy (e.g. `getByText("No metrics to show yet")`). The `<h1>Dashboard</h1>` assertion and
   all sidebar-navigation / logout tests stay unchanged.

## Explicitly out of scope (YAGNI)

- **No dashboard widget framework / grid system.** When real entities land, the page grows
  real `<StatCard>` metrics behind `<AsyncBoundary>`; that is a future change, not this one.
- **No Service Health wiring / no data fetching / no `<AsyncBoundary>`** on this page.
  Health stays on its own `/backoffice/health` route.
- **No CTA / quick-links / launchpad** affordances (those were Directions A and C, not chosen).
- **App shell, entity pattern, and design-system hardening** (Phases A/C/D) are separate.

## Component-lifecycle notes

- **`StatCard` is kept**, even though this change leaves it temporarily unused. It is a
  documented shared primitive (`@/components/erpify`, referenced in `DESIGN.md`'s adoption
  table and `pwa/CLAUDE.md`) and is the exact building block for the future real-metrics
  dashboard the owner described. Deleting it would also force doc edits — more churn, not
  less. Removing it, if ever desired, is a separate cleanup.
- **`EmptyState` stays** (still used elsewhere, e.g. banks filtered-to-zero); it is simply no
  longer used on this page.

## Acceptance criteria

- [ ] `/backoffice` shows `<h1>Dashboard</h1>` and the honest "No metrics to show yet" message;
      no fabricated numbers, no 280px placeholders, no `Welcome back, Admin` copy.
- [ ] The page is a Server Component (no `"use client"`), with no unused imports.
- [ ] Only semantic token utilities are used; no raw palette classes.
- [ ] `dashboard.spec.ts` passes with the updated assertion; sidebar/logout tests untouched.
- [ ] `make pwa.quality` (ESLint + Prettier) passes.
- [ ] Light and dark modes both render correctly (token-driven; visually verified).

## Future hook (not built here)

When the first metric-bearing entity (e.g. Projects) ships, this page becomes the home for
real KPIs: `<StatCard>` tiles fed by a use case, wrapped in `<AsyncBoundary>`, with honest
empty/error states. The copy above already sets that expectation for the operator.
