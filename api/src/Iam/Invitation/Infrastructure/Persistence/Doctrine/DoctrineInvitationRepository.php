<?php

declare(strict_types=1);

namespace Erpify\Iam\Invitation\Infrastructure\Persistence\Doctrine;

use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Erpify\Iam\Invitation\Domain\Entity\Invitation;
use Erpify\Iam\Invitation\Domain\Repository\InvitationRepository;
use Override;
use RuntimeException;
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

        // Coerce rather than fall back to 0: a driver reporting the count as a numeric STRING would
        // otherwise report "nothing deleted" for rows that really went, and an erasure reaching only
        // this table would then skip the compliance record its own mutation requires.
        if (!\is_numeric($affected)) {
            throw new RuntimeException(\sprintf(
                'Deleting the %s rows of a subject returned a non-numeric affected-row count: %s.',
                'invitation',
                \get_debug_type($affected),
            ));
        }

        return (int) $affected;
    }
}
