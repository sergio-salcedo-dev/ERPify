<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Backoffice\BankAccount\Application;

use Erpify\Documents\Document\Domain\Projection\DocumentListRow;
use Erpify\Documents\Document\Domain\Repository\DocumentListingRepository;
use Erpify\Shared\Search\Domain\Page;
use Erpify\Shared\Search\Domain\SearchCriteria;
use LogicException;
use Override;

/**
 * The Documents listing as the evidence seam reads it: a batch lookup over rows a test planted, counting the calls so
 * a test can assert the seam was not reached at all.
 *
 * @internal
 */
final class InMemoryDocumentListing implements DocumentListingRepository
{
    public int $findManyCalls = 0;

    /**
     * @param array<string, DocumentListRow> $rows keyed by lower-case id
     */
    public function __construct(private readonly array $rows)
    {
    }

    #[Override]
    public function search(SearchCriteria $criteria): Page
    {
        throw new LogicException('The evidence seam never searches.');
    }

    #[Override]
    public function find(string $id): ?DocumentListRow
    {
        throw new LogicException('The evidence seam reads in batches.');
    }

    /**
     * @return DocumentListRow[]
     */
    #[Override]
    public function findMany(array $ids): array
    {
        ++$this->findManyCalls;

        $found = [];

        foreach (\array_unique(\array_map(\strtolower(...), $ids)) as $id) {
            if (isset($this->rows[$id])) {
                $found[] = $this->rows[$id];
            }
        }

        return $found;
    }
}
