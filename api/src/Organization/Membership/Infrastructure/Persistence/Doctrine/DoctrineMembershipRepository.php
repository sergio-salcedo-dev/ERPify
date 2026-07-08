<?php

declare(strict_types=1);

namespace Erpify\Organization\Membership\Infrastructure\Persistence\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Erpify\Organization\Membership\Domain\Entity\Membership;
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

    #[Override]
    public function save(Membership $membership): void
    {
        $this->entityManager->persist($membership);
        $this->entityManager->flush();
    }

    #[Override]
    public function remove(Membership $membership): void
    {
        $this->entityManager->remove($membership);
        $this->entityManager->flush();
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
