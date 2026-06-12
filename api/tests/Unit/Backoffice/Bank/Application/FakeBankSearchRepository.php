<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Backoffice\Bank\Application;

use Erpify\Backoffice\Bank\Domain\Repository\BankSearchRepository;
use Erpify\Shared\Domain\Search\Page;
use Erpify\Shared\Domain\Search\SearchCriteria;
use Override;

/**
 * In-memory {@see BankSearchRepository} returning a prebuilt {@see Page}, so a test can drive the
 * read-side enrichment in {@see \Erpify\Backoffice\Bank\Application\BankSearcher} without a database.
 *
 * @internal
 */
final readonly class FakeBankSearchRepository implements BankSearchRepository
{
    /**
     * @param Page<\Erpify\Backoffice\Bank\Domain\Entity\Bank> $page
     */
    public function __construct(private Page $page)
    {
    }

    #[Override]
    public function search(SearchCriteria $criteria): Page
    {
        return $this->page;
    }
}
