# Banks reset-filter chip bar + "Code" naming — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the panel-buried banks Reset with an always-visible active-filter chip bar (removable chips + a reachable Clear all), and standardise the user-facing label of the `shortName` field to "Code".

**Architecture:** `BanksFilters` renders a new presentational `BanksActiveFilters` between its toolbar and panel; a pure `buildActiveFilterChips` helper derives chip descriptors from the filter/sort. Per-chip removal reuses `BanksFilters`' existing debounce-cancel logic. "Code" is a strings-only change (PWA labels + Zod messages + 3 API validation messages); the internal field name `shortName` is unchanged.

**Tech Stack:** Next.js 16 / React / TypeScript, Tailwind 4 (BEM class names), Vitest + Testing Library, Playwright; PHP 8.5 / Symfony Validator on the API side.

**Spec:** `docs/superpowers/specs/2026-06-10-reset-filter-button-improvements-design.md`

**Delivery:** Part B (Tasks 1–4) → one `refactor` commit. Part A (Tasks 5–9) → one `feat` commit.

**Note for executor:** Worktree e2e runs the Next webServer on the host. If it fails with `EACCES … pwa/.next-e2e` or `… next-env.d.ts`, those are root-owned from the container; `rm -rf pwa/.next-e2e` and `rm -f pwa/next-env.d.ts` (they regenerate), or run `! make pwa.chown.next` in a terminal. Run host Playwright with `PLAYWRIGHT_HOST_PLATFORM_OVERRIDE=ubuntu24.04-x64`.

---

## Part B — "Code" naming unification

### Task 1: Zod schema messages → "code"

**Files:**
- Test: `pwa/tests/context/backoffice/bank/application/schemas/BankSchema.test.ts`
- Modify: `pwa/src/context/backoffice/bank/application/schemas/BankSchema.ts`

- [ ] **Step 1: Update the two failing assertions in the schema test**

In `BankSchema.test.ts`, change the two `shortName` message expectations:

```ts
// in "requires `shortName`…"
expect(issue?.message).toBe("The code field is required.");
```

```ts
// in "rejects shortNames longer than 50 chars…"
expect(result.issues.find((i) => i.path === "shortName")?.message).toBe(
  "The code must not exceed 50 characters.",
);
```

(Leave the field key `shortName` and the `name` messages unchanged.)

- [ ] **Step 2: Run the test to verify it fails**

Run: `make pwa.test.unit c='tests/context/backoffice/bank/application/schemas/BankSchema.test.ts'`
Expected: FAIL — received "The shortName field is required." / "The shortName must not exceed 50 characters."

- [ ] **Step 3: Update the schema messages**

In `BankSchema.ts`, change the two `shortName` messages (keep the `.min(1, …)`/`.max(50, …)` structure and the field key):

```ts
shortName: z
  .string({ error: "The code field is required." })
  .trim()
  .min(1, "The code field is required.")
  .max(50, "The code must not exceed 50 characters."),
```

Also update the doc-comment line that reads `- \`shortName\` — \`#[Assert\NotBlank]\`, …` only if it quotes the message text (it does not; leave the constraint summary as-is).

- [ ] **Step 4: Run the test to verify it passes**

Run: `make pwa.test.unit c='tests/context/backoffice/bank/application/schemas/BankSchema.test.ts'`
Expected: PASS

### Task 2: API validation messages → "code"

**Files:**
- Modify: `api/src/Backoffice/Bank/Application/Command/CreateBankCommand.php:24-25`
- Modify: `api/src/Backoffice/Bank/Application/Command/UpdateBankCommand.php` (same two attributes)
- Modify: `api/src/Backoffice/Bank/Domain/Entity/Bank.php:30`

> No API unit/functional/Behat test asserts these strings (verified by grep over `api/tests` and `api/features`). The cross-stack guard is the PWA real-api e2e in Task 4. This task is a synchronized string change verified by the PHP gates.

- [ ] **Step 1: Change the two command messages (both files)**

In `CreateBankCommand.php` and `UpdateBankCommand.php`, the `$shortName` constructor parameter:

```php
#[Assert\NotBlank(message: 'The code field is required.')]
#[Assert\Length(max: 50, maxMessage: 'The code must not exceed {{ limit }} characters.')]
public string $shortName = '',
```

- [ ] **Step 2: Change the UniqueEntity message**

In `Bank.php` line 30:

```php
#[UniqueEntity(fields: ['shortName'], message: 'This code is already in use.')]
```

- [ ] **Step 3: Static analysis on the three files**

Run: `make php.stan PHP_SERVICE=messenger_worker`
Expected: no new errors. (Use `PHP_SERVICE=messenger_worker` only if the `php` web worker is restart-looping; otherwise plain `make php.stan`.)

