<?php

declare(strict_types=1);

namespace Erpify\Shared\Persistence\Infrastructure;

use Doctrine\ORM\EntityManagerInterface;
use Erpify\Shared\Persistence\Application\TransactionManager;
use Override;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * The Doctrine adapter for {@see TransactionManager}: delegates to {@see EntityManagerInterface::wrapInTransaction},
 * which begins a transaction, flushes and commits on success, and rolls back on any throwable. Confining the
 * EntityManager here keeps orchestration (Application) free of the ORM and is the deptrac-clean seam that
 * pre-existing `wrapInTransaction`-in-Application usages are being ratcheted towards.
 */
#[AsAlias(TransactionManager::class)]
final readonly class DoctrineTransactionManager implements TransactionManager
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @template T
     *
     * @param callable(): T $operation
     *
     * @return T
     */
    #[Override]
    public function transactional(callable $operation): mixed
    {
        return $this->entityManager->wrapInTransaction($operation);
    }
}
