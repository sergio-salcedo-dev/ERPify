# Code Review — Pagination Infrastructure (2026-04-28)

**Source:** Uncommitted changes on `main` (`git diff HEAD`)
**Mode:** no-spec
**Scope:** 13 files, +789/-24 — new pagination infrastructure under `api/src/Shared/Infrastructure/Persistence/` (Paginator, PaginatorCursor, PaginatorCursorFactory, AbstractSearchRepository, AbstractRepository, PaginationMode, QueryBuilderWithOptions) plus the Bank module wired to use it.
**Review layers:** Blind Hunter, Edge Case Hunter (Acceptance Auditor skipped — no spec).

Status legend: `[ ]` open, `[x]` done, `[~]` deferred.

---

## Patch — HIGH

- [ ] **H1. Count query ignores cursor `andWhere` → wrong totals beyond page 1** — `Paginator.php:88-97`
  `setCursorCount` resets only `orderBy`. The cursor's `andWhere` predicate and bound parameters remain, so the count reflects "rows after the cursor", not the dataset. `pageCount` and `count` are wrong on every cursor-paginated request after page 1.
  *Fix:* clone the QB, reset `where` (or strip just the cursor predicate by tagged param), and clear cursor params before counting.

- [ ] **H2. `hasMorePages` always `false` in `DETAILED` mode** — `Paginator.php:67-79, 127-134`
  The `+1 row` lookahead is only added in `LIGHT` mode. In `DETAILED` mode `$noOfResults > getMaxResults()` is unreachable.
  *Fix:* either also `+1` in detailed mode and trim before returning, or derive `hasMorePages` from `currentPage < pageCount` when count is available.

- [ ] **H3. Cursor never returned to the client** — `BanksGetController.php:33-42`
  Response only emits `items`, `count`, `pageCount`, `currentPage`, `hasMorePages`. `Paginator::getCursor()` is built but never serialized; clients cannot use cursor pagination even though `?cursor=` is read on input.
  *Fix:* add `cursor` (and probably `nextCursor`/`prevCursor`) to the response payload.

- [ ] **H4. Invalid `paginationMode` silently downgrades to DETAILED** — `BanksGetController.php:23-26`
  `PaginationMode::tryFrom('bogus')` → `null` → treated as default. Also `?paginationMode[]=x` raises an uncaught `BadRequestException`.
  *Fix:* validate explicitly; return 400 on unknown values; coerce to scalar before `tryFrom`.

- [ ] **H5. Unbounded `page` parameter — OFFSET DoS** — `BanksGetController.php:26`
  `\max(1, getInt('page'))` clamps below but not above. `?page=999999999` → huge `setFirstResult` → table scan.
  *Fix:* clamp `page` to `[1, getPageCount()]`; return 404/empty page or last page beyond bounds.

- [ ] **H6. DateTime cursor values formatted as ATOM strings, bound as DQL datetime params** — `Paginator.php:316-322`
  `DateTimeInterface::ATOM` produces an offset string (`+00:00`); bound against a `timestamptz` column it relies on lexicographic luck and breaks across timezones. The only Bank order-by (`b.createdAt`) is affected.
  *Fix:* keep DateTime objects (Doctrine binds them via the datetime type), or normalize to UTC ISO microseconds and bind with the `datetime` type explicitly.

- [ ] **H7. Order-by regex matches empty `clause` for direction-less columns** — `Paginator.php:280`
  `^(?P<clause>.*?)( ?(?P<dir>asc|desc))?$` is fully lazy with optional suffix. Input `b.createdAt` (no direction) yields `clause=""`, `dir=""`.
  *Fix:* anchor properly, e.g. `^(?P<clause>.+?)(?:\s+(?P<dir>asc|desc))?$` (case-insensitive), and default `dir` to `asc`.

- [ ] **H8. `ENABLE_CURSOR_PAGINATION=false` in `PostgresBankRepository::getSearchQueryBuilder`** — `PostgresBankRepository.php:53-57`
  Disables cursor pagination for Bank. With this flag off, `getOrderByColumns()` returns `[]`, `firstItem`/`lastItem` are always empty — cursors carry no positional data. Combined with H3, cursor pagination is fully non-functional for Banks.
  *Fix:* either flip the default to `true` for the Bank repository or, if the option is intentional, document why and remove the cursor surface.

- [ ] **H9. Cursor unsigned + zip-bomb vector** — `PaginatorCursorFactory.php:17-37`
  `gzuncompress($decoded)` with no `$max_length` cap allows memory-bomb input. Decoded JSON is accepted with arbitrary client-supplied `firstItem`/`lastItem` flowing into WHERE clauses — clients can forge values to skip rows or probe data.
  *Fix:* cap `gzuncompress($decoded, MAX_DECOMPRESSED_BYTES)`; sign payload with HMAC keyed by `APP_SECRET`; reject mismatched signatures with 400.

## Patch — MEDIUM

- [ ] **M1. `getPageCount()` zero-mode + division-by-zero** — `Paginator.php:117`
  `LIGHT` mode never sets count → `(int)null = 0` → `getPageCount` always 0 even with rows. `\ceil($count / $maxPerPage)` throws if `maxPerPage=0`.
  *Fix:* return `null` or `-1` for unknown count in light mode; guard `maxPerPage > 0`.

