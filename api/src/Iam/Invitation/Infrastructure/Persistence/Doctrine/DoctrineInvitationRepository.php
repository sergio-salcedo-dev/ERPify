<?php

declare(strict_types=1);

namespace Erpify\Iam\Invitation\Infrastructure\Persistence\Doctrine;

use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Erpify\Iam\Invitation\Domain\Entity\Invitation;
use Erpify\Iam\Invitation\Domain\Enum\InvitationStatus;
use Erpify\Iam\Invitation\Domain\Repository\InvitationRepository;
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
     */
    #[Override]
    public function deleteAllForInvitedUser(string $userId): int
    {
        $affected = $this->entityManager->createQueryBuilder()
            ->delete(Invitation::class, 'i')
            ->where('i.invitedUserId = :userId')
            ->setParameter('userId', $userId)
            ->getQuery()
            ->execute()
        ;

        return \is_int($affected) ? $affected : 0;
    }
}
