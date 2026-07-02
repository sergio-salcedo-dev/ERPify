<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Identity\Infrastructure\Persistence\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
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
        // The aggregate stores email canonicalized (trimmed, lower-cased); match that same form so the
        // lookup the session firewall performs is case-insensitive against the UNIQUE column.
        /** @var User|null $user */
        $user = $this->entityManager->createQueryBuilder()
            ->select('u')
            ->from(User::class, 'u')
            ->where('u.email = :email')
            ->setParameter('email', \mb_strtolower(\trim($email)))
            ->getQuery()
            ->getOneOrNullResult()
        ;

        return $user;
    }
}
