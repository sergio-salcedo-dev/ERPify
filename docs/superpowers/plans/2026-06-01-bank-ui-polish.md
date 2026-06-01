# Bank UI Polish Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Polish the back-office Banks UI with consistent transient feedback, smoother loading/error/empty states, and debounced filtering — without touching the DDD/Inversify architecture or the API.

**Architecture:** All changes live in the PWA (`pwa/`). Two reusable primitives are added (`Spinner` in `@/components/erpify`, `useDebouncedValue` in `@/lib`), the shared `AsyncBoundary` gains an optional `errorAction` slot, and the rest are edits to the existing bank route components under `src/app/backoffice/banks/`. No domain/application/infrastructure layer changes — only `application` use cases are already in place and reused.

**Tech Stack:** Next.js 16 (App Router) · TypeScript (strict) · Tailwind 4 · Shadcn UI · lucide-react · Vitest + @testing-library/react · Playwright. Toast feedback goes through the `toastNotifier` port (`@/context/shared/infrastructure/Notification/Toast`); icons via `lucide-react`; spinners via `Loader2` + `animate-spin`.

**Scope (confirmed with the user):** form success toasts, submit spinner, search debounce, loading skeletons, filter-panel expand/collapse transition, distinct empty-filtered-state styling, truncated-name tooltips, retry-on-error.

**Conventions to honour (from `pwa/CLAUDE.md`):**
- Every action control carries `title` + short static `aria-label` + a textual fallback; decorative icons get `aria-hidden="true"`.
- Static `data-testid` literals must be globally unique (guarded by `tests/data-testid-uniqueness.test.ts`). Reusable components accept a `testId` prop instead of hardcoding.
- `toastNotifier` is typed as the `ToastNotifier` port; messages are plain strings (never HTML).
- BEM-flavoured class names; mobile-first Tailwind.
- Run `make pwa.quality` at the end of the whole task. Run unit tests with `make pwa.test.unit c='<path>'`.

---

## File Structure

**New files:**
- `pwa/src/components/erpify/Spinner.tsx` — decorative animated spinner primitive (one responsibility: render `Loader2` spinning). Exported from the erpify barrel.
- `pwa/src/lib/useDebouncedValue.ts` — generic debounce hook (one responsibility: delay a changing value).
- `pwa/src/app/backoffice/banks/_components/BanksListSkeleton.tsx` — loading placeholder for the list (table or cards shape).
- `pwa/src/app/backoffice/banks/_components/BanksEmptyFiltered.tsx` — the "no banks match your filters" panel, extracted from `page.tsx` so it is independently testable and restyled.
- Test files mirroring the above under `pwa/tests/...`.

**Modified files:**
- `pwa/src/components/erpify/index.ts` — export `Spinner`.
- `pwa/src/components/erpify/AsyncBoundary.tsx` — add optional `errorAction` slot.
- `pwa/src/app/backoffice/banks/_components/BankForm.tsx` — success toast + submit spinner.
- `pwa/src/app/backoffice/banks/_components/DeleteBankButton.tsx` — confirm-button spinner.
- `pwa/src/app/backoffice/banks/_components/BanksFilters.tsx` — debounced text filters + animated panel.
- `pwa/src/app/backoffice/banks/_components/BanksTable.tsx` — `title` tooltip on truncated short-name cell.
- `pwa/src/app/backoffice/banks/_components/BanksCards.tsx` — `title` tooltip on truncated short-name.
- `pwa/src/app/backoffice/banks/page.tsx` — wire skeleton, retry, and the extracted empty-filtered component; refactor fetch into a re-runnable `loadBanks`.

---

## Task 1: `Spinner` primitive

**Files:**
- Create: `pwa/src/components/erpify/Spinner.tsx`
- Modify: `pwa/src/components/erpify/index.ts`
- Test: `pwa/tests/components/erpify/Spinner.test.tsx`

- [ ] **Step 1: Write the failing test**

Create `pwa/tests/components/erpify/Spinner.test.tsx`:

```tsx
import { describe, expect, it } from "vitest";
import { render, screen } from "@testing-library/react";
import { Spinner } from "@/components/erpify";

describe("Spinner", () => {
  it("renders a decorative animated spinner with the merged className", () => {
    render(<Spinner className="size-3.5" testId="x-spinner" />);
    const el = screen.getByTestId("x-spinner");
    expect(el).toHaveClass("animate-spin");
    expect(el).toHaveClass("size-3.5");
    // Decorative: hidden from assistive tech because the surrounding
    // control (button) already carries the accessible name.
    expect(el).toHaveAttribute("aria-hidden", "true");
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `make pwa.test.unit c='tests/components/erpify/Spinner.test.tsx'`
Expected: FAIL — `Spinner` is not exported from `@/components/erpify`.

- [ ] **Step 3: Create the component**

Create `pwa/src/components/erpify/Spinner.tsx`:

```tsx
import { Loader2 } from "lucide-react";
import { cn } from "@/lib/utils";

interface SpinnerProps {
  /** Extra classes; defaults to a 1rem icon. */
  className?: string;
  /** Optional test id passthrough (never hardcode in shared components). */
  testId?: string;
}

/**
 * Decorative loading spinner. Always `aria-hidden` — the control that wraps
 * it (submit button, etc.) already exposes the accessible name (e.g.
 * "Saving…"), so the spinner must not add a second name.
 */
export function Spinner({ className, testId }: Readonly<SpinnerProps>) {
  return (
    <Loader2
      className={cn("size-4 animate-spin", className)}
      aria-hidden="true"
      data-testid={testId}
    />
  );
}
```

- [ ] **Step 4: Export from the barrel**

In `pwa/src/components/erpify/index.ts`, add the export alongside the others (alphabetical-ish, follow the existing style):

```ts
export { Spinner } from "./Spinner";
```

- [ ] **Step 5: Run test to verify it passes**

Run: `make pwa.test.unit c='tests/components/erpify/Spinner.test.tsx'`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add pwa/src/components/erpify/Spinner.tsx pwa/src/components/erpify/index.ts pwa/tests/components/erpify/Spinner.test.tsx
git commit -m "feat(pwa): add Spinner primitive to erpify barrel"
```

---

## Task 2: Form success toasts + submit spinner (`BankForm`)

**Files:**
- Modify: `pwa/src/app/backoffice/banks/_components/BankForm.tsx`
- Test: `pwa/tests/app/backoffice/banks/bankFormFeedback.test.tsx`

- [ ] **Step 1: Write the failing test**

Create `pwa/tests/app/backoffice/banks/bankFormFeedback.test.tsx`:

