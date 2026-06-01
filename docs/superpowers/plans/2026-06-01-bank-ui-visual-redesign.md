# Bank UI Visual Redesign Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Apply the **Direction B ("data-honest")** visual redesign from the `.superpowers/brainstorm/1305963-1780241138` mockups to the back-office Banks surfaces — monogram avatars, a real "New" badge (`createdAt ≤ 7d`), relative timestamps (full date on hover), and a restyled bank-detail page — without touching the DDD/Inversify architecture or the API.

**Architecture:** All changes live in the PWA (`pwa/`). One reusable primitive is added (`MonogramAvatar` in `@/components/erpify`), the shared `DateTimeProvider` port gains relative-time formatting (port + `date-fns` adapter), a pure bank-recency helper is added under the banks `_lib`, and the rest are edits to the existing bank components (`BanksTable`, `BanksCards`, `page.tsx`, `[id]/page.tsx`). No domain/application/infrastructure use-case changes — existing `SearchBanks` / `FindBank` data is reused.

**Tech Stack:** Next.js 16 (App Router) · TypeScript (strict) · Tailwind 4 · Shadcn UI · lucide-react · date-fns · Vitest + @testing-library/react. Dates go through the `dateTimeProvider` port (`@/context/shared/infrastructure/DateTimeProvider`); the "New" badge reuses the existing `<StatusBadge variant="info">`; icons via `lucide-react`.

**Scope mapping (mockup → this plan):**

| Mockup element (`proposed-v1/v2.html`, `direction.html`) | Where it lands |
| --- | --- |
| Monogram avatar tile (indigo tint, "SB"/"BV") on rows + detail | New `MonogramAvatar` primitive (Tasks 1–2), wired into table/cards/detail (Tasks 5–7) |
| "New" badge from `createdAt ≤ 7d` | `isRecentlyCreated` helper (Task 4) + `<StatusBadge variant="info" label="New">` |
| Relative timestamps ("2 days ago", full date on hover) | `formatIsoToRelative` on the port (Task 3), rendered with `title={absolute}` |
| Header subtitle "N banks · M added this week" | `page.tsx` (Task 6) |
| Redesigned detail: avatar header, icon-led meta grid, Identifier row inline copy, top actions trimmed to Edit+Delete | `[id]/page.tsx` (Task 7) |
| Toolbar restyle (segmented toggle + grouped Filters) | **Already satisfied** by `BanksViewToggle` — no task |
| Row hover wash / clickable rows | **Already satisfied** by `<DataTable>` `onRowActivate` — no task |

**Conventions to honour (from `pwa/CLAUDE.md`, `pwa/DESIGN.md`):**
- Reusable primitives accept a `testId` prop; never hardcode `data-testid`. Static literals must be globally unique (`tests/data-testid-uniqueness.test.ts`).
- Render every ISO timestamp via the `dateTimeProvider` port — never `new Date(...).toLocaleString()` in components.
- Brand indigo is "interactive/CTA only" by default. The monogram identity tint is a **documented governance exception** added in Task 1 (DESIGN.md). The "New" badge uses the already-sanctioned `info` semantic token, not decorative indigo.
- Every action control keeps `title` + short static `aria-label` + textual fallback; decorative icons get `aria-hidden="true"`.
- Run `make pwa.quality` at the end. Run unit tests with `make pwa.test.unit c='<path>'`.

**Governance note (read before Task 1):** Direction B was chosen in brainstorming over Direction A (neutral tiles) and Direction C (multi-hue). The single defensible departure from current governance is the indigo-tinted identity monogram. Task 1 sanctions it explicitly in DESIGN.md as an *identity affordance* (not a decorative flourish, not a status signal). If a reviewer rejects the exception, the fallback is a neutral tile (`bg-muted text-muted-foreground border` instead of `bg-primary/10 text-primary`) — a one-line change in `MonogramAvatar`.

---

## File Structure

**New files:**
- `pwa/src/components/erpify/MonogramAvatar.tsx` — entity-agnostic monogram avatar primitive (one responsibility: render up-to-2 initials in a tinted tile). Barrel-exported.
- `pwa/src/components/erpify/initials.ts` — pure helper deriving up-to-2 uppercase initials from a name (one responsibility).
- `pwa/src/app/backoffice/banks/_lib/bankRecency.ts` — pure predicate: was this bank created within N days? (one responsibility).
- Test files mirroring the above under `pwa/tests/...`.

**Modified files:**
- `pwa/src/components/erpify/index.ts` — export `MonogramAvatar` + `initials`.
- `pwa/src/context/shared/domain/DateTimeProvider/DateTimeProvider.ts` — add `formatToRelative` + `formatIsoToRelative` to the port.
- `pwa/src/context/shared/infrastructure/DateTimeProvider/DateFnsDateTimeProvider.ts` — implement them with `date-fns`.
- `pwa/src/app/backoffice/banks/_components/BanksTable.tsx` — avatar in the Name cell, "New" badge, relative Created/Updated cells with absolute `title`.
- `pwa/src/app/backoffice/banks/_components/BanksCards.tsx` — avatar in header, "New" badge, relative timestamps with absolute `title`.
- `pwa/src/app/backoffice/banks/page.tsx` — header subtitle "N banks · M added this week".
- `pwa/src/app/backoffice/banks/[id]/page.tsx` — avatar header + "New" badge, icon-led meta grid, relative+absolute timestamps, Identifier row inline copy, drop redundant top Copy-ID button.
- `pwa/DESIGN.md` — sanction the monogram identity tint; document the recency-badge pattern and the relative-time port method.
- `pwa/CLAUDE.md` — list `<MonogramAvatar>` and the new port method under "Shared building blocks".

---

## Task 1: `MonogramAvatar` primitive + `initials` helper + governance note

**Files:**
- Create: `pwa/src/components/erpify/initials.ts`
- Create: `pwa/src/components/erpify/MonogramAvatar.tsx`
- Modify: `pwa/src/components/erpify/index.ts`
- Modify: `pwa/DESIGN.md`
- Test: `pwa/tests/components/erpify/initials.test.ts`
- Test: `pwa/tests/components/erpify/MonogramAvatar.test.tsx`

- [ ] **Step 1: Write the failing test for `initials`**

Create `pwa/tests/components/erpify/initials.test.ts`:

```ts
import { describe, expect, it } from "vitest";
import { initials } from "@/components/erpify/initials";

describe("initials", () => {
  it("takes the first letter of the first two words, uppercased", () => {
    expect(initials("Santander Bank")).toBe("SB");
  });

  it("uses the first two letters when there is a single word", () => {
    expect(initials("BBVA")).toBe("BB");
  });

  it("handles a single-letter name", () => {
    expect(initials("X")).toBe("X");
  });

  it("ignores surrounding and inner whitespace", () => {
    expect(initials("  caixa   bank  ")).toBe("CB");
  });

  it("returns an empty string for an empty or whitespace-only name", () => {
    expect(initials("")).toBe("");
    expect(initials("   ")).toBe("");
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `make pwa.test.unit c='tests/components/erpify/initials.test.ts'`
Expected: FAIL — cannot resolve `@/components/erpify/initials`.

- [ ] **Step 3: Implement `initials`**

Create `pwa/src/components/erpify/initials.ts`:

```ts
/**
 * Derive up to two uppercase initials from a display name, for monogram
 * avatars. Multi-word names use the first letter of the first two words
 * ("Santander Bank" → "SB"); single-word names use the first two letters
 * ("BBVA" → "BB"). Returns "" for empty / whitespace-only input so callers
 * can decide on a fallback.
 */
