<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Bank\Infrastructure\Controller;

use Erpify\Backoffice\Bank\Application\BankSearcher;
use Erpify\Shared\Application\Http\Search\SearchQuery;
use Erpify\Shared\Infrastructure\Http\Controller\AbstractSearchController;
use Erpify\Shared\Infrastructure\Http\Responder\ResponderInterface;
use Erpify\Shared\Infrastructure\Persistence\PaginatorCursorFactory;
use JsonException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\Exception\ExceptionInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

#[Route('/banks', name: 'backoffice_bank_search', methods: ['GET'])]
final readonly class BankSearchController extends AbstractSearchController
{
    public function __construct(
        private BankSearcher $bankSearcher,
        NormalizerInterface $normalizer,
        ResponderInterface $responder,
        PaginatorCursorFactory $paginatorCursorFactory,
    ) {
        parent::__construct($normalizer, $responder, $paginatorCursorFactory);
    }

    /**
     * @throws ExceptionInterface
     * @throws JsonException
     */
    public function __invoke(
        #[MapQueryString]
        SearchQuery $query = new SearchQuery(),
    ): Response {
        return $this->buildResponse(
            paginatedResult: $this->bankSearcher->search($query->toCriteria()),
            serializerGroups: [
                'identifiable',
                'timestamped',
                'bank:search',
            ],
        );
    }
}
