<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Infrastructure\Persistence\Doctrine;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Erpify\Iam\Identity\Domain\Entity\RecoverySecret;
use Erpify\Iam\Identity\Domain\Exception\RecoverySecretAlreadyExists;
use Erpify\Iam\Identity\Domain\Repository\RecoverySecretRepository;
use Erpify\Shared\Uuid\Domain\Uuid;
use Override;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * Recovery-secret persistence by COMPOSITION (injected {@see EntityManagerInterface}, no
 * `ServiceEntityRepository` inheritance), mirroring {@see DoctrineUserRepository}.
 *
 * Both `ForUpdate` finders are DQL lock queries carrying `HINT_REFRESH`, not `find(…, PESSIMISTIC_WRITE)`.
 * Their callers have already resolved the same row this request, and on a managed entity `find()` only LOCKS
 * it — the hydrated state would stay the pre-lock snapshot, so the verify these methods exist to serialise
 * would run against exactly the stale row the lock was taken to rule out. The hint re-hydrates from the
 * locked row inside the same `SELECT … FOR UPDATE`.
 */
#[AsAlias(RecoverySecretRepository::class)]
final readonly class DoctrineRecoverySecretRepository implements RecoverySecretRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    #[Override]
    public function save(RecoverySecret $secret): void
    {
        try {
            $this->entityManager->persist($secret);
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            // One row per identity is a schema invariant, so two mints racing past the same locked read
            // cannot both land. It is translated into the module's OWN refusal rather than the generic
            // ConcurrentUniqueWrite so the raced mint and the checked one answer with one wire type: the
            // caller met one situation and has one remedy, and two spellings of it is how a client ends up
            // handling only whichever it happened to see first.
            //
            // Unbound, like the six other catches of this type in the tree. Binding it would carry the
            // SQLSTATE into the dev debug chain while the two naming gates disagree about what the variable
            // may be called: Rector demands the type's full name and PHPMD refuses any identifier that long.
            //
            // What the unbound catch costs is stated rather than waved past: this table carries a second
            // unique constraint, its `PRIMARY KEY (id)`, so a selector collision would also answer "you
            // already hold one" — the wrong answer with the wrong remedy. Selectors are UUID v7, so that
            // branch is unreachable in practice; it is named because an unbound catch is only reviewable
            // when the set it swallows is written down.
            throw new RecoverySecretAlreadyExists();
        }
    }

    #[Override]
    public function remove(RecoverySecret $secret): void
    {
        $this->entityManager->remove($secret);
        $this->entityManager->flush();
    }

    #[Override]
    public function findBySelector(string $selector): ?RecoverySecret
    {
        // A malformed selector is treated as an absent row: it can key nothing, so guarding here keeps a
        // hostile value from reaching Postgres as a uuid cast error (a 500 that would also distinguish a
        // malformed selector from a merely unknown one) and lets it collapse into the caller's opaque wall.
        if (!Uuid::isValid($selector)) {
            return null;
        }

        return $this->entityManager->find(RecoverySecret::class, $selector);
    }

    #[Override]
    public function findBySelectorForUpdate(string $selector): ?RecoverySecret
    {
        if (!Uuid::isValid($selector)) {
            return null;
        }

        $secret = $this->entityManager->createQueryBuilder()
            ->select('s')
            ->from(RecoverySecret::class, 's')
            ->where('s.id = :selector')
            ->setParameter('selector', $selector)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult()
        ;

        return $secret instanceof RecoverySecret ? $secret : null;
    }

    #[Override]
    public function findByUserId(string $userId): ?RecoverySecret
    {
        return $this->entityManager->getRepository(RecoverySecret::class)->findOneBy(['userId' => $userId]);
    }

    #[Override]
    public function findByUserIdForUpdate(string $userId): ?RecoverySecret
    {
        // Spelled out rather than factored with the selector lookup above, deliberately: the shared form
        // would have to take the column as an argument, which is DQL assembled from a variable — the one
        // shape this repo's security checklist refuses outright — to save two lines on the only two callers
        // that will ever exist.
        $secret = $this->entityManager->createQueryBuilder()
            ->select('s')
            ->from(RecoverySecret::class, 's')
            ->where('s.userId = :userId')
            ->setParameter('userId', $userId)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult()
        ;

        return $secret instanceof RecoverySecret ? $secret : null;
    }

    #[Override]
    public function deleteAllForUser(string $userId): int
    {
        $affected = $this->entityManager->createQueryBuilder()
            ->delete(RecoverySecret::class, 's')
            ->where('s.userId = :userId')
            ->setParameter('userId', $userId)
            ->getQuery()
            ->execute()
        ;

        return \is_int($affected) ? $affected : 0;
    }
}
