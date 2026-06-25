<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Shared\Persistence;

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use Erpify\Shared\Persistence\Infrastructure\PostgresAdvisoryLock;
use PHPUnit\Framework\Attributes\CoversClass;
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
}
