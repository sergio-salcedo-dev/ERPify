<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Backoffice\BankAccount\Application;

use Erpify\Backoffice\BankAccount\Domain\Projection\BankAccountCollectionRow;
use Erpify\Backoffice\BankAccount\Domain\Repository\BankAccountCollectionSearchRepository;
use Erpify\Shared\Search\Domain\Page;
use Erpify\Shared\Search\Domain\SearchCriteria;
use Override;

/**
 * In-memory {@see BankAccountCollectionSearchRepository} returning a prebuilt {@see Page} and recording
 * whether it was reached, so a test can assert the searcher delegates the read and passes the page
 * through unchanged.
 *
 * @internal
 */
final class InMemoryBankAccountCollectionSearchRepository implements BankAccountCollectionSearchRepository
{
    public bool $called = false;

    /**
     * @param Page<BankAccountCollectionRow> $page
     */
    public function __construct(private readonly Page $page)
    {
    }

    /**
     * @return Page<BankAccountCollectionRow>
     */
    #[Override]
    public function search(SearchCriteria $criteria): Page
    {
        $this->called = true;

        return $this->page;
    }
}
