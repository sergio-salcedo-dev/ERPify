---
title: 'PWA Backoffice Bank list — client-side pagination'
type: 'feature'
created: '2026-05-08'
status: 'done'
baseline_commit: 'ebe9094'
context:
  - pwa/CLAUDE.md
  - pwa/AGENTS.md
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** The Backoffice Bank list renders every loaded row in a single scrollable table. With even a moderate catalog (50+ banks) the table is unwieldy and operators cannot focus on a slice.

**Approach:** Add client-side pagination that runs after filter + sort on the loaded `banks` array. Page size is a fixed 10. Prev / Next + a "Page X of N" indicator appear below the table only when more than one page is needed. No backend changes — `SearchBanks` still feeds the pipeline; the existing oversize-dataset notice (when `meta.nextCursor` is set) is reworded to mention pagination.

## Boundaries & Constraints

**Always:**
- Pagination operates on the in-memory `visibleBanks` array (post `applyFilters` + `applySort`); never refetch on page change.
- Default and only page size in this iteration: 10. Code constant only.
- Prev / Next + "Page X of N" indicator. Hide the whole pagination block when `totalPages <= 1`.
- When `filter` or `sort` changes, reset `page` to 1; never render an empty page while non-empty data exists (clamp to last valid page).
- Pure logic (`paginate(items, page, pageSize)` returning `{ rows, totalPages, totalRows, page }`) lives in `_lib/paginate.ts`. No React imports — unit-test target.
- BEM class names; strict TS, no `any`; aria-label on Prev / Next; `aria-live="polite"` on the indicator.
- `nextCursor` notice copy must read: filters, sort, **and pagination** apply only to the loaded page.
- E2E covers at least 50 mocked banks demonstrating multi-page navigation, partial last page, and page-reset on filter/sort.

**Ask First:**
- A user-facing page-size selector.
- Numbered page buttons or a "Load more" pattern.
- Persisting `page` / `pageSize` in the URL.
- Backend cursor-driven pagination.

**Never:**
- Modify any PHP / API code.
- Refetch from the API on page change.
- Add a third-party pagination library — `useState` + the pure helper only.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output |
|----------|---------------|-----------------|
| Single page | `visibleBanks.length <= 10` | Pagination block hidden |
| Page 1 of multi | 50 rows, page 1 | Rows 1–10; "Page 1 of 5"; Prev disabled, Next enabled |
| Mid page | 50 rows, page 3 | Rows 21–30; both Prev and Next enabled |
| Last page, partial | 47 rows, page 5 | Rows 41–47; "Page 5 of 5"; Next disabled |
| Filter changes | 50 rows on page 4, filter → 12 matches | Page reset to 1; rows 1–10 of the 12 |
| Sort changes | 50 rows on page 3, sort cycle | Page reset to 1 |
| Filter narrows below current page | 50 rows on page 5, filter → 8 matches | Pagination hidden; 8 rows shown |
| Empty filter result | filter → 0 | Existing "no matches" panel; no pagination |
| Source list empty | API `data:[]` | Existing first-run `<EmptyState>`; no pagination |
| nextCursor present | API meta has `nextCursor` | Notice rewords to call out pagination too |

</frozen-after-approval>

## Code Map

- `pwa/src/app/backoffice/banks/_lib/paginate.ts` — new pure module: `paginate<T>`, `BANKS_PAGE_SIZE = 10`.
- `pwa/src/app/backoffice/banks/_components/BanksPagination.tsx` — new `"use client"` component: Prev / Next + indicator.
- `pwa/src/app/backoffice/banks/page.tsx` — hold `page` state; reset on filter/sort change; derive paged slice; update `nextCursor` notice copy.
- `pwa/tests/app/backoffice/banks/paginate.test.ts` — Vitest spec over the I/O matrix.
- `pwa/tests/e2e/fixtures/banks-api.ts` — export `makeBanks(count)` helper.
- `pwa/tests/e2e/backoffice/banks.spec.ts` — extend with a `pagination` describe block over 50 mocked banks.

## Tasks & Acceptance