```tsx
import { beforeEach, describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { BankForm } from "@/app/backoffice/banks/_components/BankForm";
import { Bank } from "@/context/backoffice/bank/domain/Bank";
import { PersistenceAction } from "@/context/shared/domain/types/status";
import { toastNotifier } from "@/context/shared/infrastructure/Notification/Toast";

const CREATED = Bank.fromPrimitives({
  id: "11111111-1111-4111-8111-111111111111",
  name: "Acme Savings",
  shortName: "ACME",
  createdAt: "2026-01-01T10:00:00Z",
  updatedAt: "2026-01-01T10:00:00Z",
});

const push = vi.fn();
const refresh = vi.fn();
vi.mock("next/navigation", () => ({
  useRouter: () => ({ push, refresh, back: vi.fn() }),
}));

const createRun = vi.fn();
const updateRun = vi.fn();
vi.mock("@/context/shared/infrastructure/DependencyInjection/Container", () => ({
  container: {
    get: (token: string) => {
      if (token === "BackOfficeCreateBank") return { run: createRun };
      if (token === "BackOfficeUpdateBank") return { run: updateRun };
      throw new Error(`Unexpected DI token ${token}`);
    },
  },
}));

vi.mock("@/context/shared/infrastructure/Notification/Toast", () => ({
  toastNotifier: { success: vi.fn(), error: vi.fn(), info: vi.fn(), warning: vi.fn() },
}));

describe("BankForm — feedback", () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it("shows a success toast with the bank name after create", async () => {
    createRun.mockResolvedValue(CREATED);
    render(<BankForm mode={PersistenceAction.CREATING} />);

    fireEvent.change(screen.getByTestId("bank-form__name"), { target: { value: "Acme Savings" } });
    fireEvent.change(screen.getByTestId("bank-form__short-name"), { target: { value: "ACME" } });
    fireEvent.submit(screen.getByTestId("bank-form"));

    await waitFor(() => {
      expect(toastNotifier.success).toHaveBeenCalledWith("Bank created", { description: "Acme Savings" });
    });
    expect(push).toHaveBeenCalled();
  });

  it("shows a 'Saving…' spinner while the submit is in flight", async () => {
    let resolveCreate: (b: Bank) => void = () => {};
    createRun.mockReturnValue(new Promise<Bank>((resolve) => {
      resolveCreate = resolve;
    }));
    render(<BankForm mode={PersistenceAction.CREATING} />);

    fireEvent.change(screen.getByTestId("bank-form__name"), { target: { value: "Acme Savings" } });
    fireEvent.change(screen.getByTestId("bank-form__short-name"), { target: { value: "ACME" } });
    fireEvent.submit(screen.getByTestId("bank-form"));

    expect(await screen.findByTestId("bank-form__submit-spinner")).toBeInTheDocument();
    expect(screen.getByTestId("bank-form__submit")).toBeDisabled();

    resolveCreate(CREATED);
    await waitFor(() => {
      expect(screen.queryByTestId("bank-form__submit-spinner")).not.toBeInTheDocument();
    });
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `make pwa.test.unit c='tests/app/backoffice/banks/bankFormFeedback.test.tsx'`
Expected: FAIL — no toast call on success; no `bank-form__submit-spinner` element.

- [ ] **Step 3: Add the toast import and Spinner import**

In `pwa/src/app/backoffice/banks/_components/BankForm.tsx`, add to the imports (after the existing `@/components/erpify` import on line 17):

```tsx
import { FormField, ProblemDisplay, Spinner } from "@/components/erpify";
import { toastNotifier } from "@/context/shared/infrastructure/Notification/Toast";
```

(Replace the existing `import { FormField, ProblemDisplay } from "@/components/erpify";` line with the first line above; add the `toastNotifier` line below it.)

- [ ] **Step 4: Fire the success toast in `onSubmit`**

In the `onSubmit` handler, surface a toast immediately before each navigation. Replace the create branch and the update tail so they read:

```tsx
      if (mode === PersistenceAction.CREATING) {
        const useCase = container.get<CreateBank>("BackOfficeCreateBank");
        const created = await useCase.run(values);
        toastNotifier.success("Bank created", { description: created.name });
        router.push(safeHref(bankRoutes.detail(created.id)));
        router.refresh();
        return;
      }

      if (!initial) {
        throw new Error("BankForm in edit mode requires `initial`.");
      }

      const useCase = container.get<UpdateBank>("BackOfficeUpdateBank");
      const updated = await useCase.run(initial.id, values);
      toastNotifier.success("Changes saved", { description: updated.name });
      router.push(safeHref(bankRoutes.detail(updated.id)));
      router.refresh();
```

- [ ] **Step 5: Render the spinner inside the submit button**

Replace the submit `<Button>` block (lines ~192–202) so its children show the spinner while submitting. Note `submitButtonLabel` is no longer needed — delete the `const submitButtonLabel = …` line (line 121) and replace the button:

```tsx
        <Button
          type="submit"
          size="sm"
          disabled={submitting}
          data-icon={submitting ? "inline-start" : undefined}
          aria-label={mode === PersistenceAction.CREATING ? "Create bank" : "Save bank changes"}
          title={mode === PersistenceAction.CREATING ? "Create bank" : "Save bank changes"}
          className="w-full sm:w-auto"
          data-testid="bank-form__submit"
        >
          {submitting ? (
            <>
              <Spinner className="size-3.5" testId="bank-form__submit-spinner" />
              Saving…
            </>
          ) : (
            submitLabelIdle
          )}
        </Button>
