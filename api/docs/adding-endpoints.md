# Adding endpoints

Patterns for adding HTTP endpoints to the API. Each section covers one endpoint shape; more will be added as new boundaries are standardized (create / update / delete / detail / etc.).

## Search endpoints

The search-endpoint boundary is centralized — the shared `SearchQuery` DTO + the shared `SearchCriteria` + one ~12-line controller per entity. The original design (`adr-2026-04-29-search-controller-boundary.md`, recoverable from git history) used per-entity DTO/criteria subclasses; those were retired in favour of the generic `filters[]` contract. The architectural pattern and the "add a filterable list" recipe now live in [`../../docs/architecture-api.md`](../../docs/architecture-api.md#filterable-search-generic-filters-contract); `Bank` is the canonical pilot.

### Skeleton

Replace `Bank`/`bank` with the new entity:

1. **Domain search repository** — `<Entity>SearchRepository::search(SearchCriteria $criteria): PaginatedResult<<Entity>>`, a read-side port separate from the aggregate-lifecycle `<Entity>Repository` (ISP — a future search provider implements only this one).
2. **Application searcher** — `<Entity>Searcher` is a thin wrapper receiving the base `SearchCriteria` (the controller calls `$query->toCriteria()`) and forwarding to the repo.
3. **Search field map** — every search repository extends `AbstractDoctrineSearchRepository` and MUST implement `searchFieldMap(): SearchFieldMap` (abstract): the mandatory allow-list of publicly filterable fields for the generic `filters[]` contract (see below). Return `new SearchFieldMap([])` to expose no filterable field. Mark mappings over UUID columns with `requiresUuidValues: true` so malformed values surface as a 400 instead of a Postgres error; it is incompatible with the `contains` operator (a partial value can never be a valid UUID), so restrict `operators` to eq/in — the `FieldMapping` constructor rejects the combination.
4. **Controller** — extend `Erpify\Shared\Infrastructure\Http\Controller\AbstractSearchController` and map the shared base DTO directly — search endpoints do not subclass it; filtering is expressed exclusively through the generic `filters[]` grammar:

```php
#[Route('/<entities>', name: '<office>_<entity>_search', methods: ['GET'])]
final class <Entity>SearchController extends AbstractSearchController
{
    public function __construct(
        private readonly <Entity>Searcher $searcher,
        NormalizerInterface $normalizer,
        ResponderInterface $responder,
        PaginatorCursorFactory $paginatorCursorFactory,
    ) {
        parent::__construct($normalizer, $responder, $paginatorCursorFactory);
    }

    public function __invoke(#[MapQueryString] SearchQuery $query = new SearchQuery()): Response
    {
        return $this->buildResponse($this->searcher->search($query->toCriteria()), ['identifiable', 'timestamped', '<entity>:search']);
    }
}
```

### Generic `filters[]` wire contract

Every search endpoint inherits the generic filter grammar from the `SearchQuery` base — no per-entity code is needed beyond the repository's `searchFieldMap()`:

```text
GET /api/v1/backoffice/banks?filters[0][field]=name&filters[0][operator]=contains&filters[0][value]=banc
GET /api/v1/backoffice/banks?filters[0][field]=name&filters[0][operator]=in
    &filters[0][value][]=BBVA&filters[0][value][]=CaixaBank
```

- **Grammar** — `filters[N][field]`, `filters[N][operator]`, `filters[N][value]` (scalar for `eq`/`contains`) or `filters[N][value][]` (list for `in`). Indexes must be contiguous from 0; any other shape is a 400 `validation-failed`.
- **Operators** — `eq` · `in` · `contains`, strictly lowercase (the enum backing string IS the wire contract).
- **Caps** — `SearchQuery::MAX_FILTERS = 20` filters per request, `FilterQuery::MAX_IN_VALUES = 100` values per `in` filter, 255 chars per value (the searchable columns' VARCHAR bound). All validated at mapping time.
- **Effective limit** — the wire bounds requests before the caps do: the real ceiling is `min(caps, max_input_vars, URL length)`. The PHP container runs the default `max_input_vars = 1000` (each `filters[...]` pair counts as one input var) and typical URL limits (~8 KB) bite even earlier for long values.
- **Error layers** — shape problems (unknown operator token, value/operator mismatch, caps, indexes) fail in mapping as 400 `validation-failed` with `violations[]`; semantic problems (field outside the repository's allow-list → `unknown-search-field`, operator not allowed for the field → `unsupported-search-operator`, value not matching the field's required format → `invalid-search-value`) fail in the filter applier as 400s from the `invalid-search-criteria` family. Never validate filters in controllers or use cases.
- **No filters** — an absent or empty `filters` produces no filtering (it is not an error). Several filters on the same field compose with AND.

### Conventions that bite

- The route name **must** end with `_search`. The `_search` suffix is the project-wide convention for paginated read endpoints; downstream operators (logs, metrics, tracing) key off it.
- `ValidationFailedException` from the DTO is automatically mapped to a 400 `validation-failed` RFC 9457 Problem Details body (Symfony's `RequestPayloadValueResolver` wraps it in an `HttpException(422)`; `Erpify\Shared\Application\Problem\ProblemDetailsFactory` walks `getPrevious()` and re-maps it to 400 with the structured `violations` extension). See [`../../docs/api-error-contract.md`](../../docs/api-error-contract.md).
- Pagination caps live on `SearchQuery::MAX_PAGE` / `SearchQuery::MAX_LIMIT` and apply to every search endpoint.
- `SearchQuery` and `SearchCriteria` are final on purpose: per-entity typed filter params (the old `names[]`/`ids[]`) were retired before any production deployment. Expose new filterable fields through the repository's `searchFieldMap()` — never through new wire params or subclasses.
