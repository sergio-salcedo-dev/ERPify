# Adding endpoints

Patterns for adding HTTP endpoints to the API. Each section covers one endpoint shape; more will be added as new boundaries are standardized (create / update / delete / detail / etc.).

## Search endpoints

The search-endpoint boundary is centralized — one Application DTO + one Domain criteria + one ~12-line controller per entity. See [`../../_bmad-output/planning-artifacts/adr-2026-04-29-search-controller-boundary.md`](../../_bmad-output/planning-artifacts/adr-2026-04-29-search-controller-boundary.md) for the full design and `Bank` for the canonical pilot.

### Skeleton

Replace `Bank`/`bank` with the new entity:

1. **Domain criteria** — `src/<Office>/<Entity>/Domain/Search/<Entity>SearchCriteria.php` extending `Erpify\Shared\Domain\Search\SearchCriteria`. Add only the entity-specific filter properties.
2. **Application DTO** — `src/<Office>/<Entity>/Application/Http/<Entity>SearchQuery.php` extending `Erpify\Shared\Application\Http\Search\SearchQuery`. Decorate filter properties with `#[Assert\…]`. Override `toCriteria(): <Entity>SearchCriteria`.
3. **Domain search repository** — `<Entity>SearchRepository::search(SearchCriteria $criteria): PaginatedResult<<Entity>>`, a read-side port separate from the aggregate-lifecycle `<Entity>Repository` (ISP — a future search provider implements only this one). Concrete repo asserts the concrete subtype inside `getSearchQueryBuilder`.
4. **Application searcher** — `<Entity>Searcher` is a thin wrapper that calls `$query->toCriteria()` and forwards to the repo.
5. **Search field map** — every search repository extends `AbstractDoctrineSearchRepository` and MUST implement `searchFieldMap(): SearchFieldMap` (abstract): the mandatory allow-list of publicly filterable fields for the generic `filters[]` contract (see below). Return `new SearchFieldMap([])` to expose no filterable field. Mark mappings over UUID columns with `requiresUuidValues: true` so malformed values surface as a 400 instead of a Postgres error.
6. **Controller** — extend `Erpify\Shared\Infrastructure\Http\Controller\AbstractSearchController` and use `#[MapQueryString] <Entity>SearchQuery $query = new <Entity>SearchQuery()`:

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

    public function __invoke(#[MapQueryString] <Entity>SearchQuery $query = new <Entity>SearchQuery()): Response
    {
        return $this->buildResponse($this->searcher->search($query), ['identifiable', 'timestamped', '<entity>:search']);
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
- **Caps** — `SearchQuery::MAX_FILTERS = 20` filters per request, `FilterQuery::MAX_IN_VALUES = 100` values per `in` filter, 255 chars per value (mirrors the legacy `names[]` cap). All validated at mapping time.
- **Effective limit** — the wire bounds requests before the caps do: the real ceiling is `min(caps, max_input_vars, URL length)`. The PHP container runs the default `max_input_vars = 1000` (each `filters[...]` pair counts as one input var) and typical URL limits (~8 KB) bite even earlier for long values.
- **Error layers** — shape problems (unknown operator token, value/operator mismatch, caps, indexes) fail in mapping as 400 `validation-failed` with `violations[]`; semantic problems (field outside the repository's allow-list → `unknown-search-field`, operator not allowed for the field → `unsupported-search-operator`, value not matching the field's required format → `invalid-search-value`) fail in the filter applier as 400s from the `invalid-search-criteria` family. Never validate filters in controllers or use cases.
- **No filters** — an absent or empty `filters` produces no filtering (it is not an error). Several filters on the same field compose with AND.

### Conventions that bite

- The route name **must** end with `_search`. The `_search` suffix is the project-wide convention for paginated read endpoints; downstream operators (logs, metrics, tracing) key off it.
- `ValidationFailedException` from the DTO is automatically mapped to a 400 `validation-failed` RFC 9457 Problem Details body (Symfony's `RequestPayloadValueResolver` wraps it in an `HttpException(422)`; `Erpify\Shared\Application\Problem\ProblemDetailsFactory` walks `getPrevious()` and re-maps it to 400 with the structured `violations` extension). See [`../../docs/api-error-contract.md`](../../docs/api-error-contract.md).
- Pagination caps live on `SearchQuery::MAX_PAGE` / `SearchQuery::MAX_LIMIT` — override per entity by re-declaring the `#[Assert\LessThanOrEqual]` attribute on the filter DTO.
- A per-entity `<Entity>SearchQuery` subclass re-declares the base constructor params as plain (non-promoted) arguments. It MUST repeat the `@param array<int, FilterQuery> $filters` docblock on its own constructor — `#[MapQueryString]` reads the docblock of the concrete class it instantiates; without it the nested DTO mapping silently stops working.
