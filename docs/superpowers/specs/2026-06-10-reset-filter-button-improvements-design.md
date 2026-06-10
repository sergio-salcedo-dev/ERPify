# Banks list — active-filter chip bar + "Code" naming unification

- **Date:** 2026-06-10
- **Scope:** `pwa` (backoffice banks), with three message-only string edits in `api`
- **Branch:** `feat/pwa-reset-filter-button-improvements`
- **Status:** ready-for-dev

## Problem

The banks list reset/clear-filters experience has two weaknesses, and the
field shown as the bank's short code is labelled inconsistently:

1. **The Reset button is unreachable when it matters.** It lives *inside* the
   collapsible filter panel (`BanksFilters.tsx`), so when the panel is collapsed
   the user cannot clear active filters without first expanding it. There are
   also two differently-labelled reset affordances — the panel "Reset" and the
   empty-state "Reset filters" (`BanksEmptyFiltered.tsx`).
2. **No at-a-glance view of what is filtering the list.** Active filters are
   only visible by expanding the panel and reading each field.
3. **The `shortName` field reads as two different words.** The list column
   header says **"Code"** (`BanksTable.tsx`), while the filter, the form, the
   detail view, the sort dropdown, and the API validation messages say **"short
   name"** / `shortName`.

## Goals

- Surface active filters as removable **chips** in an **always-visible bar**, so
  clearing (individually or all-at-once) works regardless of panel state.
- Make a single, consistent **Clear all** affordance the unified reset.
- Preserve keyboard accessibility (focus never strands on `<body>`).
- Standardise the user-facing term for `shortName` to **"Code"** everywhere,
  keeping `shortName` as the internal identifier.

## Non-goals

- Renaming the `shortName` field end-to-end (entity property, DB column, wire,
  events). Out of scope — a possible future change. Only user-facing strings
  change here.
- Adding screen-reader announcements, a count in the Clear-all label, undo, or a
  keyboard shortcut. Considered and explicitly deferred — the only behaviour
  polish in scope is focus management.
- Changing the debounce/realtime/pagination behaviour of the list.

---

## Part A — Active-filter chip bar (reset UX)

### Architecture & ownership

The chip bar is rendered **by `BanksFilters.tsx`**, as a sibling between the
toolbar `<div>` and the collapsible panel `<section>`. It is shown whenever any
filter or non-default sort is active (`canReset`), independent of whether the
panel is open.

**Why `BanksFilters` owns it (not a new page-level component):** `BanksFilters`
already owns the debounced local mirrors for the `name` / `shortName` text
inputs (`nameInput`, `shortNameInput`) and the `handleReset` logic that cancels
a pending debounce by clearing those mirrors. Per-chip removal of those two
fields must reuse that cancellation; keeping the bar here avoids lifting the
mirror state up into `page.tsx` and re-introducing the debounce race that
`handleReset` already guards.

A new **presentational** component
`pwa/src/app/backoffice/banks/_components/BanksActiveFilters.tsx` renders the
chip list plus the Clear-all button from props. It holds no filter logic — it
receives chip descriptors and callbacks — so it is trivially unit-testable.

```
<section class="banks-filters">
  <div class="banks-filters__toolbar"> … search, Filters toggle … </div>
  <BanksActiveFilters … />            ← NEW, shown when canReset
  <section class="banks-filters__panel"> … fields, sort … </section>   ← Reset button removed
</section>
```

### Chip model & labels

A pure helper in `pwa/src/app/backoffice/banks/_lib/banksFilterSort.ts`:

```ts
type FilterChipKey = "name" | "shortName" | "created" | "sort";
interface FilterChipDescriptor { key: FilterChipKey; label: string }
function buildActiveFilterChips(filter: BanksFilter, sort: BanksSort): FilterChipDescriptor[]
```

Chips, in order, each present only when active:

| key         | when                       | label example                       |
| ----------- | -------------------------- | ----------------------------------- |
| `name`      | `filter.name` non-blank    | `Name: Acme`                        |
| `shortName` | `filter.shortName` non-blank | `Code: ACM` (see Part B)          |
| `created`   | either bound set (adaptive)| both → `Created: Jun 1 – Jun 30, 2026`; from-only → `Created after: Jun 1, 2026`; to-only → `Created before: Jun 30, 2026` |
| `sort`      | `!isDefaultSort(sort)`     | `Sorted: Created ↓` (column label from `BANKS_SORTABLE_COLUMNS` + direction arrow) |

Date formatting uses the existing `dateTimeProvider`
(`@/context/shared/infrastructure/DateTimeProvider`), parsing the stored
`yyyy-mm-dd` as a **local** date to avoid a timezone off-by-one. The exact
provider method is pinned during implementation; no `date-fns` types leak past
the boundary.

The helper is pure and label-only; the component maps each `key` to its removal
handler (below).

### Removal & Clear-all behaviour

Per-chip `✕`:

| key         | action                                                                  |
| ----------- | ----------------------------------------------------------------------- |
| `name`      | `onFilterChange({ …filter, name: "" })` **and** `setNameInput("")` (cancels pending debounce) |
| `shortName` | clear field **and** `setShortNameInput("")`                             |
| `created`   | clear both `createdFrom` and `createdTo`                                |
| `sort`      | `onSortChange(DEFAULT_SORT)`                                            |

