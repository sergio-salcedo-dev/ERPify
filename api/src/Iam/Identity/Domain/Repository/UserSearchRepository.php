<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Domain\Repository;

use Erpify\Iam\Identity\Domain\Projection\UserRow;
use Erpify\Shared\Search\Domain\Page;
use Erpify\Shared\Search\Domain\SearchCriteria;

/**
 * Read-side keyset search port for the identity register, distinct from the aggregate-lifecycle
 * {@see UserRepository}. Each row is a {@see UserRow} projection (explicit columns, never the aggregate),
 * so the port returns the projection — never the {@see \Erpify\Iam\Identity\Domain\Entity\User} entity,
 * which carries the credential and must not reach a read view.
 */
interface UserSearchRepository
{
    /**
     * @return Page<UserRow>
     */
    public function search(SearchCriteria $criteria): Page;
}
