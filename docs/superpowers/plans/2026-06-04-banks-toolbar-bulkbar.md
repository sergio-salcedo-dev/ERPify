# Banks Toolbar Search + Floating Bulk Bar Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Promote the banks name filter to an always-visible toolbar search (with the documented-but-unbuilt `/` shortcut) and convert the inline bulk-action bar into a floating bottom-center surface, per `docs/superpowers/specs/2026-06-04-banks-toolbar-bulkbar-design.md`.

**Architecture:** All changes live in the banks entity UI (`pwa/src/app/backoffice/banks/`) plus one shared keyboard constant. No new components: `BanksFilters` gains the toolbar search + shortcut, `BanksBulkBar` changes positioning classes, `page.tsx` adds selection-conditional bottom clearance. State flow (debounced `filter.name`, optimistic bulk delete, live region) is untouched.

**Tech Stack:** Next.js 16 App Router, TypeScript strict, Tailwind 4 tokens, Vitest + Testing Library, Playwright (CI-only — local e2e impossible, see memory `pwa-e2e-local-ownership-blocker`).

**Worktree:** `/home/sergio-dev/Projects/ERPify/.claude/worktrees/pwa-banks-toolbar-db5z` (branch `feat/pwa-banks-toolbar-db5z`). Run all `make` targets from the worktree root so they hit its isolated stack. Unit tests run in the container: `make pwa.test.unit c='<file>'`.

---

### Task 1: Panel-scoped filter count helpers

The "Filters (n)" badge and the panel auto-open heuristic must stop counting `name` once the search moves to the toolbar. `countActiveFilters` / `hasActiveFilter` have **no callers outside `BanksFilters`** (verified by grep) — replace them with panel-scoped variants instead of keeping dead exports.

**Files:**
- Modify: `pwa/src/app/backoffice/banks/_lib/banksFilterSort.ts:42-59`
- Test: `pwa/tests/app/backoffice/banks/banksFilterSort.test.ts`

- [ ] **Step 1: Confirm no other callers (safety grep)**

Run from `pwa/`:
```bash
grep -rn "countActiveFilters\|hasActiveFilter" src/ tests/ --include='*.ts*' | grep -v banksFilterSort
```
Expected: only `BanksFilters.tsx` imports/uses them, plus their own tests in `banksFilterSort.test.ts`. If anything else shows up, stop and re-scope.

- [ ] **Step 2: Write the failing tests**

In `pwa/tests/app/backoffice/banks/banksFilterSort.test.ts`, locate the existing `countActiveFilters` / `hasActiveFilter` describe blocks and replace them with:

```ts
describe("countPanelFilters", () => {
  it("counts only the panel-hosted fields (short name + created range)", () => {
    expect(countPanelFilters(EMPTY_FILTER)).toBe(0);
    expect(countPanelFilters({ ...EMPTY_FILTER, name: "acme" })).toBe(0);
    expect(countPanelFilters({ ...EMPTY_FILTER, shortName: "ACM" })).toBe(1);
    expect(
      countPanelFilters({
        ...EMPTY_FILTER,
        shortName: "ACM",
        createdFrom: "2026-01-01",
        createdTo: "2026-02-01",
      }),
    ).toBe(3);
  });

  it("treats whitespace-only values as inactive", () => {
    expect(countPanelFilters({ ...EMPTY_FILTER, shortName: "  " })).toBe(0);
  });
});

describe("hasActivePanelFilter", () => {
  it("is false when only the toolbar search (name) is set", () => {
    expect(hasActivePanelFilter({ ...EMPTY_FILTER, name: "acme" })).toBe(false);
    expect(hasActivePanelFilter({ ...EMPTY_FILTER, createdFrom: "2026-01-01" })).toBe(true);
  });
});
```

Update the test file's import to pull `countPanelFilters` and `hasActivePanelFilter` from `@/app/backoffice/banks/_lib/banksFilterSort` (and drop `countActiveFilters` / `hasActiveFilter` from it).

- [ ] **Step 3: Run tests to verify they fail**

