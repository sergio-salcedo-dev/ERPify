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

    #[Override]
    public function save(ErasureResweep $resweep): void
    {
        $this->entityManager->persist($resweep);
        $this->entityManager->flush();
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
    public function delete(ErasureResweep $resweep): void
    {
        $this->entityManager->remove($resweep);
        $this->entityManager->flush();
    }
}
