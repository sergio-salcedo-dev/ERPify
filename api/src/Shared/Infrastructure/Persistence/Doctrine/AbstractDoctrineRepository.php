<?php

declare(strict_types=1);

namespace Erpify\Shared\Infrastructure\Persistence\Doctrine;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Override;

/**
 * Legacy Doctrine repository base, retained ONLY to back the env-gated `pagination_mode=legacy`
 * search valve (its sole live subtype is {@see AbstractDoctrineSearchRepository}); the cursor-only
 * runtime read-path uses {@see \Doctrine\ORM\EntityManagerInterface} by composition instead. The
 * whole legacy pagination stack is deleted in PR4 (AR16).
 *
 * PR3 stripped the helpers the flip left without a caller (FR11): the persist/flush wrappers (the
 * composition repositories call `EntityManagerInterface::persist()`/`flush()` directly), `addLimit`
 * (callers use `setMaxResults` directly), and the `addWhere*`/`sanitizeArray` filter helpers with
 * their `generateUniqueParameter*` chain. The reason that chain existed is preserved here as
 * institutional knowledge, since the live filtering now runs through the keyset engine's applier:
 *
 *   why (param naming): a generated DQL parameter name must be STABLE across executions of the same
 *   logical query. An unstable name (e.g. a counter or random suffix) makes Doctrine emit a fresh
 *   SQL cache file per request, which can grow until it exhausts disk. Any future parameter-name
 *   generator (in the engine's applier or elsewhere) must keep the name a deterministic function of
 *   the query shape, not of call order or time.
 *
 * @template T of object
 *
 * @extends ServiceEntityRepository<T>
 */
abstract class AbstractDoctrineRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct(registry: $registry, entityClass: static::getEntityClassName());
    }

    /** @return class-string<T> */
    abstract protected static function getEntityClassName(): string;

    /**
     * @return QueryBuilderWithOptions
     */
    #[Override]
    public function createQueryBuilder(string $alias, ?string $indexBy = null): QueryBuilder
    {
        return (new QueryBuilderWithOptions($this->getEntityManager()))
            ->select($alias)
            ->from($this->getClassName(), $alias, $indexBy)
        ;
    }
}
