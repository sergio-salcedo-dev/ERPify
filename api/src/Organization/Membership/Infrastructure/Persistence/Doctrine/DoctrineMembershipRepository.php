<?php

declare(strict_types=1);

namespace Erpify\Organization\Membership\Infrastructure\Persistence\Doctrine;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Erpify\Organization\Membership\Domain\Entity\Membership;
use Erpify\Organization\Membership\Domain\Exception\UserAlreadyMember;
use Erpify\Organization\Membership\Domain\Repository\MembershipRepository;
use Override;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * Membership persistence by COMPOSITION: implements its domain port with an injected
 * {@see EntityManagerInterface} — no `ServiceEntityRepository` inheritance.
 */
#[AsAlias(MembershipRepository::class)]
final readonly class DoctrineMembershipRepository implements MembershipRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /**
     * Translates the `user_id` UNIQUE breach into the domain {@see UserAlreadyMember} — the backstop for the
     * grant-duplicate race the application-level pre-check cannot close (two concurrent grants both pass the
     * "already a member?" read, then collide on INSERT). Mirroring how the session adapter maps a DBAL failure
     * to its domain exception, this keeps DBAL confined to Infrastructure while the caller sees one meaning.
     *
     * @throws UserAlreadyMember when the user already has a membership (unique-constraint violation)
     */
    #[Override]
    public function save(Membership $membership): void
    {
        try {
            $this->entityManager->persist($membership);
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            throw new UserAlreadyMember($membership->userId());
        }
    }

    #[Override]
    public function remove(Membership $membership): void
    {
        $this->entityManager->remove($membership);
        $this->entityManager->flush();
    }

    #[Override]
    public function deleteAllForUser(string $userId): int
    {
        $affected = $this->entityManager->createQueryBuilder()
            ->delete(Membership::class, 'm')
            ->where('m.userId = :userId')
            ->setParameter('userId', $userId)
            ->getQuery()
            ->execute()
        ;

        // `is_numeric` rather than the siblings' `is_int`: a driver reporting the count as a numeric
        // STRING would otherwise read as "nothing deleted" for rows that really went, and an erasure
        // reaching only this table would then skip the compliance record its own mutation requires.
        return \is_numeric($affected) ? (int) $affected : 0;
    }

    #[Override]
    public function findByUserId(string $userId): ?Membership
    {
        return $this->entityManager->getRepository(Membership::class)->findOneBy(['userId' => $userId]);
    }

    #[Override]
    public function findByOrganizationId(string $organizationId): array
    {
        return $this->entityManager->getRepository(Membership::class)->findBy(['organizationId' => $organizationId]);
    }
}