**Execution:**
- [x] `_lib/paginate.ts` — `BANKS_PAGE_SIZE = 10`; `paginate<T>(items, page, pageSize)` clamps `page` to `[1, max(1, totalPages)]`, returns `{ rows, totalPages, totalRows, page }` where `rows = items.slice((page-1)*pageSize, page*pageSize)`. `totalPages = max(1, ceil(totalRows / pageSize))`. Pure; no React imports.
- [x] `_components/BanksPagination.tsx` — props `{ page, totalPages, onPageChange }`. BEM `banks-pagination`. Prev / Next `<Button variant="outline" size="sm">` with aria-labels and disabled at the boundaries. Indicator span `aria-live="polite"` reads `"Page {page} of {totalPages}"`. `data-testid` on prev / next / indicator.
- [x] `app/backoffice/banks/page.tsx` — `const [page, setPage] = useState(1)`; `useEffect(() => setPage(1), [filter, sort])`; `const paged = useMemo(() => paginate(visibleBanks, page, BANKS_PAGE_SIZE), [visibleBanks, page])`. Render `<BanksTable banks={paged.rows} sort onSortChange>` and, only when `paged.totalPages > 1`, `<BanksPagination page={paged.page} totalPages={paged.totalPages} onPageChange={setPage}>`. Reword `nextCursor` notice to include "and pagination".
- [x] `tests/app/backoffice/banks/paginate.test.ts` — cover each I/O matrix row plus immutability.
- [x] `tests/e2e/fixtures/banks-api.ts` — `makeBanks(count, opts?)`: deterministic ids `00000000-0000-4000-8000-${i.padStart(12,"0")}`, names `"Bank 001"…`, shortNames `"BNK001"…`, `createdAt = 2026-01-01 + i days` UTC.
- [x] `tests/e2e/backoffice/banks.spec.ts` — `pagination` describe, 50 banks: (a) "Page 1 of 5" + 10 rows + Prev disabled; (b) Next → "Page 2 of 5" with new rows; (c) navigate to last page → Next disabled; (d) Prev returns to previous page; (e) typing a filter that yields ≤10 matches hides the pagination block; (f) sorting resets indicator to "Page 1 of …".

### Review Findings (2026-05-08, multi-agent review of `spec-pwa-bank-list-pagination`)

- [x] [Review][Patch] `paginate` lets `NaN` page leak (`Math.max(1, NaN) = NaN`) — guard with `Number.isFinite(page)` and floor before clamping [pwa/src/app/backoffice/banks/_lib/paginate.ts:18-20]
- [x] [Review][Patch] Non-finite / zero / negative `pageSize` unguarded (`pageSize=0` yields `totalPages=Infinity`) — clamp to `Math.max(1, Math.floor(pageSize)) || 1` [pwa/src/app/backoffice/banks/_lib/paginate.ts:18]
- [x] [Review][Patch] Prev / Next buttons had `data-testid` but no BEM element classes; only the indicator did — add `banks-pagination__prev` / `__next` [pwa/src/app/backoffice/banks/_components/BanksPagination.tsx:21,46]
- [x] [Review][Patch] Partial-last-page unit test sliced rows but did not assert `totalRows` — added the assertion [pwa/tests/app/backoffice/banks/paginate.test.ts:31]
- [x] [Review][Patch] AC "page 4 → filter to 8 matches → block hides, 8 rows render" had no E2E at the exact stated state — added a focused spec [pwa/tests/e2e/backoffice/banks.spec.ts:369]
- [x] [Review][Patch] Misleading arithmetic comment in the "narrowing to >10" E2E — rewrote to reflect the actual `makeBanks` UTC-day walk [pwa/tests/e2e/backoffice/banks.spec.ts:362]
- [x] [Review][Defer] Focus is lost when the active Prev/Next becomes disabled at the last click — keyboard users have to tab back in [pwa/src/app/backoffice/banks/_components/BanksPagination.tsx] — deferred, a11y polish
- [x] [Review][Defer] `aria-live="polite"` indicator re-announces on every filter keystroke that changes `totalPages` — flooding screen readers; consider scoping the live region to user-driven page clicks [pwa/src/app/backoffice/banks/_components/BanksPagination.tsx:35] — deferred, a11y polish
- [x] [Review][Defer] Pagination buttons lack `aria-controls` pointing at the table region [pwa/src/app/backoffice/banks/_components/BanksPagination.tsx] — deferred, a11y polish