Run from the worktree root:
```bash
make pwa.test.unit c='tests/app/backoffice/banks/banksFilterSort.test.ts'
```
Expected: FAIL — `countPanelFilters` is not exported.

- [ ] **Step 4: Implement the helpers**

In `pwa/src/app/backoffice/banks/_lib/banksFilterSort.ts`, replace the `hasActiveFilter` + `countActiveFilters` block (lines 42–59) with:

```ts
/**
 * Number of populated fields among the panel-hosted filters (short name +
 * created range). The name search lives in the always-visible toolbar, so the
 * "Filters (n)" badge and the auto-open heuristic only count what a collapsed
 * panel would otherwise hide. Whitespace-only values count as inactive
 * (mirrors `applyFilters`).
 */
export function countPanelFilters(filter: BanksFilter): number {
  let count = 0;
  if (filter.shortName.trim()) count += 1;
  if (filter.createdFrom.trim()) count += 1;
  if (filter.createdTo.trim()) count += 1;
  return count;
}

export function hasActivePanelFilter(filter: BanksFilter): boolean {
  return countPanelFilters(filter) > 0;
}
```

`BanksFilters.tsx` still imports the old names at this point — fix its imports in the same step so the build compiles: in `pwa/src/app/backoffice/banks/_components/BanksFilters.tsx` change the import block (lines 10–18) to import `countPanelFilters` and `hasActivePanelFilter` (dropping `countActiveFilters`, `hasActiveFilter`), change line 56 to use `hasActivePanelFilter(filter)`, and line 136 to `const activeCount = countPanelFilters(filter);`. (The search input itself moves in Task 2 — this task only keeps the code compiling with the new badge semantics.)

- [ ] **Step 5: Run tests to verify they pass**

