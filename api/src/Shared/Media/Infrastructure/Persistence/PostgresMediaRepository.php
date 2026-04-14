<?php

declare(strict_types=1);

namespace Erpify\Shared\Media\Infrastructure\Persistence;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Erpify\Shared\Media\Domain\Entity\Media;
use Erpify\Shared\Media\Domain\Repository\MediaRepository;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * @extends \Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository<\Erpify\Shared\Media\Domain\Entity\Media>
 */
#[AsAlias(MediaRepository::class)]
final class PostgresMediaRepository extends ServiceEntityRepository implements MediaRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Media::class);
    }

    public function save(Media $media): void
    {
        $this->getEntityManager()->persist($media);
        $this->getEntityManager()->flush();
    }

    public function findActiveByContentHash(string $contentHash): ?Media
    {
        return $this->createQueryBuilder('m')
            ->where('m.contentHash = :h')
            ->andWhere('m.deletedAt IS NULL')
            ->setParameter('h', $contentHash)
            ->getQuery()
            ->getOneOrNullResult()
        ;
    }

    public function existsActiveByContentHash(string $contentHash): bool
    {
        $row = $this->createQueryBuilder('m')
            ->select('m.id')
            ->where('m.contentHash = :h')
            ->andWhere('m.deletedAt IS NULL')
            ->setParameter('h', $contentHash)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult()
        ;

        return null !== $row;
    }
}