- [ ] **Step 4: Confirm no functional/Behat test asserts the old strings**

Run: `make php.unit c='--filter Bank'` and, if bank Behat features exist, `make php.behat`
Expected: PASS (no assertion references the old "short name" message). If one surfaces, update it to the "code" wording.

### Task 3: PWA visible labels → "Code"

**Files:**
- Modify: `pwa/src/app/backoffice/banks/_lib/banksFilterSort.ts` (`BANKS_SORTABLE_COLUMNS`)
- Test: `pwa/tests/app/backoffice/banks/banksFilterSort.test.ts`
- Modify: `pwa/src/app/backoffice/banks/_components/BanksFilters.tsx` (short-name `FormField` label)
- Modify: `pwa/src/app/backoffice/banks/_components/BankForm.tsx:217`
- Modify: `pwa/src/app/backoffice/banks/[id]/page.tsx:305`

- [ ] **Step 1: Add a failing assertion for the sortable-column label**

In `banksFilterSort.test.ts`, inside `describe("BANKS_SORTABLE_COLUMNS")`, add:

```ts
it("labels the shortName column 'Code' (the unique upper-case bank code)", () => {
  const column = BANKS_SORTABLE_COLUMNS.find((c) => c.id === "shortName");
  expect(column?.label).toBe("Code");
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `make pwa.test.unit c='tests/app/backoffice/banks/banksFilterSort.test.ts'`
Expected: FAIL — received "Short name".

- [ ] **Step 3: Rename the column label**

In `banksFilterSort.ts`, the `BANKS_SORTABLE_COLUMNS` entry for `shortName`:

```ts
{ id: "shortName", label: "Code" },
```

- [ ] **Step 4: Run it to verify it passes**

Run: `make pwa.test.unit c='tests/app/backoffice/banks/banksFilterSort.test.ts'`
Expected: PASS

- [ ] **Step 5: Rename the three remaining visible labels**

`BanksFilters.tsx` — the short-name field:

```tsx
<FormField name="banks-filters-short-name" label="Code">
```

`BankForm.tsx` line ~217:

```tsx
<FormField
  name="shortName"
  label="Code"
  required
  error={errors.shortName?.message}
  helper={'Saved in upper-case ASCII without accents — e.g. "bbva" → "BBVA", "GLÉ" → "GLE".'}
>
```

`[id]/page.tsx` line ~305:

```tsx
<Field
  label="Code"
  value={bank.shortName}
  valueClassName="font-mono text-xs uppercase"
  testId="banks-detail__field-shortname"
