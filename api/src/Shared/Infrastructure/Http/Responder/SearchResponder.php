<?php

declare(strict_types=1);

namespace Erpify\Shared\Infrastructure\Http\Responder;

use Erpify\Shared\Application\Http\Search\SearchQuery;
use Erpify\Shared\Domain\Search\Page;
use Erpify\Shared\Domain\Search\SearchCriteria;
use JsonException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Serializer\Exception\ExceptionInterface;

/**
 * Assembles the wire response for search endpoints: serializes the {@see Page} items
 * through {@see ResourceResponder} and wraps them with the cursor-only pagination
 * envelope {@see PaginationMeta}.
 *
 * This is the SINGLE compositor of the navigation envelope (W9/OQ-4): the keyset engine
 * produces a {@see Page} carrying OPAQUE cursors, and the materialization of those cursors
 * into relative `links.next`/`links.prev` happens ONLY here, at the HTTP boundary — never in
 * the engine or the domain. The query string is rebuilt from the validated {@see SearchQuery}
 * (the one normalized source, W2), substituting only the `after`/`before` cursor; everything
 * else (`limit`/`sort`/`direction`/`filters[]`/`paginationMode`) is preserved so the link's
 * fingerprint still matches. {@see UrlGeneratorInterface} URL-encodes every segment, and the
 * route is same-endpoint relative — no host, so no open-redirect surface.
 *
 * Links are GATED ON THE FLAG (`hasNext`/`hasPrev`), not on mere cursor presence: a last page
 * may still carry a boundary cursor, but its `links.next` is `null`. W10 guarantees that when a
 * flag is true the matching cursor is non-null, so a true affordance never yields a null link.
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
final readonly class SearchResponder
{
    private const string AFTER = 'after';

    private const string BEFORE = 'before';

    public function __construct(
        private ResourceResponder $resourceResponder,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * @param Page<object> $page
     * @param string       $routeName the search endpoint's route name, for relative-link generation
     * @param list<string> $groups    serializer groups projecting each result
     *
     * @throws JsonException
     * @throws ExceptionInterface
     */
    public function respond(Page $page, SearchQuery $query, string $routeName, array $groups): Response
    {
        $meta = PaginationMeta::fromPage(
            $page,
            $this->linkFor($routeName, $query, self::AFTER, $page->hasNext, $page->nextCursor),
            $this->linkFor($routeName, $query, self::BEFORE, $page->hasPrev, $page->prevCursor),
        );

        return $this->resourceResponder->respondCollection($page->items, $groups, ['pagination' => $meta->toArray()]);
    }

    /**
     * @SuppressWarnings("PHPMD.BooleanArgumentFlag")
     */
    private function linkFor(
        string $routeName,
        SearchQuery $query,
        string $cursorParam,
        bool $applies,
        ?string $cursor,
    ): ?string {
        if (!$applies || null === $cursor) {
            return null;
        }

        return $this->urlGenerator->generate(
            $routeName,
            [...$this->preservedParams($query), $cursorParam => $cursor],
            UrlGeneratorInterface::ABSOLUTE_PATH,
        );
    }

    /**
     * The query params carried verbatim into every navigation link, rebuilt from the validated
     * DTO (never the raw request). `after`/`before` are deliberately excluded — each link supplies
     * exactly one of them.
     *
     * @return array<string, mixed>
     */
    private function preservedParams(SearchQuery $query): array
    {
        $params = [
            'limit' => $query->limit ?? SearchCriteria::DEFAULT_LIMIT,
            'paginationMode' => $query->paginationMode->value,
        ];

        if (null !== $query->sort && '' !== $query->sort) {
            $params['sort'] = $query->sort;
        }

        if ($query->direction instanceof \Erpify\Shared\Domain\Search\SortDirection) {
            $params['direction'] = $query->direction->value;
        }

        $filters = [];

        foreach ($query->filters as $filter) {
            $filters[] = [
                'field' => $filter->field,
                'operator' => $filter->operator?->value,
                'value' => $filter->value,
            ];
        }

        if ([] !== $filters) {
            $params['filters'] = $filters;
        }

        return $params;
    }
}