**Acceptance Criteria:**
- Given the API returns 50 rows, when the page renders, then exactly 10 rows are visible, "Page 1 of 5" appears, Prev is disabled, and Next is enabled.
- Given the user clicks Next from page 1, then a different set of 10 rows renders, the indicator reads "Page 2 of 5", and Prev becomes enabled.
- Given the user is on page 4 of 5 and types a filter narrowing to 8 rows, when the filter applies, then the pagination block disappears and the 8 matching rows render.
- Given the user is on page 3 and clicks any sortable column header, then the indicator reads "Page 1 of N" for the new page count.
- Given the API returns 5 rows total, the pagination block does not render at all.
- Given the API returns `meta.nextCursor`, the notice reads "More banks available. Filters, sort, and pagination apply only to this page."
- `make pwa.lint`, `make pwa.test.unit c='app/backoffice/banks/paginate.test.ts'`, `make pwa.test.e2e c='backoffice/banks.spec.ts'`, and `make pwa.build` all pass.

## Design Notes

**Why client-side:** the API returns up to 1000 rows per call (`MAX_LIMIT`); real bank catalogs fit in one fetch. Keeping the pipeline `loaded → filter → sort → paginate` makes filter/sort coherent — they cannot "see" hidden pages because there are no hidden pages until `nextCursor` fires, and that case already has a notice.

**Why no page-size selector:** scope discipline. Sizes / defaults / persistence are separate UX decisions and are explicitly Ask-First.

## Verification

**Commands:**
- `make pwa.lint` — 0 ESLint/Prettier errors.
- `make pwa.test.unit c='app/backoffice/banks/paginate.test.ts'` — green.
- `make pwa.test.e2e c='backoffice/banks.spec.ts'` — existing + new specs green.
- `make pwa.build` — succeeds.

**Manual checks:**
- `make dev` → load fixtures → confirm Prev / Next traverses pages, single-page lists hide the block, filter / sort reset to page 1.

## Suggested Review Order

**Pure logic — start here**

- Tiny pure helper; clamps `NaN` page and non-positive `pageSize`, returns `{ rows, totalPages, totalRows, page }`.
  [`paginate.ts:10`](../../pwa/src/app/backoffice/banks/_lib/paginate.ts#L10)

**Page composition**

- New `page` state; reset effect on `[filter, sort]`; derived `paged` slice.
  [`page.tsx:47`](../../pwa/src/app/backoffice/banks/page.tsx#L47)
- Pagination block rendered only when `paged.totalPages > 1`; `nextCursor` notice copy includes "and pagination".
  [`page.tsx:156`](../../pwa/src/app/backoffice/banks/page.tsx#L156)

**UI surface**

- Controlled component: Prev / Next + "Page X of N"; aria-labels, aria-live indicator, BEM element classes on every interactive child.
  [`BanksPagination.tsx:11`](../../pwa/src/app/backoffice/banks/_components/BanksPagination.tsx#L11)

**Tests & fixture**

- 14 unit cases cover the I/O matrix plus NaN/non-positive guards.
  [`paginate.test.ts:12`](../../pwa/tests/app/backoffice/banks/paginate.test.ts#L12)
- Pagination E2E describe — 50-bank navigation, partial last page via narrowing filter, page-reset on filter / sort, single-page hide, nextCursor notice copy.
  [`banks.spec.ts:288`](../../pwa/tests/e2e/backoffice/banks.spec.ts#L288)
- New `makeBanks(count)` fixture helper — deterministic ids, names, and createdAt walking forward one UTC day.
  [`banks-api.ts:43`](../../pwa/tests/e2e/fixtures/banks-api.ts#L43)
