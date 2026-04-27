<?php

declare(strict_types=1);

namespace Erpify\Shared\Infrastructure\Persistence;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\Persistence\ManagerRegistry;
use Override;

/**
 * @template T of object
 *
 * @extends ServiceEntityRepository<T>
 */
abstract class AbstractRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, static::getEntityClassName());
    }

    #[Override]
    public function createQueryBuilder(string $alias, ?string $indexBy = null): QueryBuilderWithOptions
    {
        return (new QueryBuilderWithOptions(parent::getEntityManager()))
            ->select($alias)
            ->from($this->getClassName(), $alias, $indexBy)
        ;
    }

    #[Override]
    protected function getEntityManager(): EntityManagerInterface
    {
        return parent::getEntityManager();
    }

    /** @return ClassMetadata<T> */
    #[Override]
    protected function getClassMetadata(): ClassMetadata
    {
        return parent::getClassMetadata();
    }

    /** @return class-string<T> */
    abstract protected static function getEntityClassName(): string;
}
