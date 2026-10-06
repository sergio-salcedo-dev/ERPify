<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Infrastructure\Persistence\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Erpify\Iam\Identity\Domain\Entity\ErasureResweep;
use Erpify\Iam\Identity\Domain\Repository\ErasureResweepRepository;
use Override;
use SortDirection as NativeSortDirection;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * {@see ErasureResweepRepository} through the ORM. The table holds one row per subject erased within the last
 * window, so reading it whole is bounded by the erasure rate rather than by history.
 */
#[AsAlias(ErasureResweepRepository::class)]
final readonly class DoctrineErasureResweepRepository implements ErasureResweepRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /**
     * An upsert on the subject rather than a persist, and the conflict is reachable: an identity recreated under
     * the same id — a fixture or seed with fixed ids — and erased again inside the window would otherwise fail
     * the erasure on the unique index, with the subject's id in the driver's `DETAIL` line and so in the error
     * response and the log. A second erasure restarts the window, because it is owed one of its own. Written
     * through the connection rather than a flush, which also spares the erasure the savepoint pair a nested
     * flush emits.
     */
    #[Override]
    public function save(ErasureResweep $resweep): void
    {
        $this->entityManager->getConnection()->executeStatement(
            'INSERT INTO identity_erasure_resweep (id, subject_id, created_at, updated_at) '
            . 'VALUES (CAST(:id AS UUID), CAST(:subject_id AS UUID), :scheduled_at, :scheduled_at) '
            . 'ON CONFLICT (subject_id) DO UPDATE SET created_at = EXCLUDED.created_at, '
            . 'updated_at = EXCLUDED.updated_at',
            [
                'id' => $resweep->getId(),
                'subject_id' => $resweep->subjectId(),
                'scheduled_at' => $resweep->getCreatedAt()->format('Y-m-d H:i:s'),
            ],
        );
    }

    #[Override]
    public function findAll(): array
    {
        /** @var list<ErasureResweep> $resweeps */
        $resweeps = $this->entityManager->createQueryBuilder()
            ->select('r')
            ->from(ErasureResweep::class, 'r')
            ->orderBy('r.createdAt', NativeSortDirection::Ascending)
            ->addOrderBy('r.id', NativeSortDirection::Ascending)
            ->getQuery()
            ->getResult()
        ;

        return $resweeps;
    }

    #[Override]
    public function deleteForSubject(string $subjectId): void
    {
        $this->entityManager->createQueryBuilder()
            ->delete(ErasureResweep::class, 'r')
            ->where('r.subjectId = :subjectId')
            ->setParameter('subjectId', $subjectId)
            ->getQuery()
            ->execute()
        ;
    }
}
