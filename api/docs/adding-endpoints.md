# Adding endpoints

Patterns for adding HTTP endpoints to the API. Each section covers one endpoint shape; more will be added as new boundaries are standardized (create / update / delete / detail / etc.).

## Route naming convention

Route names follow `<office>_<entity>_<action>` — **entity-first, then an intent verb, never the HTTP method**. The HTTP method is already declared in `methods: [...]`, so repeating it in the name (`_post`, `_put`) is redundant and less expressive; the intent verb instead mirrors the application-layer use-case vocabulary (`BankCreator` / `CreateBankCommand`, `BankUpdater` / `UpdateBankCommand`). Entity-first keeps every route for an entity clustered together in `debug:router`, logs, and traces.

| Operation                 | HTTP                           | Route name suffix |
|---------------------------|--------------------------------|-------------------|
| Paginated collection read | `GET /<entities>`              | `_search`         |
| Single-resource read      | `GET /<entities>/{id}`         | `_get`            |
| Create                    | `POST /<entities>`             | `_create`         |
| Update                    | `PUT`/`PATCH /<entities>/{id}` | `_update`         |
| Delete                    | `DELETE /<entities>/{id}`      | `_delete`         |

`Bank` is the canonical reference: `backoffice_bank_search` / `_get` / `_create` / `_update` / `_delete`. Route names are server-internal identifiers (clients call by path, not by name), so renaming one has zero client blast radius — but keep the suffix vocabulary stable, because operators key off suffixes like `_search` for logs / metrics / tracing.

## Behat feature file layout

`api/features/` mirrors the `src/` bounded-context + module tree, lowercased: `src/Backoffice/BankAccount/` → `features/backoffice/bank_account/`. Path segments and `.feature` filenames are **snake_case** — multi-word module names, groupings, and file names join words with `_` (`bank_account/`, `error_contract/`, `rate_limiting/`, `access_control.feature`, `dispatch_event.feature`), never solid-concatenated. A bounded context that is already a single (compound) token stays solid: `backoffice/`, `frontoffice/`, `shared/`. The suite registers only the three context roots (`features/backoffice`, `features/frontoffice`, `features/shared`) and recurses, so subdirectory names follow this convention freely without touching `tools/behat/behat.yml.dist`.

## Search endpoints

