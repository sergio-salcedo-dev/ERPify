<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Iam\Identity\Infrastructure\Persistence\Doctrine;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Erpify\Iam\Identity\Domain\Enum\IdentityStatus;
use Erpify\Iam\Identity\Domain\Enum\Role;
use Erpify\Iam\Identity\Domain\Projection\UserRow;
use Erpify\Iam\Identity\Infrastructure\Persistence\Doctrine\DoctrineUserSearchRepository;
use Erpify\Shared\Search\Domain\Filter;
use Erpify\Shared\Search\Domain\Filters;
use Erpify\Shared\Search\Domain\Page;
use Erpify\Shared\Search\Domain\SearchCriteria;
use Erpify\Shared\Search\Domain\SortDirection;
use Erpify\Shared\Search\Infrastructure\Persistence\Doctrine\DoctrineSearchEngine;
use Erpify\Tests\DataFixtures\UserFixtureFactory;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * GATE — proves the shared keyset {@see DoctrineSearchEngine} paginates the `SELECT NEW UserRow(…)`
 * projection over `identity_user` (single FROM, no JOIN) against REAL Postgres. The load-bearing
 * regression: the JSON `roles` column hydrates INSIDE `SELECT NEW` as a `list<string>`, the `status`
 * enum-column as its {@see IdentityStatus} case and the timestamps as `DateTimeImmutable` — the exact
 * hydration a spike verified and this test freezes. It also pins the enum-column `status` eq filter (an
 * untyped string bound against the enum-backed column) and the keyset cursor round-trip.
 *
 * Each test runs inside {@see inRolledBackTransaction}: the suite shares the dev database and has no DAMA
 * auto-rollback, so `identity_user` is truncated and re-seeded inside a transaction that is always rolled
 * back — the seeded rows never leak.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
