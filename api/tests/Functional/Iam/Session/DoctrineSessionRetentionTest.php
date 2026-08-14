<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Iam\Session;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Erpify\Iam\Session\Domain\Entity\Session;
use Erpify\Iam\Session\Infrastructure\Persistence\Doctrine\DoctrineSessionRepository;
use Erpify\Shared\Clock\Domain\SystemClock;
use Erpify\Shared\Uuid\Domain\Uuid;
use Erpify\Tests\Unit\Iam\Session\Application\FixedClock;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The retention sweep against REAL Postgres, apart from the rest of the adapter because it is the one
 * statement that DELETES: the reads and the revocations can be wrong and leave the table intact, while an
 * over-wide window here destroys rows nothing can bring back.
 *
 * What the unit cases over the double cannot say is exactly what these say: that the two branches are
 * disjoint in SQL and that `NULL`/comparison semantics behave as the windows assume. Each test runs inside a
 * transaction that truncates `iam_session` and always rolls back.
 *
 * @internal
 */
#[CoversClass(DoctrineSessionRepository::class)]
final class DoctrineSessionRetentionTest extends KernelTestCase
{
    private const string NOW = '2026-07-10T12:00:00+00:00';

    private EntityManagerInterface $entityManager;

    private Connection $connection;

    private DoctrineSessionRepository $repository;

    #[Override]
    protected function setUp(): void
    {
        self::bootKernel();

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;
        $this->connection = $entityManager->getConnection();
        $clock = new FixedClock(new DateTimeImmutable(self::NOW));
        $this->repository = new DoctrineSessionRepository($entityManager, $clock);
    }

    protected function tearDown(): void
    {
        SystemClock::reset();
        parent::tearDown();
    }

    public function testDropsBothKindsOfDeadRowAndLeavesTheRestAlone(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $now = new DateTimeImmutable(self::NOW);
            $userId = Uuid::generate();

            $this->saveRevokedSession($userId, '-31 days');   // past the revocation window
            $this->saveRevokedSession($userId, '-30 days');   // exactly on it — the window is strict
            $this->saveActiveSession($userId, '-91 days');
            $this->saveActiveSession($userId, '-90 days');
            $this->saveActiveSession($userId, '+1 hour');

            $this->entityManager->clear();

            $deleted = $this->repository->deleteRetired($now->modify('-30 days'), $now->modify('-90 days'));

            // Two of five, with the survivors counted rather than inferred: an off-by-one on either window
            // moves this to 3, while a statement matching everything moves it to 5.
            $this->assertSame(2, $deleted);
            $this->assertSame(3, $this->rowCountForUser($userId));
        });
    }

    public function testARevocationCannotExtendTheLifeOfAnAlreadyLapsedSession(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $now = new DateTimeImmutable(self::NOW);
            $userId = Uuid::generate();

            // Revoked yesterday, lapsed two hundred days before that. The expiry branch names no status
            // precisely so this row goes: a bulk revocation stamps `revoked_at = now` on every ACTIVE row of a
            // user including long-expired ones, so restricting that branch to ACTIVE would let an ordinary
            // password change buy a months-dead row another thirty days of holding ip/device.
            $this->saveRevokedSession($userId, '-1 day', expiryOffset: '-200 days');
            $this->entityManager->clear();

            $deleted = $this->repository->deleteRetired($now->modify('-30 days'), $now->modify('-90 days'));

            $this->assertSame(1, $deleted);
            $this->assertSame(0, $this->rowCountForUser($userId));
        });
    }

    public function testKeepsARevokedSessionStillInsideBothWindows(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $now = new DateTimeImmutable(self::NOW);
            $userId = Uuid::generate();
            $this->saveRevokedSession($userId, '-1 day');
            $this->entityManager->clear();

            $deleted = $this->repository->deleteRetired($now->modify('-30 days'), $now->modify('-90 days'));

            $this->assertSame(0, $deleted);
            $this->assertSame(1, $this->rowCountForUser($userId));
        });
    }

    public function testASecondSweepAtTheSameInstantRemovesNothing(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $now = new DateTimeImmutable(self::NOW);
            $userId = Uuid::generate();
            $this->saveRevokedSession($userId, '-31 days');
            $this->entityManager->clear();

            $revokedBefore = $now->modify('-30 days');
            $expiredBefore = $now->modify('-90 days');

            $this->assertSame(1, $this->repository->deleteRetired($revokedBefore, $expiredBefore));
            $this->assertSame(0, $this->repository->deleteRetired($revokedBefore, $expiredBefore));
        });
    }

    private function saveRevokedSession(string $userId, string $revokedOffset, string $expiryOffset = '+1 hour'): void
    {
        $now = new DateTimeImmutable(self::NOW);
        $session = $this->newSession($userId, $expiryOffset);

        // The aggregate stamps `revokedAt` from the ambient clock, so it is frozen for the revocation and
        // released immediately — nothing else in this class wants a frozen one.
        SystemClock::set(new FixedClock($now->modify($revokedOffset)));

        try {
            $session->revoke();
        } finally {
            SystemClock::reset();
        }

        $session->pullDomainEvents();
        $this->repository->save($session);
    }

    private function saveActiveSession(string $userId, string $expiryOffset): void
    {
        $this->repository->save($this->newSession($userId, $expiryOffset));
    }

    private function newSession(string $userId, string $expiryOffset): Session
    {
        $now = new DateTimeImmutable(self::NOW);
        $session = Session::start(
            Uuid::generate(),
            $userId,
            Uuid::generate(),
            'Chrome on macOS',
            '203.0.113.7',
            $now->modify($expiryOffset),
        );
        $session->pullDomainEvents();

        return $session;
    }

    private function rowCountForUser(string $userId): int
    {
        $count = $this->connection->fetchOne(
            'SELECT COUNT(*) FROM iam_session WHERE user_id = CAST(:id AS uuid)',
            ['id' => $userId],
        );

        return \is_numeric($count) ? (int) $count : 0;
    }

    /**
     * @param callable(): void $testBody
     */
    private function inRolledBackTransaction(callable $testBody): void
    {
        $this->connection->beginTransaction();

        try {
            $this->connection->executeStatement('TRUNCATE iam_session RESTART IDENTITY CASCADE');
            $testBody();
        } finally {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }
        }
    }
}
