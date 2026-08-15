<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Shared\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use Erpify\Shared\Persistence\Infrastructure\PostgresAdvisoryLock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Verifies the mutual-exclusion behaviour of the advisory lock against a real Postgres across two
 * independent sessions: while one session holds the named lock a second cannot acquire it, and once the
 * work finishes the lock is released so a later attempt succeeds.
 *
 * @internal
 */
#[CoversClass(PostgresAdvisoryLock::class)]
final class PostgresAdvisoryLockFunctionalTest extends KernelTestCase
{
    private const string LOCK = 'advisory-lock-functional-test';

    public function testAHeldLockBlocksAnotherSessionAndIsReleasedAfterTheWork(): void
    {
        self::bootKernel();

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $primaryConnection = $entityManager->getConnection();
        $secondConnection = DriverManager::getConnection($primaryConnection->getParams());

        try {
            $primaryLock = new PostgresAdvisoryLock($primaryConnection);
            $secondLock = new PostgresAdvisoryLock($secondConnection);
            $noop = static function (): void {
            };

            $secondAcquiredWhileHeld = true;
            $ranUnderLock = $primaryLock->withTryLock(
                self::LOCK,
                static function () use ($secondLock, $noop, &$secondAcquiredWhileHeld): void {
                    $secondAcquiredWhileHeld = $secondLock->withTryLock(self::LOCK, $noop);
                },
            );

            $this->assertTrue($ranUnderLock, 'the first session acquires the lock and runs the work');
            $this->assertFalse($secondAcquiredWhileHeld, 'a second session cannot acquire the held lock');

            $this->assertTrue(
                $secondLock->withTryLock(self::LOCK, $noop),
                'the lock is released after the work, so a later attempt succeeds',
            );
        } finally {
            $secondConnection->close();
        }
    }

    #[Test]
    public function theLiveLockNamesDoNotCollideInTheThirtyTwoBitKeySpace(): void
    {
        // `hashtext` returns an int4, so two names share a 2^32 space. The arithmetic says a collision is
        // negligible; this says it about the names that actually exist, because a collision would not make
        // one job wait for the other — both run on the same worker, where the lock is re-entrant, so both
        // would believe they held exclusivity and neither would report anything.
        self::bootKernel();
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        $this->assertInstanceOf(Connection::class, $connection);

        $auditKey = $connection->fetchOne('SELECT hashtext(:name)', ['name' => 'audit_log_retention_prune']);
        $failedKey = $connection->fetchOne(
            'SELECT hashtext(:name)',
            ['name' => 'failed_transport_retention_prune'],
        );

        $this->assertIsNumeric($auditKey);
        $this->assertIsNumeric($failedKey);
        $this->assertNotSame(
            (int) $auditKey,
            (int) $failedKey,
            'the two live advisory-lock names hash to the same key: one prune would silently skip for ever, '
            . 'or worse, both would hold what each believes is an exclusive lock.',
        );
    }
}
