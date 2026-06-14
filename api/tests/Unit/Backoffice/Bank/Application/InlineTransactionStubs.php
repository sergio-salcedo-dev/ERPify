<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Backoffice\Bank\Application;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Stubs for the persistence boundary a transactional use case coordinates: an EntityManager whose
 * `wrapInTransaction` runs the callback inline (so a unit test exercises save/remove + publish
 * ordering without a real transaction; atomicity itself is covered functionally/Behat) and a no-op
 * ManagerRegistry for the post-rollback recount path.
 *
 * @phpstan-require-extends \PHPUnit\Framework\TestCase
 */
trait InlineTransactionStubs
{
    private function inlineTransactionEntityManager(): EntityManagerInterface
    {
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('wrapInTransaction')->willReturnCallback(
            static fn (callable $func): mixed => $func(),
        );

        return $entityManager;
    }

    private function noopManagerRegistry(): ManagerRegistry
    {
        return $this->createStub(ManagerRegistry::class);
    }
}