/>
```

(Keep all `name=` / `data-testid=` / field keys as `shortName` / `short-name`.)

- [ ] **Step 6: Verify quality + existing component tests still pass**

Run: `make pwa.quality`
Run: `make pwa.test.unit c='tests/app/backoffice/banks/'`
Expected: PASS (no test asserts the visible "Short name" label — form/detail tests use testids).

### Task 4: Update e2e message assertions + mock fixture; commit Part B

**Files:**
- Modify: `pwa/tests/e2e/backoffice/banks.spec.ts:279`
- Modify: `pwa/tests/e2e/fixtures/banks-api.ts:381`
- Modify: `pwa/tests/e2e/backoffice/banks-real-api-flows.spec.ts:190` and its test title at line ~114

- [ ] **Step 1: Update the mocked 422 violation message (fixture)**

`banks-api.ts:381`:

```ts
message: "The code must not exceed 50 characters.",
```

- [ ] **Step 2: Update the two e2e assertions and the "Short name" test title**

`banks.spec.ts:279`:

```ts
await expect(page.getByText("The code must not exceed 50 characters.")).toBeVisible();
```

`banks-real-api-flows.spec.ts:190`:

```ts
await expect(page.getByText("The code field is required.")).toBeVisible();
```

`banks-real-api-flows.spec.ts:114` — rename the test title for consistency:

```ts
test("sort — Code column flips ascending and descending", async ({ page }) => {
```

If that test selects the column header by accessible name, confirm it targets `"Code"` (the `BanksTable` header is already "Code"); switch any `name: "Short name"` header locator to `name: "Code"`. Comments mentioning "short name" may stay or be updated to "code" — cosmetic.

- [ ] **Step 3: Run the affected e2e**

Run: `cd pwa && PLAYWRIGHT_HOST_PLATFORM_OVERRIDE=ubuntu24.04-x64 npx playwright test backoffice/banks.spec.ts -g "validation" --workers=1`
Then the real-api flow if the local stack is seeded: `... npx playwright test backoffice/banks-real-api-flows.spec.ts -g "required" --workers=1`
Expected: PASS (the create/length flows now assert the "code" wording).

- [ ] **Step 4: Commit Part B**

```bash
git add pwa/src/context/backoffice/bank/application/schemas/BankSchema.ts \
        pwa/tests/context/backoffice/bank/application/schemas/BankSchema.test.ts \
        api/src/Backoffice/Bank/Application/Command/CreateBankCommand.php \
        api/src/Backoffice/Bank/Application/Command/UpdateBankCommand.php \
        api/src/Backoffice/Bank/Domain/Entity/Bank.php \
        pwa/src/app/backoffice/banks/_lib/banksFilterSort.ts \
        pwa/tests/app/backoffice/banks/banksFilterSort.test.ts \
        pwa/src/app/backoffice/banks/_components/BanksFilters.tsx \
        pwa/src/app/backoffice/banks/_components/BankForm.tsx \
        "pwa/src/app/backoffice/banks/[id]/page.tsx" \
        pwa/tests/e2e/backoffice/banks.spec.ts \
        pwa/tests/e2e/fixtures/banks-api.ts \
        pwa/tests/e2e/backoffice/banks-real-api-flows.spec.ts
git commit -m 'refactor(banks): unify shortName label to "Code"'
```

(End the message with the `Co-Authored-By` trailer per repo convention.)

---

## Part A — Active-filter chip bar

### Task 5: `buildActiveFilterChips` pure helper

**Files:**
- Modify: `pwa/src/app/backoffice/banks/_lib/banksFilterSort.ts`
- Test: `pwa/tests/app/backoffice/banks/banksFilterSort.test.ts`

- [ ] **Step 1: Write the failing tests**

First, extend the **top** import block (imports must stay at the top — ESLint `import/first`): add `buildActiveFilterChips` to the existing `@/app/backoffice/banks/_lib/banksFilterSort` import, and add a new line `import { dateTimeProvider } from "@/context/shared/infrastructure/DateTimeProvider";`.

Then append this describe block to `banksFilterSort.test.ts`:

```ts
describe("buildActiveFilterChips", () => {
  it("returns no chips for the empty filter at the default sort", () => {
    expect(buildActiveFilterChips(EMPTY_FILTER, DEFAULT_SORT, dateTimeProvider)).toEqual([]);
  });

  it("labels name and code chips", () => {
    const chips = buildActiveFilterChips(
      { ...EMPTY_FILTER, name: "Acme", shortName: "ACM" },
      DEFAULT_SORT,
      dateTimeProvider,
    );
    expect(chips).toEqual([
      { key: "name", label: "Name: Acme" },
      { key: "shortName", label: "Code: ACM" },
    ]);
  });

  it("renders an adaptive created chip (range / after / before) as dd/mm/yyyy", () => {
    const range = buildActiveFilterChips(
      { ...EMPTY_FILTER, createdFrom: "2026-06-01", createdTo: "2026-06-30" },
      DEFAULT_SORT,
      dateTimeProvider,
    );
    expect(range[0]).toEqual({ key: "created", label: "Created: 01/06/2026 – 30/06/2026" });

    const from = buildActiveFilterChips(
      { ...EMPTY_FILTER, createdFrom: "2026-06-01" },
      DEFAULT_SORT,
      dateTimeProvider,
    );
    expect(from[0]).toEqual({ key: "created", label: "Created after: 01/06/2026" });

    const to = buildActiveFilterChips(
      { ...EMPTY_FILTER, createdTo: "2026-06-30" },
      DEFAULT_SORT,
      dateTimeProvider,
    );
    expect(to[0]).toEqual({ key: "created", label: "Created before: 30/06/2026" });
  });

  it("adds a sort chip only when sort drifts from the default", () => {
    expect(
      buildActiveFilterChips(EMPTY_FILTER, { columnId: "createdAt", direction: "desc" }, dateTimeProvider),
    ).toEqual([{ key: "sort", label: "Sorted: Created ↓" }]);
    expect(buildActiveFilterChips(EMPTY_FILTER, null, dateTimeProvider)).toEqual([
      { key: "sort", label: "Unsorted" },
    ]);
    expect(buildActiveFilterChips(EMPTY_FILTER, DEFAULT_SORT, dateTimeProvider)).toEqual([]);
  });

  it("treats whitespace-only text filters as inactive", () => {
    expect(
      buildActiveFilterChips({ ...EMPTY_FILTER, name: "  ", shortName: " " }, DEFAULT_SORT, dateTimeProvider),
    ).toEqual([]);
  });
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `make pwa.test.unit c='tests/app/backoffice/banks/banksFilterSort.test.ts'`
Expected: FAIL — `buildActiveFilterChips` is not exported.

- [ ] **Step 3: Implement the helper**

Append to `banksFilterSort.ts` (add the `SortDirection` import if not present — it already imports it):

```ts
import type { DateTimeProvider } from "@/context/shared/domain/DateTimeProvider/DateTimeProvider";

export type FilterChipKey = "name" | "shortName" | "created" | "sort";

export interface FilterChipDescriptor {
  key: FilterChipKey;
  /** Human label rendered inside the chip, e.g. `Code: ACM`. */
  label: string;
}

const SORT_ARROW: Record<SortDirection, string> = {
  [SortDirection.ASC]: "↑",
  [SortDirection.DESC]: "↓",
};

function formatCreatedChipLabel(filter: BanksFilter, dateTime: DateTimeProvider): string | null {
  const from = filter.createdFrom.trim();
  const to = filter.createdTo.trim();
  const fmt = (iso: string): string => {
    const parsed = dateTime.parseISO(iso);
    return parsed ? dateTime.formatToDate(parsed) : iso;
  };
  if (from && to) return `Created: ${fmt(from)} – ${fmt(to)}`;
  if (from) return `Created after: ${fmt(from)}`;
  if (to) return `Created before: ${fmt(to)}`;
  return null;
}

/**
 * Descriptors for the always-visible active-filter chip bar. Mirrors the same
 * "active" rules as the badge/Reset helpers above; the name search lives in the
 * toolbar but still earns a chip so the bar is a complete summary. The sort
 * chip appears for any drift from {@link DEFAULT_SORT}, including the user's
 * explicit "None" (`null`), which reads as "Unsorted".
 */
export function buildActiveFilterChips(
  filter: BanksFilter,
  sort: BanksSort,
  dateTime: DateTimeProvider,
): FilterChipDescriptor[] {
  const chips: FilterChipDescriptor[] = [];
  if (filter.name.trim()) chips.push({ key: "name", label: `Name: ${filter.name.trim()}` });
  if (filter.shortName.trim()) chips.push({ key: "shortName", label: `Code: ${filter.shortName.trim()}` });

  const created = formatCreatedChipLabel(filter, dateTime);
  if (created) chips.push({ key: "created", label: created });

  if (!isDefaultSort(sort)) {
    if (sort) {
      const column = BANKS_SORTABLE_COLUMNS.find((c) => c.id === sort.columnId);
      chips.push({ key: "sort", label: `Sorted: ${column?.label ?? sort.columnId} ${SORT_ARROW[sort.direction]}` });
    } else {
      chips.push({ key: "sort", label: "Unsorted" });
    }
  }
  return chips;
}
```

- [ ] **Step 4: Run to verify it passes**

Run: `make pwa.test.unit c='tests/app/backoffice/banks/banksFilterSort.test.ts'`
Expected: PASS

### Task 6: `BanksActiveFilters` presentational component

**Files:**
- Create: `pwa/src/app/backoffice/banks/_components/BanksActiveFilters.tsx`
- Test: `pwa/tests/app/backoffice/banks/banksActiveFilters.test.tsx`

- [ ] **Step 1: Write the failing test**

Create `banksActiveFilters.test.tsx`:

```tsx
import { describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen } from "@testing-library/react";
import { BanksActiveFilters } from "@/app/backoffice/banks/_components/BanksActiveFilters";

const chips = [
  { key: "name" as const, label: "Name: Acme" },
  { key: "shortName" as const, label: "Code: ACM" },
];

it("renders a chip per descriptor and a Clear all button", () => {
  render(<BanksActiveFilters chips={chips} onRemove={vi.fn()} onClearAll={vi.fn()} />);
  expect(screen.getByTestId("banks-filters__active")).toBeInTheDocument();
  expect(screen.getByText("Name: Acme")).toBeInTheDocument();
  expect(screen.getByText("Code: ACM")).toBeInTheDocument();
  expect(screen.getByTestId("banks-filters__clear-all")).toBeInTheDocument();
});

it("calls onRemove with the chip key when its ✕ is clicked", () => {
  const onRemove = vi.fn();
  render(<BanksActiveFilters chips={chips} onRemove={onRemove} onClearAll={vi.fn()} />);
  fireEvent.click(screen.getByTestId("banks-filters__chip-shortName"));
  expect(onRemove).toHaveBeenCalledWith("shortName");
});

it("calls onClearAll when Clear all is clicked", () => {
  const onClearAll = vi.fn();
  render(<BanksActiveFilters chips={chips} onRemove={vi.fn()} onClearAll={onClearAll} />);
  fireEvent.click(screen.getByTestId("banks-filters__clear-all"));
  expect(onClearAll).toHaveBeenCalledTimes(1);
});

it("moves focus to the next chip after a non-last chip is removed", () => {
  const { rerender } = render(
    <BanksActiveFilters chips={chips} onRemove={vi.fn()} onClearAll={vi.fn()} />,
  );
  fireEvent.click(screen.getByTestId("banks-filters__chip-name")); // remove index 0
  // Parent re-renders with the remaining chip now at index 0.
  rerender(
    <BanksActiveFilters
      chips={[{ key: "shortName", label: "Code: ACM" }]}
      onRemove={vi.fn()}
      onClearAll={vi.fn()}
    />,
  );
  expect(screen.getByTestId("banks-filters__chip-shortName")).toHaveFocus();
});

it("moves focus to Clear all after the last chip is removed", () => {
  const { rerender } = render(
    <BanksActiveFilters chips={chips} onRemove={vi.fn()} onClearAll={vi.fn()} />,
  );
  fireEvent.click(screen.getByTestId("banks-filters__chip-shortName")); // remove index 1 (last)
  rerender(
    <BanksActiveFilters
      chips={[{ key: "name", label: "Name: Acme" }]}
      onRemove={vi.fn()}
      onClearAll={vi.fn()}
    />,
  );
  expect(screen.getByTestId("banks-filters__clear-all")).toHaveFocus();
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `make pwa.test.unit c='tests/app/backoffice/banks/banksActiveFilters.test.tsx'`
Expected: FAIL — module `BanksActiveFilters` not found.

- [ ] **Step 3: Implement the component**

Create `BanksActiveFilters.tsx`:

```tsx
"use client";

import { useEffect, useRef } from "react";
import { X } from "lucide-react";
import { Button } from "@/components/ui/button";
import type { FilterChipDescriptor, FilterChipKey } from "../_lib/banksFilterSort";

interface BanksActiveFiltersProps {
  chips: ReadonlyArray<FilterChipDescriptor>;
  /** Remove a single filter by chip key. */
  onRemove: (key: FilterChipKey) => void;
  /** Clear every filter + reset sort. */
  onClearAll: () => void;
}

/**
 * Always-visible summary of the active filters/sort: one removable chip each,
 * plus a single Clear all. Purely presentational — `BanksFilters` owns the
 * filter state and passes the chip descriptors + callbacks.
 *
 * Focus after a per-chip removal stays inside the bar: the next chip's ✕, or
 * Clear all when the removed chip was the last one. The bar-emptied case (no
 * chips left) is handled by the parent, which focuses the Filters toggle.
 */
export function BanksActiveFilters({ chips, onRemove, onClearAll }: Readonly<BanksActiveFiltersProps>) {
  // Keyed by chip.key (NOT index): React reconciles chips by key, so an
  // index-keyed ref array would null out the wrong slot when a middle chip
  // unmounts. We record the removed chip's position, then after re-render focus
  // whatever chip now occupies that slot (the "next" one), or Clear all.
  const removeRefs = useRef<Map<FilterChipKey, HTMLButtonElement>>(new Map());
  const clearAllRef = useRef<HTMLButtonElement>(null);
  const pendingFocusIndex = useRef<number | null>(null);

  const handleRemove = (index: number, key: FilterChipKey): void => {
    pendingFocusIndex.current = index;
    onRemove(key);
  };

  useEffect(() => {
    const index = pendingFocusIndex.current;
    if (index === null) return;
    pendingFocusIndex.current = null;
    // `chips` is the post-removal array: the chip that followed the removed one
    // now sits at `index`. Focus its ✕; if the removed chip was last, focus
    // Clear all.
    const nextChip = chips[index];
    const next = nextChip ? removeRefs.current.get(nextChip.key) : undefined;
    (next ?? clearAllRef.current)?.focus();
  }, [chips]);

  return (
    <section
      className="banks-filters__active mt-3 flex flex-wrap items-center gap-2"
      aria-label="Active filters"
      data-testid="banks-filters__active"
    >
      {chips.map((chip, index) => (
        <span
          key={chip.key}
          className="banks-filters__chip border-border bg-muted/40 text-foreground inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-xs"
        >
          {chip.label}
          <button
            type="button"
            ref={(el) => {
              if (el) removeRefs.current.set(chip.key, el);
              else removeRefs.current.delete(chip.key);
            }}
            onClick={() => handleRemove(index, chip.key)}
            className="banks-filters__chip-remove text-muted-foreground hover:text-foreground focus-visible:ring-ring -mr-0.5 inline-flex size-4 items-center justify-center rounded-full focus-visible:ring-2 focus-visible:outline-none"
            aria-label={`Remove filter ${chip.label}`}
            title={`Remove filter ${chip.label}`}
            data-testid={`banks-filters__chip-${chip.key}`}
          >
            <X className="size-3" aria-hidden="true" />
          </button>
        </span>
      ))}
      <Button
        type="button"
        ref={clearAllRef}
        variant="outline"
        size="sm"
        onClick={onClearAll}
        aria-label="Clear all filters and sort"
        title="Clear all filters and sort"
        className="banks-filters__clear-all min-h-7"
        data-testid="banks-filters__clear-all"
      >
        Clear all
      </Button>
    </section>
  );
}
```

> If `@/components/ui/button`'s `Button` does not forward a `ref`, render a native `<button>` styled with `buttonVariants({ variant: "outline", size: "sm" })` from `@/components/ui/button-variants` instead, so `clearAllRef` works. Verify by checking whether `Button` is wrapped in `forwardRef`.

- [ ] **Step 4: Run to verify it passes**

Run: `make pwa.test.unit c='tests/app/backoffice/banks/banksActiveFilters.test.tsx'`
Expected: PASS (all 5 cases, including the two focus cases).

### Task 7: Wire the chip bar into `BanksFilters`; remove the panel Reset

**Files:**
- Modify: `pwa/src/app/backoffice/banks/_components/BanksFilters.tsx`
- Test: `pwa/tests/app/backoffice/banks/banksFiltersDebounce.test.tsx`

- [ ] **Step 1: Write a failing integration test (chip removal cancels the debounce; bar replaces the panel Reset)**

In `banksFiltersDebounce.test.tsx`, replace the existing test that clicks `banks-filters__reset` (the "clears the name input on Reset even when the debounce has not propagated yet" test) so it drives the chip bar's Clear all, and add a code-chip removal case. Use these two tests:

```ts
it("Clear all clears a pending (un-propagated) name input and cancels the debounce", () => {
  const onFilterChange = vi.fn();
  const onReset = vi.fn();
  render(
    <BanksFilters
      filter={{ ...EMPTY_FILTER, shortName: "x" }}
      onFilterChange={onFilterChange}
      sort={DEFAULT_SORT}
      onSortChange={vi.fn()}
      onReset={onReset}
      defaultOpen
    />,
  );

  const nameInput = screen.getByTestId("banks-filters__name") as HTMLInputElement;
  fireEvent.change(nameInput, { target: { value: "cosmos" } });

  fireEvent.click(screen.getByTestId("banks-filters__clear-all"));
  expect(onReset).toHaveBeenCalledTimes(1);
  expect((screen.getByTestId("banks-filters__name") as HTMLInputElement).value).toBe("");

  act(() => {
    vi.advanceTimersByTime(300);
  });
  expect(onFilterChange).not.toHaveBeenCalled();
});

it("removing the code chip clears shortName without re-applying a pending value", () => {
  const onFilterChange = vi.fn();
  render(
    <BanksFilters
      filter={{ ...EMPTY_FILTER, shortName: "ACM" }}
      onFilterChange={onFilterChange}
      sort={DEFAULT_SORT}
      onSortChange={vi.fn()}
      onReset={vi.fn()}
      defaultOpen
    />,
  );

  fireEvent.click(screen.getByTestId("banks-filters__chip-shortName"));
  expect(onFilterChange).toHaveBeenCalledWith(expect.objectContaining({ shortName: "" }));
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `make pwa.test.unit c='tests/app/backoffice/banks/banksFiltersDebounce.test.tsx'`
Expected: FAIL — `banks-filters__clear-all` / `banks-filters__chip-shortName` not found.

- [ ] **Step 3: Add imports + the toggle ref + chip/removal wiring in `BanksFilters.tsx`**

Add imports:

```tsx
import { dateTimeProvider } from "@/context/shared/infrastructure/DateTimeProvider";
import { buildActiveFilterChips, type FilterChipKey } from "../_lib/banksFilterSort";
import { BanksActiveFilters } from "./BanksActiveFilters";
```

Add a ref for the Filters toggle and a pending-focus flag near the other refs:

```tsx
const toggleRef = useRef<HTMLButtonElement>(null);
const pendingToggleFocus = useRef(false);
```

Add the removal handler and Clear-all wrapper (place beside `handleReset`):

```tsx
const chips = buildActiveFilterChips(filter, sort, dateTimeProvider);

const willEmptyAfter = (nextFilter: BanksFilter, nextSort: BanksSort): boolean =>
  !hasActiveFilter(nextFilter) && isDefaultSort(nextSort);

const handleClearAll = (): void => {
  pendingToggleFocus.current = true;
  handleReset();
};

const handleRemoveChip = (key: FilterChipKey): void => {
  let nextFilter = filter;
  let nextSort = sort;
  switch (key) {
    case "name":
      nextFilter = { ...filter, name: "" };
      setNameInput("");
      break;
    case "shortName":
      nextFilter = { ...filter, shortName: "" };
      setShortNameInput("");
      break;
    case "created":
      nextFilter = { ...filter, createdFrom: "", createdTo: "" };
      break;
    case "sort":
      nextSort = DEFAULT_SORT;
      break;
  }
  if (willEmptyAfter(nextFilter, nextSort)) {
    pendingToggleFocus.current = true;
  }
  if (nextSort !== sort) onSortChange(nextSort);
  if (nextFilter !== filter) onFilterChange(nextFilter);
};
```

Add the effect that focuses the toggle once the bar disappears after a clear (place beside the other effects):

```tsx
useEffect(() => {
  if (!canReset && pendingToggleFocus.current) {
    pendingToggleFocus.current = false;
    toggleRef.current?.focus();
  }
}, [canReset]);
```

> `hasActiveFilter`, `isDefaultSort`, `BanksFilter`, `BanksSort` are already imported from `../_lib/banksFilterSort`. **Add `DEFAULT_SORT`** to that import (it is not currently imported) alongside `buildActiveFilterChips` / `FilterChipKey` from Step 3's import line.

- [ ] **Step 4: Attach the toggle ref, render the bar, and delete the panel Reset block**

On the Filters toggle `<Button>`, add `ref={toggleRef}`.

Insert the bar **between** the `banks-filters__toolbar` div and the `banks-filters__panel` section:

```tsx
{canReset ? (
  <BanksActiveFilters chips={chips} onRemove={handleRemoveChip} onClearAll={handleClearAll} />
) : null}
```

Delete the entire panel-internal Reset block (the `{canReset ? (<div className="banks-filters__actions …"> … data-testid="banks-filters__reset" … </div>) : null}` at the end of `banks-filters__panel-fields`).

- [ ] **Step 5: Run to verify it passes (and the existing debounce sync test still passes)**

Run: `make pwa.test.unit c='tests/app/backoffice/banks/banksFiltersDebounce.test.tsx'`
Expected: PASS — both new tests and the retained "syncs the inputs down…" test.

- [ ] **Step 6: Run the banks component suite for regressions**

Run: `make pwa.test.unit c='tests/app/backoffice/banks/'`
Expected: PASS. (If `banksFiltersToolbarSearch.test.tsx` referenced `banks-filters__reset`, update it to `banks-filters__clear-all`.)

### Task 8: Unify the empty-filtered Clear all

**Files:**
- Modify: `pwa/src/app/backoffice/banks/_components/BanksEmptyFiltered.tsx`
- Test: `pwa/tests/app/backoffice/banks/banksEmptyFiltered.test.tsx`

- [ ] **Step 1: Update the test to expect the unified "Clear all" label**

In `banksEmptyFiltered.test.tsx`, change the button query/label expectation to "Clear all" (keep the `onReset` callback assertion and the `data-testid="banks-list__reset-filters"`):

```ts
fireEvent.click(screen.getByRole("button", { name: /clear all/i }));
expect(onReset).toHaveBeenCalledTimes(1);
```

- [ ] **Step 2: Run to verify it fails**

Run: `make pwa.test.unit c='tests/app/backoffice/banks/banksEmptyFiltered.test.tsx'`
Expected: FAIL — current button is named "Reset filters".

- [ ] **Step 3: Update the button label + aria/title to match the chip bar**

In `BanksEmptyFiltered.tsx`, change the button text to `Clear all`, `aria-label="Clear all filters and sort"`, `title="Clear all filters and sort"` (keep `onClick={onReset}` and `data-testid="banks-list__reset-filters"`).

- [ ] **Step 4: Run to verify it passes**

Run: `make pwa.test.unit c='tests/app/backoffice/banks/banksEmptyFiltered.test.tsx'`
Expected: PASS

### Task 9: e2e rework for the chip bar; commit Part A

**Files:**
- Modify: `pwa/tests/e2e/backoffice/banks.spec.ts`
- Modify: `pwa/tests/e2e/backoffice/banks-real-api-flows.spec.ts`

The old reset testid `banks-filters__reset` lived inside the panel, so several tests opened the panel first. The chip bar is always visible, so panel-open is no longer required to reach Clear all. Apply these mappings:

- [ ] **Step 1: Replace `banks-filters__reset` with `banks-filters__clear-all`**

In `banks.spec.ts`, update every occurrence (lines ~382, 385, 387, 389, 434, 583, 708, 711, 713, 716, 1067, 1076) from `banks-filters__reset` to `banks-filters__clear-all`. Remove any now-unnecessary `banks-filters__toggle` click that existed *only* to reveal the reset control (keep toggle clicks that assert panel behaviour). In `banks-real-api-flows.spec.ts:109`, change `banks-filters__reset` → `banks-filters__clear-all`.

For the two visibility tests, the semantics are unchanged (Clear all appears iff `canReset`):

```ts
// "Reset button appears when sort drifts even without an active filter" (rename to Clear all)
await expect(page.getByTestId("banks-filters__clear-all")).toHaveCount(0);
await page.getByTestId("banks-filters__sort-direction").selectOption("desc");
await expect(page.getByTestId("banks-filters__clear-all")).toBeVisible();
await page.getByTestId("banks-filters__clear-all").click();
await expect(page.getByTestId("banks-filters__sort-by")).toHaveValue("name");
await expect(page.getByTestId("banks-filters__clear-all")).toHaveCount(0);
```

Note: with `sort-by`/`sort-direction` inside the panel, the test must still open the toggle to reach those selects — keep that toggle click; only the reset control moved out.

- [ ] **Step 2: Add a chip-removal e2e**

Add to the "filters and sort" describe in `banks.spec.ts`:

```ts
test("active-filter chips appear in the bar and remove individually", async ({ page }) => {
  await mockBanksApi(page, { list: "happy", list_banks: allBanks });
  await page.goto("/backoffice/banks");

  await page.getByTestId("banks-filters__name").fill("cosmos");
  // The chip bar is visible without opening the panel.
  await expect(page.getByTestId("banks-filters__active")).toBeVisible();
  await expect(page.getByTestId("banks-filters__chip-name")).toBeVisible();

  await page.getByTestId("banks-filters__chip-name").click();
  await expect(page.getByTestId("banks-filters__name")).toHaveValue("");
  await expect(page.getByTestId("banks-filters__active")).toHaveCount(0);
});
```

- [ ] **Step 3: Update the empty-filtered label assertion (if any)**

`banks.spec.ts:489` clicks `banks-list__reset-filters` (testid unchanged) — no change needed. If a sibling assertion checks the button *text* "Reset filters", change it to "Clear all".

- [ ] **Step 4: Run the reworked e2e**

Run: `cd pwa && PLAYWRIGHT_HOST_PLATFORM_OVERRIDE=ubuntu24.04-x64 npx playwright test backoffice/banks.spec.ts --workers=2`
Expected: PASS (58 prior + the new chip test; reset assertions now target the chip bar).

- [ ] **Step 5: Full PWA gates**

Run: `make pwa.quality`
Run: `make pwa.test.unit`
Expected: PASS, `EXIT=0`.

- [ ] **Step 6: Commit Part A**

```bash
git add pwa/src/app/backoffice/banks/_lib/banksFilterSort.ts \
        pwa/src/app/backoffice/banks/_components/BanksActiveFilters.tsx \
        pwa/src/app/backoffice/banks/_components/BanksFilters.tsx \
        pwa/src/app/backoffice/banks/_components/BanksEmptyFiltered.tsx \
        pwa/tests/app/backoffice/banks/banksFilterSort.test.ts \
        pwa/tests/app/backoffice/banks/banksActiveFilters.test.tsx \
        pwa/tests/app/backoffice/banks/banksFiltersDebounce.test.tsx \
        pwa/tests/app/backoffice/banks/banksEmptyFiltered.test.tsx \
        pwa/tests/e2e/backoffice/banks.spec.ts \
        pwa/tests/e2e/backoffice/banks-real-api-flows.spec.ts
git commit -m 'feat(banks): active-filter chip bar with reachable clear-all'
```

(End the message with the `Co-Authored-By` trailer.)

---

## Final verification

- [ ] `make pwa.quality` → `EXIT=0`
- [ ] `make pwa.test.unit` → all PASS
- [ ] `cd pwa && PLAYWRIGHT_HOST_PLATFORM_OVERRIDE=ubuntu24.04-x64 npx playwright test backoffice/banks.spec.ts` → all PASS
- [ ] `make php.stan` + `make php.quality` → no new findings (Part B touched PHP)
- [ ] Self-check the security checklist (CSS/labels/strings only — no XSS/injection/auth surface; the chip labels render API/user text as escaped JSX text, never as HTML or an `href`).

## Notes / risks

- **Debounce race** is covered by Task 7's chip-removal + Clear-all tests: name/code removal clears the local mirror so a pending 300 ms debounce cannot re-apply the value.
- **e2e flakiness**: mocked specs can see real rows via the live Mercure stream under parallel runs; keep id-scoped locators and rerun a spec in isolation before investigating. The 300 ms filter debounce means chip assertions should wait for `banks-filters__active` before acting.
- **`Button` ref**: if `@/components/ui/button` does not `forwardRef`, use the `buttonVariants` + native `<button>` fallback noted in Task 6 Step 3 for `clearAllRef`.
- **Cards/stacked surfaces**: the chip bar lives in `BanksFilters`, shared by table and cards views, so no per-surface work is needed.
