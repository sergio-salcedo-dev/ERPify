<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Application;

use Erpify\Iam\Identity\Domain\Projection\UserRow;
use Erpify\Iam\Identity\Domain\Repository\UserSearchRepository;
use Erpify\Shared\Search\Domain\Page;
use Erpify\Shared\Search\Domain\SearchCriteria;
use Override;

/**
 * In-memory {@see UserSearchRepository} returning a pre-seeded page and recording the criteria it was
 * handed, so a test can prove {@see \Erpify\Iam\Identity\Application\UserSearcher} passes the criteria
 * through unchanged and returns the page verbatim — without a container or a database.
 *
 * @internal
 */
final class InMemoryUserSearchRepository implements UserSearchRepository
{
    public ?SearchCriteria $receivedCriteria = null;

    /**
     * @param Page<UserRow> $page
     */
    public function __construct(private readonly Page $page)
    {
    }

    /**
     * @return Page<UserRow>
     */
    #[Override]
    public function search(SearchCriteria $criteria): Page
    {
        $this->receivedCriteria = $criteria;

        return $this->page;
    }
}
