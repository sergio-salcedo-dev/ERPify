<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Bank\Application;

use Erpify\Backoffice\Bank\Domain\Entity\Bank;
use Erpify\Backoffice\Bank\Domain\Repository\BankRepository;
use Erpify\Backoffice\Bank\Domain\Search\BankSearchCriteria;
use Erpify\Shared\Domain\Search\PaginatedResult;
use Erpify\Shared\Domain\Search\PaginationMode;
use Erpify\Shared\Infrastructure\Persistence\QueryParam;

final readonly class BankSearcher
{
    public function __construct(private BankRepository $bankRepository)
    {
    }

    /**
     * @param array<string, mixed> $queryParams
     *
     * @return PaginatedResult<Bank>
     */
    public function search(array $queryParams): PaginatedResult
    {
        return $this->bankRepository->search($this->toCriteria($queryParams));
    }

    /**
     * @param array<string, mixed> $queryParams
     *
     * @SuppressWarnings("PHPMD.CyclomaticComplexity")
     */
    private function toCriteria(array $queryParams): BankSearchCriteria
    {
        $cursor = $queryParams[QueryParam::CURSOR->value] ?? null;
        $page = $queryParams[QueryParam::PAGE->value] ?? 1;
        $limit = $queryParams[QueryParam::LIMIT->value] ?? null;
        $paginationMode = $queryParams[QueryParam::PAGINATION_MODE->value] ?? PaginationMode::LIGHT;
        $ids = $queryParams[QueryParam::IDS->value] ?? null;
        $names = $queryParams['names'] ?? null;

        \assert(null === $cursor || \is_string($cursor));
        \assert(\is_int($page) || (\is_string($page) && \ctype_digit($page)));
        \assert(null === $limit || \is_int($limit) || (\is_string($limit) && \ctype_digit($limit)));
        \assert($paginationMode instanceof PaginationMode);
        \assert(null === $ids || \is_array($ids));
        \assert(null === $names || \is_array($names));

        /** @var list<string>|null $idsList */
        $idsList = null === $ids ? null : \array_values($ids);
        /** @var list<string>|null $namesList */
        $namesList = null === $names ? null : \array_values($names);

        return new BankSearchCriteria(
            cursor: $cursor,
            page: (int) $page,
            limit: null === $limit ? null : (int) $limit,
            paginationMode: $paginationMode,
            ids: $idsList,
            names: $namesList,
        );
    }
}
