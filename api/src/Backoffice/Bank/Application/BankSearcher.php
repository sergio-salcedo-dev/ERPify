<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Bank\Application;

use Erpify\Backoffice\Bank\Application\Query\SearchBanksQuery;
use Erpify\Backoffice\Bank\Domain\Entity\Bank;
use Erpify\Backoffice\Bank\Domain\Repository\BankSearchRepository;
use Erpify\Backoffice\BankAccount\Domain\Repository\BankAccountCounter;
use Erpify\Shared\Domain\Search\Page;

/**
 * Read-side handler for {@see SearchBanksQuery} — the query counterpart of the
 * write-side {@see BankCreator}.
 */
final readonly class BankSearcher
{
    public function __construct(
        private BankSearchRepository $bankSearchRepository,
        private BankAccountCounter $accountCounts,
    ) {
    }

    /**
     * @return Page<Bank>
     */
    public function search(SearchBanksQuery $query): Page
    {
        $page = $this->bankSearchRepository->search($query->criteria);

        $counts = $this->accountCounts->countsByBankIds(
            \array_values(\array_filter(\array_map(
                static fn (Bank $bank): ?string => $bank->getId(),
                $page->items,
            ))),
        );

        foreach ($page->items as $bank) {
            $id = $bank->getId();
            $bank->assignAccountCount(null !== $id ? ($counts[$id] ?? 0) : 0);
        }

        return $page;
    }
}
