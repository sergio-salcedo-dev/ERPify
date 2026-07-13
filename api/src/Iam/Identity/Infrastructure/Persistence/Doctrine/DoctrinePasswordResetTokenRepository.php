<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Infrastructure\Persistence\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Erpify\Iam\Identity\Domain\Entity\PasswordResetToken;
use Erpify\Iam\Identity\Domain\Repository\PasswordResetTokenRepository;
use Erpify\Shared\Uuid\Domain\Uuid;
use Override;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * Password-reset-token persistence by COMPOSITION (injected {@see EntityManagerInterface}, no
 * `ServiceEntityRepository` inheritance), mirroring {@see DoctrineUserRepository}. The supersede is a directed
 * DELETE — no aggregate hydration, since a pending token has no events to pull.
 */
#[AsAlias(PasswordResetTokenRepository::class)]
final readonly class DoctrinePasswordResetTokenRepository implements PasswordResetTokenRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    #[Override]
    public function save(PasswordResetToken $token): void
    {
        $this->entityManager->persist($token);
        $this->entityManager->flush();
    }

    #[Override]
    public function consume(PasswordResetToken $token): bool
    {
        // A conditional delete rather than remove()+flush(): its affected-row count is the single-use guard.
        // Two concurrent completions both resolve the same live token, but this DELETE serialises on the row
        // lock — the first removes one row (true), the loser removes none (false) and aborts, so a token can
        // drive at most one reset even under a concurrent replay.
        $affected = $this->entityManager->createQueryBuilder()
            ->delete(PasswordResetToken::class, 't')
            ->where('t.id = :id')
            ->setParameter('id', $token->getId())
            ->getQuery()
            ->execute()
        ;

        return \is_int($affected) && $affected > 0;
    }

    #[Override]
    public function findById(string $id): ?PasswordResetToken
    {
        // A malformed id is treated as an absent row: the selector half of a reset link is a UUID PK, so a
        // non-UUID value can key no row — guarding here keeps a bad selector from reaching Postgres as a uuid
        // cast error (a 500) and lets it collapse to the caller's uniform invalid-token instead.
        if (!Uuid::isValid($id)) {
            return null;
        }

        return $this->entityManager->find(PasswordResetToken::class, $id);
    }

    #[Override]
    public function deleteAllForUser(string $userId): void
    {
        $this->entityManager->createQueryBuilder()
            ->delete(PasswordResetToken::class, 't')
            ->where('t.userId = :userId')
            ->setParameter('userId', $userId)
            ->getQuery()
            ->execute()
        ;
    }
}
