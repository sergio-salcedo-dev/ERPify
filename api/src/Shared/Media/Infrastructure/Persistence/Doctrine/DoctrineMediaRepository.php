<?php

declare(strict_types=1);

namespace Erpify\Shared\Media\Infrastructure\Persistence\Doctrine;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\Persistence\ManagerRegistry;
use Erpify\Shared\Media\Domain\Entity\Media;
use Erpify\Shared\Media\Domain\Repository\MediaRepository;
use Override;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * @extends \Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository<\Erpify\Shared\Media\Domain\Entity\Media>
 */
#[AsAlias(MediaRepository::class)]
final class DoctrineMediaRepository extends ServiceEntityRepository implements MediaRepository
{
    public function __construct(
        ManagerRegistry $registry,
        private readonly MediaConcurrentInsertResolver $concurrentInsertResolver,
    ) {
        parent::__construct($registry, Media::class);
    }

    #[Override]
    public function saveOrGetByContentHash(Media $media): Media
    {
        try {
            $this->getEntityManager()->persist($media);
            $this->getEntityManager()->flush();
        } catch (UniqueConstraintViolationException) {
            // why: a concurrent request inserted the same content hash between the caller's dedup
            // lookup and this flush; media_content_hash_uniq rejected ours, so the winning row is
            // the canonical one — recover it on a fresh entity manager.
            return $this->concurrentInsertResolver->resolveWinner($media->getContentHash(), $this);
        }

        return $media;
    }

    #[Override]
    public function findByContentHash(string $contentHash): ?Media
    {
        /** @var Media|null */
        return $this->createQueryBuilder('m')
            ->where('m.contentHash = :h')
            ->setParameter('h', $contentHash)
            ->getQuery()
            ->getOneOrNullResult()
        ;
    }

    #[Override]
    public function existsByContentHash(string $contentHash): bool
    {
        $row = $this->createQueryBuilder('m')
            ->select('m.id')
            ->where('m.contentHash = :h')
            ->setParameter('h', $contentHash)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult()
        ;

        return null !== $row;
    }
}
