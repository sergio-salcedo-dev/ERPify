---
title: 'PWA Backoffice Bank list — client-side filters + sort'
type: 'feature'
created: '2026-05-08'
status: 'done'
baseline_commit: '80ad581'
context:
  - pwa/CLAUDE.md
  - pwa/AGENTS.md
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** The Backoffice Bank list at `/backoffice/banks` shows every loaded row with no way to narrow it down — operators can't search by name or short name, can't scope to a creation window, and can't reorder columns.

**Approach:** Add a client-side filter bar (name contains, shortName contains, createdAt range) and column-header sort that operate in-memory on the rows already returned by `SearchBanks`. Pure logic lives in a small testable module; the page composes the bar + table; the table delegates sort to the existing `<DataTable sortable>` plumbing.

## Boundaries & Constraints

**Always:**
- Operate on the rows already returned by `SearchBanks` — no extra API calls, no new query params.
- All active filters AND together; empty filter is ignored. Name and shortName matching is case-insensitive substring (`toLowerCase()` on both sides).
- `createdFrom` is inclusive at start-of-day local; `createdTo` is inclusive at end-of-day local (`23:59:59.999`). Either bound may be empty.
- Sort is single-column, tri-state (asc → desc → unsorted) driven by `<DataTable sort onSortChange>`; unsorted = API original order.
- Pure logic (`applyFilters`, `applySort`) lives in `_lib/banksFilterSort.ts` — no React/DOM imports, unit-test target. Page derives `visibleBanks` via `useMemo` and never mutates the source array.
- BEM class names on new JSX (`banks-filters__field`, etc.); strict TS, no `any`.
- When filters/sort hide every row but the source list is non-empty, render an inline "no matches" panel with a `[Reset filters]` button — distinct from the existing first-run `<EmptyState>`.
- When `meta.nextCursor` exists, the existing notice must explicitly state filters and sort apply only to the loaded page.

**Ask First:**
- Persisting filter/sort state in the URL.
- Pushing the filter/sort to the backend (would change `BankSearchQuery`/`BankSearchCriteria`).
- Replacing or removing the `updatedAt` column (currently kept; `createdAt` is added).
- Adding sortable fields beyond `name`, `shortName`, `createdAt`.

**Never:**
- Modify any PHP / API code (`BankSearchQuery`, controller, criteria, repository).
- Modify `SearchBanks`, `ApiBankRepository`, `BankRepository`, or `Bank` domain types.
- Introduce a third-party form/state library — `useState` + `useMemo` only.
- Refetch on filter/sort changes; debounce/throttle inputs.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior |
|----------|---------------|----------------------------|
| Name filter, case mismatch | `name="bbva"`, row `name="BBVA"` | Row matches |
| Name + shortName combined | `name="bank"`, `shortName="ib"` | Only rows where both contain (AND) |
| createdAt range | `from=2026-01-01`, `to=2026-01-31` | Rows in `[2026-01-01 00:00:00.000, 2026-01-31 23:59:59.999]` local; either bound may be omitted |
| createdAt invalid range | `from > to` | Zero matches; "no matches" panel |
| Sort cycle | header click on `name` | asc → desc → null (original order); `aria-sort` updates |
| Filters narrow to zero | non-empty source | Hide table; show "No banks match your filters" + `[Reset filters]` |
| Source list empty | API `data:[]` | Existing first-run `<EmptyState>`; filter bar hidden |
| Row with bad ISO `createdAt` | `createdAt="not-a-date"` | Excluded from range filter; sortable still renders (NaN sorts last asc / first desc) |

</frozen-after-approval>

## Code Map

- `pwa/src/app/backoffice/banks/_lib/banksFilterSort.ts` — new pure module: `BanksFilter`, `BanksSort`, `EMPTY_FILTER`, `hasActiveFilter`, `applyFilters`, `applySort`.
- `pwa/src/app/backoffice/banks/_components/BanksFilters.tsx` — new `"use client"` controlled component: 4 inputs + `[Reset]` button.
- `pwa/src/app/backoffice/banks/_components/BanksTable.tsx` — accept `sort` + `onSortChange`; mark `name`/`shortName`/`createdAt` `sortable: true`; add a `createdAt` column; keep `updatedAt`.
- `pwa/src/app/backoffice/banks/page.tsx` — hold filter + sort state, derive `visibleBanks`, render `<BanksFilters>` and the "no matches" panel, update `nextCursor` notice.
- `pwa/tests/unit/app/backoffice/banks/banksFilterSort.test.ts` — Vitest spec covering the I/O matrix.
- `pwa/tests/e2e/backoffice/banks.spec.ts` — extend with filter/sort/reset specs against `page.route` fixtures.

## Tasks & Acceptance

