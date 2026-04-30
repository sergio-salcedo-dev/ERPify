<?php

declare(strict_types=1);

namespace Erpify\Shared\Infrastructure\Http\Controller;

use Erpify\Shared\Application\UseCase\Result;
use Erpify\Shared\Domain\Search\PaginatedResult;
use Erpify\Shared\Infrastructure\Http\Responder\ResponderInterface;
use Erpify\Shared\Infrastructure\Persistence\PaginatorCursorFactory;
use JsonException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Serializer\Exception\ExceptionInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Templates the response side of search endpoints. Concrete controllers
 * extend this and call `buildResponse(...)` from their `__invoke`.
 *
 * Constructor uses non-promoted protected properties so subclasses can
 * declare their own promoted readonly properties (entity searcher, etc.).
 */
abstract readonly class AbstractSearchController
{
    public function __construct(
        protected NormalizerInterface $normalizer,
        protected ResponderInterface $responder,
        protected PaginatorCursorFactory $paginatorCursorFactory,
    ) {
    }

    /**
     * @param PaginatedResult<object> $paginatedResult
     * @param list<string>            $serializerGroups
     *
     * @throws JsonException
     * @throws ExceptionInterface
     */
    protected function buildResponse(PaginatedResult $paginatedResult, array $serializerGroups): Response
    {
        /** @var array<int, mixed> $items */
        $items = $this->normalizer->normalize(
            \array_values(\iterator_to_array($paginatedResult)),
            'json',
            ['groups' => $serializerGroups],
        );

        return $this->responder->respond(Result::ok([
            'items' => $items,
            'pagination' => [
                'currentPage' => $paginatedResult->getCurrentPage(),
                'pageCount' => $paginatedResult->getPageCount(),
                'count' => $paginatedResult->getCursor()->getCount(),
                'hasMorePages' => $paginatedResult->hasMorePages(),
                'cursor' => $this->paginatorCursorFactory->toString($paginatedResult->getCursor()),
            ],
        ]));
    }
}
