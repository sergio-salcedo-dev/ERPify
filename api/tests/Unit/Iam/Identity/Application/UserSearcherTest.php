<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Application;

use DateTimeImmutable;
use Erpify\Iam\Identity\Application\Query\SearchUsersQuery;
use Erpify\Iam\Identity\Application\UserSearcher;
use Erpify\Iam\Identity\Domain\Enum\IdentityStatus;
use Erpify\Iam\Identity\Domain\Projection\UserRow;
use Erpify\Shared\Search\Domain\Page;
use Erpify\Shared\Search\Domain\SearchCriteria;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(UserSearcher::class)]
#[CoversClass(SearchUsersQuery::class)]
final class UserSearcherTest extends TestCase
{
    public function testPassesTheCriteriaToTheRepositoryAndReturnsItsPageVerbatim(): void
    {
        $page = new Page(items: [$this->row()], hasNext: false, hasPrev: false);
        $repository = new InMemoryUserSearchRepository($page);
        $criteria = new SearchCriteria(limit: 25, sort: 'email');

        $result = (new UserSearcher($repository))->search(new SearchUsersQuery($criteria));

        // The handler is a thin pass-through: no enrichment, no criteria rewriting.
        $this->assertSame($page, $result);
        $this->assertSame($criteria, $repository->receivedCriteria);
    }

    private function row(): UserRow
    {
        return new UserRow(
            '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a66',
            'admin@erpify.test',
            ['ADMIN'],
            IdentityStatus::ACTIVE,
            new DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            new DateTimeImmutable('2026-01-02T00:00:00+00:00'),
        );
    }
}
