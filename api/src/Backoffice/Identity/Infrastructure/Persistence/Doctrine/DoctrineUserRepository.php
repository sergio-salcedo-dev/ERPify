<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Identity\Infrastructure\Persistence\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Erpify\Backoffice\Identity\Domain\Email;
use Erpify\Backoffice\Identity\Domain\Entity\User;
use Erpify\Backoffice\Identity\Domain\Repository\UserRepository;
use Override;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * User persistence by COMPOSITION: implements its domain port with an injected
 * {@see EntityManagerInterface} — no `ServiceEntityRepository` inheritance, no `getEntityClassName()`.
 */
#[AsAlias(UserRepository::class)]
final readonly class DoctrineUserRepository implements UserRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    #[Override]
    public function save(User $user): void
    {
        $this->entityManager->persist($user);
        $this->entityManager->flush();
    }

    #[Override]
    public function remove(User $user): void
    {
        $this->entityManager->remove($user);
        $this->entityManager->flush();
    }

    #[Override]
    public function findById(string $id): ?User
    {
        return $this->entityManager->find(User::class, $id);
    }

    #[Override]
    public function findByEmail(string $email): ?User
    {
        // Canonicalize the lookup key through the same Email value object the aggregate stores with, so
        // the match is case-insensitive against the UNIQUE column with one shared definition.
        $canonicalEmail = Email::from($email)->toString();

        return $this->entityManager->getRepository(User::class)->findOneBy(['email' => $canonicalEmail]);
    }
}
