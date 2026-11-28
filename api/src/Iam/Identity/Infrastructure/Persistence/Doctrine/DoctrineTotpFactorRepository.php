<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Infrastructure\Persistence\Doctrine;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Erpify\Iam\Identity\Domain\Entity\TotpFactor;
use Erpify\Iam\Identity\Domain\Repository\TotpFactorRepository;
use Erpify\Shared\Persistence\Domain\Exception\ConcurrentUniqueWrite;
use Override;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * Second-factor persistence by COMPOSITION, mirroring {@see DoctrineRecoverySecretRepository}.
 *
 * The locking finder is a DQL lock query carrying `HINT_REFRESH` rather than `find(…, PESSIMISTIC_WRITE)`: on an
 * entity already managed in this request `find()` only locks it, and the decision this lock serialises — whether
 * a code was already accepted at a step — would then run against the pre-lock snapshot.
 */
#[AsAlias(TotpFactorRepository::class)]
final readonly class DoctrineTotpFactorRepository implements TotpFactorRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    #[Override]
    public function save(TotpFactor $factor): void
    {
        try {
            $this->entityManager->persist($factor);
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            // One row per identity is a schema invariant: two first enrolments racing past the same locked read
            // (no row to lock yet) cannot both land. The loser is answered with the generic retryable conflict
            // rather than a module type, because what it met is a PENDING row a retry restarts, not an enabled
            // factor. The table's other unique constraint is its primary key, a UUID v7 the caller mints; a
            // collision there is unreachable in practice and would be answered the same way.
            throw ConcurrentUniqueWrite::onWrite('totp-factor');
        }
    }

    #[Override]
    public function remove(TotpFactor $factor): void
    {
        $this->entityManager->remove($factor);
        $this->entityManager->flush();
    }

    #[Override]
    public function findByUserIdForUpdate(string $userId): ?TotpFactor
    {
        $factor = $this->entityManager->createQueryBuilder()
            ->select('f')
            ->from(TotpFactor::class, 'f')
            ->where('f.userId = :userId')
            ->setParameter('userId', $userId)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult()
        ;

        return $factor instanceof TotpFactor ? $factor : null;
    }
}
