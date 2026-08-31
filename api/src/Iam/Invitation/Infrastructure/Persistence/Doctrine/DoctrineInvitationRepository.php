<?php

declare(strict_types=1);

namespace Erpify\Iam\Invitation\Infrastructure\Persistence\Doctrine;

use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Erpify\Iam\Invitation\Domain\Entity\Invitation;
use Erpify\Iam\Invitation\Domain\Enum\InvitationStatus;
use Erpify\Iam\Invitation\Domain\Repository\InvitationRepository;
use Erpify\Shared\Persistence\Infrastructure\AffectedRows;
use Override;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * Invitation persistence by COMPOSITION (injected {@see EntityManagerInterface}, no `ServiceEntityRepository`),
 * mirroring {@see \Erpify\Iam\Session\Infrastructure\Persistence\Doctrine\DoctrineSessionRepository}. The accept
 * flow's caller validates the selector as a UUID before calling {@see findById()}, so the primary-key lookup
 * never receives a malformed id.
 */
#[AsAlias(InvitationRepository::class)]
final readonly class DoctrineInvitationRepository implements InvitationRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    #[Override]
    public function save(Invitation $invitation): void
    {
        $this->entityManager->persist($invitation);
        $this->entityManager->flush();
    }

    #[Override]
    public function findById(string $id): ?Invitation
    {
        return $this->entityManager->find(Invitation::class, $id);
    }

    #[Override]
    public function findByIdForUpdate(string $id): ?Invitation
    {
        return $this->entityManager->find(Invitation::class, $id, LockMode::PESSIMISTIC_WRITE);
    }

    /**
     * `SELECT … FOR UPDATE` through the ORM: the aggregates come back hydrated because the caller drives each
     * one's `SENT → REVOKED` transition (and its event), and the lock mode is what serialises a concurrent
     * accept of any of them onto this transaction. `invited_user_id` is indexed, so the predicate is an index
     * scan; the status narrows it to the revocable rows in the same round trip.
     *
     * **`ORDER BY id` is not presentation.** This read locks a SET rather than a row, and Postgres promises no
     * stable scan order across plans — the same predicate can arrive by index scan on one execution and by
     * bitmap heap scan on the next, walking the rows in opposite directions. Two concurrent revocations of the
     * same invitee would then each hold a row the other is waiting for: an ABBA within one table, surfacing as
     * `40P01` and a 503 with nothing in the code to explain it. Sorting fixes this statement's direction for
     * every plan. The caller does not care about the order and never will, which is exactly why the clause has
     * to say why it is here.
     *
     * Its sibling {@see deleteAllForInvitedUser()} locks an overlapping set of the same table and reaches the
     * same direction by a different route, because a bulk `DELETE` admits no `ORDER BY`.
     *
     * @return list<Invitation>
     */
    #[Override]
    public function findSentByInvitedUserForUpdate(string $userId): array
    {
        /** @phpstan-var list<Invitation> */
        return $this->entityManager->createQueryBuilder()
            ->select('i')
            ->from(Invitation::class, 'i')
            ->where('i.invitedUserId = :userId')
            ->andWhere('i.status = :status')
            ->orderBy('i.id', 'ASC')
            ->setParameter('userId', $userId)
            ->setParameter('status', InvitationStatus::SENT->value)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getResult()
        ;
    }

    /**
     * A directed bulk DELETE rather than load-then-remove: it spares a round trip per row, and the caller
     * needs the count, not the aggregates. `invited_user_id` is indexed, so the predicate is an index scan.
     *
     * **It acquires its rows through an ordered lock first, and that read is the point rather than overhead.**
     * A `DELETE` cannot carry `ORDER BY`, so on its own it takes the subject's rows in whatever direction the
     * plan walks them — while {@see findSentByInvitedUserForUpdate()} takes an overlapping set ascending by
     * id. Two directions over shared rows is an ABBA within one table: a revocation racing an erasure of the
     * same invitee each hold a row the other waits for, and it surfaces as `40P01` and a 503. One extra round
     * trip on an operation that runs once per person, against a deadlock nothing in the code would explain.
     *
     * The lock read must therefore run inside the caller's transaction — outside one Doctrine refuses it,
     * which is the right failure: a lock released at the end of its own statement protects nothing.
     */
    #[Override]
    public function deleteAllForInvitedUser(string $userId): int
    {
        $this->lockInvitedUserRowsInIdOrder($userId);

        $affected = $this->entityManager->createQueryBuilder()
            ->delete(Invitation::class, 'i')
            ->where('i.invitedUserId = :userId')
            ->setParameter('userId', $userId)
            ->getQuery()
            ->execute()
        ;

        return AffectedRows::from($affected);
    }

    /**
     * Ids only, and the result is discarded: nothing here wants the aggregates, only the order in which their
     * rows are taken. Unfiltered by status, because the delete that follows is unfiltered too — locking a
     * narrower set than the one being deleted would leave the difference to the plan again.
     */
    private function lockInvitedUserRowsInIdOrder(string $userId): void
    {
        $this->entityManager->createQueryBuilder()
            ->select('i.id')
            ->from(Invitation::class, 'i')
            ->where('i.invitedUserId = :userId')
            ->orderBy('i.id', 'ASC')
            ->setParameter('userId', $userId)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getResult()
        ;
    }
}
