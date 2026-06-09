<?php

declare(strict_types=1);

namespace Erpify\Shared\Infrastructure\Http\Responder;

use Erpify\Shared\Domain\Search\PaginatedResult;
use Erpify\Shared\Infrastructure\Persistence\PaginatorCursorFactory;
use JsonException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Serializer\Exception\ExceptionInterface;

/**
 * Assembles the wire response for search endpoints: serializes the page of
 * results through {@see ResourceResponder} and wraps it with the pagination
 * envelope (cursor, page count, has-more flag). Search controllers inject this
 * collaborator instead of extending a shared base class.
 */
final readonly class SearchResponder
{
    public function __construct(
        private ResourceResponder $resourceResponder,
        private PaginatorCursorFactory $paginatorCursorFactory,
    ) {
    }

    /**
     * @param PaginatedResult<object> $paginatedResult
     * @param list<string>            $groups          serializer groups projecting each result
     *
     * @throws JsonException
     * @throws ExceptionInterface
     */
    public function respond(PaginatedResult $paginatedResult, array $groups): Response
    {
        // Materialize first: PaginatedResult computes its pagination state
        // (hasMorePages, cursor, page count) lazily while iterating, so the
        // envelope below must be read AFTER the items have been pulled.
        $items = \iterator_to_array($paginatedResult);

        $cursor = $this->paginatorCursorFactory->toString($paginatedResult->getCursor());

        return $this->resourceResponder->respondCollection(
            $items,
            $groups,
            ['pagination' => PaginationMeta::fromPaginatedResult($paginatedResult, $cursor)->toArray()],
        );
    }
}
