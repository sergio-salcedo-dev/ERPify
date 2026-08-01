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