**Clear all** reuses the existing `handleReset` → `EMPTY_FILTER` +
`DEFAULT_SORT`, clearing both local mirrors. This *is* the unified reset.

### Focus management (the in-scope behaviour-polish item)

After removing a chip, focus moves to the next chip's remove button; if it was
the last chip, focus moves to the Clear-all button; when the bar empties
entirely (Clear all, or removing the final chip), focus moves to the Filters
toggle. Keyboard users are never dropped to `<body>`.

### Unification & removals

- Remove the panel-internal Reset block (the `canReset ? <Reset/> : null` inside
  the panel in `BanksFilters.tsx`). Its job moves to the bar's Clear all.
- `BanksEmptyFiltered.tsx` keeps its contextual button but is unified to the
  **same label and behaviour** as the bar's Clear all (calls `resetFilters`).
- Test ids: add `banks-filters__active` (bar), `banks-filters__chip-<key>`
  (chip remove control), `banks-filters__clear-all` (Clear all). Retire the old
  `banks-filters__reset`.

---

## Part B — "Code" naming unification

The internal field name `shortName` stays unchanged — property, `data-testid`s
(`*__short-name`, `*__field-shortname`), and the sort-column id `shortName` all
remain. Only **user-facing strings** change to **Code / code**.

### PWA labels → "Code"

| File | Change |
| ---- | ------ |
| `pwa/src/app/backoffice/banks/_components/BanksFilters.tsx` | short-name `FormField` `label="Short name"` → `"Code"` |
| `pwa/src/app/backoffice/banks/_components/BankForm.tsx` (≈ line 217) | `label="Short name"` → `"Code"` |
| `pwa/src/app/backoffice/banks/[id]/page.tsx` (≈ line 305) | `<Field label="Short name">` → `"Code"` |
| `pwa/src/app/backoffice/banks/_lib/banksFilterSort.ts` | `BANKS_SORTABLE_COLUMNS` `shortName` `label: "Short name"` → `"Code"` (drives both the sort dropdown and the sort chip) |
| chip bar | new chip label `Code: ACM` |
| `pwa/src/app/backoffice/banks/_components/BanksTable.tsx` | already `"Code"` — no change |

### PWA Zod schema (must match API 422 strings)

`pwa/src/context/backoffice/bank/application/schemas/BankSchema.ts`:

- `"The shortName field is required."` → `"The code field is required."`
- `"The shortName must not exceed 50 characters."` → `"The code must not exceed 50 characters."`

### API messages → "code" (preserve Zod↔422 parity per `pwa/CLAUDE.md`)

- `api/src/Backoffice/Bank/Application/Command/CreateBankCommand.php` — `NotBlank`
  message and `Length` `maxMessage` for `shortName` → "code".
- `api/src/Backoffice/Bank/Application/Command/UpdateBankCommand.php` — same two.
- `api/src/Backoffice/Bank/Domain/Entity/Bank.php` (≈ line 30) — `UniqueEntity`
  message `"This short name is already in use."` → `"This code is already in use."`

No field rename, no migration, no logic change — strings only.

---

## Testing

- **Unit** (`_lib`): `buildActiveFilterChips` — which chips appear, label
  wording, adaptive date branches, sort-only-when-drifted.
- **Component** (Vitest): `BanksActiveFilters` renders chips, each `✕` fires the
  right callback, Clear all fires reset; `BanksFilters` integration — removing a
  name/code chip cancels the pending debounce (the existing race guard, now via
  chip); focus moves to the correct target after removal.
- **e2e** (Playwright): rework the reset assertions in
  `pwa/tests/e2e/backoffice/banks.spec.ts` and
  `pwa/tests/e2e/backoffice/banks-real-api-flows.spec.ts` from
  `banks-filters__reset` to the chip bar + `banks-filters__clear-all`; add a
  chip-removal flow. Mind the known debounce/active-filter-badge race and the
  Mercure cross-spec pollution when asserting.
- **API** tests asserting the three changed messages (functional / Behat) are
  updated to the new "code" strings.

### Gates

- PWA: `make pwa.quality` + `make pwa.test`.
- PHP (Part B touches 3 files): `make php.stan` on the changed files, then
  `make php.quality`.

## Delivery

Two commits in this branch, reviewable (or splittable) independently:

1. `refactor(banks): unify shortName label to "Code"` — Part B (PWA labels +
   Zod + API messages + their test assertions).
2. `feat(banks): active-filter chip bar with reachable clear-all` — Part A.

## Risks & notes

- **Debounce race on chip removal.** The name/code chips reflect *committed*
  filter values; their `✕` must clear the local input mirror, not only the
  parent filter, or a pending debounce can re-apply the just-cleared text. This
  is why the bar lives in `BanksFilters` (reuses `handleReset`'s cancellation).
- **e2e flakiness.** Mocked-banks specs can receive real rows from parallel
  real-API specs via the live Mercure stream; id-scoped locators and
  single-spec reruns mitigate. The 300 ms filter debounce + page-reset race
  means chip/filter assertions should wait for the active state before acting.
- **Doc updates.** This is a UI/label change with no new module, route, or
  endpoint shape — no architecture/quickref doc update is required. If the chip
  bar introduces a reusable primitive later, revisit `pwa/docs/`.