#[CoversClass(DoctrineUserSearchRepository::class)]
#[CoversClass(UserRow::class)]
final class DoctrineUserSearchRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;

    private Connection $connection;

    private DoctrineUserSearchRepository $repository;

    #[Override]
    protected function setUp(): void
    {
        self::bootKernel();

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;
        $this->connection = $entityManager->getConnection();

        $searchEngine = self::getContainer()->get(DoctrineSearchEngine::class);
        $this->assertInstanceOf(DoctrineSearchEngine::class, $searchEngine);

        // The gate proves the engine + projection against real Postgres, so the repository is built from its
        // REAL collaborators — the shared keyset engine — never a fake.
        $this->repository = new DoctrineUserSearchRepository($entityManager, $searchEngine);
    }

    public function testProjectionHydratesRolesAsListStatusEnumAndTimestamps(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $this->truncate();
            $this->persistUser(
                'admin@erpify.test',
                $this->atTime('2026-01-01 10:00:00'),
                [Role::AUDIT_READER->value, Role::MANAGER->value],
            );
            $this->flushAndClear();

            $row = $this->firstRow($this->repository->search(new SearchCriteria(limit: 10, sort: 'email')));

            // The load-bearing hydration: the JSON column is a real PHP list, in declaration order — not a
            // JSON string (which the array-typed constructor would have rejected with a TypeError first).
            $this->assertSame(['AUDIT_READER', 'MANAGER'], $row->roles);
            // The enum CASE hydrates (a backing string 'ACTIVE' could never be identical to the enum), and
            // the timestamp is a DateTimeImmutable whose value round-trips.
            $this->assertSame(IdentityStatus::ACTIVE, $row->status);
            $this->assertSame('2026-01-01 10:00:00', $row->createdAt->format('Y-m-d H:i:s'));
            $this->assertSame('admin@erpify.test', $row->email);
        });
    }

    public function testStatusEqFilterSelectsOnlyThatLifecycleState(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $this->truncate();
            $this->persistUser('active@erpify.test', $this->atTime('2026-01-01 10:00:00'), [Role::VIEWER->value]);
            $this->persistUser(
                'invited@erpify.test',
                $this->atTime('2026-01-01 11:00:00'),
                [Role::VIEWER->value],
                'INVITED',
            );
            $this->flushAndClear();

            // An untyped string bound against the enum-backed column selects exactly that lifecycle state.
            $page = $this->repository->search(new SearchCriteria(
                limit: 10,
                filters: Filters::fromList([Filter::eq('status', 'INVITED')]),
                sort: 'email',
            ));

            $this->assertSame(['invited@erpify.test'], $this->emails($page));
            $this->assertSame(IdentityStatus::INVITED, $this->firstRow($page)->status);
        });
    }

    public function testEmailContainsFilterMatchesCaseInsensitively(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $this->truncate();
            $this->persistUser('alice@erpify.test', $this->atTime('2026-01-01 10:00:00'), [Role::VIEWER->value]);
            $this->persistUser('bob@example.test', $this->atTime('2026-01-01 11:00:00'), [Role::VIEWER->value]);
            $this->flushAndClear();

            // Emails are stored lower-cased; a mixed-case fragment still matches via LOWER(...) LIKE.
            $page = $this->repository->search(new SearchCriteria(
                limit: 10,
                filters: Filters::fromList([Filter::contains('email', 'ERPIFY')]),
                sort: 'email',
            ));

            $this->assertSame(['alice@erpify.test'], $this->emails($page));
        });
    }

    public function testKeysetCursorRoundTripsAcrossPagesByCreatedAt(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $this->truncate();
            $this->persistUser('one@erpify.test', $this->atTime('2026-01-01 10:00:00'), [Role::VIEWER->value]);
            $this->persistUser('two@erpify.test', $this->atTime('2026-01-01 11:00:00'), [Role::VIEWER->value]);
            $this->persistUser('three@erpify.test', $this->atTime('2026-01-01 12:00:00'), [Role::VIEWER->value]);
            $this->flushAndClear();

            $page1 = $this->repository->search(new SearchCriteria(limit: 2, sort: 'createdAt'));
            $this->assertSame(['one@erpify.test', 'two@erpify.test'], $this->emails($page1));
            $this->assertTrue($page1->hasNext);
            $this->assertFalse($page1->hasPrev);
            $this->assertNotNull($page1->nextCursor);

            $page2 = $this->repository->search(new SearchCriteria(
                cursor: $page1->nextCursor,
                limit: 2,
                sort: 'createdAt',
            ));
            $this->assertSame(['three@erpify.test'], $this->emails($page2));
            $this->assertSame([], \array_intersect($this->emails($page1), $this->emails($page2)));
            $this->assertFalse($page2->hasNext);
            $this->assertTrue($page2->hasPrev);
        });
    }

    public function testDescendingEmailSortReversesTheRegister(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $this->truncate();
            $this->persistUser('anna@erpify.test', $this->atTime('2026-01-01 10:00:00'), [Role::VIEWER->value]);
            $this->persistUser('carl@erpify.test', $this->atTime('2026-01-01 11:00:00'), [Role::VIEWER->value]);
            $this->persistUser('bob@erpify.test', $this->atTime('2026-01-01 12:00:00'), [Role::VIEWER->value]);
            $this->flushAndClear();

            $page = $this->repository->search(new SearchCriteria(
                limit: 10,
                sort: 'email',
                direction: SortDirection::DESC,
            ));

            $this->assertSame(['carl@erpify.test', 'bob@erpify.test', 'anna@erpify.test'], $this->emails($page));
        });
    }

    private function atTime(string $time): DateTimeImmutable
    {
        return new DateTimeImmutable($time);
    }

    /**
     * @param list<string> $roleValues
     */
    private function persistUser(
        string $email,
        DateTimeImmutable $createdAt,
        array $roleValues,
        string $status = 'ACTIVE',
    ): void {
        $user = UserFixtureFactory::create(Uuid::v7()->toRfc4122(), $email, 'seed-password', $roleValues, $status);
        $user->setCreatedAt($createdAt);

        $this->entityManager->persist($user);
    }

    private function truncate(): void
    {
        $this->connection->executeStatement('TRUNCATE identity_user RESTART IDENTITY CASCADE');
    }

    private function flushAndClear(): void
    {
        $this->entityManager->flush();
        $this->entityManager->clear();
    }

    /**
     * @param Page<UserRow> $page
     *
     * @return list<string>
     */
    private function emails(Page $page): array
    {
        return \array_map(static fn (UserRow $row): string => $row->email, $page->items);
    }

    /**
     * @param Page<UserRow> $page
     */
    private function firstRow(Page $page): UserRow
    {
        $first = $page->items[0] ?? null;
        $this->assertInstanceOf(UserRow::class, $first);

        return $first;
    }

    /**
     * @param callable(): void $testBody
     */
    private function inRolledBackTransaction(callable $testBody): void
    {
        $this->connection->beginTransaction();

        try {
            $testBody();
        } finally {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }
        }
    }
}
