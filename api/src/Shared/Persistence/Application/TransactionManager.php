<?php

declare(strict_types=1);

namespace Erpify\Shared\Persistence\Application;

/**
 * Runs a unit of work inside a single database transaction: every write the callback performs commits
 * together or, on any throwable, rolls back together. It is the framework-free seam a use case depends on
 * to own its transaction boundary without importing the ORM — the Doctrine adapter lives in Infrastructure
 * (ADR docs/adr/external-dependencies-in-domain.md: Doctrine stays out of Application).
 */
interface TransactionManager
{
    /**
     * @template T
     *
     * @param callable(): T $operation
     *
     * @return T
     */
    public function transactional(callable $operation): mixed;
}