export function initials(name: string): string {
  const words = name.trim().split(/\s+/).filter(Boolean);
  if (words.length === 0) return "";
  if (words.length === 1) {
    return words[0].slice(0, 2).toUpperCase();
  }
  return (words[0][0] + words[1][0]).toUpperCase();
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `make pwa.test.unit c='tests/components/erpify/initials.test.ts'`
Expected: PASS

- [ ] **Step 5: Write the failing test for `MonogramAvatar`**

Create `pwa/tests/components/erpify/MonogramAvatar.test.tsx`:

```tsx
import { describe, expect, it } from "vitest";
import { render, screen } from "@testing-library/react";
import { MonogramAvatar } from "@/components/erpify";

describe("MonogramAvatar", () => {
  it("renders the derived initials and merges the className", () => {
    render(<MonogramAvatar name="Santander Bank" className="size-12" testId="x-avatar" />);
    const el = screen.getByTestId("x-avatar");
    expect(el).toHaveTextContent("SB");
    expect(el).toHaveClass("size-12");
  });

  it("is decorative: hidden from assistive tech (the name is shown beside it)", () => {
    render(<MonogramAvatar name="BBVA" testId="x-avatar" />);
    expect(screen.getByTestId("x-avatar")).toHaveAttribute("aria-hidden", "true");
  });

  it("falls back to a neutral glyph when the name yields no initials", () => {
    render(<MonogramAvatar name="   " testId="x-avatar" />);
    expect(screen.getByTestId("x-avatar")).toHaveTextContent("–");
  });
});
```

- [ ] **Step 6: Run test to verify it fails**

Run: `make pwa.test.unit c='tests/components/erpify/MonogramAvatar.test.tsx'`
Expected: FAIL — `MonogramAvatar` is not exported from `@/components/erpify`.

- [ ] **Step 7: Implement `MonogramAvatar`**

Create `pwa/src/components/erpify/MonogramAvatar.tsx`:

```tsx
import { cn } from "@/lib/utils";
import { initials } from "./initials";

interface MonogramAvatarProps {
  /** Display name the initials are derived from. */
  name: string;
  /** Extra classes; defaults to a 2.25rem square tile. */
  className?: string;
  /** Optional test id passthrough (never hardcode in shared components). */
  testId?: string;
}

/**
 * Decorative monogram avatar: up-to-two initials in a brand-tinted square
 * tile. Always `aria-hidden` — the resource name is always rendered beside
 * it, so the avatar must not add a second accessible name.
 *
 * The brand-indigo tint here is an **identity affordance**, explicitly
 * sanctioned in DESIGN.md ("Color — brand and accent" governance note) as a
 * documented exception to "indigo is interactive-only". It is not a status
 * signal and never the sole carrier of meaning.
 */
export function MonogramAvatar({ name, className, testId }: Readonly<MonogramAvatarProps>) {
  const text = initials(name) || "–";
  return (
    <span
      aria-hidden="true"
      data-testid={testId}
      className={cn(
        "bg-primary/10 text-primary grid size-9 flex-none place-items-center rounded-lg text-xs font-semibold",
        className,
      )}
    >
      {text}
    </span>
  );
}
```

- [ ] **Step 8: Export from the barrel**

In `pwa/src/components/erpify/index.ts`, add alongside the existing exports (keep the file's ordering style):

```ts
export { MonogramAvatar } from "./MonogramAvatar";
export { initials } from "./initials";
```

- [ ] **Step 9: Run both tests to verify they pass**

Run: `make pwa.test.unit c='tests/components/erpify/MonogramAvatar.test.tsx'`
Run: `make pwa.test.unit c='tests/components/erpify/initials.test.ts'`
Expected: PASS for both.

- [ ] **Step 10: Document the governance exception in DESIGN.md**

In `pwa/DESIGN.md`, in the "Color — brand and accent (the only chromatic hue in the system)" section, immediately AFTER the brand/accent table (after the line ending `| Reserved for security UI (auth, audit, permissions) |`), add:

```markdown

> **Identity-monogram exception (sanctioned).** `<MonogramAvatar>` renders a record's initials in a brand-indigo tint (`bg-primary/10 text-primary`). This is a deliberate, documented exception to "indigo is interactive/CTA only": the tint reads as an **identity affordance**, not a decorative flourish and not a status signal. It is always `aria-hidden` and the record name is always rendered beside it, so color is never the sole signal. Neutral-tile fallback (`bg-muted text-muted-foreground border`) is the approved downgrade if this is ever revisited.
```

- [ ] **Step 11: Commit**

```bash
git add pwa/src/components/erpify/MonogramAvatar.tsx pwa/src/components/erpify/initials.ts \
  pwa/src/components/erpify/index.ts pwa/DESIGN.md \
  pwa/tests/components/erpify/MonogramAvatar.test.tsx pwa/tests/components/erpify/initials.test.ts
git commit -m "feat(pwa): add MonogramAvatar identity primitive to erpify barrel"
```

---

## Task 2: relative-time on the `DateTimeProvider` port

**Files:**
- Modify: `pwa/src/context/shared/domain/DateTimeProvider/DateTimeProvider.ts`
- Modify: `pwa/src/context/shared/infrastructure/DateTimeProvider/DateFnsDateTimeProvider.ts`
- Test: `pwa/tests/context/shared/infrastructure/DateTimeProvider/dateFnsRelative.test.ts`

- [ ] **Step 1: Write the failing test**

Create `pwa/tests/context/shared/infrastructure/DateTimeProvider/dateFnsRelative.test.ts`:

```ts
import { describe, expect, it } from "vitest";
import { DateFnsDateTimeProvider } from "@/context/shared/infrastructure/DateTimeProvider/DateFnsDateTimeProvider";

const provider = new DateFnsDateTimeProvider();

describe("DateFnsDateTimeProvider — relative time", () => {
  const base = new Date("2026-06-01T12:00:00.000Z");

  it("formats a past date with an 'ago' suffix relative to a base date", () => {
    const twoDaysEarlier = new Date("2026-05-30T12:00:00.000Z");
    expect(provider.formatToRelative(twoDaysEarlier, base)).toBe("2 days ago");
  });

  it("formats a future date with an 'in' prefix relative to a base date", () => {
    const inThreeHours = new Date("2026-06-01T15:00:00.000Z");
    expect(provider.formatToRelative(inThreeHours, base)).toBe("in about 3 hours");
  });

  it("formatIsoToRelative parses the ISO string then formats it relative to now", () => {
    // Use an instant far enough in the past that the rounded distance is stable
    // regardless of the millisecond the test runs at.
    const longAgoIso = provider.formatToISO(provider.add(provider.now(), -5, "years"));
    expect(provider.formatIsoToRelative(longAgoIso)).toBe("about 5 years ago");
  });

  it("formatIsoToRelative returns the raw input back on unparseable values", () => {
    expect(provider.formatIsoToRelative("not-a-date")).toBe("not-a-date");
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `make pwa.test.unit c='tests/context/shared/infrastructure/DateTimeProvider/dateFnsRelative.test.ts'`
Expected: FAIL — `formatToRelative` / `formatIsoToRelative` do not exist.

- [ ] **Step 3: Add the methods to the port interface**

In `pwa/src/context/shared/domain/DateTimeProvider/DateTimeProvider.ts`, add to the `DateTimeProvider` interface, immediately after the `formatToDate(date: Date): string;` declaration:

```ts
  /**
   * Human-readable distance between `date` and `baseDate` (defaults to
   * "now"), e.g. "2 days ago", "in about 3 hours". Suffix/prefix included.
   * Intended for low-precision, glanceable UI; pair it with the absolute
   * timestamp in a `title` tooltip for the exact value.
   */
  formatToRelative(date: Date, baseDate?: Date): string;
```

Then, in the convenience-methods block, immediately after the `formatIsoToLocalDateTime(iso: string): string;` declaration, add:

```ts
  /**
   * Render an ISO 8601 timestamp as a relative distance from now
   * ("2 days ago"). Returns the raw input back when it is unparseable, so UI
   * surfaces never display "Invalid Date".
   */
  formatIsoToRelative(iso: string): string;
```

- [ ] **Step 4: Implement the methods in the adapter**

In `pwa/src/context/shared/infrastructure/DateTimeProvider/DateFnsDateTimeProvider.ts`, add `formatDistance` to the existing `date-fns` import list (insert alphabetically near `endOfDay` / `format`):

```ts
  endOfDay,
  format as dfFormat,
  formatDistance,
  formatISO,
```

Then add the two methods inside the class, immediately after `formatToDate(...)` (before the `toParts` private helper):

```ts
  public formatToRelative(date: Date, baseDate?: Date): string {
    return formatDistance(date, baseDate ?? this.now(), { addSuffix: true });
  }
```

And add `formatIsoToRelative` immediately after `formatIsoToLocalDateTime(...)`:

```ts
  public formatIsoToRelative(iso: string): string {
    const date = this.parseISO(iso);
    return date ? this.formatToRelative(date) : iso;
  }
```

- [ ] **Step 5: Run test to verify it passes**

Run: `make pwa.test.unit c='tests/context/shared/infrastructure/DateTimeProvider/dateFnsRelative.test.ts'`
Expected: PASS

- [ ] **Step 6: Document the new method in DESIGN.md**

In `pwa/DESIGN.md`, in the "Mandatory composite primitives" → there is no provider section; instead update `pwa/CLAUDE.md` in Task's Step 7 below. (No DESIGN.md change needed here — the provider is infrastructure, not a design composite.)

- [ ] **Step 7: Note the method in `pwa/CLAUDE.md`**

In `pwa/CLAUDE.md`, in the "Shared building blocks" → **Dates** bullet, after the sentence ending `it returns the raw input back on unparseable values so tables never show "Invalid Date".`, add:

```markdown
  For glanceable "2 days ago" timestamps use `dateTimeProvider.formatIsoToRelative(iso)` and pair it with the absolute value in a `title` tooltip; never compute relative time inline.
```

- [ ] **Step 8: Commit**

```bash
git add pwa/src/context/shared/domain/DateTimeProvider/DateTimeProvider.ts \
  pwa/src/context/shared/infrastructure/DateTimeProvider/DateFnsDateTimeProvider.ts \
  pwa/tests/context/shared/infrastructure/DateTimeProvider/dateFnsRelative.test.ts \
  pwa/CLAUDE.md
git commit -m "feat(pwa): add relative-time formatting to the DateTimeProvider port"
```

---

## Task 3: verify the port method against the singleton (smoke)

> Tiny guard so consumers (Tasks 5–7) rely on the DI singleton, not the concrete class.

**Files:**
- Test: `pwa/tests/context/shared/infrastructure/DateTimeProvider/singletonRelative.test.ts`

- [ ] **Step 1: Write the test**

Create `pwa/tests/context/shared/infrastructure/DateTimeProvider/singletonRelative.test.ts`:

```ts
import { describe, expect, it } from "vitest";
import { dateTimeProvider } from "@/context/shared/infrastructure/DateTimeProvider";

describe("dateTimeProvider singleton — relative time", () => {
  it("exposes formatIsoToRelative and returns a non-empty string for a valid ISO", () => {
    const iso = dateTimeProvider.formatToISO(dateTimeProvider.add(dateTimeProvider.now(), -1, "days"));
    const result = dateTimeProvider.formatIsoToRelative(iso);
    expect(result).toMatch(/ago$/);
  });
});
```

- [ ] **Step 2: Run test to verify it passes**

Run: `make pwa.test.unit c='tests/context/shared/infrastructure/DateTimeProvider/singletonRelative.test.ts'`
Expected: PASS (the singleton already wires `DateFnsDateTimeProvider`).

- [ ] **Step 3: Commit**

```bash
git add pwa/tests/context/shared/infrastructure/DateTimeProvider/singletonRelative.test.ts
git commit -m "test(pwa): assert the dateTimeProvider singleton exposes relative formatting"
```

---

## Task 4: `isRecentlyCreated` bank-recency helper

**Files:**
- Create: `pwa/src/app/backoffice/banks/_lib/bankRecency.ts`
- Test: `pwa/tests/app/backoffice/banks/bankRecency.test.ts`

- [ ] **Step 1: Write the failing test**

Create `pwa/tests/app/backoffice/banks/bankRecency.test.ts`:

```ts
import { describe, expect, it } from "vitest";
import type { DateTimeProvider } from "@/context/shared/domain/DateTimeProvider/DateTimeProvider";
import { DateFnsDateTimeProvider } from "@/context/shared/infrastructure/DateTimeProvider/DateFnsDateTimeProvider";
import { isRecentlyCreated } from "@/app/backoffice/banks/_lib/bankRecency";

// A provider whose "now" is pinned, so the test is deterministic.
function providerWithNow(nowIso: string): DateTimeProvider {
  const base = new DateFnsDateTimeProvider();
  return Object.assign(Object.create(Object.getPrototypeOf(base)), base, {
    now: () => new Date(nowIso),
  });
}

const NOW = "2026-06-01T12:00:00.000Z";

describe("isRecentlyCreated", () => {
  it("is true when createdAt is within the window (default 7 days)", () => {
    const provider = providerWithNow(NOW);
    expect(isRecentlyCreated("2026-05-28T12:00:00.000Z", provider)).toBe(true);
  });

  it("is false when createdAt is older than the window", () => {
    const provider = providerWithNow(NOW);
    expect(isRecentlyCreated("2026-05-01T12:00:00.000Z", provider)).toBe(false);
  });

  it("is false for a future createdAt (clock skew is not 'new')", () => {
    const provider = providerWithNow(NOW);
    expect(isRecentlyCreated("2026-06-05T12:00:00.000Z", provider)).toBe(false);
  });

  it("is false for an unparseable timestamp", () => {
    const provider = providerWithNow(NOW);
    expect(isRecentlyCreated("not-a-date", provider)).toBe(false);
  });

  it("honours a custom window", () => {
    const provider = providerWithNow(NOW);
    expect(isRecentlyCreated("2026-05-20T12:00:00.000Z", provider, 30)).toBe(true);
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `make pwa.test.unit c='tests/app/backoffice/banks/bankRecency.test.ts'`
Expected: FAIL — cannot resolve `_lib/bankRecency`.

- [ ] **Step 3: Implement the helper**

Create `pwa/src/app/backoffice/banks/_lib/bankRecency.ts`:

```ts
import type { DateTimeProvider } from "@/context/shared/domain/DateTimeProvider/DateTimeProvider";

/** Default "recently created" window, in days, for the bank "New" badge. */
export const BANK_NEW_WINDOW_DAYS = 7;

/**
 * Whether `createdAtIso` falls within the last `withinDays` days relative to
 * the provider's "now". A future timestamp (clock skew) is never "new", and
 * an unparseable timestamp is treated as not-new rather than throwing.
 */
export function isRecentlyCreated(
  createdAtIso: string,
  provider: DateTimeProvider,
  withinDays: number = BANK_NEW_WINDOW_DAYS,
): boolean {
  const created = provider.parseISO(createdAtIso);
  if (!created) return false;
  const ageDays = provider.calculateDuration(created, provider.now(), "days");
  return ageDays >= 0 && ageDays <= withinDays;
}

/** Count how many of the given ISO timestamps are recently created. */
export function countRecentlyCreated(
  createdAtIsos: readonly string[],
  provider: DateTimeProvider,
  withinDays: number = BANK_NEW_WINDOW_DAYS,
): number {
  return createdAtIsos.filter((iso) => isRecentlyCreated(iso, provider, withinDays)).length;
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `make pwa.test.unit c='tests/app/backoffice/banks/bankRecency.test.ts'`
Expected: PASS

- [ ] **Step 5: Add a test for `countRecentlyCreated`**

Append to `pwa/tests/app/backoffice/banks/bankRecency.test.ts`:

```ts
import { countRecentlyCreated } from "@/app/backoffice/banks/_lib/bankRecency";

describe("countRecentlyCreated", () => {
  it("counts only the timestamps within the window", () => {
    const provider = providerWithNow(NOW);
    const isos = [
      "2026-05-29T12:00:00.000Z", // within 7d
      "2026-05-31T12:00:00.000Z", // within 7d
      "2026-04-01T12:00:00.000Z", // older
      "not-a-date", // unparseable
    ];
    expect(countRecentlyCreated(isos, provider)).toBe(2);
  });
});
```

- [ ] **Step 6: Run test to verify it passes**

Run: `make pwa.test.unit c='tests/app/backoffice/banks/bankRecency.test.ts'`
Expected: PASS (both `describe` blocks).

- [ ] **Step 7: Commit**

```bash
git add pwa/src/app/backoffice/banks/_lib/bankRecency.ts pwa/tests/app/backoffice/banks/bankRecency.test.ts
git commit -m "feat(pwa): add bank recency helper for the New badge"
```

---

## Task 5: `BanksTable` — avatar, New badge, relative timestamps

**Files:**
- Modify: `pwa/src/app/backoffice/banks/_components/BanksTable.tsx`
- Test: `pwa/tests/app/backoffice/banks/banksTableIdentity.test.tsx`

> **Design deviation (intentional, honest):** the mockup collapses short-name + name into one identity column. We keep the existing separate, independently-sortable `Short name` and `Name` columns (collapsing would break sort affordances and existing e2e). The avatar + "New" badge attach to the **Name** cell; the `Short name` column stays a sortable mono cell. This delivers the visual identity without regressing sort/columns.

- [ ] **Step 1: Write the failing test**

Create `pwa/tests/app/backoffice/banks/banksTableIdentity.test.tsx`:

```tsx
import { describe, expect, it, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import { BanksTable } from "@/app/backoffice/banks/_components/BanksTable";
import { Bank } from "@/context/backoffice/bank/domain/Bank";

vi.mock("next/navigation", () => ({
  useRouter: () => ({ push: vi.fn() }),
}));

const RECENT = Bank.fromPrimitives({
  id: "11111111-1111-4111-8111-111111111111",
  name: "Santander Bank",
  shortName: "SANB",
  createdAt: new Date().toISOString(),
  updatedAt: new Date().toISOString(),
});

const OLD = Bank.fromPrimitives({
  id: "22222222-2222-4222-8222-222222222222",
  name: "Caixa Bank",
  shortName: "CAIX",
  createdAt: "2020-01-01T00:00:00.000Z",
  updatedAt: "2020-01-01T00:00:00.000Z",
});

describe("BanksTable — identity cell", () => {
  it("renders a monogram avatar in the name cell", () => {
    render(<BanksTable banks={[RECENT]} />);
    expect(screen.getByTestId(`banks-table__avatar-${RECENT.id}`)).toHaveTextContent("SB");
  });

  it("shows a New badge for a recently created bank and not for an old one", () => {
    render(<BanksTable banks={[RECENT, OLD]} />);
    expect(screen.getByTestId(`banks-table__new-${RECENT.id}`)).toHaveTextContent("New");
    expect(screen.queryByTestId(`banks-table__new-${OLD.id}`)).toBeNull();
  });

  it("renders the created cell as relative text with the absolute value in the title", () => {
    render(<BanksTable banks={[OLD]} />);
    const cell = screen.getByTestId(`banks-table__created-${OLD.id}`);
    expect(cell.textContent).toMatch(/ago$/);
    // Absolute dd/mm/yyyy value lives in the tooltip.
    expect(cell).toHaveAttribute("title", expect.stringContaining("2020"));
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `make pwa.test.unit c='tests/app/backoffice/banks/banksTableIdentity.test.tsx'`
Expected: FAIL — no avatar / New / created testids yet.

- [ ] **Step 3: Update the imports and cell renderers**

In `pwa/src/app/backoffice/banks/_components/BanksTable.tsx`, update the imports near the top. Change:

```tsx
import { CopyButton, DataTable } from "@/components/erpify";
```

to:

```tsx
import { CopyButton, DataTable, MonogramAvatar, StatusBadge } from "@/components/erpify";
```

and add, after the `dateTimeProvider` import line:

```tsx
import { dateTimeProvider } from "@/context/shared/infrastructure/DateTimeProvider";
import { isRecentlyCreated } from "../_lib/bankRecency";
```

(The `dateTimeProvider` import already exists — do not duplicate it; only add the `isRecentlyCreated` import.)

- [ ] **Step 4: Replace the cell renderers**

In the same file, replace the four `render*Cell` consts (the block from `const renderShortNameCell` through `const renderUpdatedAtCell`) with:

```tsx
const renderShortNameCell = (row: Bank) => (
  <span className="block truncate font-mono text-xs uppercase" title={row.shortName}>
    {row.shortName}
  </span>
);

const renderNameCell = (row: Bank) => (
  <div className="banks-table__identity flex min-w-0 items-center gap-2.5">
    <MonogramAvatar name={row.name} testId={`banks-table__avatar-${row.id}`} />
    <span className="min-w-0 truncate">{row.name}</span>
    {isRecentlyCreated(row.createdAt, dateTimeProvider) ? (
      <StatusBadge
        variant="info"
        label="New"
        className="banks-table__new flex-none"
        data-testid={`banks-table__new-${row.id}`}
      />
    ) : null}
  </div>
);

const renderRelativeCell = (iso: string, testId: string) => (
  <span title={dateTimeProvider.formatIsoToLocalDateTime(iso)} data-testid={testId}>
    {dateTimeProvider.formatIsoToRelative(iso)}
  </span>
);

const renderCreatedAtCell = (row: Bank) =>
  renderRelativeCell(row.createdAt, `banks-table__created-${row.id}`);
const renderUpdatedAtCell = (row: Bank) =>
  renderRelativeCell(row.updatedAt, `banks-table__updated-${row.id}`);
```

> Note: `StatusBadge` must accept a `data-testid` passthrough. Step 5 adds it.

- [ ] **Step 5: Let `StatusBadge` forward a `data-testid`**

In `pwa/src/components/erpify/StatusBadge.tsx`, change the props interface and the render to forward an optional test id. Replace:

```tsx
interface StatusBadgeProps extends VariantProps<typeof statusVariants> {
  variant: StatusVariant;
  label: string;
  className?: string;
}

export function StatusBadge({ variant, label, className }: Readonly<StatusBadgeProps>) {
  const Icon = iconByVariant[variant];
  return (
    <output className={cn(statusVariants({ variant }), className)}>
      <Icon className="size-3" aria-hidden="true" />
      {label}
    </output>
  );
}
```

with:

```tsx
interface StatusBadgeProps extends VariantProps<typeof statusVariants> {
  variant: StatusVariant;
  label: string;
  className?: string;
  /** Optional test id passthrough (never hardcode in shared components). */
  "data-testid"?: string;
}

export function StatusBadge({
  variant,
  label,
  className,
  "data-testid": dataTestId,
}: Readonly<StatusBadgeProps>) {
  const Icon = iconByVariant[variant];
  return (
    <output className={cn(statusVariants({ variant }), className)} data-testid={dataTestId}>
      <Icon className="size-3" aria-hidden="true" />
      {label}
    </output>
  );
}
```

- [ ] **Step 6: Run the table test to verify it passes**

Run: `make pwa.test.unit c='tests/app/backoffice/banks/banksTableIdentity.test.tsx'`
Expected: PASS

- [ ] **Step 7: Run the existing banks/table suites to catch regressions**

Run: `make pwa.test.unit c='tests/app/backoffice/banks'`
Run: `make pwa.test.unit c='tests/components/erpify/StatusBadge.test.tsx'`
Expected: PASS. If an existing `StatusBadge` snapshot/test asserts exact prop shape, update it to allow the new optional prop.

- [ ] **Step 8: Commit**

```bash
git add pwa/src/app/backoffice/banks/_components/BanksTable.tsx \
  pwa/src/components/erpify/StatusBadge.tsx \
  pwa/tests/app/backoffice/banks/banksTableIdentity.test.tsx
git commit -m "feat(pwa): add avatar, New badge and relative dates to banks table"
```

---

## Task 6: `BanksCards` — avatar, New badge, relative timestamps

**Files:**
- Modify: `pwa/src/app/backoffice/banks/_components/BanksCards.tsx`
- Test: `pwa/tests/app/backoffice/banks/banksCardsIdentity.test.tsx`

- [ ] **Step 1: Write the failing test**

Create `pwa/tests/app/backoffice/banks/banksCardsIdentity.test.tsx`:

```tsx
import { describe, expect, it } from "vitest";
import { render, screen } from "@testing-library/react";
import { BanksCards } from "@/app/backoffice/banks/_components/BanksCards";
import { Bank } from "@/context/backoffice/bank/domain/Bank";

const RECENT = Bank.fromPrimitives({
  id: "33333333-3333-4333-8333-333333333333",
  name: "Santander Bank",
  shortName: "SANB",
  createdAt: new Date().toISOString(),
  updatedAt: new Date().toISOString(),
});

const OLD = Bank.fromPrimitives({
  id: "44444444-4444-4444-8444-444444444444",
  name: "Caixa Bank",
  shortName: "CAIX",
  createdAt: "2020-01-01T00:00:00.000Z",
  updatedAt: "2020-01-01T00:00:00.000Z",
});

describe("BanksCards — identity", () => {
  it("renders a monogram avatar in the card header", () => {
    render(<BanksCards banks={[RECENT]} />);
    expect(screen.getByTestId(`banks-cards__avatar-${RECENT.id}`)).toHaveTextContent("SB");
  });

  it("shows a New badge only for recently created banks", () => {
    render(<BanksCards banks={[RECENT, OLD]} />);
    expect(screen.getByTestId(`banks-cards__new-${RECENT.id}`)).toHaveTextContent("New");
    expect(screen.queryByTestId(`banks-cards__new-${OLD.id}`)).toBeNull();
  });

  it("renders created/updated as relative text with the absolute value in the title", () => {
    render(<BanksCards banks={[OLD]} />);
    const created = screen.getByTestId(`banks-cards__created-${OLD.id}`);
    expect(created.textContent).toMatch(/ago$/);
    expect(created).toHaveAttribute("title", expect.stringContaining("2020"));
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `make pwa.test.unit c='tests/app/backoffice/banks/banksCardsIdentity.test.tsx'`
Expected: FAIL — no avatar / New / relative title yet.

- [ ] **Step 3: Update imports**

In `pwa/src/app/backoffice/banks/_components/BanksCards.tsx`, change:

```tsx
import { CopyButton } from "@/components/erpify";
```

to:

```tsx
import { CopyButton, MonogramAvatar, StatusBadge } from "@/components/erpify";
```

and add after the existing `dateTimeProvider` import line:

```tsx
import { isRecentlyCreated } from "../_lib/bankRecency";
```

- [ ] **Step 4: Add the avatar + New badge to the card header**

In the same file, replace the `<CardHeader> … </CardHeader>` block (from `<CardHeader>` through its closing `</CardHeader>`) so the title row leads with the avatar and the "New" badge sits beside the name. Replace:

```tsx
              <CardHeader>
                <CardTitle className="banks-cards__title min-w-0 break-words">
                  <Link
                    href={detailHref}
                    className="banks-cards__name hover:underline focus-visible:underline focus-visible:outline-none"
                    title={`View bank ${bank.name}`}
                    data-testid={`banks-cards__name-${bank.id}`}
                  >
                    {bank.name}
                  </Link>
                </CardTitle>
                <CardDescription
                  className="banks-cards__shortname truncate font-mono text-xs uppercase"
                  title={bank.shortName}
                  data-testid={`banks-cards__shortname-${bank.id}`}
                >
                  {bank.shortName}
                </CardDescription>
```

with:

```tsx
              <CardHeader>
                <div className="banks-cards__identity flex min-w-0 items-center gap-2.5">
                  <MonogramAvatar
                    name={bank.name}
                    testId={`banks-cards__avatar-${bank.id}`}
                  />
                  <div className="min-w-0 flex-1">
                    <CardTitle className="banks-cards__title flex min-w-0 items-center gap-2 break-words">
                      <Link
                        href={detailHref}
                        className="banks-cards__name min-w-0 truncate hover:underline focus-visible:underline focus-visible:outline-none"
                        title={`View bank ${bank.name}`}
                        data-testid={`banks-cards__name-${bank.id}`}
                      >
                        {bank.name}
                      </Link>
                      {isRecentlyCreated(bank.createdAt, dateTimeProvider) ? (
                        <StatusBadge
                          variant="info"
                          label="New"
                          className="banks-cards__new flex-none"
                          data-testid={`banks-cards__new-${bank.id}`}
                        />
                      ) : null}
                    </CardTitle>
                    <CardDescription
                      className="banks-cards__shortname truncate font-mono text-xs uppercase"
                      title={bank.shortName}
                      data-testid={`banks-cards__shortname-${bank.id}`}
                    >
                      {bank.shortName}
                    </CardDescription>
                  </div>
                </div>
```

> Leave the `<CardAction> … </CardAction>` block exactly as-is — it remains the trailing element inside `<CardHeader>`.

- [ ] **Step 5: Convert the meta dates to relative + title**

In the same file, in `<CardContent>`, replace the two `<dd>` values. Replace:

```tsx
                  <dd
                    className="banks-cards__created text-foreground"
                    data-testid={`banks-cards__created-${bank.id}`}
                  >
                    {dateTimeProvider.formatIsoToLocalDateTime(bank.createdAt)}
                  </dd>
```

with:

```tsx
                  <dd
                    className="banks-cards__created text-foreground"
                    title={dateTimeProvider.formatIsoToLocalDateTime(bank.createdAt)}
                    data-testid={`banks-cards__created-${bank.id}`}
                  >
                    {dateTimeProvider.formatIsoToRelative(bank.createdAt)}
                  </dd>
```

and replace:

```tsx
                  <dd
                    className="banks-cards__updated text-foreground"
                    data-testid={`banks-cards__updated-${bank.id}`}
                  >
                    {dateTimeProvider.formatIsoToLocalDateTime(bank.updatedAt)}
                  </dd>
```

with:

```tsx
                  <dd
                    className="banks-cards__updated text-foreground"
                    title={dateTimeProvider.formatIsoToLocalDateTime(bank.updatedAt)}
                    data-testid={`banks-cards__updated-${bank.id}`}
                  >
                    {dateTimeProvider.formatIsoToRelative(bank.updatedAt)}
                  </dd>
```

- [ ] **Step 6: Run the cards test to verify it passes**

Run: `make pwa.test.unit c='tests/app/backoffice/banks/banksCardsIdentity.test.tsx'`
Expected: PASS

- [ ] **Step 7: Run the banks suite for regressions**

Run: `make pwa.test.unit c='tests/app/backoffice/banks'`
Expected: PASS. If an existing cards test asserts the absolute date string in the cell body, update it to assert the relative text (and the absolute value in the `title`).

- [ ] **Step 8: Commit**

```bash
git add pwa/src/app/backoffice/banks/_components/BanksCards.tsx \
  pwa/tests/app/backoffice/banks/banksCardsIdentity.test.tsx
git commit -m "feat(pwa): add avatar, New badge and relative dates to banks cards"
```

---

## Task 7: list header subtitle — "N banks · M added this week"

**Files:**
- Modify: `pwa/src/app/backoffice/banks/page.tsx`
- Test: `pwa/tests/app/backoffice/banks/banksListSubtitle.test.tsx`

- [ ] **Step 1: Write the failing test**

Create `pwa/tests/app/backoffice/banks/banksListSubtitle.test.tsx`:

```tsx
import { beforeEach, describe, expect, it, vi } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import BanksListPage from "@/app/backoffice/banks/page";
import { Bank } from "@/context/backoffice/bank/domain/Bank";

vi.mock("next/navigation", () => ({
  useRouter: () => ({ push: vi.fn(), refresh: vi.fn() }),
}));

const run = vi.fn();
vi.mock("@/context/shared/infrastructure/DependencyInjection/Container", () => ({
  container: { get: () => ({ run }) },
}));

const RECENT = Bank.fromPrimitives({
  id: "55555555-5555-4555-8555-555555555555",
  name: "Santander Bank",
  shortName: "SANB",
  createdAt: new Date().toISOString(),
  updatedAt: new Date().toISOString(),
});
const OLD = Bank.fromPrimitives({
  id: "66666666-6666-4666-8666-666666666666",
  name: "Caixa Bank",
  shortName: "CAIX",
  createdAt: "2020-01-01T00:00:00.000Z",
  updatedAt: "2020-01-01T00:00:00.000Z",
});

describe("Banks list — header total", () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it("shows the total count and how many were added in the recency window", async () => {
    run.mockResolvedValue({ banks: [RECENT, OLD], nextCursor: undefined });
    render(<BanksListPage />);
    const total = await screen.findByTestId("banks-list__total");
    await waitFor(() => {
      expect(total.textContent).toContain("2");
      expect(total.textContent).toContain("1 added this week");
    });
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `make pwa.test.unit c='tests/app/backoffice/banks/banksListSubtitle.test.tsx'`
Expected: FAIL — the subtitle has no "added this week" segment.

- [ ] **Step 3: Add imports**

In `pwa/src/app/backoffice/banks/page.tsx`, after the existing import of `bankRoutes` (the last `./_lib/...` import), add:

```tsx
import { dateTimeProvider } from "@/context/shared/infrastructure/DateTimeProvider";
import { countRecentlyCreated } from "./_lib/bankRecency";
```

- [ ] **Step 4: Compute the recent count**

In the same file, inside the `BanksListPage` component, immediately after the `visibleBanks` `useMemo` (the block ending `[banks, filter, sort],` then `);`), add:

```tsx
  const recentCount = useMemo(
    () => countRecentlyCreated(banks.map((bank) => bank.createdAt), dateTimeProvider),
    [banks],
  );
```

- [ ] **Step 5: Render the recency segment**

In the same file, replace the `banks-list__total` paragraph. Replace:

```tsx
          {state === ViewStatus.READY ? (
            <p
              className="banks-list__total text-muted-foreground mt-1 text-xs"
              data-testid="banks-list__total"
            >
              Total banks: <span className="text-foreground font-medium">{banks.length}</span>
            </p>
          ) : null}
```

with:

```tsx
          {state === ViewStatus.READY ? (
            <p
              className="banks-list__total text-muted-foreground mt-1 text-xs"
              data-testid="banks-list__total"
            >
              Total banks: <span className="text-foreground font-medium">{banks.length}</span>
              {recentCount > 0 ? (
                <>
                  {" · "}
                  <span className="text-foreground font-medium">{recentCount}</span> added this week
                </>
              ) : null}
            </p>
          ) : null}
```

- [ ] **Step 6: Run the subtitle test to verify it passes**

Run: `make pwa.test.unit c='tests/app/backoffice/banks/banksListSubtitle.test.tsx'`
Expected: PASS

- [ ] **Step 7: Run the banks suite for regressions**

Run: `make pwa.test.unit c='tests/app/backoffice/banks'`
Expected: PASS

- [ ] **Step 8: Commit**

```bash
git add pwa/src/app/backoffice/banks/page.tsx \
  pwa/tests/app/backoffice/banks/banksListSubtitle.test.tsx
git commit -m "feat(pwa): show count added this week in banks list header"
```

---

## Task 8: bank detail page redesign

**Files:**
- Modify: `pwa/src/app/backoffice/banks/[id]/page.tsx`
- Test: `pwa/tests/app/backoffice/banks/bankDetailRedesign.test.tsx`

> **What changes:** (1) the header leads with a `<MonogramAvatar>` and shows a "New" badge beside the name; (2) the redundant top **Copy bank ID** button is removed — the canonical copy moves to the Identifier row in the meta grid; (3) Created/Updated render relative text with the absolute value in `title`; (4) the ID/Identifier `<dd>` row gains an inline `<CopyButton>`. Edit + Delete stay in the header.

- [ ] **Step 1: Write the failing test**

Create `pwa/tests/app/backoffice/banks/bankDetailRedesign.test.tsx`:

```tsx
import { beforeEach, describe, expect, it, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import BankDetailPage from "@/app/backoffice/banks/[id]/page";
import { Bank } from "@/context/backoffice/bank/domain/Bank";

const BANK = Bank.fromPrimitives({
  id: "77777777-7777-4777-8777-777777777777",
  name: "Santander Bank",
  shortName: "SANB",
  createdAt: "2020-01-01T00:00:00.000Z",
  updatedAt: "2020-01-02T00:00:00.000Z",
});

vi.mock("next/navigation", () => ({
  useParams: () => ({ id: BANK.id }),
  useRouter: () => ({ push: vi.fn() }),
}));

const run = vi.fn();
vi.mock("@/context/shared/infrastructure/DependencyInjection/Container", () => ({
  container: { get: () => ({ run }) },
}));

describe("Bank detail — redesign", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    run.mockResolvedValue(BANK);
  });

  it("renders a monogram avatar in the header", async () => {
    render(<BankDetailPage />);
    expect(await screen.findByTestId("banks-detail__avatar")).toHaveTextContent("SB");
  });

  it("renders Created as relative text with the absolute value in the title", async () => {
    render(<BankDetailPage />);
    const created = await screen.findByTestId("banks-detail__field-created");
    expect(created.textContent).toMatch(/ago$/);
    expect(created).toHaveAttribute("title", expect.stringContaining("2020"));
  });

  it("moves the copy control to the Identifier row and drops the header copy button", async () => {
    render(<BankDetailPage />);
    await screen.findByTestId("banks-detail__name");
    expect(screen.queryByTestId("banks-detail__copy-id")).toBeNull();
    expect(screen.getByTestId("banks-detail__id-copy")).toBeInTheDocument();
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `make pwa.test.unit c='tests/app/backoffice/banks/bankDetailRedesign.test.tsx'`
Expected: FAIL — no avatar, header copy still present, no relative title.

- [ ] **Step 3: Update imports**

In `pwa/src/app/backoffice/banks/[id]/page.tsx`, change:

```tsx
import { CopyButton, CorrelationIdChip, EmptyState, ProblemDisplay } from "@/components/erpify";
```

to:

```tsx
import {
  CopyButton,
  CorrelationIdChip,
  EmptyState,
  MonogramAvatar,
  ProblemDisplay,
  StatusBadge,
} from "@/components/erpify";
```

and add, after the existing `dateTimeProvider` import line:

```tsx
import { isRecentlyCreated } from "../_lib/bankRecency";
```

- [ ] **Step 4: Redesign the header (avatar + New badge, drop top Copy ID)**

In the same file, replace the `<header> … </header>` block. Replace:

```tsx
          <header
            className="banks-detail__header flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between"
            data-testid="banks-detail__header"
          >
            <div className="min-w-0">
              <h1
                className="text-foreground text-xl font-semibold tracking-tight break-words sm:text-2xl"
                data-testid="banks-detail__name"
              >
                {bank.name}
              </h1>
              <p
                className="text-muted-foreground mt-1 text-sm break-words"
                data-testid="banks-detail__shortname"
              >
                {bank.shortName}
              </p>
            </div>
            <div className="flex flex-wrap items-center gap-2 sm:flex-nowrap">
              <CopyButton
                value={bank.id}
                label="Copy bank ID"
                title={`Copy bank ID ${bank.id}`}
                testId="banks-detail__copy-id"
              />
              <Link
                href={safeHref(bankRoutes.edit(bank.id))}
                className={cn(buttonVariants({ variant: "outline", size: "sm" }))}
                data-icon="inline-start"
                data-testid="banks-detail__edit-button"
                aria-label={`Edit bank ${bank.name}`}
                title={`Edit bank ${bank.name}`}
              >
                <Pencil className="size-3.5" aria-hidden="true" />
                Edit
              </Link>
              <DeleteBankButton id={bank.id} name={bank.name} onDeleted={handleDeleted} />
            </div>
          </header>
```

with:

```tsx
          <header
            className="banks-detail__header flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between"
            data-testid="banks-detail__header"
          >
            <div className="flex min-w-0 items-start gap-3">
              <MonogramAvatar
                name={bank.name}
                className="size-11 text-base"
                testId="banks-detail__avatar"
              />
              <div className="min-w-0">
                <div className="flex min-w-0 items-center gap-2">
                  <h1
                    className="text-foreground text-xl font-semibold tracking-tight break-words sm:text-2xl"
                    data-testid="banks-detail__name"
                  >
                    {bank.name}
                  </h1>
                  {isRecentlyCreated(bank.createdAt, dateTimeProvider) ? (
                    <StatusBadge
                      variant="info"
                      label="New"
                      className="banks-detail__new flex-none"
                      data-testid="banks-detail__new-badge"
                    />
                  ) : null}
                </div>
                <p
                  className="text-muted-foreground mt-1 font-mono text-sm uppercase break-words"
                  data-testid="banks-detail__shortname"
                >
                  {bank.shortName}
                </p>
              </div>
            </div>
            <div className="flex flex-wrap items-center gap-2 sm:flex-nowrap">
              <Link
                href={safeHref(bankRoutes.edit(bank.id))}
                className={cn(buttonVariants({ variant: "outline", size: "sm" }))}
                data-icon="inline-start"
                data-testid="banks-detail__edit-button"
                aria-label={`Edit bank ${bank.name}`}
                title={`Edit bank ${bank.name}`}
              >
                <Pencil className="size-3.5" aria-hidden="true" />
                Edit
              </Link>
              <DeleteBankButton id={bank.id} name={bank.name} onDeleted={handleDeleted} />
            </div>
          </header>
```

- [ ] **Step 5: Make the meta grid use relative dates + an inline-copy Identifier row**

In the same file, replace the `<dl> … </dl>` meta block. Replace:

```tsx
          <dl
            className="banks-detail__meta border-border bg-card grid grid-cols-1 gap-4 rounded-lg border p-4 sm:grid-cols-2 xl:grid-cols-4"
            data-testid="banks-detail__meta"
          >
            <Field label="Name" value={bank.name} testId="banks-detail__field-name" />
            <Field
              label="Short name"
              value={bank.shortName}
              testId="banks-detail__field-shortname"
            />
            <Field
              label="Created"
              value={dateTimeProvider.formatIsoToLocalDateTime(bank.createdAt)}
              testId="banks-detail__field-created"
            />
            <Field
              label="Updated"
              value={dateTimeProvider.formatIsoToLocalDateTime(bank.updatedAt)}
              testId="banks-detail__field-updated"
            />
            <Field
              label="ID"
              value={bank.id}
              valueClassName="banks-detail__id break-all font-mono text-xs"
              testId="banks-detail__id"
            />
          </dl>
```

with:

```tsx
          <dl
            className="banks-detail__meta border-border bg-card grid grid-cols-1 gap-4 rounded-lg border p-4 sm:grid-cols-2"
            data-testid="banks-detail__meta"
          >
            <Field label="Name" value={bank.name} testId="banks-detail__field-name" />
            <Field
              label="Short name"
              value={bank.shortName}
              valueClassName="font-mono text-xs uppercase"
              testId="banks-detail__field-shortname"
            />
            <Field
              label="Created"
              value={dateTimeProvider.formatIsoToRelative(bank.createdAt)}
              valueTitle={dateTimeProvider.formatIsoToLocalDateTime(bank.createdAt)}
              icon={<Clock className="size-3.5" aria-hidden="true" />}
              testId="banks-detail__field-created"
            />
            <Field
              label="Updated"
              value={dateTimeProvider.formatIsoToRelative(bank.updatedAt)}
              valueTitle={dateTimeProvider.formatIsoToLocalDateTime(bank.updatedAt)}
              icon={<RefreshCw className="size-3.5" aria-hidden="true" />}
              testId="banks-detail__field-updated"
            />
            <div className="banks-detail__field sm:col-span-2">
              <dt className="text-muted-foreground text-xs font-medium tracking-wide uppercase">
                Identifier
              </dt>
              <dd className="mt-1 flex items-center gap-2">
                <span
                  className="banks-detail__id text-foreground min-w-0 truncate font-mono text-xs"
                  data-testid="banks-detail__id"
                >
                  {bank.id}
                </span>
                <CopyButton
                  value={bank.id}
                  iconOnly
                  size="icon-sm"
                  label="Copy ID"
                  copiedLabel="ID copied"
                  errorLabel="Copy failed"
                  title={`Copy bank ID ${bank.id}`}
                  testId="banks-detail__id-copy"
                />
              </dd>
            </div>
          </dl>
```

- [ ] **Step 6: Add the icon imports and extend the `Field` helper**

In the same file, update the `lucide-react` import. Change:

```tsx
import { ChevronLeft, Pencil } from "lucide-react";
```

to:

```tsx
import { ChevronLeft, Clock, Pencil, RefreshCw } from "lucide-react";
```

Then replace the `Field` helper at the bottom of the file. Replace:

```tsx
function Field({
  label,
  value,
  valueClassName,
  testId,
}: Readonly<{
  label: string;
  value: string;
  valueClassName?: string;
  testId?: string;
}>) {
  return (
    <div className="banks-detail__field">
      <dt className="text-muted-foreground text-xs font-medium uppercase tracking-wide">{label}</dt>
      <dd className={cn("text-foreground mt-1 text-sm", valueClassName)} data-testid={testId}>
        {value}
      </dd>
    </div>
  );
}
```

with:

```tsx
function Field({
  label,
  value,
  valueClassName,
  valueTitle,
  icon,
  testId,
}: Readonly<{
  label: string;
  value: string;
  valueClassName?: string;
  valueTitle?: string;
  icon?: React.ReactNode;
  testId?: string;
}>) {
  return (
    <div className="banks-detail__field">
      <dt className="text-muted-foreground flex items-center gap-1.5 text-xs font-medium tracking-wide uppercase">
        {icon}
        {label}
      </dt>
      <dd
        className={cn("text-foreground mt-1 text-sm", valueClassName)}
        title={valueTitle}
        data-testid={testId}
      >
        {value}
      </dd>
    </div>
  );
}
```

> `React` is needed for `React.ReactNode`. If the file does not already import it, add `import type React from "react";` to the import block (the file already imports React hooks from `"react"`, so the namespace type is available — only add this if the linter complains about `React` being undefined).

- [ ] **Step 7: Run the detail test to verify it passes**

Run: `make pwa.test.unit c='tests/app/backoffice/banks/bankDetailRedesign.test.tsx'`
Expected: PASS

- [ ] **Step 8: Run the banks suite for regressions**

Run: `make pwa.test.unit c='tests/app/backoffice/banks'`
Expected: PASS. If an existing detail test referenced `banks-detail__copy-id` or asserted the absolute Created/Updated string, update it: the header copy id is gone (use `banks-detail__id-copy`) and Created/Updated now show relative text (absolute value in `title`).

- [ ] **Step 9: Commit**

```bash
git add pwa/src/app/backoffice/banks/[id]/page.tsx \
  pwa/tests/app/backoffice/banks/bankDetailRedesign.test.tsx
git commit -m "feat(pwa): redesign bank detail header and meta grid"
```

---

## Task 9: data-testid uniqueness, full quality sweep, manual verify

**Files:** none (verification only).

- [ ] **Step 1: Run the data-testid uniqueness guard**

Run: `make pwa.test.unit c='tests/data-testid-uniqueness.test.ts'`
Expected: PASS. New literal testids (`banks-detail__avatar`, `banks-detail__new-badge`, `banks-detail__id-copy`) are each used once; the per-row testids are template literals keyed by `row.id` and are not flagged.

- [ ] **Step 2: Run the full PWA unit suite**

Run: `make pwa.test.unit`
Expected: PASS. Fix any regression surfaced by the date/format or testid changes before continuing.

- [ ] **Step 3: Run the PWA quality gate**

Run: `make pwa.quality`
Expected: clean (ESLint + Prettier). Fix anything reported.

- [ ] **Step 4: Manual verify against the running stack**

With the stack up (`make docker.up`), open `https://localhost/backoffice/banks` and confirm against the mockups:
- Each row/card shows a monogram avatar (indigo-tinted initials).
- Banks created within 7 days show a "New" badge.
- Created/Updated read as "… ago"; hovering shows the full `dd/mm/yyyy, HH:mm:ss`.
- The header reads "Total banks: N · M added this week" when any are recent.
- Open a bank: the detail header shows the avatar + name (+ New badge if recent); the Identifier row has an inline copy button; there is no separate top "Copy bank ID" button.
- Toggle dark mode (if available) and confirm the avatar tint, badge, and focus rings still read correctly.

- [ ] **Step 5: Self-review the security checklist (frontend)**

Confirm: no `dangerouslySetInnerHTML`/`innerHTML`; all dynamic `href` still go through `safeHref` (unchanged); `title`/`aria-label` are escaped text (React) and static where row context conveys the name; no secrets/PII added to storage. Note results in the PR description.

- [ ] **Step 6: Final commit (docs catch-up if needed)**

If `make pwa.quality` or review prompted any doc updates (DESIGN.md adoption row, CLAUDE.md), stage and commit them:

```bash
git add -A
git commit -m "docs(pwa): note bank visual redesign in design + agent docs"
```

---

## Self-Review

**1. Spec coverage (mockup → task):**
- Monogram avatar → Task 1 (primitive) + Tasks 5/6/8 (wiring). ✓
- "New" badge `createdAt ≤ 7d` → Task 4 (helper) + Tasks 5/6/8. ✓
- Relative timestamps + absolute on hover → Task 2 (port) + Tasks 5/6/8. ✓
- Header "N banks · M added this week" → Task 7. ✓
- Detail redesign (avatar header, meta grid, Identifier inline copy, trimmed top copy) → Task 8. ✓
- Toolbar restyle → already satisfied (`BanksViewToggle`); documented, no task. ✓
- Row hover/clickable → already satisfied (`<DataTable>`); documented, no task. ✓

**2. Placeholder scan:** every code step shows complete code; no TODO/TBD; no "add error handling" hand-waving. ✓

**3. Type consistency:** `MonogramAvatar({ name, className, testId })`, `initials(name)`, `isRecentlyCreated(iso, provider, withinDays?)`, `countRecentlyCreated(isos, provider, withinDays?)`, `formatToRelative(date, baseDate?)`, `formatIsoToRelative(iso)`, `StatusBadge({ ..., "data-testid"? })`, `Field({ ..., valueTitle?, icon? })` — names and signatures used consistently across tasks. ✓

**Cross-task ordering:** Task 1 (primitive) and Tasks 2–4 (helpers) precede Tasks 5–8 (consumers). `StatusBadge` gains its `data-testid` passthrough in Task 5 before Tasks 6/8 use it — Tasks 6 and 8 depend on Task 5 having landed. Execute in numeric order.
