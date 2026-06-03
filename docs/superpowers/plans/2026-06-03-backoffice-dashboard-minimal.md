# Backoffice Dashboard — Minimal Honest Landing — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the fabricated `/backoffice` dashboard (fake KPI tiles + two 280px placeholders) with a minimal, honest landing — a single "no metrics yet" message — and convert the page to a Server Component.

**Architecture:** The `/backoffice` page is a leaf route rendered as `children` inside the Server-Component `layout.tsx` → client `BackOfficeLayoutClient`. Because it carries no state, hooks, or handlers, it becomes a plain Server Component (no `"use client"`). It renders only token-styled static markup, so dark mode and contrast come for free from the token layer. No new components, no data fetching, no new dependencies.

**Tech Stack:** Next.js 16 (App Router, Server Components), TypeScript, Tailwind 4 (semantic design-system tokens), Playwright (e2e).

**Spec:** `docs/superpowers/specs/2026-06-03-backoffice-dashboard-minimal-design.md`

---

## File Structure

- **Modify** `pwa/src/app/backoffice/page.tsx` — full rewrite: a Server Component rendering `<h1>Dashboard</h1>` plus one honest, centred, muted message. Removes `"use client"`, the `stats` array, all `<StatCard>` usage, both placeholder `<EmptyState>` blocks, and the now-unused `lucide-react` / `@/components/erpify` imports.
- **Modify** `pwa/tests/e2e/backoffice/dashboard.spec.ts` — update one assertion (the `Welcome back, Admin` check) to assert the new honest copy. Everything else (the `<h1>Dashboard</h1>` assertion, sidebar-navigation and logout tests) is unchanged.

No other files change. `StatCard` and `EmptyState` stay in `@/components/erpify` (documented shared primitives); this change merely stops the dashboard from consuming them. (`EmptyState` remains used elsewhere; `StatCard` becomes temporarily unused — intentional, per spec.)

---

## Task 1: Replace the dashboard with a minimal honest landing (TDD)

**Files:**
- Modify: `pwa/tests/e2e/backoffice/dashboard.spec.ts:18-21`
- Modify: `pwa/src/app/backoffice/page.tsx` (full rewrite, 1-77)

> **Prerequisite for the e2e steps:** the stack must be running so Playwright can reach the app. From the repo root run `make docker.up` (or `make app.dev`) once before Step 2 if it isn't already up.

- [ ] **Step 1: Update the e2e assertion to the new copy (the failing test)**

In `pwa/tests/e2e/backoffice/dashboard.spec.ts`, change the `displays dashboard content` test body so the second assertion targets the new message. Replace:

```ts
  test("displays dashboard content", async ({ page }) => {
    await expect(page.getByRole("heading", { level: 1, name: "Dashboard" })).toBeVisible();
    await expect(page.getByText("Welcome back, Admin")).toBeVisible();
  });
```

with:

```ts
  test("displays dashboard content", async ({ page }) => {
    await expect(page.getByRole("heading", { level: 1, name: "Dashboard" })).toBeVisible();
    await expect(page.getByText("No metrics to show yet")).toBeVisible();
  });
```

- [ ] **Step 2: Run the test to verify it fails**

Run (from repo root):

```bash
make pwa.test.e2e c='tests/e2e/backoffice/dashboard.spec.ts'
```

Expected: the `displays dashboard content` test FAILS — the current page renders `Welcome back, Admin` and has no `No metrics to show yet` text, so `getByText("No metrics to show yet")` times out. (The sidebar/logout tests in the file should still pass.)

- [ ] **Step 3: Rewrite the page as a minimal Server Component**

Replace the **entire** contents of `pwa/src/app/backoffice/page.tsx` with:

```tsx
export default function BackOfficeDashboard() {
  return (
    <div className="dashboard">
      <h1 className="dashboard__title text-foreground text-2xl font-semibold tracking-tight">
        Dashboard
      </h1>

      <div className="dashboard__empty flex min-h-[60vh] flex-col items-center justify-center gap-2 text-center">
        <p className="dashboard__empty-lead text-foreground text-base font-semibold">
          No metrics to show yet
        </p>
        <p className="dashboard__empty-detail text-muted-foreground max-w-prose text-sm">
          Operational figures — costs, profit, active projects — will appear here as you add
          data to the system.
        </p>
      </div>
    </div>
  );
}
```

Notes for the engineer:
- **No `"use client"` directive** — this is now a Server Component. Do not add a `React` import; the App Router JSX transform does not need one.
- **No imports at all** — the file is a single default-exported function. Removing the old `lucide-react` and `@/components/erpify` imports is required (unused imports fail ESLint).
- The lead line is deliberately a `<p>` (emphasized at `font-semibold` = weight 600, the system maximum), **not** an `<h2>` — the page keeps a single `<h1>` so the heading hierarchy stays honest.
- Only semantic token utilities are used (`text-foreground`, `text-muted-foreground`); no raw palette classes. BEM block/element names (`dashboard__*`) follow the repo convention.

- [ ] **Step 4: Run the test to verify it passes**

Run (from repo root):

```bash
make pwa.test.e2e c='tests/e2e/backoffice/dashboard.spec.ts'
```

Expected: all tests in `dashboard.spec.ts` PASS — `displays dashboard content` now finds both the `Dashboard` heading and the `No metrics to show yet` message; the sidebar-navigation and logout tests are unaffected.

- [ ] **Step 5: Run the PWA quality gate**

Run (from repo root):

```bash
make pwa.quality
```

Expected: PASS — ESLint reports no unused imports / no `"use client"`-related issues, Prettier reports the files are formatted. Fix anything reported before continuing.

- [ ] **Step 6: Commit**

```bash
git add pwa/src/app/backoffice/page.tsx pwa/tests/e2e/backoffice/dashboard.spec.ts
git commit -m "feat(backoffice): minimal honest dashboard landing

Replace fabricated KPI tiles and 280px placeholders with a single honest
'no metrics yet' message. Convert the page to a Server Component (no state
or interactivity remains). Update the dashboard e2e assertion to the new
copy. StatCard/EmptyState stay in @/components/erpify for future use.

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Manual verification (after Task 1)

- [ ] With the stack up, open `https://localhost/backoffice`: the page shows the `Dashboard` heading and the centred `No metrics to show yet` message — no fabricated numbers, no large placeholder cards, no `Welcome back, Admin`.
- [ ] Toggle dark mode and confirm the text uses the correct token colours and remains legible (contrast ≥ 4.5:1). No focus rings to check — nothing is interactive.
- [ ] Narrow the viewport to mobile width: the centred message reflows and stays readable; the sidebar still collapses to the mobile sheet (unchanged behaviour).

## Acceptance criteria (from spec)

- [ ] `/backoffice` shows `<h1>Dashboard</h1>` and the honest "No metrics to show yet" message; no fabricated numbers, no 280px placeholders, no `Welcome back, Admin` copy.
- [ ] The page is a Server Component (no `"use client"`), with no unused imports.
- [ ] Only semantic token utilities are used; no raw palette classes.
- [ ] `dashboard.spec.ts` passes with the updated assertion; sidebar/logout tests untouched.
- [ ] `make pwa.quality` passes.
- [ ] Light and dark modes both render correctly.
