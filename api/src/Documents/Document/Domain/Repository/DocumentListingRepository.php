<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Domain\Repository;

use Erpify\Documents\Document\Domain\Projection\DocumentListRow;
use Erpify\Shared\Search\Domain\Page;
use Erpify\Shared\Search\Domain\SearchCriteria;

/**
 * The read side of the document snapshot: metadata only, never the aggregate and never a lock. Reads nothing a
 * transition could be racing for.
 */
interface DocumentListingRepository
{
    /**
     * A keyset page of documents, newest first by default (`createdAt` descending, the id as the tie-break), with the
     * `state` filter. Erased documents are listed.
     *
     * @return Page<DocumentListRow>
     */
    public function search(SearchCriteria $criteria): Page;

    public function find(string $id): ?DocumentListRow;

    /**
     * The rows of a set of documents, in no promised order; an id with no row is simply absent. An empty set costs no
     * query. Every id must be a UUID.
     *
     * @param list<string> $ids
     *
     * @return list<DocumentListRow>
     */
    public function findMany(array $ids): array;
}
