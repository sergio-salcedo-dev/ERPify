<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Session\Infrastructure\Persistence\Doctrine;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Erpify\Iam\Session\Domain\Exception\SessionStoreUnavailable;
use Erpify\Iam\Session\Domain\SessionId;
use Erpify\Iam\Session\Infrastructure\Persistence\Doctrine\DoctrineSessionRepository;
use Erpify\Tests\Unit\Iam\Session\Application\FixedClock;
use Erpify\Tests\Unit\Iam\Session\Infrastructure\Persistence\Doctrine\Fixtures\DbalStoreFailure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

/**
 * The gate's fail-closed 503 hinges on the adapter translating a store outage into the domain
 * {@see SessionStoreUnavailable}. These unit tests pin that translation without a live Postgres: any DBAL failure
 * on either read becomes a {@see SessionStoreUnavailable} (the original preserved as `previous`), while a
 * non-DBAL failure propagates raw so a real bug is never masked as an outage.
 *
 * **The failure is driven from the EXECUTION, and that is the whole design of the double.**
 * `EntityManager::createQueryBuilder()` is `new QueryBuilder($this)` — no connection, no statement — so a stub
 * throwing from it simulates a shape production cannot produce, and a guard that had stopped covering the
 * execution would still convert it. The one call `QueryBuilder` makes on the manager is
 * `createQuery()`, from `getQuery()`, on the way to `getOneOrNullResult()` / `getResult()`: a real builder plus a
 * throwing `createQuery()` puts the failure where the driver would raise it, so the assertion tracks the
 * production boundary rather than the guard's current width.
 *
 * The listing is pinned separately from the gate's read because per-method coverage is the claim: a converter
 * one read reaches and another does not is exactly the defect, and a single test cannot see it.
 *
 * @internal
 */
#[CoversClass(DoctrineSessionRepository::class)]
final class DoctrineSessionRepositoryStoreUnavailableTest extends TestCase
{
    private const string NOW = '2026-07-10T12:00:00+00:00';

    public function testConvertsADbalFailureOnTheGatesReadToSessionStoreUnavailable(): void
    {
        $dbalFailure = new DbalStoreFailure('simulated connection loss');
        $repository = $this->repositoryFailingAtExecution($dbalFailure);

        try {
            $repository->findActiveById(SessionId::generate());
            $this->fail('Expected a DBAL failure to surface as SessionStoreUnavailable.');
        } catch (SessionStoreUnavailable $sessionStoreUnavailable) {
            $this->assertSame($dbalFailure, $sessionStoreUnavailable->getPrevious());
        }
    }

    public function testConvertsADbalFailureOnTheSessionListingToSessionStoreUnavailable(): void
    {
        $dbalFailure = new DbalStoreFailure('simulated statement timeout');
        $repository = $this->repositoryFailingAtExecution($dbalFailure);

        try {
            $repository->findByUserId('0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b');
            $this->fail('Expected a DBAL failure to surface as SessionStoreUnavailable.');
        } catch (SessionStoreUnavailable $sessionStoreUnavailable) {
            $this->assertSame($dbalFailure, $sessionStoreUnavailable->getPrevious());
        }
    }

    public function testLetsANonDbalFailurePropagateSoARealBugIsNotMaskedAsAnOutage(): void
    {
        $bug = new RuntimeException('a programming error, not an outage');
        $repository = $this->repositoryFailingAtExecution($bug);

        $this->expectExceptionObject($bug);

        $repository->findActiveById(SessionId::generate());
    }

    /**
     * An adapter over a manager that builds queries normally and fails when one is executed — `createQuery()`
     * being the single call the builder makes on the manager, and the one that stands where the driver would.
     */
    private function repositoryFailingAtExecution(Throwable $failure): DoctrineSessionRepository
    {
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('createQuery')->willThrowException($failure);
        $entityManager->method('createQueryBuilder')->willReturnCallback(
            static fn (): QueryBuilder => new QueryBuilder($entityManager),
        );

        return new DoctrineSessionRepository($entityManager, new FixedClock(new DateTimeImmutable(self::NOW)));
    }
}
