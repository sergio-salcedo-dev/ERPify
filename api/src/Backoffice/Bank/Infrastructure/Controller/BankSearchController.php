<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Bank\Infrastructure\Controller;

use Erpify\Backoffice\Bank\Application\BankSearcher;
use Erpify\Backoffice\Bank\Application\Query\SearchBanksQuery;
use Erpify\Backoffice\Bank\Infrastructure\Http\BankResourceMapper;
use Erpify\Shared\Search\Application\Http\SearchQuery;
use Erpify\Shared\Search\Infrastructure\Http\SearchResponder;
use JsonException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\Exception\ExceptionInterface;

#[Route('/banks', name: self::ROUTE_NAME, methods: ['GET'])]
final readonly class BankSearchController
{
    public const string ROUTE_NAME = 'backoffice_bank_search';

    public function __construct(
        private BankSearcher $bankSearcher,
        private BankResourceMapper $bankResourceMapper,
        private SearchResponder $searchResponder,
    ) {
    }

    /**
     * @throws ExceptionInterface
     * @throws JsonException
     */
    public function __invoke(
        #[MapQueryString]
        SearchQuery $query = new SearchQuery(),
    ): Response {
        return $this->searchResponder->respond(
            $this->bankResourceMapper->toListPage(
                $this->bankSearcher->search(new SearchBanksQuery($query->toCriteria())),
            ),
            $query,
            self::ROUTE_NAME,
        );
    }
}
