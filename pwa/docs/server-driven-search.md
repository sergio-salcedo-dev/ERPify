# Server-driven filtered search (PWA)

Filterable lists resolve filtering, sorting, and pagination on the **server**, through the shared
`Filter` vocabulary and the `buildSearchParams` serializer. This mirrors the API's generic
`filters[N][field|operator|value]` contract. The banks list is the reference implementation.

## Building blocks

| Piece                        | Location                                | Role                                                                                                                                                                                                                                          |
| ---------------------------- | --------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `Filter` / `FilterOperator`  | `context/shared/domain/Search/`         | Typed, framework-free filter vocabulary. `FilterOperator` is a union + const (`eq \| in \| contains \| gte \| lte`) — never a TS `enum`. `Filter` is a discriminated union: scalar ops carry `value: string`, `in` carries `value: string[]`. |
| `buildSearchParams(filters)` | `context/shared/infrastructure/Search/` | Serializes `Filter[]` → `URLSearchParams` in the exact wire grammar. Scalar → `filters[N][value]`; `in` → repeated `filters[N][value][]`. Empty list → no params. Composable with sort/pagination params.                                     |

## Recipe: make a list filterable

1. **Map the UI filter/sort state to the vocabulary.** Add a `toXFilters(uiFilter): Filter[]` helper that
   emits only active filters (trim, drop empties). For a date range, convert the `yyyy-mm-dd` picker value to
   inclusive ISO bounds via `dateTimeProvider` (`formatToISO(startOfDay(parseISO(v)))` for `gte`,
   `endOfDay` for `lte`). Map the UI sort to `{ field, direction }`.

2. **Give the repository a `search(criteria)`.** Criteria carries `{ filters, sort, page, cursor?, limit }`.
   In the API adapter, `buildSearchParams(filters)` then append `sort` + `direction`
   (`direction.toUpperCase()` — the API enum is `ASC`/`DESC`), `page`, `cursor` (only when navigating
   beyond page 1), and `limit`. Send **no** `paginationMode` for a prev/next cursor list — the API defaults to
   `LIGHT` (no `COUNT(*)`; `hasMorePages` from a single fetch). Return
   `{ banks, cursor, currentPage, hasMorePages }` from the `{ data, pagination }` envelope. Only a view that
   renders a real total appends `paginationMode=detailed` and surfaces `pagination.count` as `totalCount`.

3. **Wire the page.** The list state holds `filter`, `sort`, `pageSize`, `page`, and the last response's
   `cursor` (in a ref). Refetch whenever `filter`/`sort`/`page`/`pageSize` change. Use a monotonic request
   token so a slow response can never overwrite a newer one (the debounce↔pagination race). Drive `hasNext`
   from `hasMorePages` and `hasPrev` from `currentPage > 1` (no total under `LIGHT`).

## Load-bearing rules

- **Discard the cursor on any query change.** A change to a filter, the sort, or the page size resets to
  page 1 — which sends no cursor — so the stale cursor is dropped by construction. Only sequential prev/next
  replays the last cursor. The cursor is opaque: never interpret or fabricate it client-side.
- **Reconcile realtime by refetching.** A Mercure `created`/`updated`/`deleted` cannot be placed on the
  current page client-side (the filter/sort/keyset live on the server), so each event triggers a coalesced
  silent refetch of the current page. Suppress the refetch while an optimistic bulk delete owns the page.
- **No client-side filtering/sorting.** Once a list is server-driven, delete its in-memory `applyFilters` /
  `applySort` / `paginate` — the server is the single source of truth.

## Testing

- Unit-test the criteria mapper (operators, range bounds, empties) and the repository adapter (param
  serialization incl. the `direction` uppercasing, envelope → page mapping). See
  `tests/app/backoffice/banks/banksSearchCriteria.test.ts` and
  `tests/context/backoffice/bank/infrastructure/ApiBankRepository.test.ts`.
- For the list page, mock the repository's `search` to return a page shape; assert the criteria sent on
  filter/sort/page changes, not in-memory filtering.
- In E2E, the mocked fixture must emulate the server (apply the wire filters/sort, slice by page/limit) —
  see `tests/e2e/fixtures/banks-api.ts`. Wait for the active-filter badge before paginating.
