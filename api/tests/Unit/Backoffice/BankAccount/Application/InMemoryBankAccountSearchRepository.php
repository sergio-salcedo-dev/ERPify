<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Backoffice\BankAccount\Application;

use Erpify\Backoffice\BankAccount\Domain\Entity\BankAccount;
use Erpify\Backoffice\BankAccount\Domain\Repository\BankAccountSearchRepository;
use Erpify\Shared\Domain\Search\Page;
use Erpify\Shared\Domain\Search\SearchCriteria;
use Override;

/**
 * In-memory {@see BankAccountSearchRepository} returning a prebuilt {@see Page} and recording whether
 * it was reached, so a test can assert the existence guard runs before any account work.
 *
 * @internal
 */
final class InMemoryBankAccountSearchRepository implements BankAccountSearchRepository
{
    public bool $called = false;

    /**
     * @param Page<BankAccount> $page
     */
    public function __construct(private readonly Page $page)
    {
    }

    /**
     * @return Page<BankAccount>
     */
    #[Override]
    public function search(string $bankId, SearchCriteria $criteria): Page
    {
        $this->called = true;

        return $this->page;
    }
}
