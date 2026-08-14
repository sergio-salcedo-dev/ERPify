<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Iam\Session;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Erpify\Iam\Session\Domain\Entity\Session;
use Erpify\Iam\Session\Domain\SessionId;
use Erpify\Iam\Session\Infrastructure\Persistence\Doctrine\DoctrineSessionRepository;
use Erpify\Shared\Uuid\Domain\Uuid;
use Erpify\Tests\Unit\Iam\Session\Application\FixedClock;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Proves the adapter against REAL Postgres: the active-only reads push `status = ACTIVE AND expires_at > now`
 * into SQL (a revoked or time-expired session is invisible) and list newest first, and the bulk revocations
 * flip the right rows. The one statement that deletes for age lives in {@see DoctrineSessionRetentionTest}.
 * Each test runs inside a transaction that truncates `iam_session` and always rolls back.
 *
 * @internal
 */
#[CoversClass(DoctrineSessionRepository::class)]
final class DoctrineSessionRepositoryTest extends KernelTestCase
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

    public function testSaveThenFindActiveByIdRoundTripsAnActiveSession(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $id = Uuid::generate();
            $this->repository->save($this->activeSession($id, Uuid::generate(), '+1 hour'));
            $this->entityManager->clear();

            $found = $this->repository->findActiveById(SessionId::fromString($id));

            $this->assertInstanceOf(Session::class, $found);
            $this->assertSame($id, $found->getId());
        });
    }

    public function testFindActiveByIdSkipsARevokedSession(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $id = Uuid::generate();
            $session = $this->activeSession($id, Uuid::generate(), '+1 hour');
            $session->revoke();

            $this->repository->save($session);
            $this->entityManager->clear();

            $this->assertNotInstanceOf(Session::class, $this->repository->findActiveById(SessionId::fromString($id)));
        });
    }

    public function testFindActiveByIdSkipsATimeExpiredSession(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $id = Uuid::generate();
            $this->repository->save($this->activeSession($id, Uuid::generate(), '-1 hour'));
            $this->entityManager->clear();

            $this->assertNotInstanceOf(Session::class, $this->repository->findActiveById(SessionId::fromString($id)));
        });
    }

    public function testFindActiveByIdSkipsASessionExpiringOnThisVeryInstant(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $id = Uuid::generate();
            $this->repository->save($this->activeSession($id, Uuid::generate(), '+0 seconds'));
            $this->entityManager->clear();

            $this->assertNotInstanceOf(Session::class, $this->repository->findActiveById(SessionId::fromString($id)));
        });
    }

    public function testFindByUserIdReturnsOnlyTheUsersActiveSessions(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $userId = Uuid::generate();
            $this->repository->save($this->activeSession(Uuid::generate(), $userId, '+1 hour'));
            $this->repository->save($this->activeSession(Uuid::generate(), $userId, '-1 hour'));
            $this->repository->save($this->activeSession(Uuid::generate(), Uuid::generate(), '+1 hour'));

            $this->entityManager->clear();

            $this->assertCount(1, $this->repository->findByUserId($userId));
        });
    }

    public function testFindByUserIdReturnsTheUsersLiveSessionsNewestFirst(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $userId = Uuid::generate();
            $oldest = Uuid::generate();
            $middle = Uuid::generate();
            $newest = Uuid::generate();

            // Saved in an order that matches neither the expectation nor its reverse, so dropping the
            // ORDER BY cannot pass by coincidence. `createdAt` is stamped rather than left to three
            // `SystemClock::now()` calls landing microseconds apart: the claim under test is the ordering
            // clause, not how fast the three saves ran.
            $this->saveSessionCreatedAt($oldest, $userId, '2026-07-08T09:00:00+00:00');
            $this->saveSessionCreatedAt($newest, $userId, '2026-07-10T09:00:00+00:00');
            $this->saveSessionCreatedAt($middle, $userId, '2026-07-09T09:00:00+00:00');
            $this->entityManager->clear();

            $ids = \array_map(
                static fn (Session $session): ?string => $session->getId(),
                $this->repository->findByUserId($userId),
            );

            $this->assertSame([$newest, $middle, $oldest], $ids);
        });
    }

    public function testFindByUserIdBreaksACreatedAtTieOnTheSessionId(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $userId = Uuid::generate();
            // `created_at` is TIMESTAMP(0), so these two collide in the column the list orders by, and only the
            // id settles them. Inserted lowest-id first — the OPPOSITE of the expectation — because without a
            // tiebreaker Postgres returns them in heap order: seeding in the expected order would make this
            // assertion pass over a missing `addOrderBy`, which is exactly what it did before it was flipped.
            $higher = '0190c1d2-e3f4-7a5b-8c6d-000000000002';
            $lower = '0190c1d2-e3f4-7a5b-8c6d-000000000001';
            $this->saveSessionCreatedAt($lower, $userId, '2026-07-09T09:00:00+00:00');
            $this->saveSessionCreatedAt($higher, $userId, '2026-07-09T09:00:00+00:00');
            $this->entityManager->clear();

            $ids = \array_map(
                static fn (Session $session): ?string => $session->getId(),
                $this->repository->findByUserId($userId),
            );

            $this->assertSame([$higher, $lower], $ids);
        });
    }

    public function testRevokeOthersForUserRevokesEveryActiveSessionExceptTheCurrent(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $userId = Uuid::generate();
            $currentId = Uuid::generate();
            $otherId = Uuid::generate();
            $this->repository->save($this->activeSession($currentId, $userId, '+1 hour'));
            $this->repository->save($this->activeSession($otherId, $userId, '+1 hour'));

            $this->entityManager->clear();

            $current = SessionId::fromString($currentId);
            $other = SessionId::fromString($otherId);
            $this->repository->revokeOthersForUser($userId, $current);
            $this->entityManager->clear();

            $this->assertInstanceOf(Session::class, $this->repository->findActiveById($current));
            $this->assertNotInstanceOf(Session::class, $this->repository->findActiveById($other));
        });
    }

    public function testRevokeAllForUserRevokesEveryActiveSession(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $userId = Uuid::generate();
            $id = Uuid::generate();
            $this->repository->save($this->activeSession($id, $userId, '+1 hour'));
            $this->entityManager->clear();

            $this->repository->revokeAllForUser($userId);
            $this->entityManager->clear();

            $this->assertNotInstanceOf(Session::class, $this->repository->findActiveById(SessionId::fromString($id)));
        });
    }

    public function testDeleteAllForUserHardDeletesEverySessionOfTheUserAndReturnsTheCount(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $userId = Uuid::generate();
            $otherId = Uuid::generate();
            // Two rows for the subject (one active, one time-expired — both are rows, expiry is a read predicate)
            // plus one for a different user that must survive.
            $this->repository->save($this->activeSession(Uuid::generate(), $userId, '+1 hour'));
            $this->repository->save($this->activeSession(Uuid::generate(), $userId, '-1 hour'));
            $this->repository->save($this->activeSession(Uuid::generate(), $otherId, '+1 hour'));

            $this->entityManager->clear();

            $deleted = $this->repository->deleteAllForUser($userId);

            $this->assertSame(2, $deleted);
            $this->assertSame(0, $this->rowCountForUser($userId));
            $this->assertSame(1, $this->rowCountForUser($otherId));
        });
    }

    private function saveSessionCreatedAt(string $id, string $userId, string $createdAt): void
    {
        $session = $this->activeSession($id, $userId, '+1 hour');
        $session->setCreatedAt(new DateTimeImmutable($createdAt));

        $this->repository->save($session);
    }

    private function rowCountForUser(string $userId): int
    {
        $count = $this->connection->fetchOne(
            'SELECT COUNT(*) FROM iam_session WHERE user_id = CAST(:id AS uuid)',
            ['id' => $userId],
        );

        return \is_numeric($count) ? (int) $count : 0;
    }

    private function activeSession(string $id, string $userId, string $expiryOffset): Session
    {
        $now = new DateTimeImmutable(self::NOW);
        $session = Session::start(
            $id,
            $userId,
            Uuid::generate(),
            'Chrome on macOS',
            '203.0.113.7',
            $now->modify($expiryOffset),
        );
        $session->pullDomainEvents();

        return $session;
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
