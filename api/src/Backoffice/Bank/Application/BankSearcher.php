<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Bank\Application;

use Erpify\Backoffice\Bank\Application\Query\SearchBanksQuery;
use Erpify\Backoffice\Bank\Domain\Repository\BankSearchRepository;
use Erpify\Shared\Search\Domain\Page;

/**
 * Read-side handler for {@see SearchBanksQuery} — the query counterpart of the
 * write-side {@see BankCreator}.
 */
final readonly class BankSearcher
{
    public function __construct(
        private BankSearchRepository $bankSearchRepository,
        private BankAccountCountEnricher $accountCountEnricher,
    ) {
    }

    /**
     * @return Page<BankWithAccountCount>
     */
    public function search(SearchBanksQuery $query): Page
    {
        $page = $this->bankSearchRepository->search($query->criteria);

        return new Page(
            $this->accountCountEnricher->enrichAll($page->items),
            $page->hasNext,
            $page->hasPrev,
            $page->count,
            $page->nextCursor,
            $page->prevCursor,
        );
    }
}
