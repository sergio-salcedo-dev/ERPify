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
 * into SQL (a revoked or time-expired session is invisible), and the bulk revocations flip the right rows.
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