**Execution:**
- [x] `_lib/banksFilterSort.ts` — `BanksFilter = { name: string; shortName: string; createdFrom: string; createdTo: string }`; `BanksSort = DataTableSort | null`; `EMPTY_FILTER`; `hasActiveFilter(filter)`; `applyFilters(banks, filter)` (AND, case-insensitive substring; createdAt parsed via `new Date(\`${yyyy-mm-dd}T00:00:00\`)` and `T23:59:59.999`; invalid row dates excluded from range); `applySort(banks, sort)` (`name`/`shortName` via `localeCompare(undefined, { sensitivity: "base" })`, `createdAt` via `Date.parse` with NaN sort last asc / first desc; `null` returns input untouched).
- [x] `_components/BanksFilters.tsx` — props `{ filter, onFilterChange, onReset, disabled? }`. BEM root `banks-filters`. 4-column grid (md+) / stacked (mobile). Each input wrapped in `<FormField label name>` (`Name`, `Short name`, `Created from`, `Created to`) with `<Input>` (`type="text"` for names, `type="date"` for the dates). `[Reset]` `<Button variant="outline" size="sm">` disabled when `!hasActiveFilter(filter)`. `data-testid` per input + reset.
- [x] `_components/BanksTable.tsx` — add `sort?: DataTableSort | null` and `onSortChange?: (sort: DataTableSort | null) => void` props; mark the relevant columns `sortable: true`; add a `createdAt` column rendering `new Date(row.createdAt).toLocaleString()`.
- [x] `app/backoffice/banks/page.tsx` — `useState(EMPTY_FILTER)` and `useState<BanksSort>(null)`; `visibleBanks = useMemo(() => applySort(applyFilters(banks, filter), sort), [banks, filter, sort])`. Render `<BanksFilters>` above the boundary only when `state === "ready"`. Inside ready: if `visibleBanks.length === 0` render the "no matches" panel (BEM `banks-list__empty-filtered`) with a button that calls `setFilter(EMPTY_FILTER); setSort(null)`. Otherwise `<BanksTable banks={visibleBanks} sort onSortChange>`. Update `nextCursor` copy to "More banks available. Filters and sort apply only to this page."
- [x] `tests/app/backoffice/banks/banksFilterSort.test.ts` (path follows project convention; no `tests/unit/` subdirectory) — cover each I/O matrix row.
- [x] `tests/e2e/backoffice/banks.spec.ts` — fixtures with 6+ rows; assert filtered subset on `name`, AND combination, range narrowing, sort cycle order, "no matches" panel, reset.

### Review Findings (2026-05-08, multi-agent review of `spec-pwa-bank-list-filters-sort`)

- [x] [Review][Patch] Reset button stays disabled when only sort is active — lift "anything resettable?" to the parent and pass it as `resetDisabled` (also retires the previously-dead `disabled` prop) [pwa/src/app/backoffice/banks/_components/BanksFilters.tsx, page.tsx]
- [x] [Review][Patch] `Intl.Collator(undefined, …)` makes name/shortName ordering depend on machine locale — pin to `"en"` for deterministic CI/dev parity [pwa/src/app/backoffice/banks/_lib/banksFilterSort.ts:73]
- [x] [Review][Patch] Sort E2E coupled to `td:nth(1)` — replace with `getByRole("row").nth(1).getByRole("cell", { name, exact })` so column reorder doesn't silently pass-or-fail wrongly [pwa/tests/e2e/backoffice/banks.spec.ts:206-238]
- [x] [Review][Patch] AC "URL unchanged after filter input" had no test — add `expect(page).toHaveURL(...)` to the existing name-filter spec [pwa/tests/e2e/backoffice/banks.spec.ts:155]
- [x] [Review][Patch] AC "combined name + createdFrom + sort by createdAt desc" had no test — add a dedicated combined spec [pwa/tests/e2e/backoffice/banks.spec.ts:240-265]
- [x] [Review][Patch] AC "nextCursor notice copy" had no test — extend `mockBanksApi` with `list_next_cursor` and assert the copy [pwa/tests/e2e/fixtures/banks-api.ts, banks.spec.ts:267-282]
- [x] [Review][Defer] Timezone semantics: client-local bounds vs UTC `Z` row instants can shift inclusion at midnight in non-UTC runners [pwa/src/app/backoffice/banks/_lib/banksFilterSort.ts] — deferred, see deferred-work.md
- [x] [Review][Defer] DST transition day yields a 23h or 25h local-time window [pwa/src/app/backoffice/banks/_lib/banksFilterSort.ts] — deferred
- [x] [Review][Defer] Unicode NFC vs NFD not normalized on name / shortName matching [pwa/src/app/backoffice/banks/_lib/banksFilterSort.ts:50] — deferred
- [x] [Review][Defer] Turkish dotted/dotless I not handled by default-locale `toLowerCase` [pwa/src/app/backoffice/banks/_lib/banksFilterSort.ts:50] — deferred
- [x] [Review][Defer] Legacy Safari `<input type="date">` accepts free-form locale-formatted text [pwa/src/app/backoffice/banks/_components/BanksFilters.tsx] — deferred
- [x] [Review][Defer] No `aria-invalid` on impossible `from > to` range [pwa/src/app/backoffice/banks/_components/BanksFilters.tsx] — deferred
- [x] [Review][Defer] No `aria-live` region for filter count / "no matches" state [pwa/src/app/backoffice/banks/page.tsx] — deferred

