<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Shared\Audit;

use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManagerInterface;
use Erpify\Shared\Audit\Application\AuditLogEntry;
use Erpify\Shared\Audit\Domain\ActorContext;
use Erpify\Shared\Audit\Domain\AuditLevel;
use Erpify\Shared\Audit\Domain\AuditResource;
use Erpify\Shared\Audit\Infrastructure\Persistence\DbalAuditLogWriter;
use Erpify\Shared\Uuid\Domain\Uuid;

/**
 * The apparatus for asking Postgres whether a row is locked, separate from whatever invariant a test then
 * asserts with the answer.
 *
 * **A second connection is what makes the question askable at all.** The container's connection is the one
 * holding the lock, so probing with it would always succeed and prove nothing — and seeding on it would leave
 * the fixtures uncommitted and invisible to any probe. This one is built from the kernel's own parameters and
 * reused for both jobs.
 *
 * That forces the one deviation from the sibling anonymiser tests, which seed inside a rolled-back
 * transaction: rows written that way are invisible to any other connection, so a probe would find nothing and
 * report every row unlocked. The fixtures are therefore COMMITTED, and {@see releaseCommittedRows()} removes
 * them however the test ends — a shared dev database has to be left as it was found.
 */
trait ObservesRowLocksOnASecondConnection
{
    /** Committed rows, so they must be removed however the test ends. */
    private ?Connection $outside = null;

    /** @var list<string> */
    private array $seeded = [];

    /**
     * Asks the second connection to take the row. `NOWAIT` turns "somebody else holds it" into an immediate
     * `55P03` rather than a hang, which is the only reason this can run in one process.
     */
    private function isLocked(string $auditLogId): bool
    {
        $probe = $this->outsideConnection();
        $probe->beginTransaction();

        try {
            $probe->fetchOne(
                'SELECT id FROM audit_log WHERE id = CAST(:id AS UUID) FOR UPDATE NOWAIT',
                ['id' => $auditLogId],
            );

            return false;
        } catch (DriverException $driverException) {
            $this->assertSame('55P03', $driverException->getSQLState(), 'the probe failed for an unrelated reason');

            return true;
        } finally {
            if ($probe->isTransactionActive()) {
                $probe->rollBack();
            }
        }
    }

    /**
     * Written through the production writer rather than by hand, so the fixtures carry the shape a real
     * erasure will meet. The ids are collected as they are minted, because a committed row nobody recorded is
     * a row nobody can delete.
     */
    private function seed(
        ActorContext $actor,
        string $resourceType,
        string $resourceId,
    ): string {
        $entry = AuditLogEntry::create(
            'USER_ROLES_CHANGED',
            AuditLevel::SECURITY,
            $actor,
            Uuid::generate(),
            new DateTimeImmutable('2026-07-01T10:00:00+00:00'),
            AuditResource::of($resourceType, $resourceId),
            [],
            '203.0.113.7',
            'Mozilla/5.0',
        );
        (new DbalAuditLogWriter($this->outsideConnection()))->write($entry);
        $this->seeded[] = $entry->id;

        return $entry->id;
    }

    /**
     * Runs the body inside the container connection's transaction and rolls it back, so the lock the body
     * takes lives exactly as long as the erasure's would.
     */
    private function inRolledBackTransaction(callable $body): void
    {
        self::bootKernel();

        $connection = $this->containerEntityManager()->getConnection();
        $connection->beginTransaction();

        try {
            $body($connection);
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
        }
    }

    private function outsideConnection(): Connection
    {
        if (!$this->outside instanceof Connection) {
            $this->outside = DriverManager::getConnection(
                $this->containerEntityManager()->getConnection()->getParams(),
            );
        }

        return $this->outside;
    }

    /**
     * The committed half of the fixtures. Called from `tearDown()`, because the rows outlive the rolled-back
     * transaction by construction and nothing else would reclaim them.
     */
    private function releaseCommittedRows(): void
    {
        if (!$this->outside instanceof Connection) {
            return;
        }

        if ([] !== $this->seeded) {
            $this->outside->executeStatement(
                'DELETE FROM audit_log WHERE id IN (:ids)',
                ['ids' => $this->seeded],
                ['ids' => ArrayParameterType::STRING],
            );
        }

        $this->outside->close();
        $this->outside = null;

        $this->seeded = [];
    }

    private function containerEntityManager(): EntityManagerInterface
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->assertInstanceOf(EntityManagerInterface::class, $entityManager);

        return $entityManager;
    }
}
