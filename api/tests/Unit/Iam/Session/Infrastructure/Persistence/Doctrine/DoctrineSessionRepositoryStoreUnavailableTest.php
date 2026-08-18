<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Session\Infrastructure\Persistence\Doctrine;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
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
 * on the read becomes a {@see SessionStoreUnavailable} (the original preserved as `previous`), while a non-DBAL
 * failure propagates raw so a real bug is never masked as an outage.
 *
 * @internal
 */
#[CoversClass(DoctrineSessionRepository::class)]
final class DoctrineSessionRepositoryStoreUnavailableTest extends TestCase
{
    private const string NOW = '2026-07-10T12:00:00+00:00';

    public function testConvertsADbalFailureOnTheReadToSessionStoreUnavailable(): void
    {
        $dbalFailure = new DbalStoreFailure('simulated connection loss');
        $repository = new DoctrineSessionRepository(
            $this->entityManagerThrowing($dbalFailure),
            new FixedClock(new DateTimeImmutable(self::NOW)),
        );

        try {
            $repository->findActiveById(SessionId::generate());
            $this->fail('Expected a DBAL failure to surface as SessionStoreUnavailable.');
        } catch (SessionStoreUnavailable $sessionStoreUnavailable) {
            $this->assertSame($dbalFailure, $sessionStoreUnavailable->getPrevious());
        }
    }

    public function testLetsANonDbalFailurePropagateSoARealBugIsNotMaskedAsAnOutage(): void
    {
        $bug = new RuntimeException('a programming error, not an outage');
        $repository = new DoctrineSessionRepository(
            $this->entityManagerThrowing($bug),
            new FixedClock(new DateTimeImmutable(self::NOW)),
        );

        $this->expectExceptionObject($bug);

        $repository->findActiveById(SessionId::generate());
    }

    private function entityManagerThrowing(Throwable $failure): EntityManagerInterface
    {
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('createQueryBuilder')->willThrowException($failure);

        return $entityManager;
    }
}
