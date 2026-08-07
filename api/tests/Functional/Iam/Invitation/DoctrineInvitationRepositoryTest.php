<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Iam\Invitation;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Erpify\Iam\Invitation\Domain\Entity\Invitation;
use Erpify\Iam\Invitation\Domain\Enum\InvitationStatus;
use Erpify\Iam\Invitation\Infrastructure\Persistence\Doctrine\DoctrineInvitationRepository;
use Erpify\Shared\Token\Domain\SingleUseToken;
use Erpify\Shared\Uuid\Domain\Uuid;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Proves the adapter against REAL Postgres, and in particular the erasure-facing bulk delete: the predicate
 * is `invited_user_id`, which is an indexed column and NOT the primary key, so it can match several rows and
 * must match only the subject's.
 *
 * Each test runs inside a transaction that truncates `iam_invitation` and always rolls back.
 *
 * @internal
 */
#[CoversClass(DoctrineInvitationRepository::class)]
final class DoctrineInvitationRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;

    private Connection $connection;

    private DoctrineInvitationRepository $repository;

    #[Override]
    protected function setUp(): void
    {
        self::bootKernel();

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;
        $this->connection = $entityManager->getConnection();
        $this->repository = new DoctrineInvitationRepository($entityManager);
    }

    public function testDeleteAllForInvitedUserDropsEveryRowOfTheSubjectWhateverItsState(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $userId = Uuid::generate();
            $other = Uuid::generate();
            $this->repository->save($this->invitationFor($userId));
            // A retired invitation still carries the invited person's id, so the erasure has to reach it too.
            $accepted = $this->invitationFor($userId);
            $accepted->markSent();
            $accepted->accept();

            $this->repository->save($accepted);
            $this->repository->save($this->invitationFor($other));

            $deleted = $this->repository->deleteAllForInvitedUser($userId);

            // A directed bulk DELETE bypasses the identity map, so the assertions read a cleared manager.
            $this->entityManager->clear();

            $this->assertSame(2, $deleted);
            $this->assertSame(0, $this->countFor($userId));
            $this->assertSame(1, $this->countFor($other));
        });
    }

    public function testDeleteAllForInvitedUserIsIdempotent(): void
    {
        $this->inRolledBackTransaction(function (): void {
            // The erasure re-runs safely, so a subject with nothing left must delete nothing rather than fail.
            $this->assertSame(0, $this->repository->deleteAllForInvitedUser(Uuid::generate()));
        });
    }

    public function testSaveThenFindByIdRoundTripsAndTheLockingReadResolvesTheSameRow(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $invitation = $this->invitationFor(Uuid::generate());
            $this->repository->save($invitation);
            $id = $invitation->getId();
            $this->assertNotNull($id);

            $this->entityManager->clear();

            $found = $this->repository->findById($id);
            $this->assertInstanceOf(Invitation::class, $found);
            $this->assertSame(InvitationStatus::CREATED, $found->status());
            // Must run inside a transaction — this one does — because the lock has to be held until commit.
            $this->assertInstanceOf(Invitation::class, $this->repository->findByIdForUpdate($id));
            $this->assertNotInstanceOf(Invitation::class, $this->repository->findById(Uuid::generate()));
        });
    }

    public function testTheLockingReadReturnsOnlyTheSubjectsStillRevocableInvitations(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $userId = Uuid::generate();

            $live = $this->invitationFor($userId);
            $live->markSent();

            $secondLive = $this->invitationFor($userId);
            $secondLive->markSent();
            // Not revocable: never delivered, already consumed, and somebody else's.
            $undelivered = $this->invitationFor($userId);
            $spent = $this->invitationFor($userId);
            $spent->markSent();
            $spent->accept();

            $foreign = $this->invitationFor(Uuid::generate());
            $foreign->markSent();

            foreach ([$live, $secondLive, $undelivered, $spent, $foreign] as $invitation) {
                $this->repository->save($invitation);
            }

            $this->entityManager->clear();

            // Runs inside a transaction — this one does — because `FOR UPDATE` needs the lock held to commit.
            $revocable = $this->repository->findSentByInvitedUserForUpdate($userId);

            // Sorted on both sides: the query declares no ORDER BY, so which of the two comes first is
            // Postgres's business and asserting it would pin a detail the caller does not depend on.
            $foundIds = \array_map(static fn (Invitation $found): ?string => $found->getId(), $revocable);
            \sort($foundIds);
            $expectedIds = [$live->getId(), $secondLive->getId()];
            \sort($expectedIds);

            $this->assertCount(2, $revocable);
            $this->assertSame($expectedIds, $foundIds);
            $this->assertSame([], $this->repository->findSentByInvitedUserForUpdate(Uuid::generate()));
        });
    }

    public function testTheRevocableReadActuallyTakesTheRowLockItPromises(): void
    {
        // The lock is the load-bearing claim of this port: it is what makes a concurrent accept drop out of the
        // set instead of leaving the revocation to abort on it. Nothing else in the suite can see it — the
        // in-memory double cannot, and the Behat budget counts queries, not lock modes — so deleting
        // `setLockMode(...)` would leave every other assertion green.
        //
        // Observed through `pg_locks` on this backend rather than from a second connection: the fixture row is
        // written inside the transaction this class always rolls back, so no other session can see it to
        // contend for. `SELECT … FOR UPDATE` takes RowShareLock on the relation where a plain SELECT takes only
        // AccessShareLock, and the difference is visible while the transaction is still open.
        $this->inRolledBackTransaction(function (): void {
            $userId = Uuid::generate();
            $live = $this->invitationFor($userId);
            $live->markSent();

            $this->repository->save($live);
            $this->entityManager->clear();

            $this->repository->findSentByInvitedUserForUpdate($userId);

            $this->assertContains(
                'RowShareLock',
                $this->lockModesHeldOnInvitations(),
                'findSentByInvitedUserForUpdate must read FOR UPDATE — a plain read takes AccessShareLock only',
            );
        });
    }

    /**
     * @return list<string>
     */
    private function lockModesHeldOnInvitations(): array
    {
        /** @phpstan-var list<string> */
        return $this->connection->fetchFirstColumn(
            'SELECT l.mode FROM pg_locks l JOIN pg_class c ON c.oid = l.relation '
            . 'WHERE c.relname = :table AND l.pid = pg_backend_pid()',
            ['table' => 'iam_invitation'],
        );
    }

    private function countFor(string $userId): int
    {
        // count(*) surfaces as an int or a numeric string depending on the driver.
        $count = $this->connection->fetchOne(
            'SELECT count(*) FROM iam_invitation WHERE invited_user_id = :userId',
            ['userId' => $userId],
        );

        return \is_numeric($count) ? (int) $count : -1;
    }

    private function invitationFor(string $userId): Invitation
    {
        $generated = SingleUseToken::mint(new DateTimeImmutable('2026-07-21T13:00:00+00:00'));
        $invitation = Invitation::create(Uuid::generate(), Uuid::generate(), $userId, $generated->token);
        $invitation->pullDomainEvents();

        return $invitation;
    }

    /**
     * @param callable(): void $testBody
     */
    private function inRolledBackTransaction(callable $testBody): void
    {
        $this->connection->beginTransaction();

        try {
            $this->connection->executeStatement('TRUNCATE iam_invitation RESTART IDENTITY CASCADE');
            $testBody();
        } finally {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }
        }
    }
}
