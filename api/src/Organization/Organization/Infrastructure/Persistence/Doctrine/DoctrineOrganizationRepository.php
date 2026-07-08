<?php

declare(strict_types=1);

namespace Erpify\Organization\Organization\Infrastructure\Persistence\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Erpify\Organization\Organization\Domain\Entity\Organization;
use Erpify\Organization\Organization\Domain\Repository\OrganizationRepository;
use Override;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * Organization persistence by COMPOSITION: implements its domain port with an injected
 * {@see EntityManagerInterface} — no `ServiceEntityRepository` inheritance.
 */
#[AsAlias(OrganizationRepository::class)]
final readonly class DoctrineOrganizationRepository implements OrganizationRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    #[Override]
    public function save(Organization $organization): void
    {
        $this->entityManager->persist($organization);
        $this->entityManager->flush();
    }

    #[Override]
    public function findById(string $id): ?Organization
    {
        return $this->entityManager->find(Organization::class, $id);
    }

    #[Override]
    public function findTheOne(): ?Organization
    {
        return $this->entityManager->getRepository(Organization::class)->findOneBy([]);
    }
}
