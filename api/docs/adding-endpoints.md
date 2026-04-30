# Adding endpoints

Patterns for adding HTTP endpoints to the API. Each section covers one endpoint shape; more will be added as new boundaries are standardized (create / update / delete / detail / etc.).

## Search endpoints

The search-endpoint boundary is centralized — one Application DTO + one Domain criteria + one ~12-line controller per entity. See [`../../_bmad-output/planning-artifacts/adr-2026-04-29-search-controller-boundary.md`](../../_bmad-output/planning-artifacts/adr-2026-04-29-search-controller-boundary.md) for the full design and `Bank` for the canonical pilot.

### Skeleton

Replace `Bank`/`bank` with the new entity:

1. **Domain criteria** — `src/<Office>/<Entity>/Domain/Search/<Entity>SearchCriteria.php` extending `Erpify\Shared\Domain\Search\SearchCriteria`. Add only the entity-specific filter properties.
2. **Application DTO** — `src/<Office>/<Entity>/Application/Http/<Entity>SearchQuery.php` extending `Erpify\Shared\Application\Http\Search\SearchQuery`. Decorate filter properties with `#[Assert\…]`. Override `toCriteria(): <Entity>SearchCriteria`.
3. **Domain repository** — `<Entity>Repository::search(SearchCriteria $criteria): PaginatedResult<<Entity>>`. Concrete repo asserts the concrete subtype inside `getSearchQueryBuilder`.
4. **Application searcher** — `<Entity>Searcher` is a thin wrapper that calls `$query->toCriteria()` and forwards to the repo.
5. **Controller** — extend `Erpify\Shared\Infrastructure\Http\Controller\AbstractSearchController` and use `#[MapQueryString] <Entity>SearchQuery $query = new <Entity>SearchQuery()`:

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
        return $this->buildResponse($this->searcher->search($query), ['aggregate:default', '<entity>:search']);
    }
}
```

### Conventions that bite

- The route name **must** end with `_search` so `Erpify\Shared\Infrastructure\Http\EventListener\SearchExceptionListener` normalizes `InvalidArgumentException` to 400.
- `ValidationFailedException` from the DTO is automatically mapped to 422 with a `JsonApiErrorBuilder` envelope.
- Pagination caps live on `SearchQuery::MAX_PAGE` / `SearchQuery::MAX_LIMIT` — override per entity by re-declaring the `#[Assert\LessThanOrEqual]` attribute on the filter DTO.
