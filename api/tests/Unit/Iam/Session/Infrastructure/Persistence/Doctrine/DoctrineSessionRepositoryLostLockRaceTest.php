<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Session\Infrastructure\Persistence\Doctrine;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Erpify\Iam\Session\Domain\Exception\SessionStoreUnavailable;
use Erpify\Iam\Session\Infrastructure\Persistence\Doctrine\DoctrineSessionRepository;
use Erpify\Tests\Double\Clock\FixedClock;
use Erpify\Tests\Unit\Iam\Session\Infrastructure\Persistence\Doctrine\Fixtures\DbalDeadlock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * A lost lock race is not a store outage, and the adapter must not say it is when somebody owns the answer.
 *
 * DBAL marks a deadlock {@see \Doctrine\DBAL\Exception\RetryableException}, and the transaction manager that
 * opened a transaction turns that marker into the retryable 503 `transient-transaction-failure`. The ordered
 * locked read is the one statement here able to lose such a race, so it is the one driven: inside a transaction
 * the marker reaches the manager untouched; outside one there is no owner, and the outage conversion stands.
 * The failure is raised from `createQuery()`, where the driver would raise it — the reason is the sibling
 * {@see DoctrineSessionRepositoryStoreUnavailableTest}'s class docblock.
 *
 * @internal
 */
#[CoversClass(DoctrineSessionRepository::class)]
final class DoctrineSessionRepositoryLostLockRaceTest extends TestCase
{
    private const string NOW = '2026-07-10T12:00:00+00:00';

    private const string USER_ID = '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b';

    public function testHandsALostLockRaceInsideATransactionToTheTransactionsOwnerUnconverted(): void
    {
        $deadlock = new DbalDeadlock('deadlock detected');

        $this->expectExceptionObject($deadlock);

        $this->repositoryLosing($deadlock, $this->connectionInTransaction())->lockActiveForUser(self::USER_ID);
    }

    public function testConvertsALostLockRaceOutsideATransactionToSessionStoreUnavailable(): void
    {
        $deadlock = new DbalDeadlock('deadlock detected');
        $repository = $this->repositoryLosing($deadlock, $this->createStub(Connection::class));

        try {
            $repository->lockActiveForUser(self::USER_ID);
            $this->fail('Expected a lost lock race with no transaction to own it to surface as an outage.');
        } catch (SessionStoreUnavailable $sessionStoreUnavailable) {
            $this->assertSame($deadlock, $sessionStoreUnavailable->getPrevious());
        }
    }

    private function connectionInTransaction(): Connection
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('isTransactionActive')->willReturn(true);

        return $connection;
    }

    private function repositoryLosing(DbalDeadlock $deadlock, Connection $connection): DoctrineSessionRepository
    {
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('createQuery')->willThrowException($deadlock);
        $entityManager->method('getConnection')->willReturn($connection);
        $entityManager->method('createQueryBuilder')->willReturnCallback(
            static fn (): QueryBuilder => new QueryBuilder($entityManager),
        );

        return new DoctrineSessionRepository($entityManager, new FixedClock(new DateTimeImmutable(self::NOW)));
    }
}