The search-endpoint boundary is centralized — the shared `SearchQuery` DTO + the shared `SearchCriteria` + one ~12-line controller per entity. The original design (`adr-2026-04-29-search-controller-boundary.md`, recoverable from git history) used per-entity DTO/criteria subclasses; those were retired in favour of the generic `filters[]` contract. The architectural pattern and the "add a filterable list" recipe now live in [`../../docs/architecture-api.md`](../../docs/architecture-api.md#filterable-search-generic-filters-contract); `Bank` is the canonical pilot.

### Skeleton

Replace `Bank`/`bank` with the new entity:

1. **Domain search repository** — `<Entity>SearchRepository::search(SearchCriteria $criteria): Page<<Entity>>`, a read-side port separate from the aggregate-lifecycle `<Entity>Repository` (ISP — a future search provider implements only this one). The port returns the Domain `Page` (opaque cursors), never a wire DTO.
2. **Application searcher** — `<Entity>Searcher` is a thin wrapper receiving the base `SearchCriteria` (the controller calls `$query->toCriteria()`) and forwarding to the repo; it returns the `Page` straight through.
3. **Concrete repository by composition** — implement the port with an injected `EntityManagerInterface` + `DoctrineSearchEngine` (composition, no ORM base-class inheritance). `search()` builds the **base query builder only** (`SELECT`/`FROM`, plus any joins) and delegates to `$this->searchEngine->paginate($qb, $criteria, $this->searchFieldMap(), $this->sortFieldMap(), new PaginatorConfig($criteria->paginationMode), WirePaginationPolicy::wire(), $routingDirection)`. The engine owns ordering, the limit clamp, the keyset predicate, the +1 trick and cursor encoding — the repo never touches the applier, the codec or the predicate builder. `DoctrineBankRepository` is the canonical pilot.
4. **Field maps** — the concrete repository defines `searchFieldMap(): SearchFieldMap` (the filter allow-list) and `sortFieldMap(): SortFieldMap` (the sort allow-list) and passes both into the engine. Return `new SearchFieldMap([])` / `new SortFieldMap([])` to expose nothing. Mark UUID columns with `requiresUuidValues: true` (restrict `operators` to eq/in — the `FieldMapping` constructor rejects `contains` over a UUID) so a malformed value is a 422 `invalid-search-value`, not a Postgres error. Mark timestamp columns with `requiresDateTimeValues: true` and list only the range operators (`gt`/`gte`/`lt`/`lte`): bounds parse as RFC 3339 datetimes, normalize to UTC, bind as a typed parameter. Filtering and sorting are independent allow-lists; keep only index-backed columns sortable (NFR4) and map a public name to an indexed expression when needed (e.g. `name` → `b.nameNormalized`). A `sort` outside the map is a 422 `unknown-sort-field`, never interpolated into DQL raw.
5. **Controller** — a `final readonly` controller (search endpoints no longer extend a base) mapping the shared base DTO directly and handing the engine's `Page` to the shared `SearchResponder`, which composes the cursor-only envelope. Filtering is expressed exclusively through the generic `filters[]` grammar:

```php
#[Route('/<entities>', name: self::ROUTE_NAME, methods: ['GET'])]
final readonly class <Entity>SearchController
{
    public const string ROUTE_NAME = '<office>_<entity>_search';

    public function __construct(
        private <Entity>Searcher $searcher,
        private SearchResponder $searchResponder,
    ) {
    }

    public function __invoke(#[MapQueryString] SearchQuery $query = new SearchQuery()): Response
    {
        return $this->searchResponder->respond(
            $this->searcher->search($query->toCriteria()),   // Page<<Entity>>
            $query,                                           // the validated DTO — the responder rebuilds links from it (W2)
            self::ROUTE_NAME,                                 // for relative-link generation
            ['identifiable', 'timestamped', '<entity>:search'],
        );
    }
}
```

The `SearchResponder` is the **single** place an opaque cursor is materialized into the relative `links.next`/`links.prev`; the engine and the `Page` stay link-agnostic (W9). See "Cursor-only navigation wire" below.

### Generic `filters[]` wire contract

Every search endpoint inherits the generic filter grammar from the `SearchQuery` base — no per-entity code is needed beyond the repository's `searchFieldMap()`:

```text
GET /api/v1/backoffice/banks?filters[0][field]=name&filters[0][operator]=contains&filters[0][value]=banc
GET /api/v1/backoffice/banks?filters[0][field]=name&filters[0][operator]=in
    &filters[0][value][]=BBVA&filters[0][value][]=CaixaBank
GET /api/v1/backoffice/banks?filters[0][field]=createdAt&filters[0][operator]=gte
    &filters[0][value]=2026-01-01T00:00:00%2B00:00
```

- **Grammar** — `filters[N][field]`, `filters[N][operator]`, `filters[N][value]` (scalar for `eq`/`contains` and the range operators `gt`/`gte`/`lt`/`lte`) or `filters[N][value][]` (list for `in`). Indexes must be contiguous from 0; any other shape is a 422 `validation-failed`.
- **Operators** — `eq` · `in` · `contains` · `gt` · `gte` · `lt` · `lte`, strictly lowercase (the enum backing string IS the wire contract). Range operators are **allow-listed per field**: on `banks` only `createdAt` / `updatedAt` accept them, so e.g. `filters[…][field]=name&…[operator]=gt` is a 422 `unsupported-search-operator`.
- **Datetime range values** — bounds for a range filter over a timestamp field are an RFC 3339 / ISO-8601 datetime in the offset form `2026-01-01T00:00:00+00:00` (the `+` is URL-encoded as `%2B`) or the `Z` form, with optional fractional seconds — so the JS `Date.prototype.toISOString()` output `2026-01-01T00:00:00.000Z` is accepted as-is. Bounds resolve at **second precision** (the timestamp columns are `TIMESTAMP(0)`): a sub-second component is accepted but truncated to the whole second, and a value with more than 6 fractional digits is rejected. Lax/relative or partial forms (`now`, `tomorrow`, `2026-01-01`) and out-of-range UTC offsets (beyond UTC+14/-12) are rejected as 422 `invalid-search-value`. A closed range is two filters on the same field — `gte` + `lte` — which compose with AND; there is no `between` operator.
- **Caps** — `SearchQuery::MAX_FILTERS = 20` filters per request, `FilterQuery::MAX_IN_VALUES = 100` values per `in` filter, 255 chars per value (the searchable columns' VARCHAR bound). All validated at mapping time.
- **Effective limit** — the wire bounds requests before the caps do: the real ceiling is `min(caps, max_input_vars, URL length)`. The PHP container runs the default `max_input_vars = 1000` (each `filters[...]` pair counts as one input var) and typical URL limits (~8 KB) bite even earlier for long values.
- **Error layers** — both layers return **422**: shape problems (unknown operator token, value/operator mismatch such as a list value for a range operator, caps, indexes) fail in mapping as `validation-failed` with `violations[]`; semantic problems (field outside the repository's allow-list → `unknown-search-field`, operator not allowed for the field → `unsupported-search-operator`, value not matching the field's required format such as a malformed UUID or datetime → `invalid-search-value`) fail in the filter applier as the `invalid-search-criteria` family. Never validate filters in controllers or use cases.
- **No filters** — an absent or empty `filters` produces no filtering (it is not an error). Several filters on the same field compose with AND.

### Ordering (`sort` / `direction`)

The shared `SearchQuery` also carries server-side ordering — no per-entity code beyond the repository's `sortFieldMap()`:

```text
GET /api/v1/backoffice/banks?sort=name&direction=DESC
```

- **Params** — `sort` is the PUBLIC field name; `direction` is the `SortDirection` enum with wire tokens `ASC` / `DESC` (UPPER-CASE — the enum backing string IS the contract, deliberately distinct from the lowercase filter operators). Both are optional; `sort` is capped at 64 chars as a shape guard.
- **Allow-list** — `sort` is resolved against the repository's `sortFieldMap()` (public name → DQL path) and is NEVER interpolated into DQL raw. On `banks`: `name` (→ the indexed, accent-folded `nameNormalized`, so ordering is case/diacritic-insensitive — the same order the list shows), `shortName`, `createdAt`, `updatedAt`. `id` and other columns are deliberately not sortable.
- **Default** — without `sort` the order is `createdAt` ASC, with `id` as the keyset tiebreak (unchanged — fully backward compatible). A `direction` sent **without** a `sort` applies to that default field (`direction=DESC` ⇒ `createdAt` DESC) — `direction` is never ignored. An empty `sort=` is treated as "no sort" (normalized to null), so it falls back to the default order rather than failing. This differs from the PWA's client-side default (`name` ASC); a server-driven list must send `sort=name&direction=ASC` explicitly.
- **Error layers** — both are **422**: a `direction` outside the enum (or array form) is a `validation-failed` at mapping (exactly like an unknown `paginationMode`); a `sort` outside the allow-list is an `unknown-sort-field` (the `invalid-search-criteria` family), raised before any SQL runs.
- **Indexes (NFR4)** — only index-backed columns are exposed as sortable, so ordering never degrades to a filesort. The cursor's fingerprint covers the sort/filter shape, so changing `sort`/`direction`/`filters`/`limit` invalidates an existing cursor — a stale cursor against a changed query is a 422 `invalid-cursor` (fingerprint cause), never a silent offset fallback; the client discards its cursors on any query change (W8) and re-requests the first page. A non-unique sort key needs a composite `(column, id)` index for total order; a unique column's single-column index already suffices — pinned by `SortFieldMapIndexContractTest`.
- **Keyset engine (on the wire)** — `DoctrineSearchEngine` is the sole read-path query-shaper each search repository delegates to: the repo supplies only its base query builder (`SELECT`/`FROM`/joins) + `searchFieldMap()`/`sortFieldMap()`, and the engine owns sort resolution, filtering, the keyset predicate, the +1 trick, and cursor encoding. It governs the live HTTP read-path (Bank).

### Cursor-only navigation wire

Pagination is **cursor-only** — there are no page numbers on the wire. A client navigates with `limit` plus exactly one opaque cursor, and reuses the server-supplied `links` verbatim.

```text
GET /api/v1/backoffice/banks?limit=25                          # first page (no cursor)
GET /api/v1/backoffice/banks?limit=25&sort=name&after=<cursor> # next page — value of links.next, verbatim
GET /api/v1/backoffice/banks?limit=25&sort=name&before=<cursor># previous page — value of links.prev, verbatim
```

- **Params** — `after` / `before` carry the OPAQUE keyset cursor (base64url + HMAC-32 + fingerprint). They are **mutually exclusive** — both present is a 422 `validation-failed` at mapping (AR1) — and the one that arrived is the **sole** navigation authority (AR21); there is no `page`. An omitted `limit` defaults to **25** — resolved by the engine from the active policy's `WirePaginationPolicy::$defaultLimit` (the wire surface advertises `SearchCriteria::DEFAULT_LIMIT`), not baked into the criteria — and a supplied one is capped at **100** (`SearchCriteria::MAX_LIMIT`); out of `[1, 100]` is a 422 `validation-failed`.
- **Envelope** — a constant shape alongside `data`: `pagination: {hasNext, hasPrev, count, links: {next, prev}}`. `links.next`/`links.prev` are **always present** (`null` when the affordance does not apply); `count` is `null` in LIGHT (`paginationMode=light`, no COUNT) and populated in DETAILED. Nulls are emitted explicitly — `skip_null_values` is forbidden (AR20).
- **Links** — relative, same-endpoint URLs built by `SearchResponder` from the validated DTO, preserving `limit`/`sort`/`direction`/`filters[]`/`paginationMode` and substituting only the cursor. The client treats them as opaque: forward via `links.next`, back via `links.prev`, never decoding or rebuilding a cursor.
- **Invalid cursor** — a tampered/stale/expired cursor (signature, version, payload or fingerprint) is a 422 `invalid-cursor`, indistinguishable across causes, through the RFC 9457 pipeline — never a silent fall-back to page 1. A cursor `dir` that contradicts the wire param is the same 422 (integrity binding).
- **Empty page / end of data** — navigating into a deleted-row gap or past the end is **200 `items: []`**, never an error. An empty `before` walk reports `hasNext=true, hasPrev=false` (the page you came from is recoverable via `links.next`); an empty `after` walk reports the mirror. The engine mints a recovery cursor so the live affordance always has a usable link (Linkability, W10).
- **Consistency (FR14)** — keyset gives **no cross-page snapshot**: rows inserted/deleted/re-keyed between requests can shift later pages. It **does** guarantee no duplicate and no skipped rows *caused by the paging mechanism itself* (unlike OFFSET) and unique ids within any single page (the `id` tie-break). Surface this in client UX where it matters.

### Conventions that bite

- The route name **must** end with `_search`. The `_search` suffix is the project-wide convention for paginated read endpoints; downstream operators (logs, metrics, tracing) key off it.
- `ValidationFailedException` from the DTO is automatically mapped to a **422** `validation-failed` RFC 9457 Problem Details body (Symfony's `RequestPayloadValueResolver` wraps it in an `HttpException(422)`; `Erpify\Shared\ErrorContract\Application\ProblemDetailsFactory` walks `getPrevious()` and re-emits it as 422 with the structured `violations` extension in place of Symfony's generic body). See [`../../docs/api-error-contract.md`](../../docs/api-error-contract.md).
- The page-size **ceiling** lives on `SearchCriteria::MAX_LIMIT` (100) — the domain single source of truth that `SearchQuery`'s `#[Assert]` reads and `WirePaginationPolicy` mirrors. The **default** page size is a policy choice, not a domain invariant: an omitted `limit` is carried as `null` and the engine resolves it from the active `WirePaginationPolicy::$defaultLimit` (an adapter picks its default by handing the engine a different policy). `SearchCriteria::DEFAULT_LIMIT` (25) is the wire-surface value the HTTP DTO and links advertise. There is no `MAX_PAGE`: cursor-only navigation has no page number, so the only pagination invariant `SearchCriteria` enforces is an explicit `limit ∈ [1, MAX_LIMIT]` (→ `invalid-pagination` for non-HTTP adapters; the DTO rejects it earlier as `validation-failed`).
- `SearchQuery` and `SearchCriteria` are final on purpose: per-entity typed filter params (the old `names[]`/`ids[]`) were retired before any production deployment. Expose new filterable fields through the repository's `searchFieldMap()` — never through new wire params or subclasses.

### Nested (child-resource) search endpoints

A keyset endpoint under a parent path (e.g. `GET /banks/{id}/accounts`) follows the same skeleton with two additions:

- **Scope the base query, not a filter.** The parent id is a fixed route constraint, so the repository's `search(string $parentId, SearchCriteria $criteria)` adds `WHERE child.parentId = :parentId` to the base query builder before handing it to the engine — it is not a client-tunable `filters[]` entry.
- **Pass the route params to the responder.** `SearchResponder::respond(..., array $routeParams)` merges them into every `links.next`/`links.prev` so the cursor links stay valid for a parameterised route (`['id' => $id]`). Flat routes omit the argument.
- **Guard the parent's existence first.** Reuse the published `BankExistenceChecker::ensureExists($id)` (it validates the UUID shape → `400 invalid-uuid` before any query, then existence → `404`) rather than re-implementing a `find`-then-search pre-read per endpoint.

### IBAN wire contract

The IBAN is always serialized **canonical**: upper-case, no spaces, no separators (`ES9121000418450200051332`) — the form it is persisted in. Masking is presentational on the client only; the backend never masks, and the value (classified PII) is **never logged**. A future `iban` filter must canonicalize the input the same way before matching.
