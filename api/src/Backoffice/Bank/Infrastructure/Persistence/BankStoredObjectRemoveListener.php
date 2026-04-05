<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Bank\Infrastructure\Persistence;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Events;
use Erpify\Backoffice\Bank\Domain\Entity\Bank;
use Erpify\Shared\Storage\Application\StoredObjectOrphanCleaner;

/**
 * After a bank is removed, drop the Flysystem blob only if no other aggregate still references the hash.
 */
#[AsEntityListener(entity: Bank::class, event: Events::postRemove, method: 'removeStoredObjectIfOrphaned')]
final class BankStoredObjectRemoveListener
{
    public function __construct(
        private readonly StoredObjectOrphanCleaner $orphanCleaner,
    ) {
    }

    public function removeStoredObjectIfOrphaned(Bank $bank): void
    {
        $this->orphanCleaner->cleanupAfterRemoval($bank->getStoredObjectContentHash());
    }
}