```bash
make pwa.test.unit c='tests/app/backoffice/banks/banksFilterSort.test.ts'
make pwa.test.unit c='tests/app/backoffice/banks/banksFiltersDebounce.test.tsx'
```
Expected: both PASS (the debounce suite only drives `banks-filters__name` + Reset; badge semantics don't affect it).

- [ ] **Step 6: Commit**

```bash
git add pwa/src/app/backoffice/banks/_lib/banksFilterSort.ts pwa/src/app/backoffice/banks/_components/BanksFilters.tsx pwa/tests/app/backoffice/banks/banksFilterSort.test.ts
git commit -m "refactor(pwa): scope banks filter badge count to panel-hosted fields"
```

---

### Task 2: Promote the name search into the toolbar

**Files:**
- Modify: `pwa/src/app/backoffice/banks/_components/BanksFilters.tsx`
- Test (new): `pwa/tests/app/backoffice/banks/banksFiltersToolbarSearch.test.tsx`

- [ ] **Step 1: Write the failing tests**

Create `pwa/tests/app/backoffice/banks/banksFiltersToolbarSearch.test.tsx`:

```tsx
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { act, fireEvent, render, screen } from "@testing-library/react";
import { BanksFilters } from "@/app/backoffice/banks/_components/BanksFilters";
import { DEFAULT_SORT, EMPTY_FILTER } from "@/app/backoffice/banks/_lib/banksFilterSort";

function renderFilters(props: Partial<Parameters<typeof BanksFilters>[0]> = {}) {
  const onFilterChange = vi.fn();
  const utils = render(
    <BanksFilters
      filter={EMPTY_FILTER}
      onFilterChange={onFilterChange}
      sort={DEFAULT_SORT}
      onSortChange={vi.fn()}
      onReset={vi.fn()}
      {...props}
    />,
  );
  return { onFilterChange, ...utils };
}

describe("BanksFilters — toolbar search", () => {
  beforeEach(() => {
    vi.useFakeTimers();
  });
  afterEach(() => {
    vi.useRealTimers();
  });

  it("renders the name search in the toolbar, outside the collapsible panel", () => {
    renderFilters();
    const search = screen.getByTestId("banks-filters__name");
    const panel = screen.getByTestId("banks-filters__panel");
    // Panel starts collapsed (no active panel filters) yet the search is live.
    expect(panel).toHaveAttribute("aria-hidden", "true");
    expect(panel.contains(search)).toBe(false);
    expect(search).not.toHaveAttribute("disabled");
  });

  it("debounces typed text into filter.name", () => {
    const { onFilterChange } = renderFilters();
    fireEvent.change(screen.getByTestId("banks-filters__name"), {
      target: { value: "acme" },
    });
    expect(onFilterChange).not.toHaveBeenCalled();
    act(() => {
      vi.advanceTimersByTime(300);
    });
    expect(onFilterChange).toHaveBeenCalledWith({ ...EMPTY_FILTER, name: "acme" });
  });

  it("does not count name in the Filters badge and does not auto-open the panel for it", () => {
    renderFilters({ filter: { ...EMPTY_FILTER, name: "acme" } });
    expect(screen.queryByTestId("banks-filters__count")).not.toBeInTheDocument();
    expect(screen.getByTestId("banks-filters__panel")).toHaveAttribute("aria-hidden", "true");
  });

  it("still counts panel fields in the badge and auto-opens for them", () => {
    renderFilters({ filter: { ...EMPTY_FILTER, shortName: "ACM" } });
    expect(screen.getByTestId("banks-filters__count")).toHaveTextContent("1");
    expect(screen.getByTestId("banks-filters__panel")).toHaveAttribute("aria-hidden", "false");
  });
});
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
make pwa.test.unit c='tests/app/backoffice/banks/banksFiltersToolbarSearch.test.tsx'
```
Expected: FAIL — the first test fails because `banks-filters__name` currently lives inside the panel (`panel.contains(search)` is `true`).

- [ ] **Step 3: Move the input**

In `pwa/src/app/backoffice/banks/_components/BanksFilters.tsx`:

a. Add imports: `Search` joins the existing `lucide-react` import; `useRef` joins the React import:

```tsx
import { useEffect, useId, useRef, useState, type ChangeEvent, type ReactNode } from "react";
import { Search, SlidersHorizontal } from "lucide-react";
```

b. Add the ref next to the other state (after line 57's `useState`):

```tsx
const searchRef = useRef<HTMLInputElement>(null);
```

c. Replace the toolbar block (the `banks-filters__toolbar` div, lines 156–191) with — note the new `order-*` scheme (mobile stacking: search → Filters → toggles; `sm+` row: toggles → search → Filters):

```tsx
<div className="banks-filters__toolbar flex flex-col gap-3 sm:flex-row sm:items-stretch sm:justify-end">
  {leading ? (
    // Mobile: sits below the search + Filters stack, right-aligned to
    // mirror the previous standalone toggle row. Tablet+: leads the
    // right-aligned cluster (toggles, then search, then Filters).
    <div className="banks-filters__leading order-3 flex justify-end sm:order-1 sm:items-center sm:justify-start">
      {leading}
    </div>
  ) : null}
  <div className="banks-filters__search relative order-1 w-full sm:order-2 sm:w-64">
    <Search
      className="text-muted-foreground pointer-events-none absolute top-1/2 left-2.5 size-3.5 -translate-y-1/2"
      aria-hidden="true"
    />
    <Input
      ref={searchRef}
      type="search"
      value={nameInput}
      onChange={updateText("name")}
      placeholder="Search by name…"
      aria-label="Search banks by name"
      title="Search banks by name (press / to focus)"
      className="banks-filters__search-input min-h-7 pl-8"
      data-testid="banks-filters__name"
    />
  </div>
  <Button
    type="button"
    variant="outline"
    size="sm"
    onClick={() => setOpen((prev) => !prev)}
    aria-expanded={open}
    aria-controls={panelId}
    aria-label={toggleLabel}
    title={toggleLabel}
    className="banks-filters__toggle order-2 h-auto min-h-7 w-full sm:order-3 sm:w-auto"
    data-testid="banks-filters__toggle"
    data-icon="inline-start"
  >
    <SlidersHorizontal className="size-3.5" aria-hidden="true" />
    <span>Filters</span>
    {hasActive ? (
      <span
        className="banks-filters__count bg-primary text-primary-foreground ml-1 inline-flex h-5 min-w-5 items-center justify-center rounded-full px-1.5 text-xs font-medium"
        aria-hidden="true"
        data-testid="banks-filters__count"
      >
        {activeCount}
      </span>
    ) : null}
  </Button>
</div>
```

The `data-testid="banks-filters__name"` deliberately keeps its name — every existing unit/e2e locator keeps working, and the testid-uniqueness guard stays satisfied because the panel copy is removed in the same edit.

d. Delete the name `FormField` from the panel grid (lines 211–219, the `banks-filters-name` block) and tighten the grid to three fields: change the grid class on `banks-filters__grid` from `sm:grid-cols-2 lg:grid-cols-4` to `sm:grid-cols-2 lg:grid-cols-3`.

e. Update the component doc comments: in the `leading` prop JSDoc (lines 33–39), replace the last sentence with "The banks list passes its view/density toggles here; they share the toolbar row with the always-visible name search and the Filters button from `sm` up." In the `defaultOpen` JSDoc (lines 26–32), replace "with pre-set filters / sort" with "with pre-set panel filters / sort (the name search is always visible and never forces the panel open)".

- [ ] **Step 4: Run the new and adjacent suites**

```bash
make pwa.test.unit c='tests/app/backoffice/banks/banksFiltersToolbarSearch.test.tsx'
make pwa.test.unit c='tests/app/backoffice/banks/banksFiltersDebounce.test.tsx'
make pwa.test.unit c='tests/data-testid-uniqueness.test.ts'
```
Expected: all PASS. If the debounce suite fails on a `defaultOpen` assumption (it passes `defaultOpen` explicitly, so it shouldn't), fix the test's queries — not by reintroducing the panel input.

- [ ] **Step 5: Commit**

```bash
git add pwa/src/app/backoffice/banks/_components/BanksFilters.tsx pwa/tests/app/backoffice/banks/banksFiltersToolbarSearch.test.tsx
git commit -m "feat(pwa): promote banks name filter to always-visible toolbar search"
```

---

### Task 3: `/` keyboard shortcut focuses the search

**Files:**
- Modify: `pwa/src/context/shared/domain/types/keyboard.ts:11-17`
- Modify: `pwa/src/app/backoffice/banks/_components/BanksFilters.tsx`
- Test: `pwa/tests/app/backoffice/banks/banksFiltersToolbarSearch.test.tsx` (extend)

- [ ] **Step 1: Write the failing tests**

Append to `banksFiltersToolbarSearch.test.tsx` (top-level, alongside the existing describe):

```tsx
describe("BanksFilters — '/' shortcut", () => {
  it("focuses the toolbar search when '/' is pressed on the page", () => {
    renderFilters();
    fireEvent.keyDown(document.body, { key: "/" });
    expect(screen.getByTestId("banks-filters__name")).toHaveFocus();
  });

  it("stays inert while the user is typing in another field", () => {
    renderFilters({ defaultOpen: true });
    const shortName = screen.getByTestId("banks-filters__short-name");
    shortName.focus();
    fireEvent.keyDown(shortName, { key: "/" });
    expect(shortName).toHaveFocus();
  });

  it("stays inert when the keypress originates inside a dialog", () => {
    renderFilters();
    const dialog = document.createElement("div");
    dialog.setAttribute("role", "dialog");
    const button = document.createElement("button");
    dialog.appendChild(button);
    document.body.appendChild(dialog);
    button.focus();
    fireEvent.keyDown(button, { key: "/" });
    expect(screen.getByTestId("banks-filters__name")).not.toHaveFocus();
    dialog.remove();
  });
});
```

Note: these tests don't use fake timers — they're in their own describe without the `beforeEach`. Keep them outside the fake-timers describe.

- [ ] **Step 2: Run tests to verify they fail**

```bash
make pwa.test.unit c='tests/app/backoffice/banks/banksFiltersToolbarSearch.test.tsx'
```
Expected: the three new tests FAIL (no shortcut exists); the earlier four still PASS.

- [ ] **Step 3: Add the `SLASH` constant**

In `pwa/src/context/shared/domain/types/keyboard.ts`, add to the `KeyboardKey` object (after `ESCAPE`):

```ts
  SLASH: "/",
```

(The repo rule: never hard-code key literals at call sites — `KeyboardKey` is the typed home.)

- [ ] **Step 4: Add the document-level listener**

In `BanksFilters.tsx`, add the import:

```tsx
import { KeyboardKey } from "@/context/shared/domain/types/keyboard";
```

and add this effect after the debounce effect (after line 91):

```tsx
// Documented DataTable keyboard contract: `/` focuses the list search.
// Document-level so it works wherever focus rests on the page; inert while
// the user is typing elsewhere or interacting with a transient layer.
useEffect(() => {
  const handleSlash = (event: globalThis.KeyboardEvent): void => {
    if (event.key !== KeyboardKey.SLASH || event.defaultPrevented) return;
    const target = event.target;
    if (
      target instanceof HTMLElement &&
      target.closest(
        "input, textarea, select, [contenteditable='true'], [role='dialog'], [role='alertdialog'], [role='menu']",
      )
    ) {
      return;
    }
    event.preventDefault();
    searchRef.current?.focus();
  };
  document.addEventListener("keydown", handleSlash);
  return () => document.removeEventListener("keydown", handleSlash);
}, []);
```

- [ ] **Step 5: Run tests to verify they pass**

```bash
make pwa.test.unit c='tests/app/backoffice/banks/banksFiltersToolbarSearch.test.tsx'
```
Expected: all 7 PASS.

- [ ] **Step 6: Commit**

```bash
git add pwa/src/context/shared/domain/types/keyboard.ts pwa/src/app/backoffice/banks/_components/BanksFilters.tsx pwa/tests/app/backoffice/banks/banksFiltersToolbarSearch.test.tsx
git commit -m "feat(pwa): wire the documented '/' shortcut to the banks toolbar search"
```

---

### Task 4: Floating bulk-action bar + clearance

> **Superseded during execution (2026-06-04):** review of the first
> implementation found that `fixed left-1/2` centers on the viewport, not the
> sidebar-offset content column. Shipped instead (commit `bca87e7`):
> `sticky bottom-6 z-30 mx-auto` with the bar moved to the end of the
> `banks-list` container — no `pb-24` clearance needed — plus removal of the
> vestigial `overflow-auto` on the app-shell `<main>` (commit `7543f34`)
> which otherwise re-scoped sticky to a never-scrolling scrollport. The steps
> below are the original plan, kept for the execution record; the spec doc
> carries the shipped contract.

**Files:**
- Modify: `pwa/src/app/backoffice/banks/_components/BanksBulkBar.tsx:52-57`
- Modify: `pwa/src/app/backoffice/banks/page.tsx:349-354`
- Test: `pwa/tests/app/backoffice/banks/banksBulkActions.test.tsx` (extend)

- [ ] **Step 1: Write the failing test**

`banksBulkActions.test.tsx` renders the full page; its fixtures are `ACME` / `BETA` (`Bank.fromPrimitives`) and its tests select rows via `screen.getByLabelText(\`Select row ${ACME.id}\`)` after awaiting `banks-table__row-${ACME.id}`. Append inside the existing describe:

```tsx
it("floats the bulk bar and reserves bottom clearance while a selection exists", async () => {
  render(<BanksListPage />);
  await screen.findByTestId(`banks-table__row-${ACME.id}`);
  fireEvent.click(screen.getByLabelText(`Select row ${ACME.id}`));

  const bar = await screen.findByTestId("banks-list__bulk-bar");
  expect(bar.className).toContain("fixed");
  expect(bar.className).toContain("bottom-6");
  expect(screen.getByTestId("banks-list").className).toContain("pb-24");

  fireEvent.click(screen.getByTestId("banks-list__bulk-clear"));
  await waitFor(() => {
    expect(screen.queryByTestId("banks-list__bulk-bar")).not.toBeInTheDocument();
  });
  expect(screen.getByTestId("banks-list").className).not.toContain("pb-24");
});
```

- [ ] **Step 2: Run test to verify it fails**

```bash
make pwa.test.unit c='tests/app/backoffice/banks/banksBulkActions.test.tsx'
```
Expected: the new test FAILS on `expect(bar.className).toContain("fixed")`.

- [ ] **Step 3: Implement the floating bar**

In `BanksBulkBar.tsx`, replace the `<section>` className (line 54) with:

```tsx
className="banks-list__bulk-bar border-border bg-card shadow-elevation-4 fixed bottom-6 left-1/2 z-50 flex w-max max-w-[calc(100vw-2rem)] -translate-x-1/2 flex-wrap items-center gap-3 rounded-xl border px-4 py-2 sm:flex-nowrap"
```

and update the component JSDoc (lines 28–34): replace "Selection action bar shown above the list once one or more banks are selected." with "Floating bottom-center selection bar, shown once one or more banks are selected; the page reserves bottom clearance so it never covers the pagination row."

In `page.tsx`, make the root container's padding conditional. Check the file's existing imports for `cn` (from `@/lib/utils`); add it if absent. Replace the root `className` (line 351):

```tsx
className={cn(
  "banks-list mx-auto w-full max-w-[90rem] space-y-4 sm:space-y-6",
  selectedIds.size > 0 && "pb-24",
)}
```

- [ ] **Step 4: Run the suite**

```bash
make pwa.test.unit c='tests/app/backoffice/banks/banksBulkActions.test.tsx'
```
Expected: all PASS (existing bulk tests assert behavior — count, clear, delete dialog, optimistic rollback — none of which changed).

- [ ] **Step 5: Commit**

```bash
git add pwa/src/app/backoffice/banks/_components/BanksBulkBar.tsx pwa/src/app/backoffice/banks/page.tsx pwa/tests/app/backoffice/banks/banksBulkActions.test.tsx
git commit -m "feat(pwa): float banks bulk-action bar bottom-center with list clearance"
```

---

### Task 5: E2E coverage (authored locally, verified in CI)

Local e2e runs are impossible (no Playwright browsers for this host — see memory `pwa-e2e-local-ownership-blocker`). Author the spec changes, lint/typecheck them, and let CI verify.

**Files:**
- Modify: `pwa/tests/e2e/backoffice/banks-containment.spec.ts`
- Inspect (likely no change): `pwa/tests/e2e/fixtures/banks-real-api.ts:123`, `pwa/tests/e2e/backoffice/banks-real-api-flows.spec.ts`, `pwa/tests/e2e/backoffice/banks-realtime.spec.ts`

- [ ] **Step 1: Sweep existing e2e assumptions**

```bash
grep -rn -B3 -A3 "banks-filters__name" pwa/tests/e2e/
```
For each hit, check whether the surrounding flow opens the panel (`banks-filters__toggle` click) *solely* to reach the name input. The input is now always visible, so such clicks become unnecessary but **not harmful** (the panel still opens; the fill still works). Only change flows that would now break — e.g. an assertion that `banks-filters__name` is hidden while the panel is collapsed. Expected outcome: no breaking assumptions (the containment spec's `toBeHidden` checks target the panel and bulk bar, not the name input); remove a panel-open click only where the test's intent was purely "reach the name filter".

- [ ] **Step 2: Add the new behavioral spec**

In `banks-containment.spec.ts` (it already covers bulk-bar visibility), add one test following the file's existing fixture/setup conventions:

```ts
test("toolbar search, '/' shortcut and floating bulk bar", async ({ page }) => {
  // Search is reachable without opening the filters panel.
  await expect(page.getByTestId("banks-filters__name")).toBeVisible();
  await expect(page.getByTestId("banks-filters__panel")).toBeHidden();

  // '/' focuses the search from the page body.
  await page.locator("body").press("/");
  await expect(page.getByTestId("banks-filters__name")).toBeFocused();

  // Selecting a row floats the bulk bar without covering pagination.
  // Row checkboxes follow this spec file's existing convention:
  // row.locator("input[type=checkbox]").check() on a banks-table__row-* row.
  const firstRow = page.locator('[data-testid^="banks-table__row-"]').first();
  await firstRow.locator("input[type=checkbox]").check();
  const bar = page.getByTestId("banks-list__bulk-bar");
  await expect(bar).toBeVisible();
  await expect(page.getByTestId("banks-pagination__page-size")).toBeVisible();
  await page.keyboard.press("Escape");
  await expect(bar).toBeHidden();
});
```

Follow the file's existing setup (fixtures, navigation, seeded rows) for the test body's preamble — its neighbouring tests show the pattern.

- [ ] **Step 3: Typecheck + lint the e2e changes**

```bash
make pwa.quality
```
Expected: clean. (CI runs the spec itself.)

- [ ] **Step 4: Commit**

```bash
git add pwa/tests/e2e/
git commit -m "test(pwa): e2e for banks toolbar search, '/' shortcut and floating bulk bar"
```

---

### Task 6: Docs + final gates

**Files:**
- Modify: `pwa/DESIGN.md` (banks adoption row + List view pattern)

- [ ] **Step 1: Update DESIGN.md**

Two surgical edits:

1. In the **List view** pattern section, find the bullet starting "**Bulk selection.** Multi-select checkboxes…" and change "drive a selection action bar" to "drive a floating bottom-center selection bar (the list reserves bottom clearance while it is mounted)". In the same section, find "Filter bar above table, debounced 250 ms." and append: "The primary text search (the entity name) is always visible in the toolbar and is focusable with `/`; secondary filters collapse into the panel and the badge counts only them."
2. In the **Adoption status** table, banks row, append to the "List/card UX redesign (2026-06)" sentence: "Toolbar quick-search (`/`-focusable, name always visible, badge counts panel filters only) + floating bottom-center `<BanksBulkBar>` (2026-06-04)."

- [ ] **Step 2: Full unit suite + quality**

```bash
make pwa.test.unit
make pwa.quality
```
Expected: both clean. Fix anything new they report before proceeding.

- [ ] **Step 3: Security checklist self-review**

Walk the frontend checklist from root `CLAUDE.md` against the diff: no `dangerouslySetInnerHTML`/`innerHTML`/`eval`; no dynamic `href`/`src`/`router.push` added; no storage/clipboard writes added; headers untouched; no new dependencies. Record the outcome for the PR description (expected: all N/A or pass — the diff is class names, an input move, a keydown listener, and a constant).

- [ ] **Step 4: Commit docs**

```bash
git add pwa/DESIGN.md
git commit -m "docs(pwa): record banks toolbar search + floating bulk bar in DESIGN.md"
```

- [ ] **Step 5: Visual verification (both themes)**

Bring the worktree stack up if not running (`make app.dev` from the worktree). Then browse on a fixed port: `HTTPS_PORT=8443 make docker.up`, open `https://localhost:8443/backoffice/banks`. Verify in light then dark (ThemeToggle in the top bar): search sits right of the toggles and left of Filters; `/` focuses it; selecting rows floats the bar without covering pagination; panel badge ignores the name. Screenshot or playwright-cli with system Chrome per memory `pwa-e2e-local-ownership-blocker` if a visual record is wanted.

---

## Verification checklist (spec → plan)

- Search always visible, binds `filter.name`, debounced, Reset clears it → Tasks 1–2 (Reset already clears local state — `handleReset` untouched).
- Badge stops counting name; panel auto-open ignores name → Tasks 1–2.
- `/` shortcut, inert in inputs/transient layers → Task 3.
- Floating bottom-center bar, token surfaces, clearance, behavior unchanged → Task 4.
- Rejected features (Columns/Export/Deactivate/global search) → no task creates them.
- Both themes, no component branching → class-level tokens only (Tasks 2, 4); visual pass in Task 6.
- E2E keyboard walk → Task 5.
- DESIGN.md updates → Task 6.