**Acceptance Criteria:**
- Given the page has loaded N rows, when the user types in any filter, then only matching rows remain (AND-combined), the row count updates synchronously, and the URL is unchanged.
- Given filters narrow to zero but the source list is non-empty, when the page renders, then the table is hidden and a "No banks match your filters" panel with `[Reset filters]` appears; clicking the button restores the full list and clears every filter input.
- Given the user clicks the `name` header twice, then rows render in descending alphabetic order, the header announces `aria-sort="descending"`, and a third click restores the API's original order.
- Given `name="bank"`, `createdFrom=2026-01-01`, and sort by `createdAt` desc, then visible rows satisfy all three constraints.
- Given the API response carries `meta.nextCursor`, then the notice reads "More banks available. Filters and sort apply only to this page."
- `make pwa.lint`, `make pwa.test.unit c='app/backoffice/banks/banksFilterSort.test.ts'`, `make pwa.test.e2e c='backoffice/banks.spec.ts'`, `make pwa.build` all pass.

## Design Notes

**Pure module rationale:** isolating `applyFilters` / `applySort` from React keeps the unit suite cheap and decouples the rules from any future shift to backend filtering — the same module could later feed an API request builder.

**Date parsing:** `<input type="date">` returns `yyyy-mm-dd`. Suffixing local times (`T00:00:00`, `T23:59:59.999`) keeps the boundary aligned with how operators perceive "today"; UTC-only parsing would silently shift by the local offset.

## Verification

**Commands:**
- `make pwa.lint` — 0 ESLint/Prettier errors.
- `make pwa.test.unit c='app/backoffice/banks/banksFilterSort.test.ts'` — green.
- `make pwa.test.e2e c='backoffice/banks.spec.ts'` — existing + new specs green.
- `make pwa.build` — succeeds.

**Manual checks:**
- `make dev` → `http://localhost/backoffice/banks` → load fixtures via `make db.load.fixtures` → exercise filters and sort; confirm `[Reset filters]` clears every input and the active sort.

## Suggested Review Order

**Pure logic — start here**

- Filtering and sort engine; AND-combined predicates, inclusive local-day bounds, locale-pinned collator, NaN-date placement.
  [`banksFilterSort.ts:48`](../../pwa/src/app/backoffice/banks/_lib/banksFilterSort.ts#L48)
- Tri-state sort + locale "en" collator; tests rely on this for deterministic ordering.
  [`banksFilterSort.ts:85`](../../pwa/src/app/backoffice/banks/_lib/banksFilterSort.ts#L85)

**Page composition**

- Derived `visibleBanks` from `applyFilters` + `applySort`; sole place state shapes the rendered list.
  [`page.tsx:71`](../../pwa/src/app/backoffice/banks/page.tsx#L71)
- `BanksFilters` mount only when source has rows; `resetDisabled` lifted so sort is also resettable.
  [`page.tsx:102`](../../pwa/src/app/backoffice/banks/page.tsx#L102)
- Inline "no matches" panel + `[Reset filters]` button when filters narrow to zero.
  [`page.tsx:126`](../../pwa/src/app/backoffice/banks/page.tsx#L126)
- `nextCursor` notice copy updated to call out the page-only scope.
  [`page.tsx:147`](../../pwa/src/app/backoffice/banks/page.tsx#L147)

**UI surface**

- Controlled filter bar; `<FormField>` clones `<Input>` to inject id + aria; BEM root, mobile-stacked grid.
  [`BanksFilters.tsx:17`](../../pwa/src/app/backoffice/banks/_components/BanksFilters.tsx#L17)
- Table now declares `name`, `shortName`, `createdAt` sortable; new `createdAt` column added.
  [`BanksTable.tsx:8`](../../pwa/src/app/backoffice/banks/_components/BanksTable.tsx#L8)

**Tests & fixture**

- 22 unit cases cover every I/O matrix row and the immutability/locale assumptions.
  [`banksFilterSort.test.ts:68`](../../pwa/tests/app/backoffice/banks/banksFilterSort.test.ts#L68)
- Filter/sort E2E group; URL stability, AND combination, no-matches panel, sort cycle, combined filter+sort, nextCursor notice copy.
  [`banks.spec.ts:138`](../../pwa/tests/e2e/backoffice/banks.spec.ts#L138)
- Fixture extended with `list_next_cursor` so the meta path is exercisable.
  [`banks-api.ts:42`](../../pwa/tests/e2e/fixtures/banks-api.ts#L42)
