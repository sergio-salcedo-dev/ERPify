<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Application\Query;

use Erpify\Shared\Search\Domain\SearchCriteria;

/**
 * Read-side query for the identity register. It carries the generic {@see SearchCriteria} resolved from
 * the shared HTTP {@see \Erpify\Shared\Search\Application\Http\SearchQuery} (search endpoints keep that
 * boundary DTO generic and shared — they do not subclass it). The controller builds this query and
 * {@see \Erpify\Iam\Identity\Application\UserSearcher} is its handler.
 */
final readonly class SearchUsersQuery
{
    public function __construct(public SearchCriteria $criteria)
    {
    }
}
