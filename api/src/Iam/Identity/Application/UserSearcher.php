<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Application;

use Erpify\Iam\Identity\Application\Query\SearchUsersQuery;
use Erpify\Iam\Identity\Domain\Projection\UserRow;
use Erpify\Iam\Identity\Domain\Repository\UserSearchRepository;
use Erpify\Shared\Search\Domain\Page;

/**
 * Read-side handler for {@see SearchUsersQuery} — the query counterpart of the write-side identity use
 * cases. It paginates the register through the keyset search port and returns the projection page
 * verbatim; there is no read-time enrichment (the projection carries every wire field).
 */
final readonly class UserSearcher
{
    public function __construct(
        private UserSearchRepository $userSearchRepository,
    ) {
    }

    /**
     * @return Page<UserRow>
     */
    public function search(SearchUsersQuery $query): Page
    {
        return $this->userSearchRepository->search($query->criteria);
    }
}