```

- [ ] **Step 6: Run test to verify it passes**

Run: `make pwa.test.unit c='tests/app/backoffice/banks/bankFormFeedback.test.tsx'`
Expected: PASS

- [ ] **Step 7: Commit**

```bash
git add pwa/src/app/backoffice/banks/_components/BankForm.tsx pwa/tests/app/backoffice/banks/bankFormFeedback.test.tsx
git commit -m "feat(pwa): add create/update success toast and submit spinner to BankForm"
```

---

## Task 3: Delete-confirm spinner (`DeleteBankButton`)

**Files:**
- Modify: `pwa/src/app/backoffice/banks/_components/DeleteBankButton.tsx`
- Test: `pwa/tests/app/backoffice/banks/deleteBankButtonSpinner.test.tsx`

- [ ] **Step 1: Write the failing test**

Create `pwa/tests/app/backoffice/banks/deleteBankButtonSpinner.test.tsx`:

```tsx
import { beforeEach, describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { DeleteBankButton } from "@/app/backoffice/banks/_components/DeleteBankButton";

vi.mock("next/navigation", () => ({
  useRouter: () => ({ push: vi.fn(), refresh: vi.fn(), back: vi.fn() }),
}));

const deleteRun = vi.fn();
vi.mock("@/context/shared/infrastructure/DependencyInjection/Container", () => ({
  container: {
    get: (token: string) => {
      if (token === "BackOfficeDeleteBank") return { run: deleteRun };
      throw new Error(`Unexpected DI token ${token}`);
    },
  },
}));

describe("DeleteBankButton — spinner", () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it("shows a 'Deleting…' spinner while the delete is in flight", async () => {
    let resolveDelete: () => void = () => {};
    deleteRun.mockReturnValue(new Promise<void>((resolve) => {
      resolveDelete = resolve;
    }));
    const onDeleted = vi.fn();
    render(<DeleteBankButton id="abc" name="Acme Savings" onDeleted={onDeleted} />);

    fireEvent.click(screen.getByTestId("banks-detail__delete-button"));
    fireEvent.click(screen.getByTestId("banks-detail__delete-confirm"));

    expect(await screen.findByTestId("banks-detail__delete-spinner")).toBeInTheDocument();

    resolveDelete();
    await waitFor(() => {
      expect(onDeleted).toHaveBeenCalledWith("abc");
    });
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `make pwa.test.unit c='tests/app/backoffice/banks/deleteBankButtonSpinner.test.tsx'`
Expected: FAIL — no `banks-detail__delete-spinner` element.

- [ ] **Step 3: Add the Spinner import**

In `pwa/src/app/backoffice/banks/_components/DeleteBankButton.tsx`, replace the `@/components/erpify` import (line 10):

```tsx
import { ProblemDisplay, Spinner } from "@/components/erpify";
```

- [ ] **Step 4: Render the spinner in the confirm button**

Replace the confirm `<Button>` children (line ~129) so it reads:

```tsx
          <Button
            variant="destructive"
            size="sm"
            onClick={handleConfirm}
            disabled={submitting}
            data-icon={submitting ? "inline-start" : undefined}
            aria-label={`Confirm delete of bank ${name}`}
            title={`Confirm delete of bank ${name}`}
            data-testid="banks-detail__delete-confirm"
          >
            {submitting ? (
              <>
                <Spinner className="size-3.5" testId="banks-detail__delete-spinner" />
                Deleting…
              </>
            ) : (
              "Delete"
            )}
          </Button>
```

- [ ] **Step 5: Run test to verify it passes**

Run: `make pwa.test.unit c='tests/app/backoffice/banks/deleteBankButtonSpinner.test.tsx'`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add pwa/src/app/backoffice/banks/_components/DeleteBankButton.tsx pwa/tests/app/backoffice/banks/deleteBankButtonSpinner.test.tsx
git commit -m "feat(pwa): add spinner to bank delete confirmation"
```

---

## Task 4: `useDebouncedValue` hook

**Files:**
- Create: `pwa/src/lib/useDebouncedValue.ts`
- Test: `pwa/tests/lib/useDebouncedValue.test.ts`

- [ ] **Step 1: Write the failing test**

Create `pwa/tests/lib/useDebouncedValue.test.ts`:

```ts
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { act, renderHook } from "@testing-library/react";
import { useDebouncedValue } from "@/lib/useDebouncedValue";

describe("useDebouncedValue", () => {
  beforeEach(() => {
    vi.useFakeTimers();
  });
  afterEach(() => {
    vi.useRealTimers();
  });

  it("delays value updates by the given delay", () => {
    const { result, rerender } = renderHook(({ v }) => useDebouncedValue(v, 300), {
      initialProps: { v: "a" },
    });
    expect(result.current).toBe("a");

    rerender({ v: "ab" });
    expect(result.current).toBe("a");

    act(() => {
      vi.advanceTimersByTime(299);
    });
    expect(result.current).toBe("a");

    act(() => {
      vi.advanceTimersByTime(1);
    });
    expect(result.current).toBe("ab");
  });

  it("resets the timer when the value changes again before the delay elapses", () => {
    const { result, rerender } = renderHook(({ v }) => useDebouncedValue(v, 300), {
      initialProps: { v: "a" },
    });
    rerender({ v: "ab" });
    act(() => {
      vi.advanceTimersByTime(200);
    });
    rerender({ v: "abc" });
    act(() => {
      vi.advanceTimersByTime(200);
    });
    expect(result.current).toBe("a"); // first timer was cancelled
    act(() => {
      vi.advanceTimersByTime(100);
    });
    expect(result.current).toBe("abc");
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `make pwa.test.unit c='tests/lib/useDebouncedValue.test.ts'`
Expected: FAIL — module `@/lib/useDebouncedValue` not found.

- [ ] **Step 3: Create the hook**

Create `pwa/src/lib/useDebouncedValue.ts`:

```ts
import { useEffect, useState } from "react";

/**
 * Returns a debounced copy of `value` that only updates after `delayMs` has
 * elapsed without further changes. Each change resets the timer.
 */
export function useDebouncedValue<T>(value: T, delayMs: number): T {
  const [debounced, setDebounced] = useState<T>(value);

  useEffect(() => {
    const id = setTimeout(() => setDebounced(value), delayMs);
    return () => clearTimeout(id);
  }, [value, delayMs]);

  return debounced;
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `make pwa.test.unit c='tests/lib/useDebouncedValue.test.ts'`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add pwa/src/lib/useDebouncedValue.ts pwa/tests/lib/useDebouncedValue.test.ts
git commit -m "feat(pwa): add useDebouncedValue hook"
```

---

## Task 5: Debounce the bank text filters (`BanksFilters`)

**Files:**
- Modify: `pwa/src/app/backoffice/banks/_components/BanksFilters.tsx`
- Test: `pwa/tests/app/backoffice/banks/banksFiltersDebounce.test.tsx`

**Context:** Today the `name`/`shortName` inputs call `onFilterChange` on every keystroke (lines 57–61). We keep local input state, debounce it, and push the debounced value up. We also sync local state back down when the parent resets the filter (so the "Reset" button clears the inputs).

- [ ] **Step 1: Write the failing test**

Create `pwa/tests/app/backoffice/banks/banksFiltersDebounce.test.tsx`:

```tsx
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { act, fireEvent, render, screen } from "@testing-library/react";
import { BanksFilters } from "@/app/backoffice/banks/_components/BanksFilters";
import { DEFAULT_SORT, EMPTY_FILTER } from "@/app/backoffice/banks/_lib/banksFilterSort";

describe("BanksFilters — debounce", () => {
  beforeEach(() => {
    vi.useFakeTimers();
  });
  afterEach(() => {
    vi.useRealTimers();
  });

  it("debounces name filter changes (~300ms) and emits the latest value once", () => {
    const onFilterChange = vi.fn();
    render(
      <BanksFilters
        filter={EMPTY_FILTER}
        onFilterChange={onFilterChange}
        sort={DEFAULT_SORT}
        onSortChange={vi.fn()}
        onReset={vi.fn()}
        defaultOpen
      />,
    );

    const input = screen.getByTestId("banks-filters__name");
    fireEvent.change(input, { target: { value: "ac" } });
    fireEvent.change(input, { target: { value: "acme" } });

    expect(onFilterChange).not.toHaveBeenCalled();

    act(() => {
      vi.advanceTimersByTime(300);
    });

    expect(onFilterChange).toHaveBeenCalledTimes(1);
    expect(onFilterChange).toHaveBeenCalledWith(expect.objectContaining({ name: "acme" }));
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `make pwa.test.unit c='tests/app/backoffice/banks/banksFiltersDebounce.test.tsx'`
Expected: FAIL — `onFilterChange` is called synchronously, so the `not.toHaveBeenCalled()` assertion fails.

- [ ] **Step 3: Add imports and local debounced state**

In `pwa/src/app/backoffice/banks/_components/BanksFilters.tsx`:

Replace the React import (line 3):

```tsx
import { useEffect, useId, useState, type ChangeEvent, type ReactNode } from "react";
```

Add after that import:

```tsx
import { useDebouncedValue } from "@/lib/useDebouncedValue";
```

Add a debounce constant near the top, next to `NONE_SORT_VALUE` (line 41):

```tsx
const FILTER_DEBOUNCE_MS = 300;
```

- [ ] **Step 4: Wire local state + debounced propagation**

Inside the component body, right after `const [open, setOpen] = useState<boolean>(…)` (line 55), add:

```tsx
  // Local mirror of the text filters so typing stays instant while the
  // (expensive) parent re-filter is debounced. Synced back down when the
  // parent changes the filter externally (e.g. the Reset button).
  const [nameInput, setNameInput] = useState(filter.name);
  const [shortNameInput, setShortNameInput] = useState(filter.shortName);

  useEffect(() => {
    setNameInput(filter.name);
  }, [filter.name]);
  useEffect(() => {
    setShortNameInput(filter.shortName);
  }, [filter.shortName]);

  const debouncedName = useDebouncedValue(nameInput, FILTER_DEBOUNCE_MS);
  const debouncedShortName = useDebouncedValue(shortNameInput, FILTER_DEBOUNCE_MS);

  useEffect(() => {
    if (debouncedName === filter.name && debouncedShortName === filter.shortName) {
      return;
    }
    onFilterChange({ ...filter, name: debouncedName, shortName: debouncedShortName });
    // We intentionally only react to the debounced values; `filter` is read
    // as the latest closure value each render.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [debouncedName, debouncedShortName]);
```

- [ ] **Step 5: Point the inputs at local state**

Replace the `updateText` helper (lines 57–61) with a plain local setter:

```tsx
  const updateText =
    (field: "name" | "shortName") =>
    (event: ChangeEvent<HTMLInputElement>): void => {
      const next = event.target.value;
      if (field === "name") {
        setNameInput(next);
      } else {
        setShortNameInput(next);
      }
    };
```

Change the name `<Input value={…}>` (line 152) to `value={nameInput}` and the short-name `<Input value={…}>` (line 161) to `value={shortNameInput}`.

- [ ] **Step 6: Run test to verify it passes**

Run: `make pwa.test.unit c='tests/app/backoffice/banks/banksFiltersDebounce.test.tsx'`
Expected: PASS

- [ ] **Step 7: Guard against regressions in the existing filter e2e**

Run the existing unit/e2e that exercises filtering to confirm the debounce didn't break the filter→result flow.

Run: `make pwa.test.unit c='tests/app/backoffice/banks/banksFilterSort.test.ts'`
Expected: PASS (pure filter/sort logic is untouched.)

- [ ] **Step 8: Commit**

```bash
git add pwa/src/app/backoffice/banks/_components/BanksFilters.tsx pwa/tests/app/backoffice/banks/banksFiltersDebounce.test.tsx
git commit -m "feat(pwa): debounce bank name/short-name filters"
```

---

## Task 6: Animated filter-panel expand/collapse (`BanksFilters`)

**Files:**
- Modify: `pwa/src/app/backoffice/banks/_components/BanksFilters.tsx`
- Test (verify): `pwa/tests/e2e/backoffice/banks.spec.ts` (existing — lines ~278–336 assert `toBeHidden`/`toBeVisible`).

**Context:** The panel currently toggles with the `hidden` attribute (instant, `display:none`). To animate, we use the Tailwind grid-rows `0fr → 1fr` technique: an outer wrapper carries the test id + transition and collapses to **zero height** (so Playwright's `toBeHidden()` — which treats a zero-size box as hidden — still passes), while the inner element keeps the border/background/padding. `inert` + `aria-hidden` keep the collapsed content out of the a11y/tab order.

- [ ] **Step 1: Restructure the panel markup**

In `pwa/src/app/backoffice/banks/_components/BanksFilters.tsx`, replace the entire panel `<section id={panelId} … hidden={!open} …>` opening (lines 141–147) and its closing `</section>` (line 238) with a two-level structure. The **outer** element keeps `id`, `aria-label`, `data-testid="banks-filters__panel"`; the **inner** keeps the visual styling. Replace:

```tsx
      <section
        id={panelId}
        aria-label="Bank filter fields"
        hidden={!open}
        className="banks-filters__panel border-border bg-muted/20 mt-3 rounded-md border p-3 sm:p-4"
        data-testid="banks-filters__panel"
      >
```

with:

```tsx
      <section
        id={panelId}
        aria-label="Bank filter fields"
        aria-hidden={!open}
        inert={!open ? true : undefined}
        className="banks-filters__panel grid transition-[grid-template-rows] duration-200 ease-out"
        style={{ gridTemplateRows: open ? "1fr" : "0fr" }}
        data-testid="banks-filters__panel"
      >
        <div className="banks-filters__panel-inner overflow-hidden">
          <div className="banks-filters__panel-fields border-border bg-muted/20 mt-3 rounded-md border p-3 sm:p-4">
```

and replace the matching closing `</section>` (was line 238) with:

```tsx
          </div>
        </div>
      </section>
```

> Note: the `mt-3` moved onto the inner `__panel-fields` so there is **no** gap (and no bounding box) when the panel is collapsed — this keeps `toBeHidden()` accurate.

- [ ] **Step 2: TypeScript check on the changed file**

The `inert` prop is valid on React 19 / Next 16 DOM types (boolean). Confirm the file type-checks.

Run: `cd pwa && npx tsc --noEmit -p tsconfig.json` (or rely on `make pwa.quality` in Task 12).
Expected: no new type errors in `BanksFilters.tsx`.

- [ ] **Step 3: Verify the existing panel e2e still holds**

The e2e at `tests/e2e/backoffice/banks.spec.ts` (~lines 283/289/295/336) asserts the panel is hidden when collapsed and visible when open. With `grid-template-rows: 0fr` + `overflow-hidden` + no padding on the outer box, the collapsed panel has zero height → `toBeHidden()` passes; open → `1fr` reveals content → `toBeVisible()` passes.

Run: `make pwa.test.e2e c='tests/e2e/backoffice/banks.spec.ts'`
Expected: PASS for the "filters panel toggles" specs. If the runner needs the live stack, ensure it is up first (`make app.dev`). If a panel-visibility assertion fails because the collapsed box still reports a non-zero height, double-check no padding/border/margin leaked onto the outer `banks-filters__panel` element.

- [ ] **Step 4: Commit**

```bash
git add pwa/src/app/backoffice/banks/_components/BanksFilters.tsx
git commit -m "feat(pwa): animate bank filters panel expand/collapse"
```

---

## Task 7: `errorAction` slot on `AsyncBoundary`

**Files:**
- Modify: `pwa/src/components/erpify/AsyncBoundary.tsx`
- Test: `pwa/tests/components/erpify/asyncBoundaryErrorAction.test.tsx`

**Context:** The error branch (lines 72–74) renders only `<ProblemDisplay variant="panel" />`. Add an optional `errorAction` slot rendered below it so consumers (the banks list) can offer a Retry button. Optional → no impact on existing consumers.

- [ ] **Step 1: Write the failing test**

Create `pwa/tests/components/erpify/asyncBoundaryErrorAction.test.tsx`:

```tsx
import { describe, expect, it } from "vitest";
import { render, screen } from "@testing-library/react";
import { AsyncBoundary } from "@/components/erpify";
import { ViewStatus } from "@/context/shared/domain/types/status";
import type { ProblemDetails } from "@/context/shared/domain/ProblemDetails";

const problem: ProblemDetails = {
  type: "about:blank",
  title: "Unexpected error",
  status: 0,
  detail: "boom",
  instance: "i",
  "correlation-id": "c",
};

describe("AsyncBoundary — errorAction", () => {
  it("renders the errorAction node in the error state", () => {
    render(
      <AsyncBoundary
        state={ViewStatus.ERROR}
        data={[]}
        error={problem}
        errorAction={<button data-testid="retry">Retry</button>}
      >
        {() => <div>data</div>}
      </AsyncBoundary>,
    );
    expect(screen.getByTestId("retry")).toBeInTheDocument();
  });

  it("renders nothing extra in the error state when no errorAction is given", () => {
    render(
      <AsyncBoundary state={ViewStatus.ERROR} data={[]} error={problem}>
        {() => <div>data</div>}
      </AsyncBoundary>,
    );
    expect(screen.queryByTestId("retry")).not.toBeInTheDocument();
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `make pwa.test.unit c='tests/components/erpify/asyncBoundaryErrorAction.test.tsx'`
Expected: FAIL — `errorAction` prop not supported; retry button not rendered.

- [ ] **Step 3: Add the prop and render it**

In `pwa/src/components/erpify/AsyncBoundary.tsx`:

Add to the props interface (after `loading?: ReactNode;`, line 36):

```tsx
  /** Optional recovery action rendered below the error panel (e.g. Retry). */
  errorAction?: ReactNode;
```

Add `errorAction,` to the destructured params (after `loading,`, line 50).

Replace the error branch (lines 72–74):

```tsx
  if (state === ViewStatus.ERROR && error) {
    return (
      <div className="async-boundary__error space-y-4">
        <ProblemDisplay problem={error} variant="panel" />
        {errorAction ? (
          <div className="async-boundary__error-action flex justify-center">{errorAction}</div>
        ) : null}
      </div>
    );
  }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `make pwa.test.unit c='tests/components/erpify/asyncBoundaryErrorAction.test.tsx'`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add pwa/src/components/erpify/AsyncBoundary.tsx pwa/tests/components/erpify/asyncBoundaryErrorAction.test.tsx
git commit -m "feat(pwa): add optional errorAction slot to AsyncBoundary"
```

---

## Task 8: `BanksListSkeleton` + wire into list loading

**Files:**
- Create: `pwa/src/app/backoffice/banks/_components/BanksListSkeleton.tsx`
- Modify: `pwa/src/app/backoffice/banks/page.tsx`
- Test: `pwa/tests/app/backoffice/banks/banksListSkeleton.test.tsx`

**Context:** The list passes no `loading` prop, so `AsyncBoundary` shows its tiny default 3-bar skeleton. Give it a list-shaped skeleton (`view`-aware) instead.

- [ ] **Step 1: Write the failing test**

Create `pwa/tests/app/backoffice/banks/banksListSkeleton.test.tsx`:

```tsx
import { beforeEach, describe, expect, it, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import BanksListPage from "@/app/backoffice/banks/page";

vi.mock("next/navigation", () => ({
  useRouter: () => ({ push: vi.fn(), refresh: vi.fn(), back: vi.fn() }),
}));

const searchRun = vi.fn();
vi.mock("@/context/shared/infrastructure/DependencyInjection/Container", () => ({
  container: {
    get: (token: string) => {
      if (token === "BackOfficeSearchBanks") return { run: searchRun };
      throw new Error(`Unexpected DI token ${token}`);
    },
  },
}));

vi.mock("@/context/shared/infrastructure/Notification/Toast", () => ({
  toastNotifier: { success: vi.fn(), error: vi.fn(), info: vi.fn(), warning: vi.fn() },
}));

describe("BanksListPage — loading skeleton", () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it("shows the list skeleton while the search is in flight", () => {
    // Never resolves → page stays in LOADING.
    searchRun.mockReturnValue(new Promise(() => {}));
    render(<BanksListPage />);
    expect(screen.getByTestId("banks-list__skeleton")).toBeInTheDocument();
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `make pwa.test.unit c='tests/app/backoffice/banks/banksListSkeleton.test.tsx'`
Expected: FAIL — no `banks-list__skeleton` element.

- [ ] **Step 3: Create the skeleton component**

Create `pwa/src/app/backoffice/banks/_components/BanksListSkeleton.tsx`:

```tsx
import type { BanksView } from "./BanksViewToggle";

interface BanksListSkeletonProps {
  view: BanksView;
  /** Number of placeholder rows/cards. */
  rows?: number;
}

const SKELETON_ROW_KEYS = ["a", "b", "c", "d", "e", "f", "g", "h"] as const;

/**
 * List-shaped loading placeholder. Decorative: the wrapping
 * `AsyncBoundary` already exposes `role="status"`/`aria-busy`, so this is
 * `aria-hidden`.
 */
export function BanksListSkeleton({ view, rows = 6 }: Readonly<BanksListSkeletonProps>) {
  const keys = SKELETON_ROW_KEYS.slice(0, Math.min(rows, SKELETON_ROW_KEYS.length));

  if (view === "cards") {
    return (
      <ul
        className="banks-list__skeleton grid list-none grid-cols-1 gap-4 p-0 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-4"
        data-testid="banks-list__skeleton"
        aria-hidden="true"
      >
        {keys.map((key) => (
          <li key={key} className="border-border bg-card animate-pulse rounded-lg border p-4">
            <div className="bg-muted h-4 w-2/3 rounded" />
            <div className="bg-muted mt-2 h-3 w-1/3 rounded" />
            <div className="bg-muted mt-4 h-3 w-1/2 rounded" />
            <div className="bg-muted mt-2 h-3 w-2/5 rounded" />
          </li>
        ))}
      </ul>
    );
  }

  return (
    <div
      className="banks-list__skeleton border-border overflow-hidden rounded-md border"
      data-testid="banks-list__skeleton"
      aria-hidden="true"
    >
      {keys.map((key) => (
        <div
          key={key}
          className="border-border flex animate-pulse items-center gap-4 border-b p-3 last:border-b-0"
        >
          <div className="bg-muted h-4 w-24 rounded" />
          <div className="bg-muted h-4 flex-1 rounded" />
          <div className="bg-muted hidden h-4 w-32 rounded md:block" />
          <div className="bg-muted h-7 w-20 rounded" />
        </div>
      ))}
    </div>
  );
}
```

- [ ] **Step 4: Wire it into the list page**

In `pwa/src/app/backoffice/banks/page.tsx`:

Add the import next to the other `_components` imports (after line 21):

```tsx
import { BanksListSkeleton } from "./_components/BanksListSkeleton";
```

Add the `loading` prop to the `<AsyncBoundary>` element (within its props, alongside `state`, `data`, `error`, line 188):

```tsx
        loading={<BanksListSkeleton view={view} rows={Math.min(pageSize, 8)} />}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `make pwa.test.unit c='tests/app/backoffice/banks/banksListSkeleton.test.tsx'`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add pwa/src/app/backoffice/banks/_components/BanksListSkeleton.tsx pwa/src/app/backoffice/banks/page.tsx pwa/tests/app/backoffice/banks/banksListSkeleton.test.tsx
git commit -m "feat(pwa): add list-shaped loading skeleton to banks list"
```

---

## Task 9: Retry-on-error (`page.tsx`)

**Files:**
- Modify: `pwa/src/app/backoffice/banks/page.tsx`
- Test: `pwa/tests/app/backoffice/banks/banksListRetry.test.tsx`

**Context:** The fetch lives in a one-shot `useEffect` (lines 73–94). Refactor it into a re-runnable `loadBanks` callback guarded by a mounted-ref, run it on mount, and pass a Retry button to `AsyncBoundary`'s new `errorAction` slot.

- [ ] **Step 1: Write the failing test**

Create `pwa/tests/app/backoffice/banks/banksListRetry.test.tsx`:

```tsx
import { beforeEach, describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import BanksListPage from "@/app/backoffice/banks/page";
import { Bank } from "@/context/backoffice/bank/domain/Bank";

vi.mock("next/navigation", () => ({
  useRouter: () => ({ push: vi.fn(), refresh: vi.fn(), back: vi.fn() }),
}));

const ACME = Bank.fromPrimitives({
  id: "11111111-1111-4111-8111-111111111111",
  name: "Acme Savings",
  shortName: "ACME",
  createdAt: "2026-01-01T10:00:00Z",
  updatedAt: "2026-04-15T14:30:00Z",
});

const searchRun = vi.fn();
vi.mock("@/context/shared/infrastructure/DependencyInjection/Container", () => ({
  container: {
    get: (token: string) => {
      if (token === "BackOfficeSearchBanks") return { run: searchRun };
      throw new Error(`Unexpected DI token ${token}`);
    },
  },
}));

vi.mock("@/context/shared/infrastructure/Notification/Toast", () => ({
  toastNotifier: { success: vi.fn(), error: vi.fn(), info: vi.fn(), warning: vi.fn() },
}));

describe("BanksListPage — retry on error", () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it("re-runs the search when Retry is clicked after an error", async () => {
    searchRun
      .mockRejectedValueOnce(new Error("network down"))
      .mockResolvedValueOnce({ banks: [ACME], nextCursor: undefined });

    render(<BanksListPage />);

    const retry = await screen.findByTestId("banks-list__retry");
    expect(searchRun).toHaveBeenCalledTimes(1);

    fireEvent.click(retry);

    await waitFor(() => {
      expect(screen.getByRole("cell", { name: "Acme Savings", exact: true })).toBeInTheDocument();
    });
    expect(searchRun).toHaveBeenCalledTimes(2);
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `make pwa.test.unit c='tests/app/backoffice/banks/banksListRetry.test.tsx'`
Expected: FAIL — no `banks-list__retry` element.

- [ ] **Step 3: Add imports**

In `pwa/src/app/backoffice/banks/page.tsx`, replace the React import (line 3):

```tsx
import { useCallback, useEffect, useMemo, useRef, useState } from "react";
```

- [ ] **Step 4: Refactor the fetch into a re-runnable `loadBanks`**

Replace the data-fetch `useEffect` block (lines 73–94) with:

```tsx
  const mountedRef = useRef(true);
  useEffect(() => {
    mountedRef.current = true;
    return () => {
      mountedRef.current = false;
    };
  }, []);

  const loadBanks = useCallback(async () => {
    setState(ViewStatus.LOADING);
    setProblem(null);
    try {
      const useCase = container.get<SearchBanks>("BackOfficeSearchBanks");
      const result = await useCase.run();
      if (!mountedRef.current) return;
      setBanks(result.banks);
      setNextCursor(result.nextCursor);
      setState(result.banks.length === 0 ? ViewStatus.EMPTY : ViewStatus.READY);
    } catch (err) {
      if (!mountedRef.current) return;
      const fallbackDetail = err instanceof Error ? err.message : "Unknown error";
      const nextProblem = err instanceof HttpError ? err.problem : genericProblem(fallbackDetail);
      setProblem(nextProblem);
      setState(ViewStatus.ERROR);
    }
  }, []);

  useEffect(() => {
    void loadBanks();
  }, [loadBanks]);
```

- [ ] **Step 5: Pass the Retry button to AsyncBoundary**

Add the `errorAction` prop to the `<AsyncBoundary>` element (alongside `loading` from Task 8, near line 188):

```tsx
        errorAction={
          <Button
            type="button"
            variant="outline"
            size="sm"
            onClick={() => {
              void loadBanks();
            }}
            title="Retry loading banks"
            aria-label="Retry loading banks"
            data-testid="banks-list__retry"
          >
            Retry
          </Button>
        }
```

(`Button` is already imported on line 13.)

- [ ] **Step 6: Run test to verify it passes**

Run: `make pwa.test.unit c='tests/app/backoffice/banks/banksListRetry.test.tsx'`
Expected: PASS

- [ ] **Step 7: Run the existing list delete test to confirm no regression**

Run: `make pwa.test.unit c='tests/app/backoffice/banks/bankListDelete.test.tsx'`
Expected: PASS

- [ ] **Step 8: Commit**

```bash
git add pwa/src/app/backoffice/banks/page.tsx pwa/tests/app/backoffice/banks/banksListRetry.test.tsx
git commit -m "feat(pwa): add retry action to banks list error state"
```

---

## Task 10: Truncated short-name tooltips (`BanksTable`, `BanksCards`)

**Files:**
- Modify: `pwa/src/app/backoffice/banks/_components/BanksTable.tsx`
- Modify: `pwa/src/app/backoffice/banks/_components/BanksCards.tsx`
- Test: `pwa/tests/app/backoffice/banks/bankTruncationTooltips.test.tsx`

**Context:** The short-name is truncated (table column `max-w-[8rem] truncate`, card `truncate font-mono`). A long value is silently clipped with no way to read it. Add a native `title` tooltip = the full short-name. `title`/`aria-label` interpolation is XSS-safe (React escapes attributes) per `pwa/CLAUDE.md`.

- [ ] **Step 1: Write the failing test**

Create `pwa/tests/app/backoffice/banks/bankTruncationTooltips.test.tsx`:

```tsx
import { describe, expect, it, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import { BanksTable } from "@/app/backoffice/banks/_components/BanksTable";
import { BanksCards } from "@/app/backoffice/banks/_components/BanksCards";
import { Bank } from "@/context/backoffice/bank/domain/Bank";

vi.mock("next/navigation", () => ({
  useRouter: () => ({ push: vi.fn(), refresh: vi.fn(), back: vi.fn() }),
}));

const LONG = Bank.fromPrimitives({
  id: "33333333-3333-4333-8333-333333333333",
  name: "Very Long Bank Name That Wraps",
  shortName: "VERYLONGSHORTNAMEVALUE",
  createdAt: "2026-01-01T10:00:00Z",
  updatedAt: "2026-01-01T10:00:00Z",
});

describe("Bank short-name truncation tooltips", () => {
  it("table short-name cell exposes the full value via title", () => {
    render(<BanksTable banks={[LONG]} />);
    const el = screen.getByText("VERYLONGSHORTNAMEVALUE");
    expect(el).toHaveAttribute("title", "VERYLONGSHORTNAMEVALUE");
  });

  it("card short-name exposes the full value via title", () => {
    render(<BanksCards banks={[LONG]} />);
    const el = screen.getByTestId(`banks-cards__shortname-${LONG.id}`);
    expect(el).toHaveAttribute("title", "VERYLONGSHORTNAMEVALUE");
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `make pwa.test.unit c='tests/app/backoffice/banks/bankTruncationTooltips.test.tsx'`
Expected: FAIL — no `title` on the short-name cell / card description.

- [ ] **Step 3: Add the title to the table short-name cell**

In `pwa/src/app/backoffice/banks/_components/BanksTable.tsx`, replace `renderShortNameCell` (line 79):

```tsx
const renderShortNameCell = (row: Bank) => (
  <span className="block truncate" title={row.shortName}>
    {row.shortName}
  </span>
);
```

- [ ] **Step 4: Add the title to the card short-name**

In `pwa/src/app/backoffice/banks/_components/BanksCards.tsx`, add `title={bank.shortName}` to the `<CardDescription>` (line 56):

```tsx
                <CardDescription
                  className="banks-cards__shortname truncate font-mono text-xs uppercase"
                  title={bank.shortName}
                  data-testid={`banks-cards__shortname-${bank.id}`}
                >
                  {bank.shortName}
                </CardDescription>
```

- [ ] **Step 5: Run test to verify it passes**

Run: `make pwa.test.unit c='tests/app/backoffice/banks/bankTruncationTooltips.test.tsx'`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add pwa/src/app/backoffice/banks/_components/BanksTable.tsx pwa/src/app/backoffice/banks/_components/BanksCards.tsx pwa/tests/app/backoffice/banks/bankTruncationTooltips.test.tsx
git commit -m "feat(pwa): show full bank short-name on hover when truncated"
```

---

## Task 11: Distinct empty-filtered-state styling (`BanksEmptyFiltered`)

**Files:**
- Create: `pwa/src/app/backoffice/banks/_components/BanksEmptyFiltered.tsx`
- Modify: `pwa/src/app/backoffice/banks/page.tsx`
- Test: `pwa/tests/app/backoffice/banks/banksEmptyFiltered.test.tsx`

**Context:** The "no banks match your filters" block (page.tsx lines 207–236) currently uses the same solid border/padding as form cards, so it doesn't read as a distinct empty state. Extract it into its own component, restyle it (dashed border, muted background, a `Search` icon) to differentiate it from the first-run `EmptyState` and from data cards — while **preserving the existing test ids** (`banks-list__empty-filtered`, `…-heading`, `…-description`, `banks-list__reset-filters`).

- [ ] **Step 1: Write the failing test**

Create `pwa/tests/app/backoffice/banks/banksEmptyFiltered.test.tsx`:

```tsx
import { describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen } from "@testing-library/react";
import { BanksEmptyFiltered } from "@/app/backoffice/banks/_components/BanksEmptyFiltered";

describe("BanksEmptyFiltered", () => {
  it("renders the distinct dashed-border empty state and wires Reset", () => {
    const onReset = vi.fn();
    render(<BanksEmptyFiltered onReset={onReset} />);

    const section = screen.getByTestId("banks-list__empty-filtered");
    expect(section).toHaveClass("border-dashed");
    expect(screen.getByTestId("banks-list__empty-filtered-heading")).toHaveTextContent(
      "No banks match your filters",
    );
    expect(screen.getByTestId("banks-list__empty-filtered-description")).toBeInTheDocument();

    fireEvent.click(screen.getByTestId("banks-list__reset-filters"));
    expect(onReset).toHaveBeenCalledTimes(1);
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `make pwa.test.unit c='tests/app/backoffice/banks/banksEmptyFiltered.test.tsx'`
Expected: FAIL — module `BanksEmptyFiltered` not found.

- [ ] **Step 3: Create the component**

Create `pwa/src/app/backoffice/banks/_components/BanksEmptyFiltered.tsx`:

```tsx
"use client";

import { Search } from "lucide-react";
import { Button } from "@/components/ui/button";

interface BanksEmptyFilteredProps {
  onReset: () => void;
}

/**
 * Shown when banks exist but the active filters match none of them. Styled
 * distinctly (dashed border + muted background + search icon) so it reads as
 * a filtered-to-zero state rather than a data card or the first-run empty
 * state.
 */
export function BanksEmptyFiltered({ onReset }: Readonly<BanksEmptyFilteredProps>) {
  return (
    <section
      className="banks-list__empty-filtered border-border bg-muted/30 flex flex-col items-center gap-3 rounded-md border border-dashed p-8 text-center"
      data-testid="banks-list__empty-filtered"
    >
      <Search className="text-muted-foreground size-6" aria-hidden="true" />
      <div>
        <h2
          className="text-foreground text-base font-medium"
          data-testid="banks-list__empty-filtered-heading"
        >
          No banks match your filters
        </h2>
        <p
          className="text-muted-foreground mt-1 text-sm"
          data-testid="banks-list__empty-filtered-description"
        >
          Adjust the filters or clear them to see the full list.
        </p>
      </div>
      <Button
        type="button"
        variant="outline"
        size="sm"
        className="mt-1"
        onClick={onReset}
        title="Clear all bank filters"
        aria-label="Clear all bank filters"
        data-testid="banks-list__reset-filters"
      >
        Reset filters
      </Button>
    </section>
  );
}
```

- [ ] **Step 4: Swap it into the page**

In `pwa/src/app/backoffice/banks/page.tsx`:

Add the import next to the other `_components` imports (after the `BanksListSkeleton` import from Task 8):

```tsx
import { BanksEmptyFiltered } from "./_components/BanksEmptyFiltered";
```

Replace the inline empty-filtered `<section>…</section>` block (lines 208–236, the branch when `visibleBanks.length === 0`) with:

```tsx
            <BanksEmptyFiltered onReset={resetFilters} />
```

If `Button` is no longer referenced elsewhere in `page.tsx` after this change, remove its now-unused import (line 13) to keep ESLint clean. (It is still used by the Retry button from Task 9, so keep it.)

- [ ] **Step 5: Run test to verify it passes**

Run: `make pwa.test.unit c='tests/app/backoffice/banks/banksEmptyFiltered.test.tsx'`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add pwa/src/app/backoffice/banks/_components/BanksEmptyFiltered.tsx pwa/src/app/backoffice/banks/page.tsx pwa/tests/app/backoffice/banks/banksEmptyFiltered.test.tsx
git commit -m "feat(pwa): give filtered-to-zero banks state a distinct look"
```

---

## Task 12: Full verification sweep

**Files:** none (verification only).

- [ ] **Step 1: Run the full PWA unit + e2e suite for banks**

Run: `make pwa.test.unit`
Expected: PASS, including the new tests and `tests/data-testid-uniqueness.test.ts` (confirms every new `data-testid` literal is unique).

- [ ] **Step 2: Run the bank e2e (requires the live stack)**

Ensure the stack is up (`make app.dev`), then:

Run: `make pwa.test.e2e c='tests/e2e/backoffice/banks.spec.ts'`
Expected: PASS — particularly the filter-panel toggle specs (Task 6) and filter/delete flows (Task 5/9).

- [ ] **Step 3: Lint + format**

Run: `make pwa.quality`
Expected: PASS (ESLint + Prettier). Fix anything reported.

- [ ] **Step 4: Security self-review (per root `CLAUDE.md`)**

Confirm for the diff:
- No `dangerouslySetInnerHTML` / `innerHTML` / `eval` added. ✅ (all dynamic strings go into escaped `title`/`aria-label`/text.)
- No new dynamic `href`/`src`/`router.push` bypassing `safeHref`. ✅ (no navigation added; Retry calls a local callback.)
- No secrets/PII in storage, logs, or toasts (toasts show the bank name only). ✅
- `npm audit` not newly dirtied (no dependencies added — `lucide-react`, React, Tailwind already present). ✅

State explicitly in the PR description that XSS/redirect/storage classes were reviewed and N/A where they don't apply.

- [ ] **Step 5: Commit any lint fixups**

```bash
git add -A
git commit -m "chore(pwa): lint/format fixups for bank ui polish"
```

---

## Self-Review

**1. Spec coverage** — every confirmed scope item maps to a task:
- Form success toasts → Task 2 · Submit spinner → Tasks 1–3 · Search debounce → Tasks 4–5 · Loading skeletons → Task 8 · Filter-panel transition → Task 6 · Empty-filtered styling → Task 11 · Truncated-name tooltips → Task 10 · Retry-on-error → Tasks 7 + 9. No gaps.

**2. Placeholder scan** — every code step contains full code; every test step contains real assertions; commands have expected outcomes. No TBD/"handle edge cases"/"similar to Task N".

**3. Type & name consistency**
- `Spinner` props: `{ className?, testId? }` — used consistently in Tasks 2, 3 (`testId="bank-form__submit-spinner"`, `"banks-detail__delete-spinner"`).
- `useDebouncedValue(value, delayMs)` (Task 4) ↔ called as `useDebouncedValue(nameInput, FILTER_DEBOUNCE_MS)` (Task 5). ✅
- `AsyncBoundary` new prop `errorAction?: ReactNode` (Task 7) ↔ consumed in Task 9. ✅
- `BanksListSkeleton({ view, rows })` (Task 8) ↔ `<BanksListSkeleton view={view} rows={…} />`. `BanksView` imported from `./BanksViewToggle` (matches `page.tsx`'s existing import on line 21). ✅
- `BanksEmptyFiltered({ onReset })` (Task 11) ↔ `<BanksEmptyFiltered onReset={resetFilters} />`. `resetFilters` already defined in `page.tsx` (line 117). ✅
- DI tokens (`BackOfficeSearchBanks`/`CreateBank`/`UpdateBank`/`DeleteBank`) match the strings used in source and existing tests. ✅
- New `data-testid` literals (`bank-form__submit-spinner`, `banks-detail__delete-spinner`, `banks-list__skeleton`, `banks-list__retry`) are each introduced once → satisfies the uniqueness guard; the empty-filtered ids are preserved (moved, not duplicated). ✅

**4. Constraint check** — Task 6's grid-rows technique was chosen specifically so the existing `toBeHidden()`/`toBeVisible()` e2e assertions keep passing (collapsed = zero-height box). Flagged in Step 3 with a fallback diagnosis.