- [ ] **M2. `isSingleFirstPageQuery` overrides count incorrectly when filters/cursor narrow set** — `Paginator.php:96-99`
  Heuristic uses `count(results)` as total when `currentPage=1` and `count(results) < maxPerPage`. Wrong as soon as a cursor or filter narrows the set.
  *Fix:* drop the heuristic; rely on a correct count query.

- [ ] **M3. Empty result resets `firstItem`/`lastItem`** — `Paginator.php:73-83, 96`
  Empty page → `extractFields(..., null)` → `[]` — wipes cursor positional data; subsequent forward navigation lands back on page 1.
  *Fix:* preserve incoming cursor's `firstItem`/`lastItem` when no rows iterated.

- [ ] **M4. `#[Required]` setter injection on abstract repo** — `AbstractSearchRepository.php:18-22`
  Subclasses constructed outside DI (tests, fixtures) get an uninitialized `$paginatorCursorFactory`; failure is opaque.
  *Fix:* constructor-inject via `__construct` and pass `parent::__construct(...)` from concretes.

- [ ] **M5. `(int) $page` cast accepts non-numeric strings as `0`** — `AbstractSearchRepository.php:32-38`
  `assert()` is a no-op in production; "abc"→0, "3.9"→3.
  *Fix:* validate with `is_numeric` / `filter_var(..., FILTER_VALIDATE_INT)`; throw `InvalidArgumentException` on bad input.

- [ ] **M6. Parameter-name collision when alias contains underscores** — `Paginator.php:184`
  `':pagination_' . str_replace('.', '_', $orderBy)` → `a.foo_bar` and `a_foo.bar` collide on `:pagination_a_foo_bar`.
  *Fix:* use a deterministic hash or a counter for parameter naming.

- [ ] **M7. Composite-key path disables fetch-join walker** — `AbstractSearchRepository.php:65-70`
  `fetchJoinCollection=false` plus `LEFT JOIN ... addSelect(collection)` produces cartesian rows, inflating `DoctrinePaginator::count()`.
  *Fix:* keep `fetchJoinCollection=true` whenever the QB has collection joins; gate the override on absence of collection selects.

- [ ] **M8. Double serialize → json_decode → JsonResponse round-trip** — `BanksGetController.php:34-37`
  Wastes CPU; can emit `items: null` if `json_decode` silently fails; risks numeric precision loss.
  *Fix:* `serializer->normalize($items, ...)` and pass arrays to `JsonResponse`, or `JsonResponse::fromJsonString($serializer->serialize(...))`.

- [ ] **M9. `reference.php`: translator `enabled` doc default flipped `true → false`** — `api/config/reference.php:302`
  Doc-only edit but doesn't reflect Symfony's real default. Likely accidental.
  *Fix:* revert.

## Patch — LOW

- [ ] **L1. `AbstractRepository` LSP drift on `createQueryBuilder` return type** — `AbstractRepository.php:21-26`
  Narrows return to `QueryBuilderWithOptions`; will trip PHPStan / consumers expecting plain `QueryBuilder`.
  *Fix:* either restore covariant `QueryBuilder` return and document the concrete type, or accept the drift and add a `@phpstan-return` annotation.

- [ ] **L2. `Paginator` not `final` despite private state** — `Paginator.php:21`
  Subclassing risks breaking invariants (cached `$iterator`, set-once `$hasMorePages`).
  *Fix:* mark `final`.

- [ ] **L3. `createFromString` swallows malformed cursor → empty cursor** — `PaginatorCursorFactory.php:18-37`
  Caller can't distinguish "no cursor" from "bad cursor"; user lands on page 1 silently.
  *Fix:* throw on parse failure; controller maps to 400.

## Defer (1)

- [ ] **D1. Order-by source becoming user-controlled → SQLi via `sprintf` of raw `$orderBy`** — `Paginator.php:184`
  Currently developer-controlled DQL strings; not exploitable today, but trivially becomes so the moment a `?sort=` query param is mapped to order-by. Add an allow-list whitelisting valid column identifiers when that feature lands.

## Dismissed (informational)

- "PHP 8.5 typed-const requires 8.3+" — project runs PHP 8.5.
- "`AbstractRepository` re-overrides `getEntityManager`/`getClassMetadata`" — stylistic, not a defect.
- Various low-confidence regex/iteration musings the hunters retracted in-line.

---

## Resume instructions

To continue from where we left off:

1. Re-open this file and pick the next unchecked `[ ]` item.
2. The original review was over `git diff HEAD` against the working tree at the moment listed above; if the diff has shifted, re-baseline before applying.
3. After each fix, run:
   - `make php.stan` (mandatory per `CLAUDE.md`)
   - `make ci.php.lint` once the batch is done
4. Tests: add unit coverage for `Paginator` and `PaginatorCursorFactory` boundary cases (empty page, exact-multiple page size, malformed cursor, oversized cursor, cursor with timezone-divergent datetime).
